<?php
/**
 * Resolves the FinLinks API base URL. Pure PHP, no WordPress.
 *
 * Live mode never consults an override. The only URL it can return is the
 * production host. Any other host, including local hosts, is refused.
 * Test mode never calls the production host. It uses only an explicit https
 * override, so this build does not embed another environment's API host.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * API base resolution.
 */
final class Finlinks_Api_Base {

	const PROD = 'https://api.finlinks.io';

	const PROD_HOST = 'api.finlinks.io';

	/**
	 * Base URL for this mode, without a trailing slash.
	 *
	 * In live mode $override is ignored. The result is PROD, or '' when PROD
	 * itself would be a forbidden host (the gateway must then stay unavailable).
	 * In test mode an empty override yields ''. A non-empty override is used
	 * only when it is an https URL without userinfo or a query string, and its
	 * host is not the production API host.
	 *
	 * @param bool   $test_mode Whether test mode is on.
	 * @param string $override  Advanced base URL. Not read by callers when test mode is off.
	 * @return string
	 */
	public static function resolve( $test_mode, $override = '' ) {
		if ( ! $test_mode ) {
			return self::accept_live( self::PROD );
		}
		$override = trim( (string) $override );
		if ( '' === $override || ! self::is_https_base( $override ) || self::is_production_host( $override ) ) {
			return '';
		}
		return rtrim( $override, '/' );
	}

	/**
	 * Live-mode gate. Returns PROD only for the exact production URL.
	 *
	 * @param string $url Candidate base URL.
	 * @return string PROD, or '' when the candidate must not be called in live mode.
	 */
	public static function accept_live( $url ) {
		if ( ! is_string( $url ) || ! self::is_exact_prod( $url ) || self::is_forbidden_live_host( $url ) ) {
			return '';
		}
		return self::PROD;
	}

	/**
	 * True only for https://api.finlinks.io with no user, port, query, fragment or extra path.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_exact_prod( $url ) {
		$p = self::parts( $url );
		if ( null === $p ) {
			return false;
		}
		if ( 'https' !== $p['scheme'] || self::PROD_HOST !== $p['host'] ) {
			return false;
		}
		if ( $p['user'] || $p['pass'] || $p['port'] || $p['query'] || $p['fragment'] ) {
			return false;
		}
		return '' === $p['path'] || '/' === $p['path'];
	}

	/**
	 * True when the URL's host is the production API host, whatever the path is.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_production_host( $url ) {
		$p = self::parts( $url );
		return null !== $p && self::PROD_HOST === $p['host'];
	}

	/**
	 * Local hosts, which live mode must never call.
	 *
	 * A non-production hostname is already refused by is_exact_prod(). This check
	 * stays so a mistaken PROD constant that points at the local machine cannot
	 * be used for live payments.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_forbidden_live_host( $url ) {
		$p = self::parts( $url );
		if ( null === $p ) {
			return true;
		}
		$host = $p['host'];
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return true;
		}
		if ( strlen( $host ) >= 10 && '.localhost' === substr( $host, -10 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * https URL with a host, and no userinfo or query. Used for the test-mode override only.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_https_base( $url ) {
		$p = self::parts( $url );
		if ( null === $p ) {
			return false;
		}
		if ( 'https' !== $p['scheme'] || '' === $p['host'] ) {
			return false;
		}
		return ! $p['user'] && ! $p['pass'] && ! $p['query'];
	}

	/**
	 * Parsed URL pieces, or null.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private static function parts( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return null;
		}
		$host = strtolower( (string) $p['host'] );
		if ( strlen( $host ) > 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		return array(
			'scheme'   => strtolower( (string) $p['scheme'] ),
			'host'     => $host,
			'user'     => isset( $p['user'] ),
			'pass'     => isset( $p['pass'] ),
			'port'     => isset( $p['port'] ),
			'query'    => isset( $p['query'] ),
			'fragment' => isset( $p['fragment'] ),
			'path'     => isset( $p['path'] ) ? (string) $p['path'] : '',
		);
	}
}
