<?php
/**
 * Checks the hosted payment URL returned by the FinLinks API.
 *
 * The service builds hostedUrl as {portal origin}/inv/{sessionId}.
 * Live mode accepts https://portal.finlinks.io and https://pay.finlinks.io,
 * and only a path of /inv/ plus a UUID. Test mode does not pin a second portal
 * host. It accepts any other https host with an /inv/{id} path, so a developer
 * API can return its own page.
 * Host match in live mode is exact: no subdomain wildcard.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Hosted payment URL allow-list. Pure PHP.
 */
final class Finlinks_Hosted_Url {

	const PROD_HOST = 'portal.finlinks.io';
	const PAY_HOST  = 'pay.finlinks.io';

	/**
	 * Whether the buyer may be redirected to this URL in the active mode.
	 *
	 * @param string $url       hostedUrl from the create-session response.
	 * @param bool   $test_mode Whether Test mode is on.
	 * @return bool
	 */
	public static function is_allowed( $url, $test_mode ) {
		return null === self::rejection( $url, $test_mode );
	}

	/**
	 * Why a hosted URL was refused, plus the host only.
	 *
	 * Null when the URL may be used. The host never includes a user, path, or query.
	 *
	 * @param string $url       hostedUrl.
	 * @param bool   $test_mode Whether Test mode is on.
	 * @return array{reason:string,host:string}|null
	 */
	public static function rejection( $url, $test_mode ) {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 || false !== strpos( $url, '\\' ) || preg_match( "/[\x00-\x1F\x7F]/", $url ) ) {
			return array(
				'reason' => 'unparseable url',
				'host'   => '(none)',
			);
		}
		$p    = wp_parse_url( $url );
		$host = self::host_for_log( is_array( $p ) ? $p : array() );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return array(
				'reason' => 'unparseable url',
				'host'   => $host,
			);
		}
		if ( 'https' !== strtolower( (string) $p['scheme'] ) ) {
			return array(
				'reason' => 'not https',
				'host'   => $host,
			);
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) {
			return array(
				'reason' => 'userinfo, port, or fragment',
				'host'   => $host,
			);
		}
		$live_hosts = array( self::PROD_HOST, self::PAY_HOST );
		$is_live    = in_array( $host, $live_hosts, true );
		if ( $test_mode ) {
			// Test mode must not send the buyer to the live payment page.
			if ( $is_live ) {
				return array(
					'reason' => 'unexpected host',
					'host'   => $host,
				);
			}
		} elseif ( ! $is_live ) {
			return array(
				'reason' => 'unexpected host',
				'host'   => $host,
			);
		}
		$path = isset( $p['path'] ) ? (string) $p['path'] : '';
		if ( 1 === preg_match( '#^/inv/$#', $path ) || 1 === preg_match( '#^/inv//#', $path ) ) {
			return array(
				'reason' => 'empty session id',
				'host'   => $host,
			);
		}
		if ( ! $test_mode ) {
			if ( 1 !== preg_match( '#^/inv/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$#', $path ) ) {
				return array(
					'reason' => 'path is not /inv/{uuid}',
					'host'   => $host,
				);
			}
			return null;
		}
		if ( 1 !== preg_match( '#^/inv/[^/]+#', $path ) ) {
			return array(
				'reason' => 'path is not /inv/{id}',
				'host'   => $host,
			);
		}
		return null;
	}

	/**
	 * Hostname safe to write to the log, or (none)/(invalid).
	 *
	 * @param array $parts parse_url result.
	 * @return string
	 */
	private static function host_for_log( array $parts ) {
		if ( empty( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
			return '(none)';
		}
		$host = strtolower( $parts['host'] );
		if ( strlen( $host ) > 253 || 1 !== preg_match( '/^[a-z0-9.:_-]+$/', $host ) ) {
			return '(invalid)';
		}
		return $host;
	}
}
