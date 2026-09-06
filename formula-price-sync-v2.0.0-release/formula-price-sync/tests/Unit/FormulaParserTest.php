<?php
/**
 * Unit tests for Formula_Parser class.
 *
 * Tests safe formula parsing, validation, and evaluation without eval().
 */

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Engine\Formula_Parser;

class FormulaParserTest extends TestCase {
    /**
     * Test basic addition formula.
     */
    public function testBasicAddition(): void {
        $formula = '{raw_gold} + {fixed_fee}';
        $vars    = array( 'raw_gold' => 50000000, 'fixed_fee' => 500000 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 50500000, $result );
    }

    /**
     * Test multiplication formula.
     */
    public function testMultiplicationFormula(): void {
        $formula = '{weight} * {rate} * 1.07';
        $vars    = array( 'weight' => 1.5, 'rate' => 50000000 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        // 1.5 * 50000000 * 1.07 = 80250000
        $this->assertEquals( 80250000, $result );
    }

    /**
     * Test formula with parentheses.
     */
    public function testParenthesesPrecedence(): void {
        $formula = '({raw_gold} + {wage_amount}) * {profit_percent} / 100';
        $vars    = array(
            'raw_gold'      => 50000000,
            'wage_amount'   => 5000000,
            'profit_percent' => 15,
        );

        $result = Formula_Parser::evaluate( $formula, $vars );

        // (50000000 + 5000000) * 15 / 100 = 8250000
        $this->assertEquals( 8250000, $result );
    }

    /**
     * Test division in formula.
     */
    public function testDivisionInFormula(): void {
        $formula = '{raw_gold} / 2 + {fixed_fee}';
        $vars    = array( 'raw_gold' => 100000000, 'fixed_fee' => 100000 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 50100000, $result );
    }

    /**
     * Test subtraction in formula.
     */
    public function testSubtractionInFormula(): void {
        $formula = '{raw_gold} - {fixed_fee}';
        $vars    = array( 'raw_gold' => 50000000, 'fixed_fee' => 500000 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 49500000, $result );
    }

    /**
     * Test empty formula returns 0.
     */
    public function testEmptyFormulaReturnsZero(): void {
        $result = Formula_Parser::evaluate( '', array() );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test unsafe characters are rejected.
     */
    public function testUnsafeCharactersRejected(): void {
        // Test with semicolon (common injection).
        $formula = '1; DROP TABLE users';

        $result = Formula_Parser::evaluate( $formula, array() );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test eval injection is rejected.
     */
    public function testEvalInjectionRejected(): void {
        $formula = 'phpinfo()';

        $result = Formula_Parser::evaluate( $formula, array() );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test unknown variable placeholders are rejected.
     */
    public function testUnknownVarRejected(): void {
        $formula = '{unknown_var} + 100';
        $vars    = array( 'raw_gold' => 50000000 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test is_safe validates safe formulas.
     */
    public function testIsSafeValidFormula(): void {
        $formula = '{raw_gold} * {rate} + {fixed_fee}';

        $this->assertTrue( Formula_Parser::is_safe( $formula ) );
    }

    /**
     * Test is_safe rejects unsafe formulas.
     */
    public function testIsSafeRejectsUnsafeFormula(): void {
        $formula = '{raw_gold} + system( "rm -rf /" )';

        $this->assertFalse( Formula_Parser::is_safe( $formula ) );
    }

    /**
     * Test is_safe rejects formulas with curly braces containing unknown tokens.
     */
    public function testIsSafeRejectsUnknownBracedVars(): void {
        $formula = '{malicious} + 100';

        $this->assertFalse( Formula_Parser::is_safe( $formula ) );
    }

    /**
     * Test is_safe allows numeric literals only.
     */
    public function testIsSafeNumericOnlyFormula(): void {
        $formula = '12345.67 + 890';

        $this->assertTrue( Formula_Parser::is_safe( $formula ) );
    }

    /**
     * Test all allowed variables work in formulas.
     */
    public function testAllAllowedVariables(): void {
        $vars = array(
            'weight'          => 1.0,
            'rate'            => 50000000,
            'wage_percent'    => 10,
            'profit_percent'  => 15,
            'tax_percent'     => 9,
            'fixed_fee'       => 100000,
            'raw_gold'        => 50000000,
            'base_rial'       => 50000000,
            'wage_amount'     => 5000000,
            'profit_amount'   => 8250000,
            'tax_amount'      => 1192500,
        );

        foreach ( array_keys( $vars ) as $var_name ) {
            $formula = '{' . $var_name . '}';
            $result  = Formula_Parser::evaluate( $formula, $vars );
            $this->assertEquals( (float) $vars[ $var_name ], $result, "Variable {$var_name} should resolve correctly" );
        }
    }

    /**
     * Test complex nested formula evaluation.
     */
    public function testComplexNestedFormula(): void {
        $formula = '({raw_gold} + {wage_amount} + {fixed_fee}) * (1 + {profit_percent} / 100)';
        $vars    = array(
            'raw_gold'        => 50000000,
            'wage_amount'     => 5000000,
            'fixed_fee'       => 100000,
            'profit_percent'  => 15,
        );

        $result = Formula_Parser::evaluate( $formula, $vars );

        // (50000000 + 5000000 + 100000) * (1 + 15/100)
        // = 55100000 * 1.15 = 63365000
        $this->assertEquals( 63365000, $result );
    }

    /**
     * Test formula result is non-negative (negative results return 0).
     */
    public function testNegativeResultReturnsZero(): void {
        $formula = '{fixed_fee} - {raw_gold}';
        $vars    = array( 'raw_gold' => 1000000, 'fixed_fee' => 100 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test NaN results are sanitized to 0.
     */
    public function testNanResultReturnsZero(): void {
        $formula = '{rate} / 0';
        $vars    = array( 'rate' => 100 );

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test non-finite results are sanitized to 0.
     */
    public function testNonFiniteResultReturnsZero(): void {
        $formula = '{rate} * 999999999999999999999';
        $vars    = array( 'rate' => 1e308 ); // Very large number.

        $result = Formula_Parser::evaluate( $formula, $vars );

        // Result should be 0 because it's not finite.
        $this->assertEquals( 0.0, $result );
    }

    /**
     * Test variable defaults to 0 when not provided.
     */
    public function testVariableDefaultsToZero(): void {
        $formula = '{weight} + {rate}';
        $vars    = array( 'rate' => 100 ); // weight not provided.

        $result = Formula_Parser::evaluate( $formula, $vars );

        $this->assertEquals( 100, $result );
    }

    /**
     * Regression test for FPS-001: ctype → ctype_digit fix.
     * Ensures digit validation works correctly in tokenizer.
     */
    public function testFPS001RegressionCtypeDigitValidation(): void {
        // Test that numbers with digits work
        $formula = '123 + 456';
        $result  = Formula_Parser::evaluate( $formula, array() );
        $this->assertEquals( 579, $result );

        // Test decimal numbers work
        $formula = '12.34 + 56.78';
        $result  = Formula_Parser::evaluate( $formula, array() );
        $this->assertEquals( 69.12, $result );

        // Test that invalid non-digit characters in number position are rejected
        // This would have failed with the old ctype function
        $formula = '12a34 + 56'; // 'a' in the middle of a number
        $result  = Formula_Parser::evaluate( $formula, array() );
        $this->assertEquals( 0.0, $result ); // Should reject invalid number

        $formula = '12.3a4 + 56'; // 'a' in decimal part
        $result  = Formula_Parser::evaluate( $formula, array() );
        $this->assertEquals( 0.0, $result ); // Should reject invalid number

        // Test unary minus with digits works
        $formula = '100 + -50';
        $result  = Formula_Parser::evaluate( $formula, array() );
        $this->assertEquals( 50, $result );
    }
}

    /**
     * Test tokenization handles numbers and operators correctly.
     */
    public function testTokenizeExpression(): void {
        $formula = '1 + 2 * 3';

        // Tokenize is private, so we test through evaluate which uses it.
        // But we can test that precedence works.
        $vars    = array();
        $result  = Formula_Parser::evaluate( $formula, $vars );

        // 1 + 2 * 3 = 1 + 6 = 7
        $this->assertEquals( 7, $result );
    }

    /**
     * Test parentheses affect precedence.
     */
    public function testParenthesesPrecedenceInTokenize(): void {
        $formula = '(1 + 2) * 3';

        $result = Formula_Parser::evaluate( $formula, array() );

        // (1 + 2) * 3 = 3 * 3 = 9
        $this->assertEquals( 9, $result );
    }

    /**
     * Test unary minus is handled correctly.
     */
    public function testUnaryMinus(): void {
        $formula = '100 + -50';

        $result = Formula_Parser::evaluate( $formula, array() );

        $this->assertEquals( 50, $result );
    }
}