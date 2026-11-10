<?php
/**
 * Redacted record of the last checkout that did not redirect.
 *
 * Connection tools can succeed while Pay with USDC still stops. This option
 * stores the checkout stage only. It never stores an API key, webhook secret,
 * buyer email, payment key, or URL query string.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout failure stage for shop managers.
 */
final class Checkout_Diag {

	const OPTION = 'finlstpa_checkout_diag';

	/**
	 * Remember one failure. Later failures replace it.
	 *
	 * @param string $stage  Stage token.
	 * @param string $detail Safe detail token.
	 * @param int    $http   HTTP status, or 0 when there was no response.
	 * @return void
	 */
	public static function record( $stage, $detail = '', $http = 0 ) {
		$stage = sanitize_key( (string) $stage );
		if ( '' === $stage || strlen( $stage ) > 40 ) {
			$stage = 'unknown';
		}
		$http = (int) $http;
		if ( 0 > $http || 599 < $http ) {
			$http = 0;
		}
		update_option(
			self::OPTION,
			array(
				'stage'  => $stage,
				'detail' => self::detail( $detail ),
				'http'   => $http,
				'at'     => time(),
			),
			false
		);
	}

	/**
	 * Keep a short token. Anything else is reduced to letters, digits, and underscores.
	 *
	 * @param string $value Raw detail.
	 * @return string
	 */
	public static function detail( $value ) {
		$value = (string) $value;
		if ( 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $value ) ) {
			return $value;
		}
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
		$value = trim( (string) $value, '_' );
		if ( strlen( $value ) > 64 ) {
			$value = substr( $value, 0, 64 );
		}
		return $value;
	}

	/**
	 * Settings-screen note. Safe to print.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<h2>' . esc_html__( 'Checkout diagnostics', 'finlinks-stablecoin-payments-for-edd' ) . '</h2>';
		echo '<p>' . esc_html__( 'Test Connection, webhook registration, and Test Webhook do not create a payment session. The line below is the latest Pay with USDC attempt that did not redirect. It does not include the API key, webhook secret, buyer email, or payment key.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		$row = get_option( self::OPTION );
		if ( ! is_array( $row ) || empty( $row['stage'] ) || ! is_string( $row['stage'] ) || ! isset( $row['at'] ) || ! is_numeric( $row['at'] ) || (int) $row['at'] < time() - 7 * DAY_IN_SECONDS ) {
			echo '<p>' . esc_html__( 'No USDC checkout failure has been recorded in the last seven days.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
		} else {
			$stage  = self::detail( $row['stage'] );
			$detail = isset( $row['detail'] ) ? self::detail( $row['detail'] ) : '';
			$http   = isset( $row['http'] ) ? (int) $row['http'] : 0;
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: failure stage, 2: safe detail token, 3: HTTP status or 0. */
					__( 'Last checkout failure: stage %1$s, detail %2$s, HTTP %3$d.', 'finlinks-stablecoin-payments-for-edd' ),
					$stage,
					'' === $detail ? 'none' : $detail,
					$http
				)
			) . '</strong></p>';
		}
		echo '<p><code>' . esc_html( implode( ' ', self::preflight() ) ) . '</code></p>';
	}

	/**
	 * Current checkout preflight. No network call except DNS for the pinned hosts.
	 *
	 * @return string[]
	 */
	public static function preflight() {
		$key    = Settings::get( 'api_key' );
		$secret = Settings::get( 'webhook_secret' );
		$class  = 'empty';
		if ( is_string( $key ) && '' !== $key ) {
			if ( 0 === strpos( $key, 'flk_live_' ) ) {
				$class = 'live';
			} elseif ( 0 === strpos( $key, 'flk_test_' ) ) {
				$class = 'test';
			} else {
				$class = 'other';
			}
		}
		$pause      = get_option( Settings::PAUSE_OPTION );
		$pause_code = 'none';
		if ( is_array( $pause ) && ! empty( $pause['until'] ) && (int) $pause['until'] > time() ) {
			$pause_code = isset( $pause['code'] ) ? self::detail( $pause['code'] ) : 'set';
			if ( '' === $pause_code ) {
				$pause_code = 'set';
			}
		}
		$migration = get_option( Migrate::ERROR_OPTION, '' );
		$migration = is_string( $migration ) && '' !== $migration ? self::detail( $migration ) : 'none';
		$checkout  = function_exists( 'edd_get_checkout_uri' ) ? edd_get_checkout_uri() : '';
		$confirm   = function_exists( 'edd_get_confirmation_page_uri' ) ? edd_get_confirmation_page_uri() : '';
		$pairs     = array(
			'ready=' . ( Settings::checkout_ready() ? 'yes' : 'no' ),
			'mode=' . ( Settings::test_mode() ? 'test' : 'live' ),
			'key=' . $class,
			'secret=' . ( is_string( $secret ) && '' !== $secret ? 'saved' : 'missing' ),
			'database=' . ( '1' === (string) get_option( Bootstrap::DB_OPTION ) ? 'ready' : 'missing' ),
			'pause=' . $pause_code,
			'migration=' . $migration,
			'webhook=' . self::url_token( Settings::webhook_url(), false ),
			'checkout=' . self::url_token( $checkout, true ),
			'confirmation=' . self::url_token( $confirm, true ),
			'api_dns=' . Host_Policy::resolution_state( 'api.finlinks.io' ),
			'pay_dns=' . Host_Policy::resolution_state( 'pay.finlinks.io' ),
			'portal_dns=' . Host_Policy::resolution_state( 'portal.finlinks.io' ),
		);
		return $pairs;
	}

	/**
	 * url_ok token for preflight.
	 *
	 * @param mixed $url   URL.
	 * @param bool  $query Whether a query string is allowed.
	 * @return string
	 */
	private static function url_token( $url, $query ) {
		if ( ! is_string( $url ) ) {
			return 'empty';
		}
		$problem = Settings::url_problem( $url, $query );
		return '' === $problem ? 'ok' : $problem;
	}
}
