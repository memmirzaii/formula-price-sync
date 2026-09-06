<?php
/**
 * Unit tests for Formatter class.
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Helpers\Formatter;

class FormatterTest extends TestCase {
    /**
     * Test to_persian_num converts digits.
     */
    public function testToPersianNum(): void {
        $result = Formatter::to_persian_num( '1234567890' );

        $this->assertStringContainsString( '۰', $result );
        $this->assertStringContainsString( '۹', $result );
    }

    /**
     * Test to_persian_num converts thousands separator.
     */
    public function testToPersianNumWithThousandsSeparator(): void {
        $result = Formatter::to_persian_num( '1,234,567' );

        $this->assertStringContainsString( '٬', $result );
    }

    /**
     * Test format_price formats and converts to Persian.
     */
    public function testFormatPrice(): void {
        $result = Formatter::format_price( 1234567 );

        $this->assertStringContainsString( '۱', $result );
        $this->assertStringContainsString( '٬', $result );
    }

    /**
     * Test format_price handles zero.
     */
    public function testFormatPriceZero(): void {
        $result = Formatter::format_price( 0 );

        $this->assertNotEmpty( $result );
    }

    /**
     * Test num_to_words delegates to NumberToWords.
     */
    public function testNumToWordsDelegates(): void {
        $result = Formatter::num_to_words( 100 );

        $this->assertEquals( 'صد', $result );
    }

    /**
     * Test num_to_words with unit.
     */
    public function testNumToWordsWithUnit(): void {
        $result = Formatter::num_to_words( 50000, 'ریال' );

        $this->assertStringEndsWith( 'ریال', $result );
    }

    /**
     * Test empty string handling.
     */
    public function testEmptyString(): void {
        $result = Formatter::to_persian_num( '' );

        $this->assertEquals( '', $result );
    }

    /**
     * Test DIGIT_MAP constant exists.
     */
    public function testDigitMapConstant(): void {
        $this->assertArrayHasKey( '0', Formatter::DIGIT_MAP );
        $this->assertArrayHasKey( '9', Formatter::DIGIT_MAP );
    }
}