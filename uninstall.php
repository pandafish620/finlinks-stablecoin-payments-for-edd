<?php
/**
 * Remove EDD gateway settings and operational data; keep EDD orders and notes.
 *
 * @package FinLinks\EDD
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$finlinks_edd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
foreach ( $finlinks_edd_sites as $finlinks_edd_site ) {
	if ( is_multisite() ) {
		switch_to_blog( $finlinks_edd_site );
	}
	wp_clear_scheduled_hook( 'finlinks_edd_reconcile' );
	foreach ( array( 'finlinks_edd_settings', 'finlinks_edd_db_version', 'finlinks_edd_pause', 'finlinks_edd_clock', 'finlinks_edd_stale_webhook' ) as $finlinks_edd_option ) {
		delete_option( $finlinks_edd_option );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned transient/lease cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'finlinks_edd_lock_' ) . '%', $wpdb->esc_like( '_transient_finlinks_edd_' ) . '%', $wpdb->esc_like( '_transient_timeout_finlinks_edd_' ) . '%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Drop only this plugin's operational event table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'finlinks_edd_webhook_events' ) );
	if ( is_multisite() ) {
		restore_current_blog();
	}
}

