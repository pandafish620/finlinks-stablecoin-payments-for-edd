<?php
/**
 * Plugin Name: FinLinks Stablecoin Payments for Easy Digital Downloads
 * Description: Accept USDC through FinLinks hosted checkout with verified payment confirmation.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: easy-digital-downloads
 * Author: FinLinks
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: finlinks-stablecoin-payments-for-edd
 * Domain Path: /languages
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

define( 'FINLINKS_EDD_VERSION', '1.0.0' );
define( 'FINLINKS_EDD_FILE', __FILE__ );
define( 'FINLINKS_EDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINLINKS_EDD_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'amount', 'api-base', 'credentials', 'hosted-url', 'signature', 'timing', 'session-decider', 'messages', 'logger', 'api-client' ) as $finlinks_edd_core ) {
	require_once FINLINKS_EDD_DIR . 'includes/core/class-finlinks-' . $finlinks_edd_core . '.php';
}
require_once FINLINKS_EDD_DIR . 'includes/core/interface-finlinks-event-store.php';
require_once FINLINKS_EDD_DIR . 'includes/core/class-finlinks-webhook-processor.php';
foreach ( array( 'lock', 'event-store', 'settings', 'gateway', 'order-sync', 'bootstrap' ) as $finlinks_edd_adapter ) {
	require_once FINLINKS_EDD_DIR . 'includes/class-' . $finlinks_edd_adapter . '.php';
}
unset( $finlinks_edd_core, $finlinks_edd_adapter );

register_activation_hook( __FILE__, array( Bootstrap::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Bootstrap::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Bootstrap::class, 'init' ), 20 );

