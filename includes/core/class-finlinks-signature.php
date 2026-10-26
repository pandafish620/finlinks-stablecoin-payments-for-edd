<?php
/**
 * Webhook signature verification (contract §7.3). Pure PHP, no WordPress.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Finlinks-Signature = hex HMAC-SHA256(secret, "{Finlinks-Timestamp}.{raw body}").
 * The key is the webhook secret string as shown, i.e. its UTF-8 bytes, never base64url-decoded (§16.3).
 */
final class Finlinks_Signature {

	const OK            = 'ok';
	const MISSING       = 'missing_headers';
	const BAD_TIMESTAMP = 'bad_timestamp';
	const STALE         = 'stale_timestamp';
	const BAD_SIGNATURE = 'bad_signature';

	/** Replay window in seconds. */
	const TOLERANCE = 300;

	/**
	 * Compute the signature.
	 *
	 * @param string     $secret    Webhook signing secret.
	 * @param string|int $timestamp Unix seconds.
	 * @param string     $raw_body  Raw request body.
	 * @return string Lowercase hex.
	 */
	public static function sign( $secret, $timestamp, $raw_body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, (string) $secret );
	}

	/**
	 * Verify the signature headers against the raw body.
	 *
	 * @param string      $secret    Webhook signing secret.
	 * @param string|null $timestamp Finlinks-Timestamp header.
	 * @param string|null $signature Finlinks-Signature header.
	 * @param string      $raw_body  Raw body exactly as received.
	 * @param int         $now       Current unix time.
	 * @param int         $tolerance Allowed clock difference in seconds.
	 * @return string One of the class constants.
	 */
	public static function verify( $secret, $timestamp, $signature, $raw_body, $now, $tolerance = self::TOLERANCE ) {
		if ( '' === (string) $secret || null === $timestamp || null === $signature || '' === $timestamp || '' === $signature ) {
			return self::MISSING;
		}
		$timestamp = trim( (string) $timestamp );
		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return self::BAD_TIMESTAMP;
		}
		$signature = strtolower( trim( (string) $signature ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return self::BAD_SIGNATURE;
		}
		// The HMAC is checked before the replay window, so STALE is only ever reported for a
		// genuinely signed request (§16.5: 401 only for a real signature mismatch).
		$expected = self::sign( $secret, $timestamp, (string) $raw_body );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::BAD_SIGNATURE;
		}
		if ( abs( (int) $now - (int) $timestamp ) > $tolerance ) {
			return self::STALE;
		}
		return self::OK;
	}
}
