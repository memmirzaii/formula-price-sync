<?php
/**
 * Action Scheduler batch price synchronization.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Queue;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\Core\Cache_Purger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Action_Scheduler_Handler
 *
 * Handles background bulk price updates via Action Scheduler in chunks of 50.
 */
class Action_Scheduler_Handler {

	/**
	 * Action hook name for processing a chunk.
	 *
	 * @var string
	 */
	const CHUNK_ACTION = 'fps_process_product_chunk';

	/**
	 * Chunk size.
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 50;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register the worker.
		add_action( self::CHUNK_ACTION, array( __CLASS__, 'process_chunk' ), 10, 2 );

		// Optional: schedule periodic rate check (can be controlled from settings later).
		add_action( 'fps_scheduled_rate_sync', array( __CLASS__, 'on_rates_updated' ) );
	}

	/**
	 * Check whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Entry point when rates have been updated.
	 *
	 * Fetches all products/variations with `_fps_enable = yes` and schedules
	 * background chunks of 50 IDs each.
	 *
	 * @param string $trigger_type How the update was triggered (scheduled|manual|api_webhook).
	 * @param array  $filters      Optional. { product_cats: int[], product_tags: int[] }
	 * @return int Number of scheduled chunks.
	 */
	public static function on_rates_updated( string $trigger_type = 'scheduled', array $filters = array() ): int {
		if ( ! self::is_available() ) {
			// Fallback: process synchronously in smaller batches if AS is missing.
			return self::process_synchronously( $trigger_type, $filters );
		}

		$ids = self::get_enabled_product_ids( $filters );

		if ( empty( $ids ) ) {
			return 0;
		}

		$chunks          = array_chunk( $ids, self::CHUNK_SIZE );
		$scheduled_count = 0;
		$timestamp       = time();

		foreach ( $chunks as $index => $chunk ) {
			// Stagger slightly so we don't hammer the DB at the exact same second.
			$run_at = $timestamp + ( $index * 5 );

			as_schedule_single_action(
				$run_at,
				self::CHUNK_ACTION,
				array(
					'product_ids'  => $chunk,
					'trigger_type' => $trigger_type,
				),
				'fps-price-sync'
			);

			++$scheduled_count;
		}

		return $scheduled_count;
	}

