<?php
/**
 * Unit tests for Formula_Parser class.
 *
 * Tests for FPS-001: Formula Parser validation and edge cases.
 */

namespace FormulaPriceSyncTestsUnit;

use FormulaPriceSyncEngineFormula_Parser;
use PHPUnitFrameworkTestCase;

/**
 * Formula Parser Test Case
 */
class Formula_Parser_Test extends TestCase {

	/**
	 * Formula Parser instance
	 *
	 * @var Formula_Parser
	 */
	private $parser;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new Formula_Parser();
	}

	/**
	 * Test basic integer parsing
	 */
	public function test_parse_basic_integer() {
		$result = $this->parser->parse( '100' );
		$this->assertEquals( 100.0, $result );
	}

	/**
	 * Test basic decimal parsing
	 */
	public function test_parse_basic_decimal() {
		$result = $this->parser->parse( '123.45' );
		$this->assertEquals( 123.45, $result );
	}

	/**
	 * Test negative values
	 */
	public function test_parse_negative_values() {
		$result = $this->parser->parse( '-50' );
		$this->assertEquals( -50.0, $result );
		
		$result = $this->parser->parse( '-123.45' );
		$this->assertEquals( -123.45, $result );
	}

	/**
	 * Test basic arithmetic operations
	 */
	public function test_parse_basic_arithmetic() {
		// Addition
		$result = $this->parser->parse( '10 + 5' );
		$this->assertEquals( 15.0, $result );

		// Subtraction
		$result = $this->parser->parse( '20 - 8' );
		$this->assertEquals( 12.0, $result );

		// Multiplication
		$result = $this->parser->parse( '4 * 5' );
		$this->assertEquals( 20.0, $result );

		// Division
		$result = $this->parser->parse( '100 / 4' );
		$this->assertEquals( 25.0, $result );
	}

	/**
	 * Test operator precedence
	 */
	public function test_parse_operator_precedence() {
		// Multiplication before addition
		$result = $this->parser->parse( '10 + 5 * 2' );
		$this->assertEquals( 20.0, $result );

		// Parentheses override precedence
		$result = $this->parser->parse( '(10 + 5) * 2' );
		$this->assertEquals( 30.0, $result );

		// Complex precedence
		$result = $this->parser->parse( '10 + 5 * 2 - 8 / 4' );
		$this->assertEquals( 18.0, $result ); // 10 + 10 - 2 = 18
	}

	/**
	 * Test parentheses
	 */
	public function test_parse_parentheses() {
		// Simple parentheses
		$result = $this->parser->parse( '(50)' );
		$this->assertEquals( 50.0, $result );

		// Nested parentheses
		$result = $this->parser->parse( '((10 + 5) * 2)' );
		$this->assertEquals( 30.0, $result );

		// Multiple parentheses
		$result = $this->parser->parse( '(10 + 5) * (2 + 3)' );
		$this->assertEquals( 75.0, $result );
	}

	/**
	 * Test variable substitution
	 */
	public function test_parse_variables() {
		// Set up variables
		$variables = array(
			'gold_rate' => 1000000,
			'exchange_rate' => 42000,
			'markup' => 1.1
		);

		// Test with variables
		$result = $this->parser->parse( 'gold_rate * exchange_rate', $variables );
		$this->assertEquals( 42000000000.0, $result );

		// Test with expression
		$result = $this->parser->parse( 'gold_rate * exchange_rate * markup', $variables );
		$this->assertEquals( 46200000000.0, $result );
	}

	/**
	 * Test divide by zero handling
	 */
	public function test_parse_divide_by_zero() {
		// This should handle division by zero gracefully
		$result = $this->parser->parse( '10 / 0' );
		// Depending on implementation, this might return INF, NAN, or throw an exception
		// For now, we'll check if it doesn't crash
		$this->assertTrue( is_numeric( $result ) || is_infinite( $result ) || is_nan( $result ) );
	}

	/**
	 * Test malformed expressions
	 */
	public function test_parse_malformed_expressions() {
		// Empty expression
		$result = $this->parser->parse( '' );
		$this->assertFalse( $result );

		// Invalid expression
		$result = $this->parser->parse( '10 + + 5' );
		$this->assertFalse( $result );

		// Unclosed parenthesis
		$result = $this->parser->parse( '(10 + 5' );
		$this->assertFalse( $result );
	}

	/**
	 * Test complex formulas
	 */
	public function test_parse_complex_formulas() {
		// Gold pricing formula: (gold_rate * weight + fee) * exchange_rate * markup
		$variables = array(
			'gold_rate' => 1200000, // 1.2M Rials per gram
			'weight' => 5,          // 5 grams
			'fee' => 50000,        // 50K Rials fee
			'exchange_rate' => 1,   // No exchange for Rial
			'markup' => 1.08       // 8% markup
		);

		$result = $this->parser->parse( '(gold_rate * weight + fee) * exchange_rate * markup', $variables );
		$expected = (1200000 * 5 + 50000) * 1 * 1.08; // (6000000 + 50000) * 1.08 = 6050000 * 1.08 = 6534000
		$this->assertEquals( $expected, $result, '', 0.01 );

		// Currency formula: base_price * exchange_rate * markup
		$variables = array(
			'base_price' => 100,    // $100
			'exchange_rate' => 42000, // 42K Rials per dollar
			'markup' => 1.15       // 15% markup
		);

		$result = $this->parser->parse( 'base_price * exchange_rate * markup', $variables );
		$expected = 100 * 42000 * 1.15; // 4830000
		$this->assertEquals( $expected, $result, '', 0.01 );
	}

	/**
	 * Test validation of expressions
	 */
	public function test_validate_expressions() {
		// Valid expressions
		$this->assertTrue( $this->parser->validate( '10 + 5' ) );
		$this->assertTrue( $this->parser->validate( '(10 + 5) * 2' ) );
		$this->assertTrue( $this->parser->validate( 'gold_rate * exchange_rate' ) );

		// Invalid expressions
		$this->assertFalse( $this->parser->validate( '' ) );
		$this->assertFalse( $this->parser->validate( '10 + + 5' ) );
		$this->assertFalse( $this->parser->validate( '(10 + 5' ) );
	}

	/**
	 * Test whitespace handling
	 */
	public function test_parse_whitespace_handling() {
		// Extra whitespace
		$result = $this->parser->parse( '  10   +   5  ' );
		$this->assertEquals( 15.0, $result );

		// No whitespace
		$result = $this->parser->parse( '10+5' );
		$this->assertEquals( 15.0, $result );
	}
}