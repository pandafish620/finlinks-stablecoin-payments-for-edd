<?php
/**
 * Persistent atomic event deduplication, isolated from the WooCommerce table.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Event_Store implements Finlinks_Event_Store {
	const RETENTION_DAYS = 8;

	public function claim( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom-table atomic unique-key insert.
		$rows = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id, received_at) VALUES (%s, %s)', Bootstrap::events_table(), $event_id, gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $rows ) {
			throw new \RuntimeException( 'FinLinks EDD event storage is unavailable.' );
		}
		return 1 === (int) $rows;
	}

	public function release( $event_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the custom-table event claim after a retryable failure.
		$deleted = $wpdb->delete( Bootstrap::events_table(), array( 'event_id' => $event_id ), array( '%s' ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( 'FinLinks EDD event claim could not be released.' );
		}
	}

	public static function purge() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom-table retention maintenance.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', Bootstrap::events_table(), gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}
}

