<?php
/**
 * Persistent atomic event deduplication.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Database claim store for webhook event ids.
 */
final class Event_Store implements Event_Claim_Store {

	const RETENTION_DAYS = 8;

	/**
	 * Claim an event id.
	 *
	 * @param string $event_id Event id.
	 * @return bool
	 */
	public function claim( $event_id ) {
		global $wpdb;
		if ( ! is_string( $event_id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $event_id ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table atomic unique-key insert.
		$rows = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id, received_at) VALUES (%s, %s)', Bootstrap::events_table(), $event_id, gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $rows ) {
			throw new \RuntimeException( 'finlstpa event storage is unavailable.' );
		}
		return 1 === (int) $rows;
	}

	/**
	 * Release a claim after a retryable failure.
	 *
	 * @param string $event_id Event id.
	 * @return void
	 */
	public function release( $event_id ) {
		global $wpdb;
		if ( ! is_string( $event_id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $event_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the custom-table event claim after a retryable failure.
		$deleted = $wpdb->delete( Bootstrap::events_table(), array( 'event_id' => $event_id ), array( '%s' ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( 'finlstpa event claim could not be released.' );
		}
	}

	/**
	 * Delete claims older than the retention window.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table retention maintenance.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', Bootstrap::events_table(), gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}
}
