<?php
/**
 * Unit tests for Circuit_Breaker class.
 *
 * Tests for FPS-004: Circuit Breaker event contract validation.
 */

namespace FormulaPriceSyncTestsUnit;

use FormulaPriceSyncAPICircuit_Breaker;
use PHPUnitFrameworkTestCase;

/**
 * Circuit Breaker Test Case
 */
class Circuit_Breaker_Test extends TestCase {

	/**
	 * Circuit Breaker instance
	 *
	 * @var Circuit_Breaker
	 */
	private $circuit_breaker;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->circuit_breaker = new Circuit_Breaker();
	}

	/**
	 * Test initial rates acceptance
	 */
	public function test_initial_rates_acceptance() {
		$new_rates = array(
			'USD' => 42000,
			'EUR' => 45000,
			'GBP' => 50000
		);

		$result = $this->circuit_breaker->check( $new_rates );

		$this->assertTrue( $result['accepted'] );
		$this->assertEquals( $new_rates, $result['rates'] );
		$this->assertStringContainsString( 'Initial rates accepted', $result['message'] );
	}

	/**
	 * Test rates within safe deviation threshold
	 */
	public function test_rates_within_safe_threshold() {
		// First, set initial rates
		$initial_rates = array(
			'USD' => 42000,
			'EUR' => 45000
		);
		$this->circuit_breaker->check( $initial_rates );

		// Now test rates within 25% deviation
		$new_rates = array(
			'USD' => 44100, // 5% increase
			'EUR' => 43000  // ~4.44% decrease
		);

		$result = $this->circuit_breaker->check( $new_rates );

		$this->assertTrue( $result['accepted'] );
		$this->assertEquals( $new_rates, $result['rates'] );
		$this->assertStringContainsString( 'within safe deviation threshold', $result['message'] );
	}

	/**
	 * Test circuit breaker activation on anomalous rate change
	 */
	public function test_circuit_breaker_activation() {
		// First, set initial rates
		$initial_rates = array(
			'USD' => 42000,
			'EUR' => 45000
		);
		$this->circuit_breaker->check( $initial_rates );

		// Now test rates with >25% deviation
		$new_rates = array(
			'USD' => 42000 * 1.30, // 30% increase - should trigger circuit breaker
			'EUR' => 45000
		);

		$result = $this->circuit_breaker->check( $new_rates );

		$this->assertFalse( $result['accepted'] );
		$this->assertEquals( $initial_rates, $result['rates'] ); // Should keep previous safe rates
		$this->assertStringContainsString( 'Circuit Breaker activated', $result['message'] );
		$this->assertStringContainsString( 'USD', $result['message'] );
	}

	/**
	 * Test empty rates handling
	 */
	public function test_empty_rates_handling() {
		$result = $this->circuit_breaker->check( array() );

		$this->assertFalse( $result['accepted'] );
		$this->assertEmpty( $result['rates'] );
		$this->assertStringContainsString( 'No rates provided', $result['message'] );
	}

	/**
	 * Test circuit breaker reset
	 */
	public function test_circuit_breaker_reset() {
		// Set some rates
		$initial_rates = array( 'USD' => 42000 );
		$this->circuit_breaker->check( $initial_rates );

		// Reset
		$this->circuit_breaker->reset();

		// After reset, should accept any rates as initial
		$new_rates = array( 'USD' => 1000000 ); // Extreme rate
		$result = $this->circuit_breaker->check( $new_rates );

		$this->assertTrue( $result['accepted'] );
		$this->assertEquals( $new_rates, $result['rates'] );
	}

	/**
	 * Test event data structure for circuit breaker
	 * This tests FPS-004: standardized event contract
	 */
	public function test_event_contract_structure() {
		// Set initial rates
		$initial_rates = array( 'USD' => 42000 );
		$this->circuit_breaker->check( $initial_rates );

		// Trigger circuit breaker with anomalous rate
		$new_rates = array( 'USD' => 42000 * 1.30 );
		
		// Use reflection to test the trigger_event method
		$reflection = new ReflectionClass( $this->circuit_breaker );
		$method = $reflection->getMethod( 'trigger_event' );
		$method->setAccessible( true );

		// Mock the do_action to capture event data
		$event_data_captured = null;
		add_action( 'fps_circuit_breaker_triggered', function( $event_data ) use ( &$event_data_captured ) {
			$event_data_captured = $event_data;
		}, 10, 1 );

		// Call the method directly
		$method->invoke( $this->circuit_breaker, 'Test message', $initial_rates, 'anomalous_rate_change' );

		// Verify event data structure
		$this->assertNotNull( $event_data_captured );
		$this->assertArrayHasKey( 'message', $event_data_captured );
		$this->assertArrayHasKey( 'provider', $event_data_captured );
		$this->assertArrayHasKey( 'reason', $event_data_captured );
		$this->assertArrayHasKey( 'rates', $event_data_captured );
		$this->assertArrayHasKey( 'timestamp', $event_data_captured );

		// Verify specific values
		$this->assertEquals( 'Test message', $event_data_captured['message'] );
		$this->assertEquals( '', $event_data_captured['provider'] ); // Empty by default
		$this->assertEquals( 'anomalous_rate_change', $event_data_captured['reason'] );
		$this->assertEquals( $initial_rates, $event_data_captured['rates'] );
		$this->assertNotEmpty( $event_data_captured['timestamp'] );
	}

	/**
	 * Test multiple currency deviation handling
	 */
	public function test_multiple_currency_deviation() {
		// Set initial rates
		$initial_rates = array(
			'USD' => 42000,
			'EUR' => 45000,
			'GBP' => 50000
		);
		$this->circuit_breaker->check( $initial_rates );

		// Test with multiple currencies, some within threshold, some not
		$new_rates = array(
			'USD' => 42000 * 1.10, // 10% increase - OK
			'EUR' => 45000 * 1.30, // 30% increase - should trigger
			'GBP' => 50000 * 0.90  // 10% decrease - OK
		);

		$result = $this->circuit_breaker->check( $new_rates );

		$this->assertFalse( $result['accepted'] );
		$this->assertStringContainsString( 'EUR', $result['message'] ); // EUR should be mentioned as anomalous
		$this->assertStringContainsString( '>25%', $result['message'] );
	}

	/**
	 * Test zero rate handling in deviation calculation
	 */
	public function test_zero_rate_handling() {
		// Set initial rates with zero
		$initial_rates = array(
			'USD' => 42000,
			'ZERO_CURRENCY' => 0
		);
		$this->circuit_breaker->check( $initial_rates );

		// Change rates
		$new_rates = array(
			'USD' => 44100, // 5% increase
			'ZERO_CURRENCY' => 1000 // From zero to non-zero
		);

		// This should not crash due to division by zero
		$result = $this->circuit_breaker->check( $new_rates );
		
		// Should either accept or reject, but not crash
		$this->assertArrayHasKey( 'accepted', $result );
	}
}