	/**
	 * Update price for a single product or variation.
	 *
	 * @param int    $product_id   Product or variation ID.
	 * @param array  $rates        Current rates from API_Manager.
	 * @param string $trigger_type Audit trigger.
	 * @return bool True if price was changed.
	 */
	public static function update_single_product( int $product_id, array $rates, string $trigger_type = 'scheduled' ): bool {
		$enable = get_post_meta( $product_id, '_fps_enable', true );
		if ( 'yes' !== $enable ) {
			return false;
		}

		$locked = get_post_meta( $product_id, '_fps_price_locked', true );
		if ( 'yes' === $locked ) {
			return false;
		}

		$source_type = get_post_meta( $product_id, '_fps_source_type', true );
		if ( empty( $source_type ) ) {
			$source_type = 'gold_18k';
		}

		// Resolve the correct rate for this source type.
		$source_rate = self::resolve_rate( $source_type, $rates, (string) ( get_post_meta( $product_id, '_fps_currency_code', true ) ?: 'usd' ) );
		if ( $source_rate <= 0 ) {
			return false;
		}

		$meta_data = array(
			'source_type'        => $source_type,
			'currency_code'      => (string) ( get_post_meta( $product_id, '_fps_currency_code', true ) ?: 'usd' ),
			'weight'             => (float) get_post_meta( $product_id, '_fps_base_foreign_price', true ),
			'base_foreign_price' => (float) get_post_meta( $product_id, '_fps_base_foreign_price', true ),
			'wage_percent'       => (float) get_post_meta( $product_id, '_fps_wage_percent', true ),
			'profit_percent'     => (float) get_post_meta( $product_id, '_fps_profit_percent', true ),
			'tax_percent'        => (float) get_post_meta( $product_id, '_fps_tax_percent', true ) ?: 9.0,
			'fixed_fee'          => (float) get_post_meta( $product_id, '_fps_fixed_fee', true ),
			'rounding_rule'      => get_post_meta( $product_id, '_fps_rounding_rule', true ) ?: 'none',
			'custom_formula'     => (string) get_post_meta( $product_id, '_fps_custom_formula', true ),
		);

		$calculation = Calculator::calculate_price( $meta_data, $source_rate );
		$new_price   = $calculation['final_price'];

		if ( $new_price <= 0 ) {
			return false;
		}

		// Prefer WC CRUD for HPOS / lookup-table compatibility.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$old_price = (float) $product->get_price( 'edit' );

		// Only write if changed (avoid unnecessary DB writes + cache thrashing).
		if ( (string) $product->get_regular_price( 'edit' ) === (string) $new_price
			&& (string) $product->get_price( 'edit' ) === (string) $new_price ) {
			update_post_meta( $product_id, '_fps_last_synced', current_time( 'mysql' ) );
			return false;
		}

		$product->set_regular_price( $new_price );
		$product->set_price( $new_price );

		// Clear sale price if it was higher than new regular (safety).
		$sale_price = $product->get_sale_price( 'edit' );
		if ( '' !== $sale_price && null !== $sale_price && (float) $sale_price > $new_price ) {
			$product->set_sale_price( '' );
		}

		$product->save();

		update_post_meta( $product_id, '_fps_last_synced', current_time( 'mysql' ) );

		// Audit log.
		self::insert_audit_log(
			$product_id,
			(float) $old_price,
			$new_price,
			$source_rate,
			$trigger_type
		);

		// Clear product-specific caches.
		Cache_Purger::purge_product( $product_id );

		/**
		 * Fires after a single product price has been updated.
		 *
		 * @param int   $product_id Product ID.
		 * @param float $old_price  Previous price.
		 * @param float $new_price  New price.
		 * @param array $calculation Full calculation result.
		 */
		do_action( 'fps_product_price_updated', $product_id, (float) $old_price, $new_price, $calculation );

		return true;
	}

	/**
	 * Resolve the numeric rate for a given source type.
	 *
	 * @param string $source_type Source type key.
	 * @param array  $rates       Rates array from API_Manager.
	 * @return float
	 */
	private static function resolve_rate( string $source_type, array $rates, string $currency_code = 'usd' ): float {
		switch ( $source_type ) {
			case 'gold_18k':
				return isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0.0;

			case 'gold_24k':
				return isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0.0;

			case 'coin':
				return isset( $rates['coin'] ) ? (float) $rates['coin'] : 0.0;

			case 'currency':
				$code = in_array( $currency_code, array( 'usd', 'eur' ), true ) ? $currency_code : 'usd';
				if ( ! empty( $rates[ $code ] ) ) {
					return (float) $rates[ $code ];
				}
				// Fallback: the other currency if preferred is missing.
				$alt = ( 'usd' === $code ) ? 'eur' : 'usd';
				if ( ! empty( $rates[ $alt ] ) ) {
					return (float) $rates[ $alt ];
				}
				return 0.0;

			case 'custom_formula':
				// Prefer gold_18k as default rate token for formulas.
				if ( ! empty( $rates['gold_18k'] ) ) {
					return (float) $rates['gold_18k'];
				}
				if ( ! empty( $rates['usd'] ) ) {
					return (float) $rates['usd'];
				}
				return 0.0;

			default:
				return 0.0;
		}
	}

