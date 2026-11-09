<?php
/**
 * Masked credential settings and nonce-protected connection tools.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Shop-manager settings screen.
 */
final class Settings {

	const OPTION = 'finlstpa_settings';

	const NONCE_SAVE = 'finlstpa_save';

	const NONCE_TOOLS = 'finlstpa_tools';

	const CLOCK_OPTION = 'finlstpa_clock';

	const STALE_OPTION = 'finlstpa_stale_webhook';

	const PAUSE_OPTION = 'finlstpa_pause';

	const PAGE = 'finlstpa-settings';

	/**
	 * Register admin hooks. No guest AJAX hook is registered.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_finlstpa_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_finlstpa_tools', array( __CLASS__, 'post_tools' ) );
		add_action( 'wp_ajax_finlstpa_tools', array( __CLASS__, 'ajax_tools' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
	}

	/**
	 * One stored string setting.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Value when the setting is missing.
	 * @return string
	 */
	public static function get( $key, $fallback = '' ) {
		$options = get_option( self::OPTION, array() );
		return is_array( $options ) && isset( $options[ $key ] ) && is_string( $options[ $key ] ) ? $options[ $key ] : $fallback;
	}

	/**
	 * Whether Easy Digital Downloads is in test mode.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		return function_exists( 'edd_is_test_mode' ) && edd_is_test_mode();
	}

	/**
	 * API client for the saved key and base URL.
	 *
	 * @return Api_Client
	 */
	public static function api() {
		return new Api_Client( self::base(), self::get( 'api_key' ) );
	}

	/**
	 * Resolved API base. Live mode ignores any saved override.
	 *
	 * @return string
	 */
	public static function base() {
		return Api_Base::resolve( self::test_mode(), self::test_mode() ? self::get( 'api_base' ) : '' );
	}

	/**
	 * Whether credentials are complete for the current mode.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::base() && Credentials::gateway_ready( self::test_mode(), self::get( 'api_key' ), self::get( 'webhook_secret' ) );
	}

	/**
	 * Whether checkout may offer this gateway.
	 *
	 * @return bool
	 */
	public static function checkout_ready() {
		$pause = get_option( self::PAUSE_OPTION );
		return 'yes' === self::get( 'enabled' ) && '1' === get_option( Bootstrap::DB_OPTION ) && self::configured()
			&& ( ! is_array( $pause ) || empty( $pause['until'] ) || (int) $pause['until'] <= time() );
	}

