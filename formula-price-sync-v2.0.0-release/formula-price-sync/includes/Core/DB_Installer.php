<?php
/**
 * Database installer and schema management.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DB_Installer
 *
 * Handles creation and maintenance of custom database tables.
 */
class DB_Installer {

	/**
	 * Database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for stored DB version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fps_db_version';

	/**
	 * Run on plugin activation.
	 *
	 * Creates the custom price logs table and stores the schema version.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		// Auto-start 30-day trial for testing.
		if ( ! get_option( \FormulaPriceSync\Licensing\Zhaket_Guard::STATUS_OPTION ) || 'valid' !== get_option( \FormulaPriceSync\Licensing\Zhaket_Guard::STATUS_OPTION ) ) {
			\FormulaPriceSync\Licensing\Zhaket_Guard::start_trial();
		}

		// Ensure Action Scheduler tables exist if the library is available.
		if ( function_exists( 'action_scheduler_register_post_type' ) ) {
			// Action Scheduler is already bootstrapped by WooCommerce in most installs.
		}

		// Backfill missing lock meta so LEFT JOIN filter works consistently.
		self::migrate_missing_lock_meta();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Currently does not drop tables to preserve audit history.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left empty – we keep the logs table for historical data.
		// Scheduled actions will be cleaned by Action Scheduler itself.
	}

	/**
	 * Create or update the custom tables using dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'fps_price_logs';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			old_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			new_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			source_rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Maybe upgrade the database schema if version differs.
	 *
	 * Can be called on plugins_loaded or admin_init in later phases.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		// One-time defensive backfill for products that never received _fps_price_locked.
		$migrated = get_option( 'fps_lock_meta_migrated', '' );
		if ( 'yes' !== $migrated ) {
			self::migrate_missing_lock_meta();
			update_option( 'fps_lock_meta_migrated', 'yes', false );
		}
	}

	/**
	 * Backfill _fps_price_locked = 'no' for products that have _fps_enable
	 * but are missing the lock meta entirely.
	 *
	 * Missing meta is treated as unlocked by the LEFT JOIN filter; this
	 * migration makes the data explicit and guards against future INNER JOIN
	 * regressions.
	 *
	 * @return int Number of rows inserted.
	 */
	public static function migrate_missing_lock_meta(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$missing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT e.post_id
				 FROM {$wpdb->postmeta} e
				 LEFT JOIN {$wpdb->postmeta} l
				   ON l.post_id = e.post_id
				  AND l.meta_key = %s
				 WHERE e.meta_key = %s
				   AND e.meta_value = %s
				   AND l.post_id IS NULL",
				'_fps_price_locked',
				'_fps_enable',
				'yes'
			)
		);

		$count = 0;
		foreach ( (array) $missing as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id < 1 ) {
				continue;
			}
			// add_post_meta with unique=true avoids overwriting if a race occurs.
			$added = add_post_meta( $post_id, '_fps_price_locked', 'no', true );
			if ( $added ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Drop the custom table (used only for complete uninstall).
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'fps_price_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		delete_option( self::DB_VERSION_OPTION );
	}
}
