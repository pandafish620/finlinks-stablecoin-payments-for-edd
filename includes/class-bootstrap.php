<?php
/**
 * Dependency checks, schema, signed REST endpoint and backup reconciliation.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle and the signed webhook route.
 */
final class Bootstrap {

	const DB_OPTION = 'finlstpa_db_version';

	const CRON_HOOK = 'finlstpa_reconcile';

	const CRON_SCHEDULE = 'finlstpa_ten_minutes';

	const REST_NAMESPACE = 'finlstpa/v1';

	/**
	 * Previous webhook namespace. In-flight sessions and an already registered
	 * FinLinks connection still POST here. The handler is the same HMAC check.
	 */
	const LEGACY_REST_NAMESPACE = 'finlinks-edd/v1';

	/**
	 * Webhook event table, including the WordPress prefix.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'finlstpa_webhook_events';
	}

	/**
	 * Whether a plugin-owned table is present.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found === $table ) {
			return true;
		}
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %i is the identifier placeholder. SQLite has no SHOW TABLES.
		$wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';
		return '' === $error;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		Migrate::run();
		self::install();
	}

	/**
	 * Create the event table when it is missing.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		if ( '1' === get_option( self::DB_OPTION ) && self::table_exists( self::events_table() ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::events_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			event_id varchar(64) NOT NULL,
			received_at datetime NOT NULL,
			PRIMARY KEY  (event_id),
			KEY received_at (received_at)
		) {$charset};"
		);
		if ( self::table_exists( $table ) ) {
			update_option( self::DB_OPTION, '1', false );
		}
	}

	/**
	 * Deactivation clears this plugin's schedules only.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'finlinks_edd_reconcile' );
	}

	/**
	 * Boot the gateway after WordPress and, when present, Easy Digital Downloads.
	 *
	 * @return void
	 */
	public static function init() {
		Migrate::run();
		add_action( 'admin_notices', array( Migrate::class, 'render_notice' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		if ( ! defined( 'EDD_VERSION' ) || version_compare( EDD_VERSION, '3.0', '<' ) || ! function_exists( 'edd_get_order' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
			return;
		}
		if ( '1' !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
		Settings::init();
		Gateway::init();
		add_filter( 'cron_schedules', array( __CLASS__, 'interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Backup payment confirmation runs every ten minutes.
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( Order_Sync::class, 'reconcile' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FINLSTPA_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Admin notice when Easy Digital Downloads 3 is missing.
	 *
	 * @return void
	 */
	public static function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'FinLinks requires Easy Digital Downloads 3.0 or newer. No payment gateway has been activated.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
	}

	/**
	 * Custom cron interval.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public static function interval( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 600,
			'display'  => __( 'FinLinks EDD: every ten minutes', 'finlinks-stablecoin-payments-for-edd' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the backup confirmation check once.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Register the current webhook route and the one compatibility route.
	 *
	 * @return void
	 */
	public static function register_rest() {
		$args = array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => array( __CLASS__, 'webhook_permission' ),
		);
		register_rest_route( self::REST_NAMESPACE, '/webhook', $args );
		register_rest_route( self::LEGACY_REST_NAMESPACE, '/webhook', $args );
	}

	/**
	 * Payment notifications are server-to-server. Callers have no WordPress session.
	 * Authentication is the HMAC check in webhook().
	 *
	 * @return true
	 */
	public static function webhook_permission() {
		return true;
	}

	/**
	 * Verify and dispatch one signed webhook body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function webhook( \WP_REST_Request $request ) {
		$body = $request->get_body();
		if ( ! is_string( $body ) || strlen( $body ) > 262144 ) {
			return new \WP_REST_Response( array( 'error' => 'payload_too_large' ), 413 );
		}
		try {
			$processor                = new Webhook_Processor(
				Settings::get( 'webhook_secret' ),
				new Event_Store(),
				array( Order_Sync::class, 'event' ),
				null,
				static function ( $timestamp ) {
					$timestamp = (int) $timestamp;
					if ( $timestamp < 1 ) {
						return;
					}
					update_option(
						Settings::STALE_OPTION,
						array(
							'skew' => time() - $timestamp,
							'at'   => time(),
						),
						false
					);
				}
			);
			list( $status, $payload ) = $processor->handle( $request->get_headers(), $body );
		} catch ( \Throwable $error ) {
			Logger::log( 'Webhook processing deferred: local persistence unavailable.', 'error' );
			return new \WP_REST_Response( array( 'error' => 'retry_later' ), 503 );
		}
		$status = (int) $status;
		if ( $status < 200 || $status > 599 || ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'error' => 'retry_later' ), 503 );
		}
		return new \WP_REST_Response( $payload, $status );
	}

	/**
	 * Plugins screen link.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		array_unshift( $links, '<a href="' . esc_url( Settings::url() ) . '">' . esc_html__( 'Settings', 'finlinks-stablecoin-payments-for-edd' ) . '</a>' );
		return $links;
	}
}
