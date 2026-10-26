<?php
/**
 * Session expiry vs stock hold (§16.7) and clock-skew math (§16.5). Pure PHP, no WordPress.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

final class Finlinks_Timing {

	const MAX_EXPIRY    = 3600;
	const MIN_EXPIRY    = 900;
	const HOLD_MARGIN   = 300;
	const SKEW_WARN_SEC = 120;

	/**
	 * expiresInSeconds to send: min(3600, hold minutes × 60 − 300), floor 900.
	 * Null (omit the field) when WooCommerce stock holding is off: stock management disabled
	 * or hold-stock minutes empty/0, the same conditions under which Woo never auto-cancels.
	 *
	 * @param string           $manage_stock woocommerce_manage_stock option ('yes'|'no').
	 * @param string|int|null  $hold_minutes woocommerce_hold_stock_minutes option.
	 * @return int|null
	 */
	public static function expires_in( $manage_stock, $hold_minutes ) {
		if ( 'yes' !== $manage_stock ) {
			return null;
		}
		$hold_minutes = is_string( $hold_minutes ) ? trim( $hold_minutes ) : $hold_minutes;
		if ( null === $hold_minutes || '' === $hold_minutes || ! is_numeric( $hold_minutes ) || (int) $hold_minutes < 1 ) {
			return null;
		}
		$seconds = min( self::MAX_EXPIRY, (int) $hold_minutes * 60 - self::HOLD_MARGIN );
		return max( self::MIN_EXPIRY, $seconds );
	}

	/**
	 * Local clock minus server clock, in seconds, from an HTTP Date header. Null if unparseable.
	 *
	 * @param string|null $date_header RFC 7231 Date header.
	 * @param int         $now         Local unix time.
	 * @return int|null
	 */
	public static function skew_from_date_header( $date_header, $now ) {
		if ( ! is_string( $date_header ) || '' === trim( $date_header ) ) {
			return null;
		}
		$server = strtotime( $date_header );
		if ( false === $server || $server <= 0 ) {
			return null;
		}
		return (int) $now - (int) $server;
	}

	/**
	 * Whether a skew should trigger the admin warning (more than 2 minutes either way).
	 *
	 * @param int|null $skew Seconds.
	 * @return bool
	 */
	public static function skew_is_excessive( $skew ) {
		return null !== $skew && abs( (int) $skew ) > self::SKEW_WARN_SEC;
	}
}
