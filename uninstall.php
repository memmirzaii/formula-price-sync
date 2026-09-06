<?php
/**
 * Uninstall Formula Price Sync.
 *
 * @package FormulaPriceSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete options.
$options = array(
	'fps_db_version',
	'fps_license_key',
	'fps_license_status',
	'fps_manual_rates',
	'fps_last_accepted_rates',
	'fps_navasan_api_key',
	'fps_options',
	'fps_rastchin_status',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete all fps transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_fps_%'
	    OR option_name LIKE '_transient_timeout_fps_%'"
);

// Drop custom table.
$table = $wpdb->prefix . 'fps_price_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove all product meta keys.
$meta_keys = array(
	'_fps_enable',
	'_fps_source_type',
	'_fps_base_foreign_price',
	'_fps_wage_percent',
	'_fps_profit_percent',
	'_fps_tax_percent',
	'_fps_fixed_fee',
	'_fps_rounding_rule',
	'_fps_custom_formula',
	'_fps_last_synced',
);

foreach ( $meta_keys as $key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) );
}
