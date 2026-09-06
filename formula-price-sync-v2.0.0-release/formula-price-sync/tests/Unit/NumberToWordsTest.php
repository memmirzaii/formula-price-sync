<?php
/**
 * Unit tests for NumberToWords class.
 *
 * Tests Persian number to words conversion.
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Helpers\NumberToWords;

class NumberToWordsTest extends TestCase {
    /**
     * Test zero converts to صفر.
     */
    public function testZeroConvertsToSifr(): void {
        $result = NumberToWords::convert( 0 );

        $this->assertEquals( 'صفر', $result );
    }

    /**
     * Test one converts correctly.
     */
    public function testOneConvertsCorrectly(): void {
        $result = NumberToWords::convert( 1 );

        $this->assertEquals( 'یک', $result );
    }

    /**
     * Test two converts correctly.
     */
    public function testTwoConvertsCorrectly(): void {
        $result = NumberToWords::convert( 2 );

        $this->assertEquals( 'دو', $result );
    }

    /**
     * Test single digits 1-9.
     */
    public function testSingleDigits(): void {
        $expected = array(
            1 => 'یک',
            2 => 'دو',
            3 => 'سه',
            4 => 'چهار',
            5 => 'پنج',
            6 => 'شش',
            7 => 'هفت',
            8 => 'هشت',
            9 => 'نه',
        );

        foreach ( $expected as $number => $words ) {
            $this->assertEquals( $words, NumberToWords::convert( $number ), "Number {$number} should convert to {$words}" );
        }
    }

    /**
     * Test teens (10-19).
     */
    public function testTeens(): void {
        $expected = array(
            10 => 'ده',
            11 => 'یازده',
            12 => 'دوازده',
            13 => 'سیزده',
            14 => 'چهارده',
            15 => 'پانزده',
            16 => 'شانزده',
            17 => 'هفده',
            18 => 'هجده',
            19 => 'نوزده',
        );

        foreach ( $expected as $number => $words ) {
            $this->assertEquals( $words, NumberToWords::convert( $number ), "Number {$number} should convert to {$words}" );
        }
    }

    /**
     * Test tens (20, 30, 40, ...).
     */
    public function testTens(): void {
        $expected = array(
            20 => 'بیست',
            30 => 'سی',
            40 => 'چهل',
            50 => 'پنجاه',
            60 => 'شصت',
            70 => 'هفتاد',
            80 => 'هشتاد',
            90 => 'نود',
        );

        foreach ( $expected as $number => $words ) {
            $this->assertEquals( $words, NumberToWords::convert( $number ), "Number {$number} should convert to {$words}" );
        }
    }

    /**
     * Test compound tens (e.g., 21 = بیست و یک).
     */
    public function testCompoundTens(): void {
        $this->assertEquals( 'بیست و یک', NumberToWords::convert( 21 ) );
        $this->assertEquals( 'سی و پنج', NumberToWords::convert( 35 ) );
        $this->assertEquals( 'نود و نه', NumberToWords::convert( 99 ) );
    }

    /**
     * Test hundreds.
     */
    public function testHundreds(): void {
        $this->assertEquals( 'صد', NumberToWords::convert( 100 ) );
        $this->assertEquals( 'دویست', NumberToWords::convert( 200 ) );
        $this->assertEquals( 'سیصد', NumberToWords::convert( 300 ) );
        $this->assertEquals( 'نهصد', NumberToWords::convert( 900 ) );
    }

    /**
     * Test compound hundreds (e.g., 150).
     */
    public function testCompoundHundreds(): void {
        $this->assertEquals( 'صد و پنجاه', NumberToWords::convert( 150 ) );
        $this->assertEquals( 'دویست و بیست و دو', NumberToWords::convert( 222 ) );
        $this->assertEquals( 'نهصد و نود و نه', NumberToWords::convert( 999 ) );
    }

    /**
     * Test thousands.
     */
    public function testThousands(): void {
        $this->assertEquals( 'یک هزار', NumberToWords::convert( 1000 ) );
        $this->assertEquals( 'هزار', NumberToWords::convert( 1001 ) ); // Special case: 1 + thousand
    }

    /**
     * Test thousands with other values.
     */
    public function testThousandsWithOthers(): void {
        $this->assertEquals( 'دو هزار', NumberToWords::convert( 2000 ) );
        $this->assertEquals( 'یک هزار و دویست', NumberToWords::convert( 1200 ) );
        $this->assertEquals( 'پنج هزار و سیصد و پنجاه', NumberToWords::convert( 5350 ) );
    }

    /**
     * Test millions.
     */
    public function testMillions(): void {
        $this->assertEquals( 'یک میلیون', NumberToWords::convert( 1000000 ) );
        $this->assertEquals( 'دو میلیون', NumberToWords::convert( 2000000 ) );
        $this->assertEquals( 'یک میلیون و دویست هزار', NumberToWords::convert( 1200000 ) );
    }

    /**
     * Test billions.
     */
    public function testBillions(): void {
        $this->assertEquals( 'یک میلیارد', NumberToWords::convert( 1000000000 ) );
    }

    /**
     * Test with Persian digits input.
     */
    public function testPersianDigitsInput(): void {
        $result = NumberToWords::convert( '۱۲۳' );

        $this->assertEquals( 'صد و بیست و سه', $result );
    }

    /**
     * Test with comma-separated string.
     */
    public function testCommaSeparatedString(): void {
        $result = NumberToWords::convert( '1,234,567' );

        $this->assertEquals( 'یک میلیون و دویست و سی و چهار هزار و پانصد و شصت و هفت', $result );
    }

    /**
     * Test with Persian comma separator.
     */
    public function testPersianCommaSeparator(): void {
        $result = NumberToWords::convert( '۱٬۲۳۴' );

        $this->assertEquals( 'یک هزار و دویست و سی و چهار', $result );
    }

    /**
     * Test negative number.
     */
    public function testNegativeNumber(): void {
        $result = NumberToWords::convert( -500 );

        $this->assertStringStartsWith( 'منفی', $result );
    }

    /**
     * Test negative number as string.
     */
    public function testNegativeStringNumber(): void {
        $result = NumberToWords::convert( '-500' );

        $this->assertStringStartsWith( 'منفی', $result );
    }

    /**
     * Test with unit suffix.
     */
    public function testWithUnitSuffix(): void {
        $result = NumberToWords::convert( 50000, 'ریال' );

        $this->assertStringEndsWith( 'ریال', $result );
    }

    /**
     * Test float is floored.
     */
    public function testFloatFloored(): void {
        $result = NumberToWords::convert( 12.9 );

        // Should convert 12, not 13.
        $this->assertEquals( NumberToWords::convert( 12 ), $result );
    }

    /**
     * Test very large number handling.
     */
    public function testVeryLargeNumber(): void {
        $result = NumberToWords::convert( 10000000000 );

        $this->assertNotEmpty( $result );
    }

    /**
     * Test zero with unit.
     */
    public function testZeroWithUnit(): void {
        $result = NumberToWords::convert( 0, 'تومان' );

        $this->assertEquals( 'صفر تومان', $result );
    }

    /**
     * Test thousand separator edge case.
     */
    public function testThousandEdgeCase(): void {
        // 1001000 should be "یک میلیون و یک هزار" not "یک میلیون و هزار"
        $result = NumberToWords::convert( 1001000 );

        $this->assertStringContainsString( 'یک هزار', $result );
    }
}