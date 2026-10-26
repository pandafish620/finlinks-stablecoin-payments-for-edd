<?php
/**
 * API key and webhook secret rules. Pure PHP, no WordPress.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Save-time and runtime checks for the gateway credentials.
 */
final class Finlinks_Credentials {

	/**
	 * Why this value must not be saved, or '' when it may be saved.
	 *
	 * Live mode requires an flk_live_ key and a non-empty webhook secret.
	 * An flk_test_ key, any other non-flk_live_ value, and an empty secret are rejected.
	 * Test mode still allows an empty key or secret (the gateway stays unavailable until both are set).
	 *
	 * @param bool   $test_mode Whether test mode is on.
	 * @param string $field     api_key or webhook_secret.
	 * @param string $value     Value that would be stored, after "keep existing" / clear.
	 * @return string live_key, live_secret, key_format, secret_format, or ''.
	 */
	public static function save_error( $test_mode, $field, $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( 'api_key' === $field ) {
			if ( ! $test_mode ) {
				return self::is_live_key( $value ) ? '' : 'live_key';
			}
			if ( '' === $value ) {
				return '';
			}
			return self::is_key_shape( $value ) ? '' : 'key_format';
		}
		if ( 'webhook_secret' === $field ) {
			if ( ! $test_mode && '' === $value ) {
				return 'live_secret';
			}
			if ( '' === $value ) {
				return '';
			}
			return self::is_secret( $value ) ? '' : 'secret_format';
		}
		return '';
	}

	/**
	 * Whether checkout and API calls are allowed for these credentials.
	 *
	 * @param bool   $test_mode Whether test mode is on.
	 * @param string $api_key   API key.
	 * @param string $secret    Webhook signing secret.
	 * @return bool
	 */
	public static function gateway_ready( $test_mode, $api_key, $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}
		if ( $test_mode ) {
			return is_string( $api_key ) && 0 === strpos( $api_key, 'flk_test_' );
		}
		return self::is_live_key( $api_key ) && self::is_secret( $secret );
	}

	/**
	 * flk_live_ followed by 20 to 128 token characters.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_live_key( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^flk_live_[A-Za-z0-9_-]{20,128}$/', $value );
	}

	/**
	 * Either prefix, with the same length rule. Test-mode save check.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_key_shape( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^flk_(test|live)_[A-Za-z0-9_-]{20,128}$/', $value );
	}

	/**
	 * Webhook signing secret shape.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_secret( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_\-+\/=]{16,256}$/', $value );
	}
}
