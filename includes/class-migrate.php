<?php
/**
 * Idempotent move from the 1.0.0 storage names to the finlstpa names.
 *
 * Credentials are copied as the same strings. This plugin does not encrypt
 * them, so the option name is not authentication data and is not mixed into
 * the stored secret.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Upgrade storage without registering the old hooks.
 */
final class Migrate {

	const SCHEMA = 'finlstpa_schema';

	const ORDERS_SCHEMA = 'finlstpa_orders_schema';

	const VERSION = '2';

	const ERROR_OPTION = 'finlstpa_migration_error';

	/**
	 * Legacy option names deleted after a verified copy.
	 *
	 * @return string[]
	 */
	public static function legacy_options() {
		return array(
			'finlinks_edd_settings',
			'finlinks_edd_db_version',
			'finlinks_edd_pause',
			'finlinks_edd_clock',
			'finlinks_edd_stale_webhook',
		);
	}

	/**
	 * Run whatever upgrade work is still pending.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! self::needs_run() ) {
			return;
		}
		$owned = Lock::acquire( 'migrate' );
		if ( ! $owned ) {
			return;
		}
		try {
			if ( ! self::needs_run() ) {
				return;
			}
			Bootstrap::install();
			self::migrate_events();
			self::migrate_settings();
			self::migrate_state_options();
			self::migrate_cron();
			self::delete_legacy_locks();
			if ( function_exists( 'edd_get_order' ) ) {
				self::migrate_orders();
			}
			self::maybe_mark_schema();
		} finally {
			Lock::release( $owned );
		}
	}

	/**
	 * Admin notice when an upgrade stopped before deleting the previous data.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_shop_settings' ) ) {
			return;
		}
		$code = get_option( self::ERROR_OPTION, '' );
		if ( 'credentials_differ' === $code ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FinLinks kept the previous store connection because it does not match the connection saved by this version. The previous copy was not deleted and does not contain a displayed secret. Open Downloads → FinLinks USDC, save the API key and webhook secret you intend to use, then reload this screen.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		} elseif ( 'credentials_unreadable' === $code ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FinLinks could not read the previous store connection. Nothing from that copy was deleted. Open Downloads → FinLinks USDC and save the API key and webhook secret again.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		} elseif ( 'event_copy_failed' === $code ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FinLinks could not move the previous payment-notification history. That table is still in place and no credentials were removed. Reload this screen to try again.', 'finlinks-stablecoin-payments-for-edd' ) . '</p></div>';
		}
	}

	/**
	 * Whether another pass is required.
	 *
	 * @return bool
	 */
	private static function needs_run() {
		if ( self::VERSION !== (string) get_option( self::SCHEMA, '' ) ) {
			return true;
		}
		return function_exists( 'edd_get_order' ) && self::VERSION !== (string) get_option( self::ORDERS_SCHEMA, '' );
	}

