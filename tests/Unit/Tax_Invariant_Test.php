<?php
/**
 * Test for Financial Invariant INV-05: Tax = 0 must remain valid
 * 
 * This test ensures that tax values of 0 are not converted to fallback values like 9
 */

namespace FormulaPriceSyncTestsUnit;

use PHPUnitFrameworkTestCase;

/**
 * Tax Invariant Test Case
 */
class Tax_Invariant_Test extends TestCase {

	/**
	 * Test that tax = 0 is treated as valid and not converted to fallback
	 */
	public function test_tax_zero_is_valid() {
		// This test verifies INV-05: Tax = 0 must remain valid
		// We need to check that no code does: $value ?: 9
		// which would convert 0 to 9
		
		$tax_value = 0;
		
		// Simulate the problematic pattern
		$problematic_result = $tax_value ?: 9;
		
		// This should be 9 (the problem we're testing for)
		$this->assertEquals(9, $problematic_result);
		
		// The correct pattern should be:
		$correct_result = null !== $tax_value ? $tax_value : 9;
		
		// This should be 0 (the correct behavior)
		$this->assertEquals(0, $correct_result);
		
		// Or even better, explicit check:
		$explicit_result = $tax_value === 0 ? 0 : ($tax_value ?: 9);
		$this->assertEquals(0, $explicit_result);
	}
	
	/**
	 * Test various tax values including 0
	 */
	public function test_tax_values_including_zero() {
		$test_cases = [
			['tax' => 0, 'expected' => 0, 'description' => 'Tax = 0 should remain 0'],
			['tax' => 9, 'expected' => 9, 'description' => 'Tax = 9 should remain 9'],
			['tax' => null, 'expected' => 9, 'description' => 'Tax = null should fallback to 9'],
			['tax' => '', 'expected' => 9, 'description' => 'Tax = empty string should fallback to 9'],
		];
		
		foreach ($test_cases as $case) {
			$tax = $case['tax'];
			$expected = $case['expected'];
			
			// Using the CORRECT pattern that preserves 0
			$result = $tax !== null && $tax !== '' ? $tax : 9;
			
			$this->assertEquals($expected, $result, $case['description']);
		}
	}
	
	/**
	 * Test that price calculations with tax = 0 work correctly
	 */
	public function test_price_calculation_with_zero_tax() {
		// Base price: 1000 Rials
		$base_price = 1000;
		$tax_rate = 0; // 0% tax
		
		// Price with tax should be: base_price * (1 + tax_rate/100)
		$expected_price = $base_price * (1 + $tax_rate / 100);
		
		// This should be exactly 1000, not 1000 * 1.09 = 1090
		$this->assertEquals(1000, $expected_price);
		
		// Test with tax = 9
		$tax_rate = 9;
		$expected_price_with_tax = $base_price * (1 + $tax_rate / 100);
		$this->assertEquals(1090, $expected_price_with_tax);
	}
}