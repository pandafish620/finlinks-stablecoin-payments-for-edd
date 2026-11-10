<?php
/**
 * Atomic, token-owned leases for checkout intents and order updates.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed leases. The option name is a hash, not the raw scope.
 */
final class Lock {

	/**
	 * Acquire a lease. Never overwrite a live owner.
	 *
	 * @param string $scope Lock scope.
	 * @return array|false Owned lease, or false when busy or unavailable.
	 */
	public static function acquire( $scope ) {
		$name = 'finlstpa_lock_' . hash( 'sha256', (string) $scope );
		try {
			$lease = array(
				'token'   => bin2hex( random_bytes( 16 ) ),
				'expires' => time() + 180,
			);
		} catch ( \Exception $error ) {
			return false;
		}
		if ( add_option( $name, $lease, '', false ) ) {
			return array( $name, $lease );
		}
		$old = get_option( $name );
		if ( ! is_array( $old ) || empty( $old['expires'] ) || (int) $old['expires'] >= time() ) {
			return false;
		}
		if ( self::remove( $name, $old ) && add_option( $name, $lease, '', false ) ) {
			return array( $name, $lease );
		}
		return false;
	}

	/**
	 * Release a lease this request owns.
	 *
	 * @param array $owned Owned lease.
	 * @return void
	 */
	public static function release( array $owned ) {
		self::remove( $owned[0], $owned[1] );
	}

	/**
	 * Compare-and-delete one exact lease value.
	 *
	 * @param string $name  Option name.
	 * @param array  $lease Expected value.
	 * @return bool
	 */
	private static function remove( $name, array $lease ) {
		global $wpdb;
		if ( ! is_string( $name ) || ! preg_match( '/^finlstpa_lock_[a-f0-9]{64}$/', $name ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete cannot use a cached read.
		$rows = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, maybe_serialize( $lease ) ) );
		if ( 1 === (int) $rows ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		return false;
	}
}
