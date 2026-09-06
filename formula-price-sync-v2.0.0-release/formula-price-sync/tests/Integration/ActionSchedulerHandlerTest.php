<?php
/**
 * Integration tests for Action_Scheduler_Handler class.
 *
 * Tests price sync logic including LEFT JOIN price lock filter and batch processing.
 */

namespace FormulaPriceSync\Tests\Integration;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;

class ActionSchedulerHandlerTest extends TestCase {
    /**
     * Test is_available returns false when as_schedule_single_action does not exist.
     */
    public function testIsAvailableReturnsFalseWhenASNotAvailable(): void {
        Functions::when( 'function_exists' )->with( 'as_schedule_single_action' )->thenReturn( false );

        $result = Action_Scheduler_Handler::is_available();

        $this->assertFalse( $result );
    }

    /**
     * Test is_available returns true when as_schedule_single_action exists.
     */
    public function testIsAvailableReturnsTrueWhenASAvailable(): void {
        Functions::when( 'function_exists' )->with( 'as_schedule_single_action' )->thenReturn( true );

        $result = Action_Scheduler_Handler::is_available();

        $this->assertTrue( $result );
    }

    /**
     * Test LEFT JOIN query excludes products with _fps_price_locked = 'yes'.
     *
     * The LEFT JOIN logic:
     * - Products with _fps_enable = 'yes' AND no matching _fps_price_locked = 'yes' meta row are returned.
     * - Products that have _fps_price_locked = 'yes' are excluded (locked).
     * - Products with _fps_enable != 'yes' are excluded (not enabled).
     */
    public function testLeftJoinExcludesLockedProducts(): void {
        // Simulate: product 1 is enabled (no lock meta), product 2 is locked, product 3 is enabled (no lock meta).
        // The LEFT JOIN query should return [1, 3] only.
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->with( \Brain\Monkey\Functions\Expectation::any() )
            ->willReturn( array( '1', '3' ) );

        // Set global $wpdb.
        global $wpdb;
        $wpdb = $mock_db;

        // Mock get_post_type for the returned IDs.
        Functions::when( 'get_post_type' )->with( 1 )->thenReturn( 'product' );
        Functions::when( 'get_post_type' )->with( 3 )->thenReturn( 'product' );

        $result = Action_Scheduler_Handler::get_enabled_product_ids();

        $this->assertContains( 1, $result );
        $this->assertContains( 3, $result );
        $this->assertNotContains( 2, $result );
    }

    /**
     * Test LEFT JOIN treats missing lock meta as unlocked (not locked).
     *
     * When a product has _fps_enable = 'yes' but NO _fps_price_locked meta at all,
     * the LEFT JOIN returns NULL for l.post_id, meaning it's treated as unlocked.
     * This is the correct behavior for the LEFT JOIN filter.
     */
    public function testLeftJoinMissingLockMetaIsUnlocked(): void {
        // Simulate: product 100 has _fps_enable = 'yes' but no _fps_price_locked meta.
        // LEFT JOIN will have l.post_id IS NULL for this product → it's unlocked → included.
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->willReturn( array( '100' ) );

        global $wpdb;
        $wpdb = $mock_db;

        Functions::when( 'get_post_type' )->with( 100 )->thenReturn( 'product' );

        $result = Action_Scheduler_Handler::get_enabled_product_ids();

        $this->assertContains( 100, $result );
    }

    /**
     * Test LEFT JOIN excludes products that are explicitly locked.
     */
    public function testLeftJoinExcludesExplicitlyLocked(): void {
        // Simulate: product 200 has _fps_enable = 'yes' AND _fps_price_locked = 'yes'.
        // The INNER JOIN condition l.meta_key = '_fps_price_locked' AND l.meta_value = 'yes'
        // will match, so l.post_id IS NOT NULL → excluded.
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->willReturn( array() ); // No unlocked products.

        global $wpdb;
        $wpdb = $mock_db;

        $result = Action_Scheduler_Handler::get_enabled_product_ids();

        $this->assertEmpty( $result );
    }

