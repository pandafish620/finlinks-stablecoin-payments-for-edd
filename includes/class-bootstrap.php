<?php
/**
 * Dependency checks, schema, signed REST endpoint and backup reconciliation.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	const DB_OPTION = 'finlinks_edd_db_version';
	const CRON_HOOK = 'finlinks_edd_reconcile';
	const REST_NAMESPACE = 'finlinks-edd/v1';

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'finlinks_edd_webhook_events';
	}

	public static function activate() {
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::events_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			event_id varchar(64) NOT NULL,
			received_at datetime NOT NULL,
			PRIMARY KEY  (event_id),
			KEY received_at (received_at)
		) {$charset};" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Confirm schema installation before recording its version.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found === $table ) {
			update_option( self::DB_OPTION, '1', false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function init() {
		if ( ! defined( 'EDD_VERSION' ) || version_compare( EDD_VERSION, '3.0', '<' ) || ! function_exists( 'edd_get_order' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
			return;
		}
		if ( '1' !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
		Settings::init();
		Gateway::init();
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'interval' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( Order_Sync::class, 'reconcile' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FINLINKS_EDD_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function dependency_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FinLinks requires Easy Digital Downloads 3.0 or newer. No payment gateway has been activated.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		}
	}

	public static function interval( $schedules ) {
		$schedules['finlinks_edd_ten_minutes'] = array( 'interval' => 600, 'display' => __( 'FinLinks EDD: every ten minutes', 'finlinks-stablecoin-payments-for-edd' ) );
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'finlinks_edd_ten_minutes', self::CRON_HOOK );
		}
	}

	public static function register_rest() {
		register_rest_route( self::REST_NAMESPACE, '/webhook', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'webhook' ),
			// No WordPress nonce: authenticate the exact raw body using the FinLinks HMAC.
			'permission_callback' => '__return_true',
		) );
	}

	public static function webhook( \WP_REST_Request $request ) {
		if ( strlen( $request->get_body() ) > 262144 ) {
			return new \WP_REST_Response( array( 'error' => 'payload_too_large' ), 413 );
		}
		try {
			$processor = new Finlinks_Webhook_Processor(
				Settings::get( 'webhook_secret' ), new Event_Store(), array( Order_Sync::class, 'event' ),
				null, static function ( $timestamp ) {
					update_option( Settings::STALE_OPTION, array( 'skew' => time() - $timestamp, 'at' => time() ), false );
				}
			);
			list( $status, $body ) = $processor->handle( $request->get_headers(), $request->get_body() );
		} catch ( \Throwable $error ) {
			Finlinks_Logger::log( 'Webhook processing deferred: local persistence unavailable.', 'error' );
			return new \WP_REST_Response( array( 'error' => 'retry_later' ), 503 );
		}
		return new \WP_REST_Response( $body, $status );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Settings::url() ) . '">' . esc_html__( 'Settings', 'finlinks-stablecoin-payments-for-edd' ) . '</a>' );
		return $links;
	}
}