	/**
	 * Settings URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=download&page=' . self::PAGE );
	}

	/**
	 * Webhook URL to register for new sessions.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( Bootstrap::REST_NAMESPACE . '/webhook' );
	}

	/**
	 * Whether a store URL is https on this site, without userinfo, a port, or a fragment.
	 *
	 * @param string $url   URL.
	 * @param bool   $query Whether a query string is allowed.
	 * @return bool
	 */
	public static function url_ok( $url, $query = true ) {
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] )
			&& is_string( $home ) && strtolower( $parts['host'] ) === strtolower( $home )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! isset( $parts['port'] ) && ! isset( $parts['fragment'] ) && ( $query || ! isset( $parts['query'] ) );
	}

	/**
	 * Submenu under Downloads.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=download',
			__( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ),
			__( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ),
			'manage_shop_settings',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin script for the settings screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function scripts( $hook ) {
		if ( 'download_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_script( 'finlstpa-admin', FINLSTPA_URL . 'assets/js/admin.js', array(), FINLSTPA_VERSION, true );
		wp_localize_script(
			'finlstpa-admin',
			'finlstpa_admin',
			array(
				'url'         => admin_url( 'admin-ajax.php' ),
				'working'     => __( 'Running connection check…', 'finlinks-stablecoin-payments-for-edd' ),
				'unconfirmed' => __( 'The result could not be confirmed. Reload this page before retrying.', 'finlinks-stablecoin-payments-for-edd' ),
			)
		);
	}

	/**
	 * Shop managers only. Checkout and webhooks do not use this check.
	 *
	 * @return void
	 */
	private static function require_shop_manager() {
		if ( ! current_user_can( 'manage_shop_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage payment settings.', 'finlinks-stablecoin-payments-for-edd' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Read one text field after the caller-specific nonce has been checked in this function.
	 *
	 * @param string $key    Field name.
	 * @param string $action Nonce action.
	 * @return string
	 */
	private static function text_field( $key, $action ) {
		if ( wp_doing_ajax() ) {
			check_ajax_referer( $action, 'finlstpa_nonce' );
		} else {
			check_admin_referer( $action, 'finlstpa_nonce' );
		}
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function save() {
		self::require_shop_manager();
		$next = array(
			'enabled'  => 'yes' === self::text_field( 'enabled', self::NONCE_SAVE ) ? 'yes' : 'no',
			'debug'    => 'yes' === self::text_field( 'debug', self::NONCE_SAVE ) ? 'yes' : 'no',
			'api_base' => '',
		);
		if ( self::test_mode() ) {
			$next['api_base'] = esc_url_raw( self::text_field( 'api_base', self::NONCE_SAVE ) );
		}
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			$value        = self::text_field( $key, self::NONCE_SAVE );
			$clear        = self::text_field( $key . '_clear', self::NONCE_SAVE );
			$next[ $key ] = '1' === $clear ? '' : ( '' === $value ? self::get( $key ) : $value );
			if ( ( 'yes' === $next['enabled'] || '' !== $next[ $key ] ) && '' !== Credentials::save_error( self::test_mode(), $key, $next[ $key ] ) ) {
				self::notice_redirect( false, __( 'Credentials were not saved. Use Easy Digital Downloads Live mode, with a live FinLinks API key and a webhook signing secret.', 'finlinks-stablecoin-payments-for-edd' ) );
			}
		}
		if ( self::test_mode() && '' !== $next['api_base'] && '' === Api_Base::resolve( true, $next['api_base'] ) ) {
			self::notice_redirect( false, __( 'That address was not saved. Use Easy Digital Downloads Live mode. Do not enter a custom API URL. Live payments call only https://api.finlinks.io.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		update_option( self::OPTION, $next, false );
		delete_option( self::PAUSE_OPTION );
		self::notice_redirect( true, __( 'Settings saved. Register and verify the webhook before enabling this gateway in EDD.', 'finlinks-stablecoin-payments-for-edd' ) );
	}

	/**
	 * Store a short admin notice and return to the settings screen.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice_redirect( $ok, $message ) {
		$message = sanitize_text_field( (string) $message );
		if ( strlen( $message ) > 500 ) {
			$message = substr( $message, 0, 500 );
		}
		set_transient( 'finlstpa_notice_' . get_current_user_id(), array( (bool) $ok, $message ), 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Connection tools submitted without JavaScript.
	 *
	 * @return void
	 */
	public static function post_tools() {
		self::require_shop_manager();
		list( $ok, $message ) = self::run( self::text_field( 'operation', self::NONCE_TOOLS ) );
		self::notice_redirect( $ok, $message );
	}

	/**
	 * Connection tools submitted by the settings script.
	 *
	 * @return void
	 */
	public static function ajax_tools() {
		self::require_shop_manager();
		list( $ok, $message ) = self::run( self::text_field( 'operation', self::NONCE_TOOLS ) );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ), 400 );
	}

	/**
	 * Run one connection tool.
	 *
	 * @param string $operation test, register, or ping.
	 * @return array{0:bool,1:string}
	 */
	public static function run( $operation ) {
		if ( ! in_array( $operation, array( 'test', 'register', 'ping' ), true ) ) {
			return array( false, __( 'Unknown connection action.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		if ( ! self::configured() ) {
			return array( false, __( 'Save valid credentials for the current EDD mode first.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		$api = self::api();
		if ( 'register' === $operation ) {
			if ( ! self::url_ok( self::webhook_url(), false ) ) {
				return array( false, __( 'Use HTTPS and pretty permalinks for this store webhook.', 'finlinks-stablecoin-payments-for-edd' ) );
			}
			$result = $api->put_webhook( self::webhook_url() );
			if ( $result['ok'] ) {
				$result = $api->verify_connection();
			}
		} elseif ( 'test' === $operation ) {
			$result = $api->get_connection();
		} else {
			$result = $api->test_webhook();
		}
		if ( ! $result['ok'] ) {
			return array( false, Messages::admin_failure( $result['code'], $result['data'] ) );
		}
		if ( 'ping' === $operation ) {
			$delivered = ! empty( $result['data']['delivered'] );
			return array(
				$delivered,
				$delivered
					? __( 'The test webhook was delivered.', 'finlinks-stablecoin-payments-for-edd' )
					: __( 'Webhook delivery was not confirmed. Check the store firewall and webhook URL.', 'finlinks-stablecoin-payments-for-edd' ),
			);
		}
		$data   = isset( $result['data']['connection'] ) && is_array( $result['data']['connection'] ) ? $result['data']['connection'] : $result['data'];
		$status = isset( $data['status'] ) && is_string( $data['status'] ) ? strtoupper( $data['status'] ) : 'UNKNOWN';
		if ( ! preg_match( '/^[A-Z0-9_]{1,32}$/', $status ) ) {
			$status = 'UNKNOWN';
		}
		if ( 'ACTIVE' === $status ) {
			delete_option( self::PAUSE_OPTION );
		}
		/* translators: %s: connection status reported by FinLinks. */
		return array( 'register' !== $operation || 'ACTIVE' === $status, sprintf( __( 'Connection status: %s.', 'finlinks-stablecoin-payments-for-edd' ), $status ) );
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public static function render() {
		self::require_shop_manager();
		echo '<div class="wrap"><h1>' . esc_html__( 'FinLinks Stablecoin Payments for EDD', 'finlinks-stablecoin-payments-for-edd' ) . '</h1>';
		$notice = get_transient( 'finlstpa_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && array_key_exists( 0, $notice ) && isset( $notice[1] ) && is_string( $notice[1] ) && strlen( $notice[1] ) <= 500 ) {
			delete_transient( 'finlstpa_notice_' . get_current_user_id() );
			echo '<div class="notice ' . esc_attr( $notice[0] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Use a FinLinks connection for this store with Easy Digital Downloads in Live mode. Enable FinLinks USDC separately under Downloads → Settings → Payments. Do not turn on EDD Test mode, and do not enter a custom API URL.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		echo '<p>' . esc_html__( 'Webhook URL:', 'finlinks-stablecoin-payments-for-edd' ) . ' <code>' . esc_html( self::webhook_url() ) . '</code></p>';
		if ( '1' !== get_option( Bootstrap::DB_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The webhook event table is unavailable. Payments stay disabled until database setup succeeds.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		}
		foreach ( array( self::CLOCK_OPTION, self::STALE_OPTION ) as $option ) {
			$clock = get_option( $option );
			if ( is_array( $clock ) && isset( $clock['skew'], $clock['at'] ) && is_numeric( $clock['skew'] ) && is_numeric( $clock['at'] ) && (int) $clock['at'] > time() - DAY_IN_SECONDS && Timing::skew_is_excessive( (int) $clock['skew'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The server clock differs from the payment service. Correct NTP/time settings to avoid webhook delays.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
				break;
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="finlstpa_save">';
		wp_nonce_field( self::NONCE_SAVE, 'finlstpa_nonce' );
		echo '<p><label><input type="checkbox" name="enabled" value="yes" ' . checked( self::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Allow USDC payments when enabled in EDD', 'finlinks-stablecoin-payments-for-edd' ) . '</label></p>';
		echo '<p><strong>' . esc_html__( 'Mode:', 'finlinks-stablecoin-payments-for-edd' ) . '</strong> ' . esc_html( self::test_mode() ? __( 'EDD test mode', 'finlinks-stablecoin-payments-for-edd' ) : __( 'EDD live mode', 'finlinks-stablecoin-payments-for-edd' ) ) . '</p><table class="form-table"><tbody>';
		foreach ( array(
			'api_key'        => __( 'API key', 'finlinks-stablecoin-payments-for-edd' ),
			'webhook_secret' => __( 'Webhook signing secret', 'finlinks-stablecoin-payments-for-edd' ),
		) as $key => $label ) {
			echo '<tr><th><label for="' . esc_attr( 'finlstpa_' . $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="password" autocomplete="new-password" value="" id="' . esc_attr( 'finlstpa_' . $key ) . '" name="' . esc_attr( $key ) . '">';
			echo '<p class="description">' . esc_html( '' !== self::get( $key ) ? __( 'Saved. Leave blank to retain the current value.', 'finlinks-stablecoin-payments-for-edd' ) : __( 'No value saved.', 'finlinks-stablecoin-payments-for-edd' ) ) . '</p>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key . '_clear' ) . '" value="1"> ' . esc_html__( 'Remove saved value (disable the gateway first)', 'finlinks-stablecoin-payments-for-edd' ) . '</label></td></tr>';
		}
		if ( self::test_mode() ) {
			echo '<tr><th><label for="finlstpa_api_base">' . esc_html__( 'Test API base URL', 'finlinks-stablecoin-payments-for-edd' ) . '</label></th><td><input class="regular-text" type="url" id="finlstpa_api_base" name="api_base" value="' . esc_attr( self::get( 'api_base' ) ) . '"><p class="description">' . esc_html__( 'Do not use this field. Turn off EDD Test mode and save a live API key. Live payments call only https://api.finlinks.io.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></td></tr>';
		}
		echo '</tbody></table><p><label><input type="checkbox" name="debug" value="yes" ' . checked( self::get( 'debug' ), 'yes', false ) . '> ' . esc_html__( 'Enable redacted EDD diagnostic logging', 'finlinks-stablecoin-payments-for-edd' ) . '</label></p>';
		submit_button( __( 'Save FinLinks settings', 'finlinks-stablecoin-payments-for-edd' ) );
		echo '</form><h2>' . esc_html__( 'Connection tools', 'finlinks-stablecoin-payments-for-edd' ) . '</h2><p>' . esc_html__( 'Save credentials before running these checks. Registering the webhook replaces the webhook URL saved on this FinLinks connection.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		echo '<form class="finlstpa-tools" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="finlstpa_tools">';
		wp_nonce_field( self::NONCE_TOOLS, 'finlstpa_nonce' );
		foreach ( array(
			'test'     => __( 'Test Connection', 'finlinks-stablecoin-payments-for-edd' ),
			'register' => __( 'Register Webhook & Verify Store', 'finlinks-stablecoin-payments-for-edd' ),
			'ping'     => __( 'Test Webhook', 'finlinks-stablecoin-payments-for-edd' ),
		) as $value => $label ) {
			echo '<button class="button" type="submit" name="operation" value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '<p class="finlstpa-tool-result" role="status" aria-live="polite"></p></form></div>';
	}
}
