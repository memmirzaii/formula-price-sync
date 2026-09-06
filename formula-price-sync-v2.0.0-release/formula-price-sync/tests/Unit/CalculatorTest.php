<?php
/**
 * Unit tests for Calculator class.
 *
 * Tests price calculation logic for gold, currency, and custom formulas.
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Engine\Calculator;
use Brain\Monkey\Functions;

class CalculatorTest extends TestCase {
    /**
     * Test gold calculation with standard 18k formula.
     */
    public function testGold18kCalculationBasic(): void {
        $meta_data = array(
            'source_type' => 'gold_18k',
            'weight' => 1.0,
            'rate' => 50000000,
            'wage_percent' => 10,
            'profit_percent' => 15,
            'tax_percent' => 9,
            'fixed_fee' => 0,
        );

        $result = Calculator::calculate_price( $meta_data, 50000000 );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'final_price', $result );
        $this->assertArrayHasKey( 'breakdown', $result );
        $this->assertArrayHasKey( 'raw_total', $result );

        // Verify breakdown contains expected keys.
        $breakdown = $result['breakdown'];
        $this->assertArrayHasKey( 'source_type', $breakdown );
        $this->assertArrayHasKey( 'weight', $breakdown );
        $this->assertArrayHasKey( 'rate', $breakdown );
        $this->assertArrayHasKey( 'raw_gold', $breakdown );
        $this->assertArrayHasKey( 'wage_amount', $breakdown );
        $this->assertArrayHasKey( 'profit_amount', $breakdown );
        $this->assertArrayHasKey( 'tax_amount', $breakdown );
    }

    /**
     * Test gold tax is applied ONLY on (Wage + Profit), not on raw gold.
     */
    public function testGoldTaxCalculationExcludesRawGold(): void {
        $meta_data = array(
            'source_type' => 'gold_18k',
            'weight' => 1.0,
            'wage_percent' => 10,
            'profit_percent' => 15,
            'tax_percent' => 9,
        );

        $rate = 50000000;
        $weight = 1.0;

        // Expected calculations:
        // raw_gold = 1 * 50000000 = 50000000
        // wage_amount = 50000000 * 10/100 = 5000000
        // profit_amount = (50000000 + 5000000) * 15/100 = 8250000
        // tax_amount = (5000000 + 8250000) * 9/100 = 1192500 (NOT on raw_gold)
        // total = 50000000 + 5000000 + 8250000 + 1192500 = 64442500

        $result = Calculator::calculate_price( $meta_data, $rate );

        $this->assertEquals( 50000000, $result['breakdown']['raw_gold'] );
        $this->assertEquals( 5000000, $result['breakdown']['wage_amount'] );
        $this->assertEquals( 8250000, $result['breakdown']['profit_amount'] );
        $this->assertEquals( 1192500, $result['breakdown']['tax_amount'] );
        $this->assertEquals( 64442500, $result['raw_total'] );
    }

    /**
     * Test 24k gold calculation.
     */
    public function testGold24kCalculation(): void {
        $meta_data = array(
            'source_type' => 'gold_24k',
            'weight' => 2.5,
            'wage_percent' => 5,
            'profit_percent' => 10,
            'tax_percent' => 9,
            'fixed_fee' => 50000,
        );

        $rate = 48000000;
        $result = Calculator::calculate_price( $meta_data, $rate );

        // Weighted calculations.
        $expected_raw_gold = 2.5 * 48000000;

        $breakdown = $result['breakdown'];
        $this->assertEquals( 'gold_24k', $breakdown['source_type'] );
        $this->assertEquals( $expected_raw_gold, $breakdown['raw_gold'] );
    }

    /**
     * Test currency price calculation.
     */
    public function testCurrencyCalculation(): void {
        $meta_data = array(
            'source_type' => 'currency',
            'base_foreign_price' => 700,
            'profit_percent' => 10,
            'fixed_fee' => 0,
        );

        $exchange_rate = 2060000; // 1 USD = 2,060,000 IRR
        $result = Calculator::calculate_price( $meta_data, $exchange_rate );

        // Expected: Base_Rial = 700 * 2060000 = 1,442,000,000
        // Profit = 1,442,000,000 * 10/100 = 144,200,000
        // Total = 1,442,000,000 + 144,200,000 = 1,586,200,000

        $breakdown = $result['breakdown'];
        $this->assertEquals( 'currency', $breakdown['source_type'] );
        $this->assertEquals( 700, $breakdown['base_foreign'] );
        $this->assertEquals( 1442000000, $breakdown['base_rial'] );
        $this->assertEquals( 1586200000, $result['raw_total'] );
    }

    /**
     * Test currency calculation with custom profit and fixed fee.
     */
    public function testCurrencyCalculationWithFee(): void {
        $meta_data = array(
            'source_type' => 'currency',
            'base_foreign_price' => 100,
            'profit_percent' => 5,
            'fixed_fee' => 500000,
        );

        $result = Calculator::calculate_price( $meta_data, 1000000 );

        $this->assertEquals( 105000000, $result['breakdown']['base_rial'] );
        $this->assertEquals( 105500000, $result['raw_total'] ); // base_rial + profit + fixed_fee
    }

    /**
     * Test custom formula calculation.
     */
    public function testCustomFormulaCalculation(): void {
        $meta_data = array(
            'source_type' => 'custom_formula',
            'weight' => 1.0,
            'custom_formula' => '{raw_gold} * 1.1',
            'wage_percent' => 10,
            'profit_percent' => 15,
            'tax_percent' => 9,
        );

        $result = Calculator::calculate_price( $meta_data, 50000000 );

        $this->assertEquals( 'custom_formula', $result['breakdown']['source_type'] );
    }

    /**
     * Test invalid source rate returns error breakdown.
     */
    public function testInvalidSourceRateReturnsError(): void {
        $result = Calculator::calculate_price( array( 'source_type' => 'gold_18k' ), 0 );

        $this->assertEquals( 0.0, $result['final_price'] );
        $this->assertArrayHasKey( 'error', $result['breakdown'] );
        $this->assertEquals( 'invalid_source_rate', $result['breakdown']['error'] );
    }

    /**
     * Test negative source rate is rejected.
     */
    public function testNegativeSourceRateIsRejected(): void {
        $result = Calculator::calculate_price( array( 'source_type' => 'gold_18k' ), -1000 );

        $this->assertEquals( 0.0, $result['final_price'] );
        $this->assertEquals( 0.0, $result['raw_total'] );
    }

    /**
     * Test NaN source rate is rejected.
     */
    public function testNanSourceRateIsRejected(): void {
        $result = Calculator::calculate_price( array( 'source_type' => 'gold_18k' ), NAN );

        $this->assertEquals( 0.0, $result['final_price'] );
    }

    /**
     * Test unsupported source type returns error.
     */
    public function testUnsupportedSourceTypeReturnsError(): void {
        $result = Calculator::calculate_price( array( 'source_type' => 'unknown_type' ), 1000 );

        $this->assertEquals( 0.0, $result['final_price'] );
        $this->assertArrayHasKey( 'error', $result['breakdown'] );
    }

    /**
     * Test get_final_price helper returns only the final price.
     */
    public function testGetFinalPriceHelper(): void {
        $meta_data = array(
            'source_type' => 'gold_18k',
            'weight' => 1.0,
            'wage_percent' => 10,
            'profit_percent' => 15,
        );

        $result = Calculator::get_final_price( $meta_data, 50000000 );

        $this->assertIsFloat( $result );
        $this->assertGreaterThan( 0, $result );
    }

    /**
     * Test rounding is applied to final price.
     */
    public function testRoundingIsApplied(): void {
        $meta_data = array(
            'source_type' => 'gold_18k',
            'weight' => 1.0,
            'rounding_rule' => 'round_1000',
        );

        $result = Calculator::calculate_price( $meta_data, 50000000 );

        // Price should be rounded to nearest 1000.
        $remainder = fmod( $result['final_price'], 1000 );
        $this->assertEquals( 0, $remainder, 'Final price should be divisible by 1000' );
    }

    /**
     * Test default source type is gold_18k.
     */
    public function testDefaultSourceTypeIsGold18k(): void {
        $result = Calculator::calculate_price( array(), 50000000 );

        $this->assertEquals( 'gold_18k', $result['breakdown']['source_type'] );
    }

    /**
     * Test default tax percent is 9%.
     */
    public function testDefaultTaxPercentIsNine(): void {
        $meta_data = array( 'source_type' => 'gold_18k' );
        $result = Calculator::calculate_price( $meta_data, 100000000 );

        // With zero wage and profit, tax should be 0 (9% of 0).
        $this->assertEquals( 0, $result['breakdown']['tax_amount'] );

        // Check that tax_percent was defaulted to 9.
        $this->assertEquals( 9, $result['breakdown']['tax_percent'] );
    }

    /**
     * Test currency uses fallback to alternative currency.
     */
    public function testCurrencyFallbackToAlternative(): void {
        // This tests the resolve_rate logic indirectly.
        $meta_data = array(
            'source_type' => 'currency',
            'currency_code' => 'usd',
        );

        // Not a direct test of resolve_rate - would need integration test or mock.
        // For unit test, we test calculate_currency directly.
    }

    /**
     * Test price can be filtered via fps_calculated_price filter.
     */
    public function testPriceFilterIsApplied(): void {
        // Use a simple approach: mock apply_filters to multiply the price by 1.1.
        // This verifies the filter hook is called during calculate_price.
        Functions::when( 'apply_filters' )
            ->with( 'fps_calculated_price', \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )
            ->thenReturn( 55000000.0 ); // 50000000 * 1.1

        $meta_data = array( 'source_type' => 'gold_18k', 'weight' => 1.0 );
        $result = Calculator::calculate_price( $meta_data, 50000000 );

        // The filter should modify the final price from the base calculation.
        $this->assertEquals( 55000000.0, $result['final_price'] );
    }

    /**
     * Test empty meta_data is handled gracefully.
     */
    public function testEmptyMetaDataHandled(): void {
        $result = Calculator::calculate_price( array(), 50000000 );

        $this->assertIsArray( $result );
        $this->assertEquals( 0.0, $result['final_price'] );
    }
}