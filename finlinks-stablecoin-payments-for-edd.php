<?php
/**
 * Plugin Name: FinLinks Stablecoin Payments for EDD
 * Description: Accept USDC through FinLinks hosted checkout with verified payment confirmation.
 * Version: 1.1.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: easy-digital-downloads
 * Author: Finlinks Global Solutions LLC
 * Author URI: https://www.finlinksglobal.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: finlinks-stablecoin-payments-for-edd
 * Domain Path: /languages
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

define( 'FINLSTPA_VERSION', '1.1.1' );
define( 'FINLSTPA_FILE', __FILE__ );
define( 'FINLSTPA_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINLSTPA_URL', plugin_dir_url( __FILE__ ) );

( static function () {
	foreach ( array( 'amount', 'host-policy', 'api-base', 'credentials', 'hosted-url', 'signature', 'timing', 'session-decider', 'messages', 'logger', 'api-client' ) as $core ) {
		require_once FINLSTPA_DIR . 'includes/core/class-' . $core . '.php';
	}
	require_once FINLSTPA_DIR . 'includes/core/interface-event-claim-store.php';
	require_once FINLSTPA_DIR . 'includes/core/class-memory-event-store.php';
	require_once FINLSTPA_DIR . 'includes/core/class-webhook-processor.php';
	foreach ( array( 'lock', 'event-store', 'settings', 'checkout-diag', 'gateway', 'order-sync', 'migrate', 'bootstrap' ) as $adapter ) {
		require_once FINLSTPA_DIR . 'includes/class-' . $adapter . '.php';
	}
} )();

register_activation_hook( __FILE__, array( Bootstrap::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Bootstrap::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Bootstrap::class, 'init' ), 20 );
