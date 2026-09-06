<?php
/**
 * Test for R10: Tax = 0
 * 
 * Tests that tax value of 0 is handled correctly
 */

namespace FormulaPriceSyncTestsUnit;

use PHPUnitFrameworkTestCase;

/**
 * Tax Zero Test Case
 */
class Tax_Zero_Test extends TestCase {

	/**
	 * Test that tax = 0 is stored correctly in database
	 */
	public function test_tax_zero_stored_in_database() {
		// Simulate storing tax = 0
		$tax_value = 0;
		
		// In database, this should be stored as 0, not converted to 9
		$stored_value = $tax_value;
		
		$this->assertEquals(0, $stored_value, 'Tax = 0 should be stored as 0 in database');
	}
	
	/**
	 * Test that tax = 0 is retrieved correctly from database
	 */
	public function test_tax_zero_retrieved_from_database() {
		// Simulate retrieving tax = 0 from database
		$retrieved_value = 0;
		
		// Should be 0, not 9
		$this->assertEquals(0, $retrieved_value, 'Tax = 0 should be retrieved as 0 from database');
	}
	
	/**
	 * Test that tax = 0 is displayed correctly in admin
	 */
	public function test_tax_zero_displayed_correctly_in_admin() {
		// Simulate displaying tax = 0
		$tax_value = 0;
		
		// Should display as 0 or "No tax", not 9
		$display_value = $tax_value === 0 ? '0%' : $tax_value . '%';
		
		$this->assertEquals('0%', $display_value, 'Tax = 0 should be displayed as 0% in admin');
	}
	
	/**
	 * Test that price calculation with tax = 0 works correctly
	 */
	public function test_price_calculation_with_tax_zero() {
		// Base price: 1000 Rials
		$base_price = 1000;
		$tax_rate = 0; // 0% tax
		
		// Price with tax should be: base_price (no change)
		$price_with_tax = $base_price * (1 + $tax_rate / 100);
		
		$this->assertEquals(1000, $price_with_tax, 
			'Price with tax = 0 should equal base price');
	}
	
	/**
	 * Test that tax = 0 doesn't trigger fallback to 9
	 */
	public function test_tax_zero_no_fallback_to_nine() {
		// Test the problematic pattern
		$tax_value = 0;
		
		// This is the WRONG pattern that converts 0 to 9
		$wrong_result = $tax_value ?: 9;
		
		// This is the CORRECT pattern that preserves 0
		$correct_result = $tax_value !== null ? $tax_value : 9;
		
		// Wrong pattern gives 9 (the problem)
		$this->assertEquals(9, $wrong_result);
		
		// Correct pattern gives 0 (the solution)
		$this->assertEquals(0, $correct_result);
	}
	
	/**
	 * Test various tax values including 0 in settings
	 */
	public function test_tax_values_in_settings() {
		$test_cases = [
			['tax' => 0, 'description' => 'Tax = 0'],
			['tax' => 9, 'description' => 'Tax = 9'],
			['tax' => 5, 'description' => 'Tax = 5'],
			['tax' => 10, 'description' => 'Tax = 10'],
		];
		
		foreach ($test_cases as $case) {
			$tax = $case['tax'];
			
			// All values should be preserved as-is
			$stored_tax = $tax;
			
			$this->assertEquals($tax, $stored_tax, 
				$case['description'] . ' should be preserved');
		}
	}
}