<?php
/**
 * Atomic, token-owned leases for checkout intents and order updates.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Lock {
	/**
	 * Acquire a lease. Never overwrite a live owner.
	 *
	 * @param string $scope Lock scope.
	 * @return array|false Owned lease, or false when busy/unavailable.
	 */
	public static function acquire( $scope ) {
		$name  = 'finlinks_edd_lock_' . hash( 'sha256', $scope );
		$lease = array( 'token' => bin2hex( random_bytes( 16 ) ), 'expires' => time() + 180 );
		if ( add_option( $name, $lease, '', false ) ) {
			return array( $name, $lease );
		}
		$old = get_option( $name );
		if ( ! is_array( $old ) || empty( $old['expires'] ) || (int) $old['expires'] >= time() ) {
			return false;
		}
		// Compare-and-delete prevents one expired owner from removing a newer lease.
		if ( self::remove( $name, $old ) && add_option( $name, $lease, '', false ) ) {
			return array( $name, $lease );
		}
		return false;
	}

	/**
	 * @param array $owned Owned lease.
	 */
	public static function release( array $owned ) {
		self::remove( $owned[0], $owned[1] );
	}

	private static function remove( $name, array $lease ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic compare-and-delete cannot use a cached read.
		$rows = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, maybe_serialize( $lease ) ) );
		if ( 1 === (int) $rows ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		return false;
	}
}

