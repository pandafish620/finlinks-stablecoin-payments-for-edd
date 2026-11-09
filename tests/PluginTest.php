<?php
/**
 * Regression tests for the prefix migration and payment checks.
 *
 * @package Finlstpa\EDD
 */

use Finlstpa\EDD\Amount;
use Finlstpa\EDD\Api_Base;
use Finlstpa\EDD\Bootstrap;
use Finlstpa\EDD\Credentials;
use Finlstpa\EDD\Event_Store;
use Finlstpa\EDD\Gateway;
use Finlstpa\EDD\Host_Policy;
use Finlstpa\EDD\Hosted_Url;
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
	private function mock_paid( $order_id, $sid, $amount ) {
		$callback = static function ( $pre, $args, $url ) use ( $order_id, $sid, $amount ) {
			if ( false === strpos( $url, '/api/v1/commerce/sessions/' . rawurlencode( $sid ) ) ) {
				return $pre;
			}
			unset( $args );
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
							'status'       => 'paid',
							'txHash'       => str_repeat( 'ab', 16 ),
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
}
