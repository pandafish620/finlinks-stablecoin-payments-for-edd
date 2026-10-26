<?php
/**
 * Masked credential settings and nonce-protected connection tools.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'finlinks_edd_settings';
	const NONCE = 'finlinks_edd_admin';
	const CLOCK_OPTION = 'finlinks_edd_clock';
	const STALE_OPTION = 'finlinks_edd_stale_webhook';
	const PAUSE_OPTION = 'finlinks_edd_pause';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_finlinks_edd_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_finlinks_edd_tools', array( __CLASS__, 'post_tools' ) );
		add_action( 'wp_ajax_finlinks_edd_tools', array( __CLASS__, 'ajax_tools' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
	}

	public static function get( $key, $default = '' ) {
		$options = get_option( self::OPTION, array() );
		return is_array( $options ) && isset( $options[ $key ] ) && is_string( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	public static function test_mode() {
		return function_exists( 'edd_is_test_mode' ) && edd_is_test_mode();
	}

	public static function api() {
		return new Finlinks_Api_Client( self::base(), self::get( 'api_key' ) );
	}

	public static function base() {
		return Finlinks_Api_Base::resolve( self::test_mode(), self::test_mode() ? self::get( 'api_base' ) : '' );
	}

	public static function configured() {
		return '' !== self::base() && Finlinks_Credentials::gateway_ready( self::test_mode(), self::get( 'api_key' ), self::get( 'webhook_secret' ) );
	}

	public static function checkout_ready() {
		$pause = get_option( self::PAUSE_OPTION );
		return 'yes' === self::get( 'enabled' ) && '1' === get_option( Bootstrap::DB_OPTION ) && self::configured()
			&& ( ! is_array( $pause ) || empty( $pause['until'] ) || $pause['until'] <= time() );
	}

	public static function url() {
		return admin_url( 'edit.php?post_type=download&page=finlinks-edd-settings' );
	}

	public static function webhook_url() {
		return rest_url( Bootstrap::REST_NAMESPACE . '/webhook' );
	}

	public static function url_ok( $url, $query = true ) {
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return false;
		}
		$p = wp_parse_url( $url );
		return is_array( $p ) && isset( $p['scheme'], $p['host'] ) && 'https' === strtolower( $p['scheme'] )
			&& strtolower( $p['host'] ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) )
			&& ! isset( $p['user'] ) && ! isset( $p['pass'] )
			&& ! isset( $p['port'] ) && ! isset( $p['fragment'] ) && ( $query || ! isset( $p['query'] ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=download', __( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ), __( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ), 'manage_shop_settings', 'finlinks-edd-settings', array( __CLASS__, 'render' ) );
	}

	public static function scripts( $hook ) {
		if ( 'download_page_finlinks-edd-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'finlinks-edd-admin', FINLINKS_EDD_URL . 'assets/js/admin.js', array(), FINLINKS_EDD_VERSION, true );
		wp_localize_script( 'finlinks-edd-admin', 'FinLinksEDD', array(
			'url' => admin_url( 'admin-ajax.php' ),
			'working' => __( 'Running connection check…', 'finlinks-stablecoin-payments-for-edd' ),
			'unconfirmed' => __( 'The result could not be confirmed. Reload this page before retrying.', 'finlinks-stablecoin-payments-for-edd' ),
		) );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_shop_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage payment settings.', 'finlinks-stablecoin-payments-for-edd' ), '', array( 'response' => 403 ) );
		}
	}

	private static function post( $key ) {
		check_admin_referer( self::NONCE, 'nonce' );
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	public static function save() {
		self::authorize();
		check_admin_referer( self::NONCE, 'nonce' );
		$next = array(
			'enabled' => 'yes' === self::post( 'enabled' ) ? 'yes' : 'no',
			'debug' => 'yes' === self::post( 'debug' ) ? 'yes' : 'no',
			'api_base' => self::test_mode() ? esc_url_raw( self::post( 'api_base' ) ) : '',
		);
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			$value = self::post( $key );
			$next[ $key ] = '1' === self::post( $key . '_clear' ) ? '' : ( '' === $value ? self::get( $key ) : $value );
			if ( ( 'yes' === $next['enabled'] || '' !== $next[ $key ] ) && '' !== Finlinks_Credentials::save_error( self::test_mode(), $key, $next[ $key ] ) ) {
				self::notice_redirect( false, __( 'Credentials were not saved. Check the key prefix, secret format and EDD test/live mode.', 'finlinks-stablecoin-payments-for-edd' ) );
			}
		}
		if ( self::test_mode() && '' !== $next['api_base'] && '' === Finlinks_Api_Base::resolve( true, $next['api_base'] ) ) {
			self::notice_redirect( false, __( 'Use an HTTPS non-production API base for EDD test mode.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		update_option( self::OPTION, $next, false );
		delete_option( self::PAUSE_OPTION );
		self::notice_redirect( true, __( 'Settings saved. Register and verify the webhook before enabling this gateway in EDD.', 'finlinks-stablecoin-payments-for-edd' ) );
	}

	private static function notice_redirect( $ok, $message ) {
		set_transient( 'finlinks_edd_notice_' . get_current_user_id(), array( $ok, $message ), 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function post_tools() {
		self::authorize();
		check_admin_referer( self::NONCE, 'nonce' );
		list( $ok, $message ) = self::run( self::post( 'operation' ) );
		self::notice_redirect( $ok, $message );
	}

	public static function ajax_tools() {
		self::authorize();
		check_ajax_referer( self::NONCE, 'nonce' );
		list( $ok, $message ) = self::run( self::post( 'operation' ) );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}

	public static function run( $operation ) {
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
		} elseif ( 'ping' === $operation ) {
			$result = $api->test_webhook();
		} else {
			return array( false, __( 'Unknown connection action.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		if ( ! $result['ok'] ) {
			return array( false, Finlinks_Messages::admin_failure( $result['code'], $result['data'] ) );
		}
		if ( 'ping' === $operation ) {
			return array( ! empty( $result['data']['delivered'] ), ! empty( $result['data']['delivered'] )
				? __( 'The test webhook was delivered.', 'finlinks-stablecoin-payments-for-edd' )
				: __( 'Webhook delivery was not confirmed. Check the store firewall and webhook URL.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		$data   = isset( $result['data']['connection'] ) && is_array( $result['data']['connection'] ) ? $result['data']['connection'] : $result['data'];
		$status = isset( $data['status'] ) && is_string( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'unknown';
		if ( 'ACTIVE' === strtoupper( $status ) ) {
			delete_option( self::PAUSE_OPTION );
		}
		/* translators: %s: connection status reported by FinLinks. */
		return array( 'register' !== $operation || 'ACTIVE' === strtoupper( $status ), sprintf( __( 'Connection status: %s.', 'finlinks-stablecoin-payments-for-edd' ), $status ) );
	}

	public static function render() {
		self::authorize();
		echo '<div class="wrap"><h1>' . esc_html__( 'FinLinks Stablecoin Payments for EDD', 'finlinks-stablecoin-payments-for-edd' ) . '</h1>';
		$notice = get_transient( 'finlinks_edd_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'finlinks_edd_notice_' . get_current_user_id() );
			echo '<div class="notice ' . esc_attr( $notice[0] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Configure a dedicated FinLinks connection for this EDD store. Enable the gateway separately in Downloads → Settings → Payments. EDD test mode determines the key and API environment.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		echo '<p>' . esc_html__( 'Webhook URL:', 'finlinks-stablecoin-payments-for-edd' ) . ' <code>' . esc_html( self::webhook_url() ) . '</code></p>';
		if ( '1' !== get_option( Bootstrap::DB_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The webhook event table is unavailable. Payments stay disabled until database setup succeeds.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		}
		foreach ( array( self::CLOCK_OPTION, self::STALE_OPTION ) as $option ) {
			$clock = get_option( $option );
			if ( is_array( $clock ) && isset( $clock['skew'], $clock['at'] ) && $clock['at'] > time() - DAY_IN_SECONDS && Finlinks_Timing::skew_is_excessive( $clock['skew'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The server clock differs from the payment service. Correct NTP/time settings to avoid webhook delays.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
				break;
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="finlinks_edd_save">';
		wp_nonce_field( self::NONCE, 'nonce' );
		echo '<p><label><input type="checkbox" name="enabled" value="yes" ' . checked( self::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Allow USDC payments when enabled in EDD', 'finlinks-stablecoin-payments-for-edd' ) . '</label></p>';
		echo '<p><strong>' . esc_html__( 'Mode:', 'finlinks-stablecoin-payments-for-edd' ) . '</strong> ' . esc_html( self::test_mode() ? __( 'EDD test mode', 'finlinks-stablecoin-payments-for-edd' ) : __( 'EDD live mode', 'finlinks-stablecoin-payments-for-edd' ) ) . '</p><table class="form-table"><tbody>';
		foreach ( array( 'api_key' => __( 'API key', 'finlinks-stablecoin-payments-for-edd' ), 'webhook_secret' => __( 'Webhook signing secret', 'finlinks-stablecoin-payments-for-edd' ) ) as $key => $label ) {
			echo '<tr><th><label for="' . esc_attr( 'finlinks_edd_' . $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="password" autocomplete="new-password" value="" id="' . esc_attr( 'finlinks_edd_' . $key ) . '" name="' . esc_attr( $key ) . '">';
			echo '<p class="description">' . esc_html( '' !== self::get( $key ) ? __( 'Saved. Leave blank to retain the current value.', 'finlinks-stablecoin-payments-for-edd' ) : __( 'No value saved.', 'finlinks-stablecoin-payments-for-edd' ) ) . '</p>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key . '_clear' ) . '" value="1"> ' . esc_html__( 'Remove saved value (disable the gateway first)', 'finlinks-stablecoin-payments-for-edd' ) . '</label></td></tr>';
		}
		if ( self::test_mode() ) {
			echo '<tr><th><label for="finlinks_edd_api_base">' . esc_html__( 'Test API base URL', 'finlinks-stablecoin-payments-for-edd' ) . '</label></th><td><input class="regular-text" type="url" id="finlinks_edd_api_base" name="api_base" value="' . esc_attr( self::get( 'api_base' ) ) . '"><p class="description">' . esc_html__( 'Required for test mode. Use an HTTPS staging API, never the production API.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></td></tr>';
		}
		echo '</tbody></table><p><label><input type="checkbox" name="debug" value="yes" ' . checked( self::get( 'debug' ), 'yes', false ) . '> ' . esc_html__( 'Enable redacted EDD diagnostic logging', 'finlinks-stablecoin-payments-for-edd' ) . '</label></p>';
		submit_button( __( 'Save FinLinks settings', 'finlinks-stablecoin-payments-for-edd' ) );
		echo '</form><h2>' . esc_html__( 'Connection tools', 'finlinks-stablecoin-payments-for-edd' ) . '</h2><p>' . esc_html__( 'Save credentials before running these checks. Registration replaces the webhook URL on this FinLinks connection; do not share a WooCommerce connection.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		echo '<form class="finlinks-edd-tools" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="finlinks_edd_tools">';
		wp_nonce_field( self::NONCE, 'nonce' );
		foreach ( array( 'test' => __( 'Test Connection', 'finlinks-stablecoin-payments-for-edd' ), 'register' => __( 'Register Webhook & Verify Store', 'finlinks-stablecoin-payments-for-edd' ), 'ping' => __( 'Test Webhook', 'finlinks-stablecoin-payments-for-edd' ) ) as $value => $label ) {
			echo '<button class="button" type="submit" name="operation" value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '<p class="finlinks-edd-tool-result" role="status" aria-live="polite"></p></form></div>';
	}
}