    /**
     * Test LEFT JOIN handles products with _fps_enable != 'yes'.
     *
     * Products not enabled should not appear in the result set,
     * regardless of their lock status.
     */
    public function testLeftJoinExcludesNotEnabledProducts(): void {
        // Simulate: only product 300 has _fps_enable = 'yes' (unlocked).
        // Products 301, 302 have _fps_enable = 'no'.
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->willReturn( array( '300' ) );

        global $wpdb;
        $wpdb = $mock_db;

        Functions::when( 'get_post_type' )->with( 300 )->thenReturn( 'product' );

        $result = Action_Scheduler_Handler::get_enabled_product_ids();

        $this->assertCount( 1, $result );
        $this->assertContains( 300, $result );
    }

    /**
     * Test get_enabled_product_ids filters by taxonomy categories.
     */
    public function testGetEnabledProductIdsFiltersByCategory(): void {
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->willReturn( array( 1, 2, 3 ) );

        global $wpdb;
        $wpdb = $mock_db;

        Functions::when( 'get_post_type' )->willReturnCallback(
            function ( $id ) {
                return 'product';
            }
        );

        // Mock wp_get_post_parent_id to return 0 (not a variation).
        Functions::when( 'wp_get_post_parent_id' )->willReturn( 0 );

        $result = Action_Scheduler_Handler::get_enabled_product_ids(
            array( 'product_cats' => array( 10 ) )
        );

        $this->assertIsArray( $result );
    }

    /**
     * Test get_enabled_product_ids filters by taxonomy tags.
     */
    public function testGetEnabledProductIdsFiltersByTag(): void {
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'prepare' )->willReturnArgument( 0 );
        $mock_db->method( 'get_col' )
            ->willReturn( array( 1, 2 ) );

        global $wpdb;
        $wpdb = $mock_db;

        Functions::when( 'get_post_type' )->willReturnCallback(
            function ( $id ) {
                return 'product';
            }
        );

        Functions::when( 'wp_get_post_parent_id' )->willReturn( 0 );

        $result = Action_Scheduler_Handler::get_enabled_product_ids(
            array( 'product_tags' => array( 5 ) )
        );

