<?php
/**
 * Host checks for outbound API calls and buyer redirects.
 *
 * Live payments never use this list to widen the production hosts. It only
 * rejects local, private, and reserved hosts so a test-mode URL cannot point
 * at the server itself or a link-local address.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Host policy. Name checks are not enough: callers must use destination_blocked(),
 * which resolves A and AAAA answers before an outbound request.
 */
final class Host_Policy {

	/**
	 * Whether this host must not be contacted or used as a redirect target.
	 *
	 * @param string $host Hostname or IP.
	 * @return bool
	 */
	public static function is_blocked( $host ) {
		if ( ! is_string( $host ) || '' === $host || strlen( $host ) > 253 ) {
			return true;
		}
		$host = strtolower( $host );
		if ( strlen( $host ) >= 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		if ( false !== strpos( $host, '%' ) || false !== strpos( $host, ' ' ) ) {
			return true;
		}
		$reserved = array(
			'localhost',
			'localhost.localdomain',
			'metadata.google.internal',
			'metadata.google.com',
		);
		if ( in_array( $host, $reserved, true ) ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.internal', '.localdomain' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $host ) > $len && substr( $host, -$len ) === $suffix ) {
				return true;
			}
		}
		if ( ctype_digit( $host ) ) {
			return true;
		}
		if ( self::is_ip( $host ) ) {
			return self::is_blocked_ip( $host );
		}
		return false;
	}

	/**
	 * Whether this host must not be contacted, after name and address checks.
	 *
	 * An unresolved hostname fails closed. A name is also blocked when any
	 * resolved address is local, private, or reserved.
	 *
	 * @param string $host Hostname or IP.
	 * @return bool
	 */
	public static function destination_blocked( $host ) {
		if ( self::is_blocked( $host ) ) {
			return true;
		}
		$addresses = self::addresses_for( $host );
		if ( ! $addresses ) {
			return true;
		}
		foreach ( $addresses as $address ) {
			if ( ! is_string( $address ) || self::is_blocked( $address ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Addresses for a host. The finlstpa_host_addresses filter may return a list
	 * and then no DNS lookup is performed. Production returns null from that filter.
	 *
	 * @param string $host Hostname or IP.
	 * @return string[]
	 */
	public static function addresses_for( $host ) {
		if ( ! is_string( $host ) || '' === $host ) {
			return array();
		}
		$host = strtolower( $host );
		if ( strlen( $host ) >= 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		$filtered = apply_filters( 'finlstpa_host_addresses', null, $host );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}
		if ( self::is_ip( $host ) ) {
			return array( $host );
		}
		$found = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$types   = DNS_A;
			$types   = defined( 'DNS_AAAA' ) ? $types + DNS_AAAA : $types;
			$records = @dns_get_record( $host, $types ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is handled as fail closed.
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! is_array( $record ) ) {
						continue;
					}
					if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
						$found[] = $record['ip'];
					}
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$found[] = $record['ipv6'];
					}
				}
			}
		}
		if ( ! $found ) {
			$list = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is handled as fail closed.
			if ( is_array( $list ) ) {
				$found = $list;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether a string is an IP literal.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_ip( $host ) {
		return false !== filter_var( $host, FILTER_VALIDATE_IP );
	}

	/**
	 * Whether an IP literal is loopback, private, link-local, or otherwise reserved.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_blocked_ip( $ip ) {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
