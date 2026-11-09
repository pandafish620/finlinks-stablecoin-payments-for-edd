<?php
/**
 * Load WordPress and the plugin for PHPUnit.
 *
 * @package Finlstpa\EDD
 */

$finlstpa_root = getenv( 'WP_ROOT' );
if ( ! is_string( $finlstpa_root ) || '' === $finlstpa_root ) {
	fwrite( STDERR, "WP_ROOT must point at a WordPress install where this plugin is active.\n" );
	exit( 1 );
}

require rtrim( $finlstpa_root, '/' ) . '/wp-load.php';

if ( ! defined( 'FINLSTPA_VERSION' ) ) {
	fwrite( STDERR, "Plugin was not loaded from WP_ROOT.\n" );
	exit( 1 );
}

add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message ) {
			$text = '';
			if ( is_string( $message ) ) {
				$text = wp_strip_all_tags( $message );
			} elseif ( is_object( $message ) && method_exists( $message, 'get_error_message' ) ) {
				$text = $message->get_error_message();
			}
			throw new RuntimeException( 'wp_die:' . $text );
		};
	}
);

add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	},
	1
);
