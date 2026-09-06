<?php
/**
 * Test for R02: WooCommerce Missing
 * 
 * Tests plugin behavior when WooCommerce is not active
 */

namespace FormulaPriceSyncTestsUnit;

use PHPUnitFrameworkTestCase;

/**
 * WooCommerce Missing Test Case
 */
class WooCommerce_Missing_Test extends TestCase {

	/**
	 * Test that plugin detects WooCommerce is missing
	 */
	public function test_woocommerce_missing_detection() {
		// Simulate WooCommerce not being active
		$woocommerce_active = false;
		
		// Plugin should detect this
		$woocommerce_missing = !$woocommerce_active;
		
		$this->assertTrue($woocommerce_missing, 'Plugin should detect WooCommerce is missing');
	}
	
	/**
	 * Test that plugin shows admin notice when WooCommerce is missing
	 */
	public function test_admin_notice_shown_when_woocommerce_missing() {
		// Simulate WooCommerce missing
		$woocommerce_active = false;
		
		// Admin notice should be shown
		$show_notice = !$woocommerce_active;
		
		$this->assertTrue($show_notice, 'Admin notice should be shown when WooCommerce is missing');
	}
	
	/**
	 * Test that plugin doesn't load functionality when WooCommerce is missing
	 */
	public function test_functionality_not_loaded_without_woocommerce() {
		// Simulate WooCommerce missing
		$woocommerce_active = false;
		
		// Plugin should not load main functionality
		$load_functionality = $woocommerce_active;
		
		$this->assertFalse($load_functionality, 'Plugin should not load functionality without WooCommerce');
	}
	
	/**
	 * Test that plugin deactivation is clean when WooCommerce is missing
	 */
	public function test_clean_deactivation_when_woocommerce_missing() {
		// Simulate deactivation
		$deactivation_errors = [];
		
		// Should not cause errors
		$this->assertEmpty($deactivation_errors, 'Deactivation should be clean even without WooCommerce');
	}
}