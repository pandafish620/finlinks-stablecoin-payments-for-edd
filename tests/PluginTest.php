<?php
/**
 * Regression tests for the prefix migration and payment checks.
 *
 * @package Finlstpa\EDD
 */

use Finlstpa\EDD\Amount;
use Finlstpa\EDD\Api_Base;
use Finlstpa\EDD\Api_Client;
use Finlstpa\EDD\Bootstrap;
use Finlstpa\EDD\Credentials;
use Finlstpa\EDD\Event_Store;
use Finlstpa\EDD\Gateway;
use Finlstpa\EDD\Host_Policy;
use Finlstpa\EDD\Hosted_Url;
use Finlstpa\EDD\Lock;
use Finlstpa\EDD\Logger;
use Finlstpa\EDD\Memory_Event_Store;
use Finlstpa\EDD\Migrate;
use Finlstpa\EDD\Order_Sync;
use Finlstpa\EDD\Settings;
use Finlstpa\EDD\Webhook_Processor;

/**
 * Minimal PHPUnit base when the WordPress test suite is not installed.
 */
abstract class WP_UnitTestCase_Shim extends PHPUnit\Framework\TestCase {}

/**
 * Plugin behavior that must survive the prefix change.
 */
class PluginTest extends WP_UnitTestCase_Shim {