	/**
	 * Copy webhook claims into the new table, then drop the old table.
	 *
	 * @return void
	 */
	private static function migrate_events() {
		global $wpdb;
		$old = $wpdb->prefix . 'finlinks_edd_webhook_events';
		$new = Bootstrap::events_table();
		if ( $old === $new || ! Bootstrap::table_exists( $old ) || ! Bootstrap::table_exists( $new ) ) {
			return;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time read of plugin-owned rows.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id, received_at FROM %i', $old ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			self::fail( 'event_copy_failed' );
			return;
		}
		$ids = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['event_id'], $row['received_at'] ) ) {
				self::fail( 'event_copy_failed' );
				return;
			}
			$id = (string) $row['event_id'];
			$at = (string) $row['received_at'];
			if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $id ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at ) ) {
				continue;
			}
			if ( ! self::insert_event( $new, $id, $at ) ) {
				self::fail( 'event_copy_failed' );
				return;
			}
			$ids[] = $id;
		}
		foreach ( $ids as $id ) {
			if ( ! self::event_present( $new, $id ) ) {
				self::fail( 'event_copy_failed' );
				return;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop the previous table only after every copied id was read back.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
		if ( Bootstrap::table_exists( $old ) ) {
			self::fail( 'event_copy_failed' );
			return;
		}
		if ( 'event_copy_failed' === get_option( self::ERROR_OPTION, '' ) ) {
			delete_option( self::ERROR_OPTION );
		}
	}

	/**
	 * Insert one claim, including on SQLite where INSERT IGNORE may differ.
	 *
	 * @param string $table Table.
	 * @param string $id    Event id.
	 * @param string $at    Timestamp.
	 * @return bool
	 */
	private static function insert_event( $table, $id, $at ) {
		if ( false === apply_filters( 'finlstpa_migrate_event_insert', true, $id ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id, received_at) VALUES (%s, %s)', $table, $id, $at ) );
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';
		if ( '' === $error ) {
			return self::event_present( $table, $id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id = %s', $table, $id ) );
		if ( $exists === $id ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert a copied webhook claim when INSERT IGNORE is unavailable.
		$inserted = $wpdb->insert(
			$table,
			array(
				'event_id'    => $id,
				'received_at' => $at,
			),
			array( '%s', '%s' )
		);
		return false !== $inserted && self::event_present( $table, $id );
	}

	/**
	 * Whether an event id is stored.
	 *
	 * @param string $table Table.
	 * @param string $id    Event id.
	 * @return bool
	 */
	private static function event_present( $table, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id = %s', $table, $id ) );
		return $exists === $id;
	}

	/**
	 * Copy settings. The legacy row is removed only after the new row matches.
	 *
	 * @return void
	 */
	private static function migrate_settings() {
		$old = get_option( 'finlinks_edd_settings', false );
		if ( ! is_array( $old ) ) {
			return;
		}
		$old_key    = self::readable_secret( isset( $old['api_key'] ) ? $old['api_key'] : '' );
		$old_secret = self::readable_secret( isset( $old['webhook_secret'] ) ? $old['webhook_secret'] : '' );
		if ( null === $old_key || null === $old_secret ) {
			self::fail( 'credentials_unreadable' );
			return;
		}
		$existing   = get_option( Settings::OPTION, false );
		$new        = is_array( $existing ) ? $existing : array();
		$new_key    = self::readable_secret( isset( $new['api_key'] ) ? $new['api_key'] : '' );
		$new_secret = self::readable_secret( isset( $new['webhook_secret'] ) ? $new['webhook_secret'] : '' );
		if ( null === $new_key || null === $new_secret ) {
			self::fail( 'credentials_unreadable' );
			return;
		}
		if ( ( '' !== $new_key && '' !== $old_key && ! hash_equals( $new_key, $old_key ) ) || ( '' !== $new_secret && '' !== $old_secret && ! hash_equals( $new_secret, $old_secret ) ) ) {
			self::fail( 'credentials_differ' );
			return;
		}
		$merged_key    = '' !== $new_key ? $new_key : $old_key;
		$merged_secret = '' !== $new_secret ? $new_secret : $old_secret;
		$base          = self::readable_secret( isset( $old['api_base'] ) ? $old['api_base'] : '' );
		$new_base      = self::readable_secret( isset( $new['api_base'] ) ? $new['api_base'] : '' );
		$chosen_base   = is_string( $new_base ) && '' !== $new_base ? $new_base : ( is_string( $base ) ? $base : '' );
		$resolved      = Api_Base::resolve( true, $chosen_base );
		$clean         = array(
			'enabled'        => self::yes_no( is_array( $existing ) && isset( $existing['enabled'] ) ? $existing['enabled'] : ( isset( $old['enabled'] ) ? $old['enabled'] : '' ) ),
			'debug'          => self::yes_no( is_array( $existing ) && isset( $existing['debug'] ) ? $existing['debug'] : ( isset( $old['debug'] ) ? $old['debug'] : '' ) ),
			'api_base'       => ( '' !== $chosen_base && untrailingslashit( $chosen_base ) === $resolved ) ? $resolved : '',
			'api_key'        => $merged_key,
			'webhook_secret' => $merged_secret,
		);
		update_option( Settings::OPTION, $clean, false );
		$stored = get_option( Settings::OPTION, false );
		if ( ! is_array( $stored ) ) {
			self::fail( 'credentials_unreadable' );
			return;
		}
		$stored_key    = isset( $stored['api_key'] ) && is_string( $stored['api_key'] ) ? $stored['api_key'] : '';
		$stored_secret = isset( $stored['webhook_secret'] ) && is_string( $stored['webhook_secret'] ) ? $stored['webhook_secret'] : '';
		if ( ! hash_equals( $clean['api_key'], $stored_key ) || ! hash_equals( $clean['webhook_secret'], $stored_secret ) ) {
			self::fail( 'credentials_unreadable' );
			return;
		}
		delete_option( 'finlinks_edd_settings' );
		$code = get_option( self::ERROR_OPTION, '' );
		if ( 'credentials_differ' === $code || 'credentials_unreadable' === $code ) {
			delete_option( self::ERROR_OPTION );
		}
	}

	/**
	 * yes or no.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function yes_no( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Record a migration stop without storing credential bytes.
	 *
	 * @param string $code Error code.
	 * @return void
	 */
	private static function fail( $code ) {
		update_option( self::ERROR_OPTION, $code, false );
	}

	/**
	 * A stored credential or URL with no control characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function opaque( $value ) {
		$readable = self::readable_secret( $value );
		return is_string( $readable ) ? $readable : '';
	}

	/**
	 * Secret or URL bytes, '' when empty, or null when the value must not be copied.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function readable_secret( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > 256 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Move clock, pause, and stale-webhook state. These values are not secrets.
	 *
	 * @return void
	 */
	private static function migrate_state_options() {
		self::move_clock( 'finlinks_edd_clock', Settings::CLOCK_OPTION );
		self::move_clock( 'finlinks_edd_stale_webhook', Settings::STALE_OPTION );
		$pause = get_option( 'finlinks_edd_pause', false );
		if ( false !== $pause ) {
			$delete_pause = false;
			$current      = get_option( Settings::PAUSE_OPTION, false );
			if ( false !== $current ) {
				$delete_pause = true;
			} elseif ( is_array( $pause ) && isset( $pause['until'] ) && is_numeric( $pause['until'] ) ) {
				$until = (int) $pause['until'];
				$code  = isset( $pause['code'] ) ? sanitize_key( (string) $pause['code'] ) : '';
				if ( $until > time() && '' !== $code ) {
					$clean = array(
						'until' => $until,
						'code'  => $code,
					);
					update_option( Settings::PAUSE_OPTION, $clean, false );
					$stored       = get_option( Settings::PAUSE_OPTION, false );
					$delete_pause = is_array( $stored ) && (int) $stored['until'] === $until && $stored['code'] === $code;
				} else {
					$delete_pause = true;
				}
			} else {
				$delete_pause = true;
			}
			if ( $delete_pause ) {
				delete_option( 'finlinks_edd_pause' );
			}
		}
		if ( '1' === (string) get_option( Bootstrap::DB_OPTION, '' ) && Bootstrap::table_exists( Bootstrap::events_table() ) ) {
			delete_option( 'finlinks_edd_db_version' );
		}
	}

	/**
	 * Copy one clock record when the new option is empty.
	 *
	 * @param string $old_key Old option.
	 * @param string $new_key New option.
	 * @return void
	 */
	private static function move_clock( $old_key, $new_key ) {
		$old = get_option( $old_key, false );
		if ( false === $old ) {
			return;
		}
		$clean = null;
		if ( is_array( $old ) && isset( $old['skew'], $old['at'] ) && is_numeric( $old['skew'] ) && is_numeric( $old['at'] ) ) {
			$skew = (int) $old['skew'];
			$at   = (int) $old['at'];
			if ( abs( $skew ) <= 7 * DAY_IN_SECONDS && $at >= 0 ) {
				$clean = array(
					'skew' => $skew,
					'at'   => $at,
				);
			}
		}
		if ( false === get_option( $new_key, false ) && null !== $clean ) {
			update_option( $new_key, $clean, false );
			$stored = get_option( $new_key, false );
			if ( ! is_array( $stored ) || (int) $stored['skew'] !== $clean['skew'] || (int) $stored['at'] !== $clean['at'] ) {
				return;
			}
		}
		if ( false !== get_option( $new_key, false ) || null === $clean ) {
			delete_option( $old_key );
		}
	}

	/**
	 * Remove the previous reconciliation hook. It is not registered again.
	 *
	 * @return void
	 */
	private static function migrate_cron() {
		wp_clear_scheduled_hook( 'finlinks_edd_reconcile' );
	}

	/**
	 * Old leases and admin notices are short-lived and are not renamed.
	 *
	 * @return void
	 */
	private static function delete_legacy_locks() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove only the previous plugin's lock and notice rows.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'finlinks_edd_lock_' ) . '%',
				$wpdb->esc_like( '_transient_finlinks_edd_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_finlinks_edd_' ) . '%'
			)
		);
	}

	/**
	 * Move order gateway ids and order meta. Order rows themselves stay.
	 *
	 * @return void
	 */
	private static function migrate_orders() {
		if ( self::VERSION === (string) get_option( self::ORDERS_SCHEMA, '' ) ) {
			return;
		}
		global $wpdb;
		$orders = $wpdb->prefix . 'edd_orders';
		$meta   = $wpdb->prefix . 'edd_ordermeta';
		if ( ! Bootstrap::table_exists( $orders ) || ! Bootstrap::table_exists( $meta ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time gateway id rewrite. The order cache is cleared below.
		$wpdb->update(
			$orders,
			array( 'gateway' => Gateway::ID ),
			array( 'gateway' => 'finlinks_usdc' ),
			array( '%s' ),
			array( '%s' )
		);
		$map = array(
			'_finlinks_edd_session'          => Gateway::SESSION_META,
			'_finlinks_edd_last_status'      => Gateway::LAST_META,
			'_finlinks_edd_transaction_hash' => Gateway::TX_META,
			'_finlinks_edd_store_order_id'   => Gateway::STORE_META,
		);
		foreach ( $map as $old => $new ) {
			self::rename_meta( $meta, $old, $new, false );
		}
		self::rename_meta( $meta, '_finlinks_edd_note_', Gateway::NOTE_PREFIX, true );
		self::migrate_gateway_settings();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE gateway = %s', $orders, 'finlinks_usdc' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_left = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE meta_key IN (%s, %s, %s, %s) OR meta_key LIKE %s',
				$meta,
				'_finlinks_edd_session',
				'_finlinks_edd_last_status',
				'_finlinks_edd_transaction_hash',
				'_finlinks_edd_store_order_id',
				$wpdb->esc_like( '_finlinks_edd_note_' ) . '%'
			)
		);
		if ( null !== $left && '0' === (string) $left && null !== $meta_left && '0' === (string) $meta_left ) {
			update_option( self::ORDERS_SCHEMA, self::VERSION, false );
		}
		// Direct table updates bypass the EDD order cache. Clear it before later reads in this request.
		wp_cache_flush();
	}

	/**
	 * Rename one meta key, or a hashed note prefix.
	 *
	 * @param string $table       Meta table.
	 * @param string $old         Old key or prefix.
	 * @param string $replacement New key or prefix.
	 * @param bool   $prefix      Whether $old is a prefix.
	 * @return void
	 */
	private static function rename_meta( $table, $old, $replacement, $prefix ) {
		global $wpdb;
		if ( $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, edd_order_id, meta_key FROM %i WHERE meta_key LIKE %s', $table, $wpdb->esc_like( $old ) . '%' ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, edd_order_id, meta_key FROM %i WHERE meta_key = %s', $table, $old ), ARRAY_A );
		}
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['meta_id'], $row['edd_order_id'], $row['meta_key'] ) ) {
				continue;
			}
			$key = (string) $row['meta_key'];
			if ( $prefix ) {
				$suffix = substr( $key, strlen( $old ) );
				if ( ! preg_match( '/^[a-f0-9]{64}$/', $suffix ) ) {
					continue;
				}
				$target = $replacement . $suffix;
			} else {
				$target = $replacement;
			}
			$order_id = (int) $row['edd_order_id'];
			$meta_id  = (int) $row['meta_id'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$dupe = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE edd_order_id = %d AND meta_key = %s', $table, $order_id, $target ) );
			if ( null !== $dupe && '' !== (string) $dupe ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Drop a duplicate legacy meta row after the new key exists.
				$wpdb->delete( $table, array( 'meta_id' => $meta_id ), array( '%d' ) );
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rename this plugin's order meta key. Not a request-time lookup.
			$wpdb->update(
				$table,
				array( 'meta_key' => $target ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rename this plugin's order meta key. Not a request-time lookup.
				array( 'meta_id' => $meta_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Point saved EDD gateway settings at the new gateway id.
	 *
	 * @return void
	 */
	private static function migrate_gateway_settings() {
		$settings = get_option( 'edd_settings', false );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$changed = false;
		if ( isset( $settings['gateways'] ) && is_array( $settings['gateways'] ) ) {
			if ( array_key_exists( 'finlinks_usdc', $settings['gateways'] ) ) {
				$settings['gateways']['finlstpa_usdc'] = $settings['gateways']['finlinks_usdc'];
				unset( $settings['gateways']['finlinks_usdc'] );
				$changed = true;
			}
			$index = array_search( 'finlinks_usdc', $settings['gateways'], true );
			if ( false !== $index ) {
				$settings['gateways'][ $index ] = 'finlstpa_usdc';
				$changed                        = true;
			}
		}
		if ( isset( $settings['default_gateway'] ) && 'finlinks_usdc' === $settings['default_gateway'] ) {
			$settings['default_gateway'] = 'finlstpa_usdc';
			$changed                     = true;
		}
		if ( $changed ) {
			update_option( 'edd_settings', $settings );
		}
	}

	/**
	 * Record schema version 2 only after the legacy storage is gone.
	 *
	 * @return void
	 */
	private static function maybe_mark_schema() {
		global $wpdb;
		if ( '' !== (string) get_option( self::ERROR_OPTION, '' ) ) {
			return;
		}
		foreach ( self::legacy_options() as $key ) {
			if ( false !== get_option( $key, false ) ) {
				return;
			}
		}
		if ( wp_next_scheduled( 'finlinks_edd_reconcile' ) ) {
			return;
		}
		if ( Bootstrap::table_exists( $wpdb->prefix . 'finlinks_edd_webhook_events' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'finlinks_edd_lock_' ) . '%', $wpdb->esc_like( '_transient_finlinks_edd_' ) . '%' ) );
		if ( null === $left || '0' !== (string) $left ) {
			return;
		}
		update_option( self::SCHEMA, self::VERSION, false );
	}
}
