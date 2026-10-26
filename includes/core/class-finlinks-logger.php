<?php
/**
 * Redacting logger. Never writes API keys, secrets, signatures or order keys (§8.4).
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

final class Finlinks_Logger {

	/**
	 * New API key and webhook secret held for this request. Not saved yet.
	 *
	 * @var string[]
	 */
	private static $held = array();

	/**
	 * Remember secrets to mask until the next call. Pass an empty array to clear.
	 *
	 * @param string[] $values Secrets.
	 */
	public static function hold( array $values ) {
		self::$held = $values;
	}

	/**
	 * Strip secrets from a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( $message ) {
		return self::scrub( $message, Settings::get( 'webhook_secret' ) );
	}

	/**
	 * Pure redaction (unit tested): API keys, Bearer tokens, URL query strings, WooCommerce order
	 * keys, Finlinks-Signature values, the saved webhook secret, and any secrets held for this request.
	 *
	 * @param string $message Message.
	 * @param string $secret  Webhook signing secret ('' if none).
	 * @return string
	 */
	public static function scrub( $message, $secret = '' ) {
		$message = (string) $message;
		// Longer values first, so one secret cannot split another.
		foreach ( self::secret_values( $secret ) as $value ) {
			$message = str_replace( $value, '[redacted]', $message );
		}
		$message = preg_replace( '/flk_(test|live)_[A-Za-z0-9_-]+/', 'flk_$1_[redacted]', $message );
		$message = preg_replace( '/(Bearer\s+)\S+/i', '$1[redacted]', $message );
		// Query strings of URLs (Woo return URLs carry the order key).
		$message = preg_replace( '#(https?://[^\s?"\']+)\?[^\s"\']*#', '$1?[redacted]', $message );
		// Order keys outside a URL, and signature header values.
		$message = preg_replace( '/wc_order_[A-Za-z0-9]+/', 'wc_order_[redacted]', $message );
		$message = preg_replace( '/(Finlinks-Signature[\s:=]+)[0-9a-fA-F]{16,}/i', '$1[redacted]', $message );
		return $message;
	}

	/**
	 * Saved secret plus values held in memory. Shorter than 8 characters are ignored.
	 *
	 * @param string $secret Saved webhook secret.
	 * @return string[]
	 */
	private static function secret_values( $secret ) {
		$values = array( (string) $secret );
		foreach ( self::$held as $value ) {
			if ( is_string( $value ) ) {
				$values[] = $value;
			}
		}
		$out = array();
		foreach ( $values as $value ) {
			if ( strlen( $value ) >= 8 && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $out;
	}

	/**
	 * Log when debug logging is enabled (errors are always logged).
	 *
	 * @param string $message Message.
	 * @param string $level   PSR level.
	 */
	public static function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'edd_debug_log' ) || 'yes' !== Settings::get( 'debug' ) ) {
			return;
		}
		edd_debug_log( '[FinLinks EDD][' . sanitize_key( $level ) . '] ' . self::redact( $message ), true );
	}
}