	/**
	 * Reset the current user between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Source files do not keep the old public names outside the migration exceptions.
	 *
	 * @return void
	 */
	public function test_public_names_use_finlstpa_prefix() {
		$root    = dirname( __DIR__ );
		$allowed = array(
			$root . '/includes/class-migrate.php',
			$root . '/includes/class-bootstrap.php',
			$root . '/uninstall.php',
		);
		$needles = array( 'namespace FinLinks\\EDD', 'FINLINKS_EDD_', 'FinLinksEDD', 'finlinks-edd-admin', 'finlinks_edd_', 'class Finlinks_' );
		$hits    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( ! preg_match( '/\.(php|js)$/', $path ) ) {
				continue;
			}
			if ( false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/dist/' ) || false !== strpos( $path, '/docs/' ) || false !== strpos( $path, '/tests/' ) ) {
				continue;
			}
			$text = file_get_contents( $path );
			foreach ( $needles as $needle ) {
				if ( false === strpos( $text, $needle ) ) {
					continue;
				}
				if ( in_array( $path, $allowed, true ) ) {
					continue;
				}
				$hits[] = $path . ' contains ' . $needle;
			}
		}
		$this->assertSame( array(), $hits );
		$bootstrap = file_get_contents( $root . '/includes/class-bootstrap.php' );
		$this->assertStringContainsString( 'finlinks-edd/v1', $bootstrap );
		$this->assertStringContainsString( 'finlstpa/v1', $bootstrap );
		$migrate = file_get_contents( $root . '/includes/class-migrate.php' );
		$this->assertStringNotContainsString( "function_exists( 'finlstpa_", $migrate );
		$this->assertStringNotContainsString( 'class_exists( \'Finlstpa', $migrate );
	}

	/**
	 * A foreign function with an old name is not this plugin's cron callback.
	 *
	 * @return void
	 */
	public function test_old_names_do_not_receive_callbacks() {
		$this->assertTrue( class_exists( Gateway::class ) );
		$this->assertFalse( class_exists( 'FinLinks\\EDD\\Gateway' ) );
		$this->assertFalse( function_exists( 'finlinks_edd_reconcile' ) );
		$this->assertFalse( has_action( 'finlinks_edd_reconcile' ) );
		if ( function_exists( 'edd_get_order' ) ) {
			$this->assertNotFalse( has_action( 'finlstpa_reconcile' ) );
		}
	}

	/**
	 * Signature verification still uses the FinLinks header contract.
	 *
	 * @return void
	 */
	public function test_webhook_signature_replay_and_idempotency() {
		$secret = str_repeat( 's', 24 );
		$body   = '{"id":"evt_mem_1","type":"payment_session.paid","data":{"id":"sess_1"}}';
		$now    = time();
		$good   = $this->headers( $secret, $body, $now );
		$calls  = 0;
		$store  = new Memory_Event_Store();
		$proc   = new Webhook_Processor(
			$secret,
			$store,
			static function () use ( &$calls ) {
				$calls++;
				return Webhook_Processor::HANDLED;
			},
			static function () use ( $now ) {
				return $now;
			}
		);
		$bad = $proc->handle( $this->headers( 'wrong-secret-value', $body, $now ), $body );
		$this->assertSame( 401, $bad[0] );
		$this->assertSame( 0, $calls );
		$first = $proc->handle( $good, $body );
		$again = $proc->handle( $good, $body );
		$this->assertSame( 200, $first[0] );
		$this->assertSame( array( 'received' => true ), $first[1] );
		$this->assertSame( array( 'duplicate' => true ), $again[1] );
		$this->assertSame( 1, $calls );

		$stale_body = '{"id":"evt_stale","type":"ping"}';
		$stale      = $proc->handle( $this->headers( $secret, $stale_body, $now - 1000 ), $stale_body );
		$this->assertSame( 503, $stale[0] );
		$this->assertSame( 'stale_timestamp', $stale[1]['error'] );

		$db  = new Event_Store();
		$eid = 'evt_db_' . wp_generate_password( 8, false, false );
		$this->assertTrue( $db->claim( $eid ) );
		$this->assertFalse( $db->claim( $eid ) );
		$db->release( $eid );
		$this->assertTrue( $db->claim( $eid ) );
	}

	/**
	 * Both webhook routes require a valid signature.
	 *
	 * @return void
	 */
	public function test_rest_routes_reject_bad_signatures_and_accept_legacy_signed_ping() {
		$secret = str_repeat( 'r', 24 );
		$this->save_settings( $secret );
		$body = '{"id":"evt_rest_ping","type":"ping"}';
		$now  = time();
		foreach ( array( '/finlstpa/v1/webhook', '/finlinks-edd/v1/webhook' ) as $route ) {
			$bad = new WP_REST_Request( 'POST', $route );
			$bad->set_header( 'Content-Type', 'application/json' );
			$bad->set_body( $body );
			$bad_response = rest_do_request( $bad );
			$this->assertSame( 401, $bad_response->get_status(), $route );
			$good = new WP_REST_Request( 'POST', $route );
			$good->set_header( 'Content-Type', 'application/json' );
			$good->set_header( 'Finlinks-Timestamp', (string) $now );
			$good->set_header( 'Finlinks-Signature', hash_hmac( 'sha256', $now . '.' . $body, $secret ) );
			$good->set_body( $body );
			$good_response = rest_do_request( $good );
			$this->assertSame( 200, $good_response->get_status(), $route );
			$this->assertTrue( $good_response->get_data()['ok'] );
		}
	}

	/**
	 * Private hosts cannot be used for the test API or a buyer redirect.
	 *
	 * @return void
	 */
	public function test_private_hosts_are_blocked() {
		$this->assertTrue( Host_Policy::is_blocked( '127.0.0.1' ) );
		$this->assertTrue( Host_Policy::is_blocked( '169.254.169.254' ) );
		$this->assertTrue( Host_Policy::is_blocked( '10.0.0.8' ) );
		$this->assertFalse( Host_Policy::is_blocked( 'api.finlinks.io' ) );
		$this->assertSame( '', Api_Base::resolve( true, 'https://127.0.0.1' ) );
		$this->assertSame( 'https://api.finlinks.io', Api_Base::resolve( false, 'https://127.0.0.1' ) );
		$this->assertFalse( Hosted_Url::is_allowed( 'https://127.0.0.1/inv/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', false ) );
		$this->assertTrue( Hosted_Url::is_allowed( 'https://pay.finlinks.io/inv/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', false ) );
	}

	/**
	 * Logs do not keep API keys or signatures.
	 *
	 * @return void
	 */
	public function test_logger_redacts_secrets() {
		$message = 'key flk_live_abcdefghijklmnopqrst Bearer abcdefghijklmnop Finlinks-Signature: ' . str_repeat( 'ab', 32 );
		$clean   = Logger::scrub( $message, 'abcdefghijklmnop' );
		$this->assertStringNotContainsString( 'flk_live_abcdefghijklmnopqrst', $clean );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', $clean );
		$this->assertStringNotContainsString( str_repeat( 'ab', 32 ), $clean );
	}

	/**
	 * 1.0.0 settings move without changing the secret bytes.
	 *
	 * @return void
	 */
	public function test_settings_migration_preserves_credentials() {
		$key    = 'flk_live_' . str_repeat( 'a', 20 );
		$secret = str_repeat( 'b', 32 );
		delete_option( Settings::OPTION );
		delete_option( Settings::CLOCK_OPTION );
		delete_option( Settings::STALE_OPTION );
		delete_option( Settings::PAUSE_OPTION );
		delete_option( Migrate::SCHEMA );
		update_option(
			'finlinks_edd_settings',
			array(
				'enabled'        => 'yes',
				'debug'          => 'no',
				'api_base'       => 'https://127.0.0.1',
				'api_key'        => $key,
				'webhook_secret' => $secret,
			),
			false
		);
		update_option(
			'finlinks_edd_clock',
			array(
				'skew' => 30,
				'at'   => time(),
			),
			false
		);
		wp_schedule_event( time() + 60, 'hourly', 'finlinks_edd_reconcile' );
		add_option( 'finlinks_edd_lock_' . str_repeat( 'c', 64 ), array( 'token' => 'x', 'expires' => time() + 30 ), '', false );
		Migrate::run();
		$stored = get_option( Settings::OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( $key, $stored['api_key'] );
		$this->assertSame( $secret, $stored['webhook_secret'] );
		$this->assertSame( '', $stored['api_base'] );
		$this->assertFalse( get_option( 'finlinks_edd_settings', false ) );
		$this->assertFalse( wp_next_scheduled( 'finlinks_edd_reconcile' ) );
		$this->assertSame( '2', (string) get_option( Migrate::SCHEMA ) );
		$this->assertSame( 30, (int) get_option( Settings::CLOCK_OPTION )['skew'] );
	}

	/**
	 * Guests and users without the shop capability cannot save settings.
	 *
	 * @return void
	 */
	public function test_settings_require_capability_and_nonce() {
		wp_set_current_user( 0 );
		try {
			Settings::save();
			$this->fail( 'Guest save should stop.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'wp_die:', $error->getMessage() );
		}
		$this->grant_shop_manager();
		$_POST['finlstpa_nonce']    = 'not-a-nonce';
		$_REQUEST['finlstpa_nonce'] = 'not-a-nonce';
		try {
			Settings::save();
			$this->fail( 'Bad nonce should stop.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'wp_die:', $error->getMessage() );
		}
	}

	/**
	 * A shop manager can save a live key. The redirect is intercepted by the test bootstrap.
	 *
	 * @return void
	 */
	public function test_shop_manager_can_save_settings() {
		$this->grant_shop_manager();
		if ( function_exists( 'edd_update_option' ) ) {
			edd_update_option( 'test_mode', false );
		}
		$nonce                      = wp_create_nonce( Settings::NONCE_SAVE );
		$_POST['finlstpa_nonce']    = $nonce;
		$_REQUEST['finlstpa_nonce'] = $nonce;
		$_POST['enabled']           = 'yes';
		$_POST['api_key']           = 'flk_live_' . str_repeat( 'd', 20 );
		$_POST['webhook_secret']    = str_repeat( 'e', 16 );
		try {
			Settings::save();
			$this->fail( 'Save should redirect.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'redirect:', $error->getMessage() );
			$this->assertStringContainsString( 'finlstpa-settings', $error->getMessage() );
		}
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 'yes', $stored['enabled'] );
		$this->assertTrue( Credentials::is_live_key( $stored['api_key'] ) );
		$this->assertSame( str_repeat( 'e', 16 ), $stored['webhook_secret'] );
	}

	/**
	 * Unknown connection tools are rejected.
	 *
	 * @return void
	 */
	public function test_connection_tool_rejects_unknown_operation() {
		list( $ok, $message ) = Settings::run( 'drop-table' );
		$this->assertFalse( $ok );
		$this->assertNotSame( '', $message );
	}

	/**
	 * Checkout nonce is required, and a paid session completes the order once.
	 *
	 * @return void
	 */
	public function test_checkout_session_and_paid_reconciliation() {
		if ( ! function_exists( 'edd_add_order' ) || ! function_exists( 'edd_get_order' ) ) {
			$this->markTestSkipped( 'Easy Digital Downloads is not active.' );
		}
		try {
			Gateway::process( array() );
			$this->fail( 'Missing checkout nonce should stop.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'wp_die:', $error->getMessage() );
		}
		$key = 'edd' . strtolower( wp_generate_password( 20, false, false ) );
		$this->save_settings( str_repeat( 'f', 24 ) );
		update_option( Bootstrap::DB_OPTION, '1', false );
		update_option( 'home', 'https://example.test' );
		update_option( 'siteurl', 'https://example.test' );
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
		$edd_settings = get_option( 'edd_settings', array() );
		if ( ! is_array( $edd_settings ) ) {
			$edd_settings = array();
		}
		$edd_settings['test_mode'] = 0;
		$edd_settings['currency']  = 'USD';
		update_option( 'edd_settings', $edd_settings );
		$order_id = edd_add_order(
			array(
				'status'      => 'pending',
				'type'        => 'sale',
				'gateway'     => Gateway::ID,
				'mode'        => 'live',
				'currency'    => 'USD',
				'subtotal'    => 20,
				'total'       => 20,
				'payment_key' => $key,
				'email'       => 'buyer@example.com',
			)
		);
		$this->assertGreaterThan( 0, (int) $order_id );
		$sid      = 'sess_checkout_1';
		$hosted   = 'https://pay.finlinks.io/inv/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$filter   = $this->mock_api( $order_id, $sid, $hosted, '20.00' );
		$purchase = array(
			'price'        => '20.00',
			'purchase_key' => $key,
			'user_email'   => 'buyer@example.com',
		);
		$result   = Gateway::prepare( $purchase );
		remove_filter( 'pre_http_request', $filter, 10 );
		$this->assertSame( $hosted, $result );
		$this->assertSame( $sid, (string) edd_get_order_meta( $order_id, Gateway::SESSION_META, true ) );

		$paid = $this->mock_paid( $order_id, $sid, '20.00' );
		$this->assertSame( Webhook_Processor::HANDLED, Order_Sync::sync( $order_id, $sid ) );
		$order = edd_get_order( $order_id );
		$this->assertSame( 'complete', $order->status );
		$this->assertSame( Webhook_Processor::IGNORED, Order_Sync::sync( $order_id, $sid ) );
		remove_filter( 'pre_http_request', $paid, 10 );
		$this->assertTrue( Amount::is_eligible( 'USD', '20.00' ) );
		$this->assertFalse( Amount::is_eligible( 'EUR', '20.00' ) );
		$this->assertSame( 'complete', edd_get_order( $order_id )->status );
	}

	/**
	 * Legacy order meta and gateway id are rewritten.
	 *
	 * @return void
	 */
	public function test_order_meta_migration() {
		if ( ! function_exists( 'edd_add_order' ) || ! function_exists( 'edd_update_order_meta' ) ) {
			$this->markTestSkipped( 'Easy Digital Downloads is not active.' );
		}
		$order_id = edd_add_order(
			array(
				'status'      => 'pending',
				'type'        => 'sale',
				'gateway'     => 'finlinks_usdc',
				'mode'        => 'live',
				'currency'    => 'USD',
				'subtotal'    => 5,
				'total'       => 5,
				'payment_key' => 'edd' . strtolower( wp_generate_password( 20, false, false ) ),
				'email'       => 'buyer@example.com',
			)
		);
		$this->assertGreaterThan( 0, (int) $order_id );
		edd_update_order_meta( $order_id, '_finlinks_edd_session', 'sess_old_1' );
		edd_update_order_meta( $order_id, '_finlinks_edd_transaction_hash', str_repeat( 'ab', 16 ) );
		delete_option( Migrate::ORDERS_SCHEMA );
		$settings = get_option( 'edd_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['gateways']['finlinks_usdc'] = 1;
		$settings['default_gateway']           = 'finlinks_usdc';
		update_option( 'edd_settings', $settings );
		Migrate::run();
		$order = edd_get_order( $order_id );
		$this->assertSame( Gateway::ID, $order->gateway );
		$this->assertSame( 'sess_old_1', (string) edd_get_order_meta( $order_id, Gateway::SESSION_META, true ) );
		$this->assertSame( '', (string) edd_get_order_meta( $order_id, '_finlinks_edd_session', true ) );
		$this->assertSame( '2', (string) get_option( Migrate::ORDERS_SCHEMA ) );
		$saved = get_option( 'edd_settings' );
		$this->assertArrayHasKey( 'finlstpa_usdc', $saved['gateways'] );
		$this->assertArrayNotHasKey( 'finlinks_usdc', $saved['gateways'] );
		$this->assertSame( 'finlstpa_usdc', $saved['default_gateway'] );
	}

	/**
	 * Names that resolve to a private or loopback address are not requested.
	 *
	 * @return void
	 */
	public function test_transport_rejects_private_resolved_hosts_and_redirects() {
		$this->assertTrue( Host_Policy::is_blocked( '127.0.0.1' ) );
		$this->assertTrue( Host_Policy::is_blocked( '::1' ) );
		$this->assertTrue( Host_Policy::is_blocked( '[::1]' ) );
		$this->assertTrue( Host_Policy::is_blocked( 'localhost' ) );
		$this->assertTrue( Host_Policy::is_blocked( 'fe80::1' ) );
		$this->assertTrue( Host_Policy::destination_blocked( 'localhost' ) );
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				$calls++;
				return new WP_Error( 'finlstpa_test', 'should not run' );
			}
		);
		$blocked = ( new Api_Client( 'https://127.0.0.1', 'flk_test_abcdefghijklmnopqrst' ) )->get_connection();
		$this->assertSame( 0, $calls );
		$this->assertSame( 'blocked_destination', $blocked['code'] );
		remove_all_filters( 'pre_http_request' );

		$private = static function ( $addresses, $host ) {
			unset( $addresses );
			if ( 'public.example' === $host ) {
				return array( '10.1.2.3', '2001:db8::1' );
			}
			if ( 'redirect.example' === $host ) {
				return array( '1.1.1.1' );
			}
			return null;
		};
		add_filter( 'finlstpa_host_addresses', $private, 5, 2 );
		$this->assertTrue( Host_Policy::destination_blocked( 'public.example' ) );
		$redirects = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$redirects ) {
				unset( $pre, $args );
				if ( false === strpos( (string) $url, 'redirect.example' ) ) {
					return false;
				}
				$redirects++;
				return array(
					'headers'  => array( 'location' => 'http://127.0.0.1/secret' ),
					'body'     => '',
					'response' => array(
						'code'    => 302,
						'message' => 'Found',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);
		$redirected = ( new Api_Client( 'https://redirect.example', 'flk_test_abcdefghijklmnopqrst' ) )->get_connection();
		$this->assertSame( 1, $redirects );
		$this->assertSame( 'redirect_refused', $redirected['code'] );
		$this->assertFalse( $redirected['ok'] );
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'finlstpa_host_addresses', $private, 5 );
	}

	/**
	 * A failed event copy keeps the old table and leaves the schema version unset.
	 *
	 * @return void
	 */
	public function test_event_migration_failure_is_retried_without_dropping_source() {
		global $wpdb;
		$old = $wpdb->prefix . 'finlinks_edd_webhook_events';
		$new = Bootstrap::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$old} (event_id varchar(64) NOT NULL, received_at datetime NOT NULL, PRIMARY KEY (event_id))" );
		$wpdb->insert(
			$old,
			array(
				'event_id'    => 'evt_migrate_1',
				'received_at' => '2026-01-01 00:00:00',
			),
			array( '%s', '%s' )
		);
		delete_option( Migrate::SCHEMA );
		delete_option( Migrate::ERROR_OPTION );
		add_filter( 'finlstpa_migrate_event_insert', '__return_false' );
		Migrate::run();
		remove_filter( 'finlstpa_migrate_event_insert', '__return_false' );
		$this->assertSame( 'event_copy_failed', get_option( Migrate::ERROR_OPTION ) );
		$this->assertNotSame( '2', (string) get_option( Migrate::SCHEMA ) );
		$this->assertSame( $old, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		Migrate::run();
		$this->assertNotSame( $old, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) );
		$this->assertSame( 'evt_migrate_1', $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id = %s', $new, 'evt_migrate_1' ) ) );
		$this->assertSame( '2', (string) get_option( Migrate::SCHEMA ) );
		$this->assertSame( '', (string) get_option( Migrate::ERROR_OPTION, '' ) );
	}

	/**
	 * Partial settings are filled. Differing credentials are kept and block the schema flag.
	 *
	 * @return void
	 */
	public function test_partial_and_conflicting_settings_migration() {
		$old_key    = 'flk_live_' . str_repeat( 'm', 20 );
		$other_key  = 'flk_live_' . str_repeat( 'n', 20 );
		$old_secret = str_repeat( 'p', 24 );
		delete_option( Migrate::SCHEMA );
		delete_option( Migrate::ERROR_OPTION );
		update_option(
			Settings::OPTION,
			array(
				'enabled'        => 'no',
				'debug'          => 'no',
				'api_base'       => '',
				'api_key'        => $other_key,
				'webhook_secret' => $old_secret,
			),
			false
		);
		update_option(
			'finlinks_edd_settings',
			array(
				'enabled'        => 'yes',
				'debug'          => 'no',
				'api_base'       => '',
				'api_key'        => $old_key,
				'webhook_secret' => $old_secret,
			),
			false
		);
		Migrate::run();
		$this->assertSame( 'credentials_differ', get_option( Migrate::ERROR_OPTION ) );
		$this->assertNotSame( '2', (string) get_option( Migrate::SCHEMA ) );
		$this->assertSame( $other_key, get_option( Settings::OPTION )['api_key'] );
		$this->assertSame( $old_key, get_option( 'finlinks_edd_settings' )['api_key'] );

		update_option(
			Settings::OPTION,
			array(
				'enabled'        => 'no',
				'debug'          => 'no',
				'api_base'       => '',
				'api_key'        => $old_key,
				'webhook_secret' => '',
			),
			false
		);
		Migrate::run();
		$stored = get_option( Settings::OPTION );
		$this->assertSame( $old_key, $stored['api_key'] );
		$this->assertSame( $old_secret, $stored['webhook_secret'] );
		$this->assertSame( 'no', $stored['enabled'] );
		$this->assertFalse( get_option( 'finlinks_edd_settings', false ) );
		$this->assertSame( '', (string) get_option( Migrate::ERROR_OPTION, '' ) );
	}

	/**
	 * A second runner waits instead of deleting the source mid-copy.
	 *
	 * @return void
	 */
	public function test_migration_lock_keeps_source_until_the_holder_finishes() {
		$key    = 'flk_live_' . str_repeat( 'q', 20 );
		$secret = str_repeat( 'r', 24 );
		delete_option( Migrate::SCHEMA );
		delete_option( Settings::OPTION );
		update_option(
			'finlinks_edd_settings',
			array(
				'enabled'        => 'yes',
				'debug'          => 'no',
				'api_base'       => '',
				'api_key'        => $key,
				'webhook_secret' => $secret,
			),
			false
		);
		$held = Lock::acquire( 'migrate' );
		$this->assertNotFalse( $held );
		Migrate::run();
		$this->assertSame( $key, get_option( 'finlinks_edd_settings' )['api_key'] );
		Lock::release( $held );
		Migrate::run();
		$this->assertFalse( get_option( 'finlinks_edd_settings', false ) );
		$this->assertSame( $key, get_option( Settings::OPTION )['api_key'] );
	}

	/**
	 * Guests and other customers cannot resume someone else's order. A guest order can continue.
	 *
	 * @return void
	 */
	public function test_checkout_resume_requires_the_customer_or_a_guest_payment_key() {
		if ( ! function_exists( 'edd_add_order' ) ) {
			$this->markTestSkipped( 'Easy Digital Downloads is not active.' );
		}
		$this->save_settings( str_repeat( 'f', 24 ) );
		update_option( Bootstrap::DB_OPTION, '1', false );
		update_option( 'home', 'https://example.test' );
		update_option( 'siteurl', 'https://example.test' );
		update_option( 'permalink_structure', '/%postname%/' );
		$victim = wp_insert_user(
			array(
				'user_login' => 'finlstpa_victim_' . strtolower( wp_generate_password( 6, false, false ) ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'victim-' . wp_rand() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$other = wp_insert_user(
			array(
				'user_login' => 'finlstpa_other_' . strtolower( wp_generate_password( 6, false, false ) ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'other-' . wp_rand() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$this->assertGreaterThan( 0, (int) $victim );
		$victim_key = 'edd' . strtolower( wp_generate_password( 20, false, false ) );
		$victim_id  = edd_add_order(
			array(
				'status'      => 'pending',
				'type'        => 'sale',
				'gateway'     => Gateway::ID,
				'mode'        => 'live',
				'currency'    => 'USD',
				'subtotal'    => 20,
				'total'       => 20,
				'payment_key' => $victim_key,
				'user_id'     => $victim,
				'email'       => 'buyer@example.com',
			)
		);
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'edd_orders',
			array( 'user_id' => $victim ),
			array( 'id' => $victim_id ),
			array( '%d' ),
			array( '%d' )
		);
		wp_cache_flush();
		wp_set_current_user( 0 );
		$guest_attempt = Gateway::prepare(
			array(
				'price'        => '20.00',
				'purchase_key' => $victim_key,
				'order_id'     => $victim_id,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $guest_attempt );
		$this->assertSame( 'pending', edd_get_order( $victim_id )->status );
		wp_set_current_user( $other );
		$auth_attempt = Gateway::prepare(
			array(
				'price'        => '20.00',
				'purchase_key' => $victim_key,
				'order_id'     => $victim_id,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $auth_attempt );
		$this->assertSame( 'pending', edd_get_order( $victim_id )->status );

		wp_set_current_user( 0 );
		$guest_key = 'edd' . strtolower( wp_generate_password( 20, false, false ) );
		$guest_id  = edd_add_order(
			array(
				'status'      => 'pending',
				'type'        => 'sale',
				'gateway'     => Gateway::ID,
				'mode'        => 'live',
				'currency'    => 'USD',
				'subtotal'    => 20,
				'total'       => 20,
				'payment_key' => $guest_key,
				'user_id'     => 0,
				'email'       => 'guest@example.com',
			)
		);
		$sid       = 'sess_guest_1';
		$hosted    = 'https://pay.finlinks.io/inv/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$filter    = $this->mock_api( $guest_id, $sid, $hosted, '20.00' );
		$continued = Gateway::prepare(
			array(
				'price'        => '20.00',
				'purchase_key' => $guest_key,
				'order_id'     => $victim_id,
			)
		);
		remove_filter( 'pre_http_request', $filter, 10 );
		$this->assertSame( $hosted, $continued );
		$this->assertSame( 'pending', edd_get_order( $victim_id )->status );
		$this->assertSame( '', (string) edd_get_order_meta( $victim_id, Gateway::SESSION_META, true ) );
		$this->assertSame( $sid, (string) edd_get_order_meta( $guest_id, Gateway::SESSION_META, true ) );
	}

	/**
	 * A signed notification cannot complete a different order or a mismatched amount.
	 *
	 * @return void
	 */
	public function test_webhook_does_not_pay_the_wrong_order() {
		if ( ! function_exists( 'edd_add_order' ) ) {
			$this->markTestSkipped( 'Easy Digital Downloads is not active.' );
		}
		$this->save_settings( str_repeat( 'f', 24 ) );
		$first = $this->pending_order();
		$second = $this->pending_order();
		edd_update_order_meta( $first, Gateway::SESSION_META, 'sess_one' );
		edd_update_order_meta( $second, Gateway::SESSION_META, 'sess_two' );
		$mismatch = $this->mock_paid( $first, 'sess_one', '9.00' );
		$this->assertSame( Webhook_Processor::HANDLED, Order_Sync::sync( $first, 'sess_one' ) );
		$this->assertSame( 'pending', edd_get_order( $first )->status );
		$this->assertSame( 'paid_mismatch', (string) edd_get_order_meta( $first, Gateway::LAST_META, true ) );
		$this->assertSame( 'pending', edd_get_order( $second )->status );
		remove_filter( 'pre_http_request', $mismatch, 10 );

		$third = $this->pending_order();
		edd_update_order_meta( $third, Gateway::SESSION_META, 'sess_three' );
		$currency = $this->mock_paid_body(
			$third,
			'sess_three',
			array(
				'amount'   => '20.00',
				'currency' => 'EUR',
			)
		);
		$this->assertSame( Webhook_Processor::HANDLED, Order_Sync::sync( $third, 'sess_three' ) );
		$this->assertSame( 'pending', edd_get_order( $third )->status );
		$this->assertSame( 'paid_mismatch', (string) edd_get_order_meta( $third, Gateway::LAST_META, true ) );
		remove_filter( 'pre_http_request', $currency, 10 );

		$wrong = $this->mock_paid( $second, 'sess_two', '20.00' );
		$this->assertSame( Webhook_Processor::IGNORED, Order_Sync::sync( $first, 'sess_two' ) );
		$this->assertSame( 'pending', edd_get_order( $first )->status );
		$this->assertSame( 'pending', edd_get_order( $second )->status );
		remove_filter( 'pre_http_request', $wrong, 10 );

		$paid = $this->mock_paid( $first, 'sess_one', '20.00' );
		$this->assertSame( Webhook_Processor::HANDLED, Order_Sync::sync( $first, 'sess_one' ) );
		$this->assertSame( 'complete', edd_get_order( $first )->status );
		$this->assertSame( Webhook_Processor::IGNORED, Order_Sync::sync( $first, 'sess_one' ) );
		$this->assertSame( 'pending', edd_get_order( $second )->status );
		remove_filter( 'pre_http_request', $paid, 10 );
	}

	/**
	 * Signed headers for a raw body.
	 *
	 * @param string $secret    Secret.
	 * @param string $body      Raw body.
	 * @param int    $timestamp Unix time.
	 * @return array
	 */
	private function headers( $secret, $body, $timestamp ) {
		return array(
			'finlinks-timestamp' => (string) $timestamp,
			'finlinks-signature' => hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
		);
	}

	/**
	 * Store a live configuration.
	 *
	 * @param string $secret Webhook secret.
	 * @return void
	 */
	private function save_settings( $secret ) {
		update_option(
			Settings::OPTION,
			array(
				'enabled'        => 'yes',
				'debug'          => 'no',
				'api_base'       => '',
				'api_key'        => 'flk_live_' . str_repeat( 'e', 20 ),
				'webhook_secret' => $secret,
			),
			false
		);
	}

	/**
	 * Give the current admin user the EDD settings capability.
	 *
	 * @return void
	 */
	private function grant_shop_manager() {
		$user = get_user_by( 'id', 1 );
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => 'finlstpa_admin',
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => 'finlstpa-admin@example.com',
					'role'       => 'administrator',
				)
			);
			$user    = get_user_by( 'id', $user_id );
		}
		$user->add_cap( 'manage_shop_settings' );
		wp_set_current_user( $user->ID );
	}

	/**
	 * Mock session creation.
	 *
	 * @param int    $order_id Order id.
	 * @param string $sid      Session id.
	 * @param string $hosted   Hosted URL.
	 * @param string $amount   Amount.
	 * @return callable
	 */
	private function mock_api( $order_id, $sid, $hosted, $amount ) {
		$callback = static function ( $pre, $args, $url ) use ( $order_id, $sid, $hosted, $amount ) {
			if ( ! is_string( $url ) || false === strpos( $url, '/api/v1/commerce/sessions' ) || ! is_array( $args ) || 'POST' !== $args['method'] || false !== strpos( $url, '/cancel' ) ) {
				return $pre;
			}
			return array(
				'headers'  => array( 'date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'data'    => array(
							'id'           => $sid,
							'storeOrderId' => 'edd-' . $order_id,
							'livemode'     => true,
							'amount'       => $amount,
							'currency'     => 'USD',
							'hostedUrl'    => $hosted,
							'status'       => 'open',
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	/**
	 * Mock a paid session read.
	 *
	 * @param int    $order_id Order id.
	 * @param string $sid      Session id.
	 * @param string $amount   Amount.
	 * @return callable
	 */
	private function pending_order() {
		return edd_add_order(
			array(
				'status'      => 'pending',
				'type'        => 'sale',
				'gateway'     => Gateway::ID,
				'mode'        => 'live',
				'currency'    => 'USD',
				'subtotal'    => 20,
				'total'       => 20,
				'payment_key' => 'edd' . strtolower( wp_generate_password( 20, false, false ) ),
				'user_id'     => 0,
				'email'       => 'guest@example.com',
			)
		);
	}

	/**
	 * Mock a paid session read with extra fields.
	 *
	 * @param int    $order_id Order id.
	 * @param string $sid      Session id.
	 * @param array  $extra    Session fields.
	 * @return callable
	 */
	private function mock_paid_body( $order_id, $sid, array $extra ) {
		$callback = static function ( $pre, $args, $url ) use ( $order_id, $sid, $extra ) {
			if ( ! is_string( $url ) || false === strpos( $url, '/api/v1/commerce/sessions/' . rawurlencode( $sid ) ) ) {
				return $pre;
			}
			unset( $args );
			$data = array_merge(
				array(
					'id'           => $sid,
					'storeOrderId' => 'edd-' . $order_id,
					'livemode'     => true,
					'amount'       => '20.00',
					'currency'     => 'USD',
					'status'       => 'paid',
					'txHash'       => str_repeat( 'ab', 16 ),
				),
				$extra
			);
			return array(
				'headers'  => array( 'date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'data'    => $data,
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	/**
	 * Mock a paid session read.
	 *
	 * @param int    $order_id Order id.
	 * @param string $sid      Session id.
	 * @param string $amount   Amount.
	 * @return callable
	 */
	private function mock_paid( $order_id, $sid, $amount ) {
		return $this->mock_paid_body(
			$order_id,
			$sid,
			array(
				'amount' => $amount,
			)
		);
	}
}
