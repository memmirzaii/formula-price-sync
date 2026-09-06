<?php
/**
 * Unit tests for Rounding class.
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Engine\Rounding;

class RoundingTest extends TestCase {
    /**
     * Test no rounding rule.
     */
    public function testNoRounding(): void {
        $result = Rounding::apply( 1234.56, 'none' );

        $this->assertEquals( 1234.56, $result );
    }

    /**
     * Test ceil_1000 rounds up.
     */
    public function testCeil1000(): void {
        $result = Rounding::apply( 1234, 'ceil_1000' );

        $this->assertEquals( 2000.0, $result );
    }

    /**
     * Test ceil_1000 with exact multiple.
     */
    public function testCeil1000ExactMultiple(): void {
        $result = Rounding::apply( 3000, 'ceil_1000' );

        $this->assertEquals( 3000.0, $result );
    }

    /**
     * Test round_1000 rounds to nearest.
     */
    public function testRound1000(): void {
        $this->assertEquals( 1000.0, Rounding::apply( 1499, 'round_1000' ) );
        $this->assertEquals( 1000.0, Rounding::apply( 1500, 'round_1000' ) );
        $this->assertEquals( 2000.0, Rounding::apply( 1501, 'round_1000' ) );
    }

    /**
     * Test round_10000 rounds to nearest 10000.
     */
    public function testRound10000(): void {
        $result = Rounding::apply( 55000, 'round_10000' );

        $this->assertEquals( 60000.0, $result );
    }

    /**
     * Test zero price.
     */
    public function testZeroPrice(): void {
        $result = Rounding::apply( 0, 'ceil_1000' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test negative price is treated as zero.
     */
    public function testNegativePrice(): void {
        $result = Rounding::apply( -100, 'none' );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test unknown rule defaults to none.
     */
    public function testUnknownRuleDefaultsToNone(): void {
        $result = Rounding::apply( 1234.56, 'unknown_rule' );

        $this->assertEquals( 1234.56, $result );
    }

    /**
     * Test get_rule_labels returns all labels.
     */
    public function testGetRuleLabels(): void {
        $labels = Rounding::get_rule_labels();

        $this->assertArrayHasKey( 'none', $labels );
        $this->assertArrayHasKey( 'ceil_1000', $labels );
        $this->assertArrayHasKey( 'round_1000', $labels );
        $this->assertArrayHasKey( 'round_10000', $labels );
    }

    /**
     * Test is_valid_rule.
     */
    public function testIsValidRule(): void {
        $this->assertTrue( Rounding::is_valid_rule( 'none' ) );
        $this->assertTrue( Rounding::is_valid_rule( 'ceil_1000' ) );
        $this->assertFalse( Rounding::is_valid_rule( 'invalid' ) );
    }
}