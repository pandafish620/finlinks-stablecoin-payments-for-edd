<?php
/**
 * Amount and currency rules (contract §5.2, §15 decision 4). Pure PHP, no WordPress.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Decimal-string helpers. Never uses floats for comparisons.
 */
final class Finlinks_Amount {

	/** Minimum per session, in USD cents (1.00 USD). */
	const MIN_MINOR = 100;

	/** Maximum per session, in USD cents (10,000.00 USD). */
	const MAX_MINOR = 1000000;

	/** Store currencies this gateway is offered for. Non-USD stores are out of scope (§15). */
	const SUPPORTED_CURRENCIES = array( 'USD' );

	/**
	 * Format a WooCommerce total as a 2-decimal string ("50.00").
	 *
	 * @param string|float|int $total Order or cart total.
	 * @return string
	 */
	public static function format_usd( $total ) {
		if ( is_string( $total ) && preg_match( '/^\d+(\.\d{1,2})?$/', $total ) ) {
			$parts = explode( '.', $total . '.' );
			return $parts[0] . '.' . str_pad( $parts[1], 2, '0' );
		}
		return number_format( round( (float) $total, 2 ), 2, '.', '' );
	}

	/**
	 * Convert a decimal string to integer minor units. Returns null on bad input
	 * or when the value has more decimals than allowed (non-zero beyond $decimals).
	 *
	 * @param string $value    Decimal string.
	 * @param int    $decimals Minor-unit decimals.
	 * @return int|null
	 */
	public static function to_minor( $value, $decimals = 2 ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{1,12})(?:\.(\d+))?$/', $value, $m ) ) {
			return null;
		}
		$frac = isset( $m[2] ) ? $m[2] : '';
		if ( strlen( $frac ) > $decimals ) {
			if ( '' !== trim( substr( $frac, $decimals ), '0' ) ) {
				return null;
			}
			$frac = substr( $frac, 0, $decimals );
		}
		$frac = str_pad( $frac, $decimals, '0' );
		return (int) ( $m[1] . $frac );
	}

	/**
	 * Canonical form of a decimal string: "050.100" -> "50.1", "50.00" -> "50".
	 *
	 * @param string $value Decimal string.
	 * @return string|null
	 */
	public static function normalize( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $value, $m ) ) {
			return null;
		}
		$int  = ltrim( $m[1], '0' );
		$int  = '' === $int ? '0' : $int;
		$frac = isset( $m[2] ) ? rtrim( $m[2], '0' ) : '';
		return '' === $frac ? $int : $int . '.' . $frac;
	}

	/**
	 * Decimal-string equality ("50" == "50.00").
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return bool
	 */
	public static function equals( $a, $b ) {
		$na = self::normalize( $a );
		return null !== $na && self::normalize( $b ) === $na;
	}

	/**
	 * Whether the currency is supported.
	 *
	 * @param string $currency ISO code.
	 * @return bool
	 */
	public static function is_supported_currency( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::SUPPORTED_CURRENCIES, true );
	}

	/**
	 * Whether the gateway may be offered for this currency and total.
	 *
	 * @param string           $currency ISO code.
	 * @param string|float|int $total    Total.
	 * @return bool
	 */
	public static function is_eligible( $currency, $total ) {
		if ( ! self::is_supported_currency( $currency ) ) {
			return false;
		}
		$minor = self::to_minor( self::format_usd( $total ), 2 );
		return null !== $minor && $minor >= self::MIN_MINOR && $minor <= self::MAX_MINOR;
	}
}
