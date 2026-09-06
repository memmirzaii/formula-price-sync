<?php
/**
 * Bootstrap file for Formula Price Sync tests.
 *
 * Sets up WordPress environment and autoloading for PHPUnit tests.
 */

// Define ABSPATH if not defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Define WP_DEBUG for testing
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// Define FPS_VERSION for testing
if ( ! defined( 'FPS_VERSION' ) ) {
	define( 'FPS_VERSION', '2.0.0' );
}

// Load Composer autoloader
$composer_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoloader ) ) {
	require_once $composer_autoloader;
}

// Load WordPress test environment if available
if ( file_exists( dirname( __DIR__ ) . '/tests/wp-tests-config.php' ) ) {
	require_once dirname( __DIR__ ) . '/tests/wp-tests-config.php';
} elseif ( file_exists( dirname( __DIR__ ) . '/wp-tests-config.php' ) ) {
	require_once dirname( __DIR__ ) . '/wp-tests-config.php';
}

// Load Brain Monkey for WordPress mocking
if ( class_exists( 'Brain\Monkey\Functions' ) ) {
	\Brain\Monkey\Functions::when( 'add_action' )->alias( function( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return true;
	} );
	\Brain\Monkey\Functions::when( 'add_filter' )->alias( function( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return true;
	} );
	\Brain\Monkey\Functions::when( 'do_action' )->alias( function( $tag, ...$args ) {
		return null;
	} );
	\Brain\Monkey\Functions::when( 'apply_filters' )->alias( function( $tag, $value, ...$args ) {
		return $value;
	} );
}