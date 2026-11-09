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
 * Pure host policy. Domain names are not resolved here.
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
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}
		return false;
	}
}
