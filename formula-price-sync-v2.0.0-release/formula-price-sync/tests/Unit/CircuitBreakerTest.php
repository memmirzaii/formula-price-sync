<?php
/**
 * Unit tests for Circuit_Breaker class.
 *
 * Tests rate spike detection and validation logic.
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\API\Circuit_Breaker;

class CircuitBreakerTest extends TestCase {
    /**
     * Test first run accepts rates.
     */
    public function testFirstRunAcceptsRates(): void {
        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( array() );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate(
            array(
                'usd'       => 2060000,
                'eur'       => 2200000,
                'gold_18k'  => 50000000,
                'gold_24k'  => 52000000,
                'coin'      => 1000000,
            )
        );

        $this->assertTrue( $result['accepted'] );
        $this->assertEquals( 'First run or no previous data – rates accepted.', $result['message'] );
    }

    /**
     * Test empty new rates are accepted (first run).
     */
    public function testEmptyNewRatesAcceptedOnFirstRun(): void {
        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( array() );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( array() );

        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test rates within 25% deviation are accepted.
     */
    public function testRatesWithinDeviationAccepted(): void {
        $previous = array(
            'usd'       => 2000000,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // 5% deviation - well within threshold.
        $new_rates = array(
            'usd'      => 2100000,
            'gold_18k' => 52500000,
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertTrue( $result['accepted'] );
        $this->assertStringContainsString( 'accepted', $result['message'] );
    }

    /**
     * Test rates exceeding 25% deviation are rejected.
     */
    public function testRatesExceedingDeviationRejected(): void {
        $previous = array(
            'usd'       => 2000000,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // 50% deviation - exceeds threshold.
        $new_rates = array(
            'usd'      => 3000000, // 50% increase
            'gold_18k' => 75000000, // 50% increase
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertFalse( $result['accepted'] );
        $this->assertStringContainsString( 'Circuit Breaker activated', $result['message'] );
        $this->assertEquals( $previous, $result['rates'] ); // Returns previous rates.
    }

    /**
     * Test partial deviation (one key exceeds, one doesn't).
     */
    public function testPartialDeviation(): void {
        $previous = array(
            'usd'       => 2000000,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // USD is fine (5%), gold_18k is way over (50%).
        $new_rates = array(
            'usd'      => 2100000,
            'gold_18k' => 75000000,
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertFalse( $result['accepted'] );
    }

    /**
     * Test zero old value is skipped.
     */
    public function testZeroOldValueIsSkipped(): void {
        $previous = array(
            'usd'       => 0,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // USD was 0, now it's any value - should not trigger rejection.
        $new_rates = array(
            'usd'      => 3000000,
            'gold_18k' => 52000000,
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        // Should be accepted because gold_18k deviation is within threshold and usd was 0.
        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test missing key pairs are skipped.
     */
    public function testMissingKeysAreSkipped(): void {
        $previous = array(
            'usd'       => 2000000,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // New rates only has usd (matching previous) and a new key 'eur'.
        $new_rates = array(
            'usd'      => 2100000,
            'eur'      => 2200000, // not in previous, skipped
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test exact 25% deviation is NOT rejected.
     */
    public function testExact25PercentDeviationNotRejected(): void {
        $previous = array(
            'usd'       => 1000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // Exactly 25% deviation.
        $new_rates = array(
            'usd' => 1250000, // exactly 25% increase
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test 25.01% deviation is rejected.
     */
    public function testSlightlyOver25PercentRejected(): void {
        $previous = array(
            'usd' => 1000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        // 26% deviation.
        $new_rates = array(
            'usd' => 1260000,
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertFalse( $result['accepted'] );
    }

    /**
     * Test accepted rates are stored.
     */
    public function testAcceptedRatesAreStored(): void {
        $previous = array();

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );
        Functions::when( 'update_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, \Brain\Monkey\Functions\Expectation::any(), false )->thenReturn( true );

        $new_rates = array(
            'usd' => 2000000,
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test all keys missing from new rates returns accepted.
     */
    public function testAllKeysMissingFromNewRates(): void {
        $previous = array(
            'usd' => 2000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        $new_rates = array( 'eur' => 2000000 );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        // All keys in previous are missing from new_rates -> accepted.
        $this->assertTrue( $result['accepted'] );
    }

    /**
     * Test MAX_DEVIATION_PERCENT constant.
     */
    public function testMaxDeviationPercentConstant(): void {
        $this->assertEquals( 25.0, Circuit_Breaker::MAX_DEVIATION_PERCENT );
    }

    /**
     * Test last accepted option key.
     */
    public function testLastAcceptedOptionConstant(): void {
        $this->assertEquals( 'fps_last_accepted_rates', Circuit_Breaker::LAST_ACCEPTED_OPTION );
    }

    /**
     * Test accepted rates returned in result.
     */
    public function testAcceptedRatesInResult(): void {
        $previous = array(
            'usd' => 2000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );

        $new_rates = array(
            'usd' => 2050000, // 2.5% - accepted.
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertEquals( $new_rates, $result['rates'] );
    }

    /**
     * Regression test for FPS-004: undefined $previous in trigger_admin_warning.
     * Ensures Circuit_Breaker properly passes previous rates when triggering warnings.
     */
    public function testFPS004RegressionUndefinedPreviousFixed(): void {
        $previous = array(
            'usd'       => 2000000,
            'gold_18k'  => 50000000,
        );

        Functions::when( 'get_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() )->thenReturn( $previous );
        Functions::when( 'update_option' )->with( Circuit_Breaker::LAST_ACCEPTED_OPTION, \Brain\Monkey\Functions\Expectation::any(), false )->thenReturn( true );
        Functions::when( 'set_transient' )->with( Circuit_Breaker::WARNING_TRANSIENT, \Brain\Monkey\Functions\Expectation::any(), HOUR_IN_SECONDS * 6 )->thenReturn( true );

        // 50% deviation - exceeds threshold, should trigger warning
        $new_rates = array(
            'usd'      => 3000000, // 50% increase
            'gold_18k' => 75000000, // 50% increase
        );

        $breaker = new Circuit_Breaker();
        $result  = $breaker->validate( $new_rates );

        $this->assertFalse( $result['accepted'] );
        $this->assertStringContainsString( 'Circuit Breaker activated', $result['message'] );
        $this->assertEquals( $previous, $result['rates'] ); // Returns previous safe rates
    }
}