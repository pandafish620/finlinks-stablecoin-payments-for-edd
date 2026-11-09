<?php
/**
 * Remove gateway settings and operational data. Orders, transactions, and notes stay.
 *
 * @package Finlstpa\EDD
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's options, schedules, leases, and event tables on every site.
 *
 * Order meta is left in place so Easy Digital Downloads order history remains,
 * which matches the 1.0.0 uninstall behavior.
 *
 * @return void
 */
function finlstpa_uninstall() {
	$finlstpa_sites = is_multisite() ? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) : array( get_current_blog_id() );
	foreach ( $finlstpa_sites as $finlstpa_site ) {
		if ( is_multisite() ) {
			switch_to_blog( $finlstpa_site );
		}
		wp_clear_scheduled_hook( 'finlstpa_reconcile' );
		wp_clear_scheduled_hook( 'finlinks_edd_reconcile' );
		foreach (
			array(
				'finlstpa_settings',
				'finlstpa_db_version',
				'finlstpa_schema',
				'finlstpa_orders_schema',
				'finlstpa_pause',
				'finlstpa_clock',
				'finlstpa_stale_webhook',
				'finlinks_edd_settings',
				'finlinks_edd_db_version',
				'finlinks_edd_pause',
				'finlinks_edd_clock',
				'finlinks_edd_stale_webhook',
			) as $finlstpa_option
		) {
			delete_option( $finlstpa_option );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transient and lease cleanup on uninstall.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'finlstpa_lock_' ) . '%',
				$wpdb->esc_like( '_transient_finlstpa_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_finlstpa_' ) . '%',
				$wpdb->esc_like( 'finlinks_edd_lock_' ) . '%',
				$wpdb->esc_like( '_transient_finlinks_edd_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_finlinks_edd_' ) . '%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop only this plugin's event tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'finlstpa_webhook_events' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop a leftover table from 1.0.0.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'finlinks_edd_webhook_events' ) );
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
}

finlstpa_uninstall();