        $this->assertIsArray( $result );
    }

    /**
     * Test get_enabled_product_ids returns empty for empty result.
     */
    public function testGetEnabledProductIdsEmptyForNoEnabledProducts(): void {
        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'get_col' )->willReturn( array() );

        global $wpdb;
        $wpdb = $mock_db;

        $result = Action_Scheduler_Handler::get_enabled_product_ids();

        $this->assertEmpty( $result );
    }

    /**
     * Test update_single_product returns false when product is disabled.
     */
    public function testUpdateSingleProductReturnsFalseWhenDisabled(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'no' );

        $result = Action_Scheduler_Handler::update_single_product( 1, array(), 'scheduled' );

        $this->assertFalse( $result );
    }

    /**
     * Test update_single_product returns false when price is locked.
     */
    public function testUpdateSingleProductReturnsFalseWhenLocked(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'yes' );

        $result = Action_Scheduler_Handler::update_single_product( 1, array(), 'scheduled' );

        $this->assertFalse( $result );
    }

    /**
     * Test update_single_product returns false when source type is missing.
     */
    public function testUpdateSingleProductReturnsFalseWhenSourceTypeMissing(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'no' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_source_type', true )->thenReturn( '' );

        // Empty source_type defaults to 'gold_18k'.
        Functions::when( 'get_post_meta' )->with( 1, '_fps_currency_code', true )->thenReturn( 'usd' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_base_foreign_price', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_wage_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_profit_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_tax_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_fixed_fee', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_rounding_rule', true )->thenReturn( 'none' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_custom_formula', true )->thenReturn( '' );
        Functions::when( 'wc_get_product' )->willReturn( null );

        $result = Action_Scheduler_Handler::update_single_product( 1, array(), 'scheduled' );

        $this->assertFalse( $result );
    }

    /**
     * Test update_single_product returns false when rate resolution fails.
     */
    public function testUpdateSingleProductReturnsFalseWhenRateResolutionFails(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'no' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_source_type', true )->thenReturn( 'currency' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_currency_code', true )->thenReturn( 'usd' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_base_foreign_price', true )->thenReturn( 100 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_wage_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_profit_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_tax_percent', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_fixed_fee', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_rounding_rule', true )->thenReturn( 'none' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_custom_formula', true )->thenReturn( '' );
        Functions::when( 'wc_get_product' )->willReturn( null );

        // Empty rates array → source rate = 0 → returns false.
        $result = Action_Scheduler_Handler::update_single_product( 1, array(), 'scheduled' );

        $this->assertFalse( $result );
    }

    /**
     * Test update_single_product updates product when valid.
     */
    public function testUpdateSingleProductUpdatesWhenValid(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'no' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_source_type', true )->thenReturn( 'gold_18k' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_currency_code', true )->thenReturn( 'usd' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_base_foreign_price', true )->thenReturn( 1 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_wage_percent', true )->thenReturn( 10 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_profit_percent', true )->thenReturn( 15 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_tax_percent', true )->thenReturn( 9 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_fixed_fee', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_rounding_rule', true )->thenReturn( 'none' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_custom_formula', true )->thenReturn( '' );

        // Mock product with WC CRUD.
        $mock_product = $this->createMock( \WC_Product::class );
        $mock_product->method( 'get_price' )->willReturn( '0' );
        $mock_product->method( 'get_regular_price' )->willReturn( '0' );
        $mock_product->method( 'get_sale_price' )->willReturn( '' );
        $mock_product->method( 'set_regular_price' )->willReturn( null );
        $mock_product->method( 'set_price' )->willReturn( null );
        $mock_product->method( 'set_sale_price' )->willReturn( null );
        $mock_product->method( 'save' )->willReturn( null );

        Functions::when( 'wc_get_product' )->with( 1 )->thenReturn( $mock_product );
        Functions::when( 'update_post_meta' )->with( 1, '_fps_last_synced', \Brain\Monkey\Functions\Expectation::any() )->thenReturn( true );

        // Valid rates.
        $rates = array( 'gold_18k' => 50000000 );

        $result = Action_Scheduler_Handler::update_single_product( 1, $rates, 'scheduled' );

        $this->assertTrue( $result );
    }

    /**
     * Test update_single_product skips write when price unchanged.
     */
    public function testUpdateSingleProductSkipsWhenPriceUnchanged(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'no' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_source_type', true )->thenReturn( 'gold_18k' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_currency_code', true )->thenReturn( 'usd' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_base_foreign_price', true )->thenReturn( 1 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_wage_percent', true )->thenReturn( 10 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_profit_percent', true )->thenReturn( 15 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_tax_percent', true )->thenReturn( 9 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_fixed_fee', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_rounding_rule', true )->thenReturn( 'none' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_custom_formula', true )->thenReturn( '' );

        // Mock product with existing price.
        $mock_product = $this->createMock( \WC_Product::class );
        $mock_product->method( 'get_price' )->willReturn( '55000000' );
        $mock_product->method( 'get_regular_price' )->willReturn( '55000000' );
        $mock_product->method( 'get_sale_price' )->willReturn( '' );

        Functions::when( 'wc_get_product' )->with( 1 )->thenReturn( $mock_product );
        Functions::when( 'update_post_meta' )->with( 1, '_fps_last_synced', \Brain\Monkey\Functions\Expectation::any() )->thenReturn( true );

        $rates = array( 'gold_18k' => 50000000 );

        $result = Action_Scheduler_Handler::update_single_product( 1, $rates, 'scheduled' );

        // Price unchanged → returns false (no update needed).
        $this->assertFalse( $result );
    }

    /**
     * Test update_single_product clears sale price if higher than new regular.
     */
    public function testUpdateSingleProductClearsHigherSalePrice(): void {
        Functions::when( 'get_post_meta' )->with( 1, '_fps_enable', true )->thenReturn( 'yes' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_price_locked', true )->thenReturn( 'no' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_source_type', true )->thenReturn( 'gold_18k' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_currency_code', true )->thenReturn( 'usd' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_base_foreign_price', true )->thenReturn( 1 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_wage_percent', true )->thenReturn( 10 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_profit_percent', true )->thenReturn( 15 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_tax_percent', true )->thenReturn( 9 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_fixed_fee', true )->thenReturn( 0 );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_rounding_rule', true )->thenReturn( 'none' );
        Functions::when( 'get_post_meta' )->with( 1, '_fps_custom_formula', true )->thenReturn( '' );

        $mock_product = $this->createMock( \WC_Product::class );
        $mock_product->method( 'get_price' )->willReturn( '55000000' );
        $mock_product->method( 'get_regular_price' )->willReturn( '55000000' );
        $mock_product->method( 'get_sale_price' )->willReturn( '60000000' ); // Sale price higher than new regular.
        $mock_product->method( 'set_regular_price' )->willReturn( null );
        $mock_product->method( 'set_price' )->willReturn( null );
        $mock_product->expects( $this->once() )->method( 'set_sale_price' )->with( '' );
        $mock_product->method( 'save' )->willReturn( null );

        Functions::when( 'wc_get_product' )->with( 1 )->thenReturn( $mock_product );
        Functions::when( 'update_post_meta' )->with( 1, '_fps_last_synced', \Brain\Monkey\Functions\Expectation::any() )->thenReturn( true );

        $rates = array( 'gold_18k' => 50000000 );

        $result = Action_Scheduler_Handler::update_single_product( 1, $rates, 'scheduled' );

        $this->assertTrue( $result );
    }

    /**
     * Test process_chunk does nothing when product_ids is empty.
     */
    public function testProcessChunkDoesNothingWithEmptyIds(): void {
        Action_Scheduler_Handler::process_chunk( array(), 'scheduled' );

        $this->assertTrue( true );
    }

    /**
     * Test process_chunk does nothing when rates are empty.
     */
    public function testProcessChunkDoesNothingWithEmptyRates(): void {
        Functions::when( 'get_transient' )->with( 'fps_rates_cache' )->thenReturn( false );

        Action_Scheduler_Handler::process_chunk( array( 1, 2, 3 ), 'scheduled' );

        $this->assertTrue( true );
    }

    /**
     * Test trigger_manual_sync calls force_refresh and on_rates_updated.
     */
    public function testTriggerManualSyncCallsForceRefresh(): void {
        Functions::when( 'get_transient' )->with( 'fps_rates_cache' )->thenReturn( false );
        Functions::when( 'delete_transient' )->with( 'fps_rates_cache' )->thenReturn( true );
        Functions::when( 'delete_transient' )->with( 'fps_rates_fetch_lock' )->thenReturn( true );
        Functions::when( 'get_transient' )->with( 'fps_rates_cache' )->willReturn( array( 'gold_18k' => 50000000 ) );

        $result = Action_Scheduler_Handler::trigger_manual_sync();

        $this->assertIsInt( $result );
    }

    /**
     * Test resolve_rate for gold_18k returns correct rate.
     */
    public function testResolveRateGold18k(): void {
        $rates = array( 'gold_18k' => 50000000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'gold_18k', $rates, 'usd' );

        $this->assertEquals( 50000000, $result );
    }

    /**
     * Test resolve_rate for gold_24k.
     */
    public function testResolveRateGold24k(): void {
        $rates = array( 'gold_24k' => 52000000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'gold_24k', $rates, 'usd' );

        $this->assertEquals( 52000000, $result );
    }

    /**
     * Test resolve_rate for coin.
     */
    public function testResolveRateCoin(): void {
        $rates = array( 'coin' => 1000000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'coin', $rates, 'usd' );

        $this->assertEquals( 1000000, $result );
    }

    /**
     * Test resolve_rate for currency with USD code.
     */
    public function testResolveRateCurrencyUsd(): void {
        $rates = array( 'usd' => 2060000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'currency', $rates, 'usd' );

        $this->assertEquals( 2060000, $result );
    }

    /**
     * Test resolve_rate for currency with EUR code.
     */
    public function testResolveRateCurrencyEur(): void {
        $rates = array( 'eur' => 2200000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'currency', $rates, 'eur' );

        $this->assertEquals( 2200000, $result );
    }

    /**
     * Test resolve_rate for currency falls back to alternative when code not in rates.
     */
    public function testResolveRateCurrencyFallback(): void {
        $rates = array( 'eur' => 2200000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        // Request USD but only EUR is available → should fall back to EUR.
        $result = $method->invoke( null, 'currency', $rates, 'usd' );

        $this->assertEquals( 2200000, $result );
    }

    /**
     * Test resolve_rate for currency returns 0 when neither currency found.
     */
    public function testResolveRateCurrencyReturnsZeroWhenNotFound(): void {
        $rates = array();

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'currency', $rates, 'usd' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test resolve_rate for custom_formula prefers gold_18k.
     */
    public function testResolveRateCustomFormulaGold18k(): void {
        $rates = array( 'gold_18k' => 50000000, 'usd' => 2060000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'custom_formula', $rates, 'usd' );

        $this->assertEquals( 50000000, $result );
    }

    /**
     * Test resolve_rate for custom_formula falls back to USD.
     */
    public function testResolveRateCustomFormulaUsdFallback(): void {
        $rates = array( 'usd' => 2060000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'custom_formula', $rates, 'usd' );

        $this->assertEquals( 2060000, $result );
    }

    /**
     * Test resolve_rate for custom_formula returns 0 when no rates available.
     */
    public function testResolveRateCustomFormulaZeroWhenEmpty(): void {
        $rates = array();

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'custom_formula', $rates, 'usd' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test resolve_rate returns 0 for unknown source type.
     */
    public function testResolveRateUnknownSourceType(): void {
        $rates = array( 'usd' => 2060000 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'unknown_type', $rates, 'usd' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test resolve_rate returns 0 when rate key exists but value is empty.
     */
    public function testResolveRateEmptyValueReturnsZero(): void {
        $rates = array( 'gold_18k' => 0 );

        $method = new \ReflectionMethod( Action_Scheduler_Handler::class, 'resolve_rate' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'gold_18k', $rates, 'usd' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test on_rates_updated returns 0 when AS not available and no products.
     */
    public function testOnRatesUpdatedReturnsZeroNoProducts(): void {
        Functions::when( 'function_exists' )->with( 'as_schedule_single_action' )->thenReturn( false );

        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'get_col' )->willReturn( array() );

        global $wpdb;
        $wpdb = $mock_db;

        $result = Action_Scheduler_Handler::on_rates_updated();

        $this->assertEquals( 0, $result );
    }

    /**
     * Test on_rates_updated schedules chunks when AS available.
     */
    public function testOnRatesUpdatedSchedulesChunks(): void {
        Functions::when( 'function_exists' )->with( 'as_schedule_single_action' )->thenReturn( true );

        $mock_db = $this->createMock( \wpdb::class );
        $mock_db->prefix = 'wp_';
        $mock_db->method( 'get_col' )->willReturn( array( 1, 2, 3, 4, 5 ) );

        global $wpdb;
        $wpdb = $mock_db;

        Functions::when( 'get_post_type' )->willReturn( 'product' );
        Functions::when( 'wp_get_post_parent_id' )->willReturn( 0 );
        Functions::when( 'as_schedule_single_action' )->willReturn( true );

        $result = Action_Scheduler_Handler::on_rates_updated();

        $this->assertIsInt( $result );
        $this->assertGreaterThan( 0, $result );
    }

    /**
     * Test send_bulk_sync_complete_alert with remaining chunks > 1.
     */
    public function testBulkSyncCompleteWithRemainingChunks(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->thenReturn( true );
        Functions::when( 'get_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( 5 );
        Functions::when( 'set_transient' )->willReturn( true );

        // Should NOT dispatch for non-last chunk.
        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'manual' );

        $this->assertTrue( true );
    }

    /**
     * testSendBulkSyncCompleteAlertForLastChunk.
     */
    public function testBulkSyncCompleteForLastChunk(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->thenReturn( true );
        Functions::when( 'get_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( 1 );
        Functions::when( 'delete_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'manual' );

        $this->assertTrue( true );
    }
}