	/**
	 * Insert a row into the audit log table.
	 *
	 * @param int    $product_id   Product or variation ID.
	 * @param float  $old_price    Previous price.
	 * @param float  $new_price    New price.
	 * @param float  $source_rate  Rate used for calculation.
	 * @param string $trigger_type Trigger type.
	 * @return void
	 */
	private static function insert_audit_log(
		int $product_id,
		float $old_price,
		float $new_price,
		float $source_rate,
		string $trigger_type
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fps_price_logs';

		// Determine if this is a variation.
		$post_type     = get_post_type( $product_id );
		$variation_id  = 0;
		$parent_id     = $product_id;

		if ( 'product_variation' === $post_type ) {
			$variation_id = $product_id;
			$parent_id    = wp_get_post_parent_id( $product_id );
			if ( ! $parent_id ) {
				$parent_id = $product_id;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'product_id'   => $parent_id,
				'variation_id' => $variation_id,
				'old_price'    => $old_price,
				'new_price'    => $new_price,
				'source_rate'  => $source_rate,
				'trigger_type' => sanitize_key( $trigger_type ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%f', '%f', '%f', '%s', '%s' )
		);
	}

	/**
	 * Get all product and variation IDs that have auto-pricing enabled.
	 *
	 * @param array $args {
	 *     Optional. Filtering arguments.
	 *
	 *     @type int[] $product_cats Product category term IDs to restrict to.
	 *     @type int[] $product_tags Product tag term IDs to restrict to.
	 * }
	 * @return int[]
	 */
	public static function get_enabled_product_ids( array $args = array() ): array {
		global $wpdb;

		$categories = isset( $args['product_cats'] ) && is_array( $args['product_cats'] )
			? array_filter( array_map( 'absint', $args['product_cats'] ) )
			: array();
		$tags = isset( $args['product_tags'] ) && is_array( $args['product_tags'] )
			? array_filter( array_map( 'absint', $args['product_tags'] ) )
			: array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// LEFT JOIN: missing _fps_price_locked meta is treated as unlocked (not locked).
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT e.post_id
				 FROM {$wpdb->postmeta} e
				 LEFT JOIN {$wpdb->postmeta} l
				   ON l.post_id = e.post_id
				  AND l.meta_key = %s
				  AND l.meta_value = %s
				 WHERE e.meta_key = %s
				   AND e.meta_value = %s
				   AND l.post_id IS NULL",
				'_fps_price_locked',
				'yes',
				'_fps_enable',
				'yes'
			)
		);

		$ids = array_map( 'absint', (array) $ids );
		$ids = array_filter( $ids );

		$restricted_ids = array();
		if ( ! empty( $categories ) || ! empty( $tags ) ) {
			$restricted_ids = self::filter_ids_by_taxonomy( $ids, $categories, $tags );
			if ( null === $restricted_ids ) {
				$ids = array();
			} else {
				$ids = $restricted_ids;
			}
		}

		// Keep only existing products/variations.
		$valid = array();
		foreach ( $ids as $id ) {
			$type = get_post_type( $id );
			if ( in_array( $type, array( 'product', 'product_variation' ), true ) ) {
				$valid[] = $id;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Filter a list of product/variation IDs by category and tag taxonomy.
	 *
	 * For variation IDs, the parent product's taxonomies are used.
	 *
	 * @param int[] $ids        Candidate IDs.
	 * @param int[] $categories Category term IDs (empty = no category filter).
	 * @param int[] $tags       Tag term IDs (empty = no tag filter).
	 * @return int[]|null Filtered IDs, or null when no candidates match.
	 */
	private static function filter_ids_by_taxonomy( array $ids, array $categories, array $tags ): ?array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$product_ids = array();
		foreach ( $ids as $id ) {
			$type = get_post_type( $id );
			if ( 'product_variation' === $type ) {
				$parent = wp_get_post_parent_id( $id );
				if ( $parent ) {
					$product_ids[ $parent ] = true;
				}
			} elseif ( 'product' === $type ) {
				$product_ids[ $id ] = true;
			}
		}

		if ( empty( $product_ids ) ) {
			return array();
		}

		$product_id_list = array_keys( $product_ids );
		$placeholders     = implode( ',', array_fill( 0, count( $product_id_list ), '%d' ) );

		$clauses = array();
		$params  = array();

		if ( ! empty( $categories ) ) {
			$cat_placeholders = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
			$clauses[]        = "( tt.taxonomy = 'product_cat' AND tt.term_id IN ( $cat_placeholders ) )";
			$params           = array_merge( $params, $categories );
		}

		if ( ! empty( $tags ) ) {
			$tag_placeholders = implode( ',', array_fill( 0, count( $tags ), '%d' ) );
			$clauses[]        = "( tt.taxonomy = 'product_tag' AND tt.term_id IN ( $tag_placeholders ) )";
			$params           = array_merge( $params, $tags );
		}

		if ( empty( $clauses ) ) {
			return $ids;
		}

		$where = implode( ' OR ', $clauses );
		$query = "SELECT DISTINCT tr.object_id
				  FROM {$wpdb->term_relationships} tr
				  INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				  WHERE tr.object_id IN ( $placeholders ) AND ( $where )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$matching_products = $wpdb->get_col( $wpdb->prepare( $query, array_merge( $product_id_list, $params ) ) );
		$matching_products = array_map( 'absint', (array) $matching_products );
		$matching_products = array_flip( $matching_products );

		$filtered = array();
		foreach ( $ids as $id ) {
			$type = get_post_type( $id );
			if ( 'product_variation' === $type ) {
				$parent = wp_get_post_parent_id( $id );
				if ( $parent && isset( $matching_products[ $parent ] ) ) {
					$filtered[] = $id;
				}
			} elseif ( 'product' === $type ) {
				if ( isset( $matching_products[ $id ] ) ) {
					$filtered[] = $id;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Synchronous fallback when Action Scheduler is not available.
	 *
	 * @param string $trigger_type Trigger type.
	 * @param array  $filters      Optional filter arguments.
	 * @return int Number of chunks processed.
	 */
	private static function process_synchronously( string $trigger_type, array $filters = array() ): int {
		$ids    = self::get_enabled_product_ids( $filters );
		$chunks = array_chunk( $ids, self::CHUNK_SIZE );
		$count  = 0;

		foreach ( $chunks as $chunk ) {
			self::process_chunk( $chunk, $trigger_type, $filters );
			++$count;
		}

		return $count;
	}

	/**
	 * Worker: process one chunk of product IDs.
	 *
	 * @param array  $product_ids  Array of product/variation IDs.
	 * @param string $trigger_type Trigger type for audit log.
	 * @param array  $filters      Optional filter arguments.
	 * @return void
	 */
	public static function process_chunk( array $product_ids, string $trigger_type = 'scheduled', array $filters = array() ): void {
		if ( empty( $product_ids ) ) {
			return;
		}

		$api_manager = new API_Manager();
		$rates       = $api_manager->get_rates();

		if ( empty( $rates ) ) {
			return;
		}

		$updated = 0;

		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 ) {
				continue;
			}

			$result = self::update_single_product( $product_id, $rates, $trigger_type );
			if ( $result ) {
				++$updated;
			}
		}

		// After the whole chunk is done, purge caches once.
		if ( $updated > 0 ) {
			Cache_Purger::purge_all();
		}

		/**
		 * Fires after a chunk has been processed.
		 *
		 * @param array  $product_ids  Processed IDs.
		 * @param int    $updated      How many were actually updated.
		 * @param string $trigger_type Trigger type.
		 */
		do_action( 'fps_chunk_processed', $product_ids, $updated, $trigger_type );
	}

	/**
	 * Manually trigger a full sync (for admin button).
	 *
	 * @param array $filters Optional. { product_cats: int[], product_tags: int[] }
	 * @return int Number of scheduled/processed chunks.
	 */
	public static function trigger_manual_sync( array $filters = array() ): int {
		// Force a fresh rate fetch first.
		$api = new API_Manager();
		$api->force_refresh();

		return self::on_rates_updated( 'manual', $filters );
	}
}
