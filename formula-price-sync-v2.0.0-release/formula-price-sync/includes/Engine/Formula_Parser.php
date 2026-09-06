<?php
/**
 * Safe custom formula parser (no eval).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Formula_Parser
 *
 * Evaluates simple arithmetic formulas with allowed variables.
 * Example: {weight} * {rate} * 1.07 + {fixed_fee}
 */
class Formula_Parser {

	/**
	 * Allowed variable placeholders.
	 *
	 * @var string[]
	 */
	const ALLOWED_VARS = array(
		'weight',
		'rate',
		'wage_percent',
		'profit_percent',
		'tax_percent',
		'fixed_fee',
		'raw_gold',
		'base_rial',
		'wage_amount',
		'profit_amount',
		'tax_amount',
	);

	/**
	 * Evaluate a formula string with given variables.
	 *
	 * @param string               $formula Formula text.
	 * @param array<string, float> $vars    Variable map (without braces).
	 * @return float
	 */
	public static function evaluate( string $formula, array $vars ): float {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return 0.0;
		}

		if ( ! self::is_safe( $formula ) ) {
			return 0.0;
		}

		// Replace {var} with numeric values.
		foreach ( self::ALLOWED_VARS as $key ) {
			$value = isset( $vars[ $key ] ) ? (float) $vars[ $key ] : 0.0;
			if ( ! is_finite( $value ) ) {
				$value = 0.0;
			}
			$formula = str_replace( '{' . $key . '}', (string) $value, $formula );
		}

		// Reject any remaining braces (unknown vars).
		if ( false !== strpos( $formula, '{' ) || false !== strpos( $formula, '}' ) ) {
			return 0.0;
		}

		$result = self::safe_math( $formula );

		if ( ! is_finite( $result ) || $result < 0 ) {
			return 0.0;
		}

		return $result;
	}

	/**
	 * Check formula only contains safe characters and known vars.
	 *
	 * @param string $formula Formula.
	 * @return bool
	 */
	public static function is_safe( string $formula ): bool {
		// Allowed: digits, operators, dots, spaces, parentheses, braces, letters, underscore.
		if ( ! preg_match( '/^[0-9\s\+\-\*\/\.\(\)\{\}a-zA-Z_]+$/', $formula ) ) {
			return false;
		}

		// Extract {tokens} and verify against allow-list.
		if ( preg_match_all( '/\{([a-zA-Z_]+)\}/', $formula, $matches ) ) {
			foreach ( $matches[1] as $token ) {
				if ( ! in_array( $token, self::ALLOWED_VARS, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Safe arithmetic evaluation without eval().
	 * Supports + - * / and parentheses via shunting-yard + RPN.
	 *
	 * @param string $expr Expression with numbers only.
	 * @return float
	 */
	private static function safe_math( string $expr ): float {
		$expr = preg_replace( '/\s+/', '', $expr );
		if ( null === $expr || '' === $expr ) {
			return 0.0;
		}

		// Only numbers and operators left.
		if ( ! preg_match( '/^[0-9\+\-\*\/\.\(\)]+$/', $expr ) ) {
			return 0.0;
		}

		$tokens = self::tokenize( $expr );
		if ( empty( $tokens ) ) {
			return 0.0;
		}

		$rpn = self::to_rpn( $tokens );
		return self::eval_rpn( $rpn );
	}

	/**
	 * Tokenize expression.
	 *
	 * @param string $expr Expression.
	 * @return array
	 */
	private static function tokenize( string $expr ): array {
		$tokens = array();
		$i      = 0;
		$len    = strlen( $expr );

		while ( $i < $len ) {
			$ch = $expr[ $i ];

			if ( '(' === $ch || ')' === $ch || '+' === $ch || '*' === $ch || '/' === $ch ) {
				$tokens[] = $ch;
				++$i;
				continue;
			}

			// Unary or binary minus.
			if ( '-' === $ch ) {
				$prev = ! empty( $tokens ) ? end( $tokens ) : null;
				if ( null === $prev || '(' === $prev || '+' === $prev || '-' === $prev || '*' === $prev || '/' === $prev ) {
					// Unary minus – attach to number.
					++$i;
					$num = '-';
					while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
						$num .= $expr[ $i ];
						++$i;
					}
					if ( is_numeric( $num ) ) {
						$tokens[] = $num;
					} else {
						return array();
					}
					continue;
				}
				$tokens[] = $ch;
				++$i;
				continue;
			}

			// Number.
			if ( ctype_digit( $ch ) || '.' === $ch ) {
				$num = '';
				while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
					$num .= $expr[ $i ];
					++$i;
				}
				if ( ! is_numeric( $num ) ) {
					return array();
				}
				$tokens[] = $num;
				continue;
			}

			return array();
		}

		return $tokens;
	}

	/**
	 * Convert infix tokens to RPN (shunting-yard).
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private static function to_rpn( array $tokens ): array {
		$out   = array();
		$stack = array();
		$prec  = array(
			'+' => 1,
			'-' => 1,
			'*' => 2,
			'/' => 2,
		);

		foreach ( $tokens as $token ) {
			if ( is_numeric( $token ) ) {
				$out[] = $token;
			} elseif ( isset( $prec[ $token ] ) ) {
				while (
					! empty( $stack )
					&& isset( $prec[ end( $stack ) ] )
					&& $prec[ end( $stack ) ] >= $prec[ $token ]
				) {
					$out[] = array_pop( $stack );
				}
				$stack[] = $token;
			} elseif ( '(' === $token ) {
				$stack[] = $token;
			} elseif ( ')' === $token ) {
				while ( ! empty( $stack ) && '(' !== end( $stack ) ) {
					$out[] = array_pop( $stack );
				}
				if ( empty( $stack ) ) {
					return array();
				}
				array_pop( $stack ); // pop '('
			}
		}

		while ( ! empty( $stack ) ) {
			$op = array_pop( $stack );
			if ( '(' === $op || ')' === $op ) {
				return array();
			}
			$out[] = $op;
		}

		return $out;
	}

	/**
	 * Evaluate RPN stack.
	 *
	 * @param array $rpn RPN tokens.
	 * @return float
	 */
	private static function eval_rpn( array $rpn ): float {
		$stack = array();

		foreach ( $rpn as $token ) {
			if ( is_numeric( $token ) ) {
				$stack[] = (float) $token;
				continue;
			}

			if ( count( $stack ) < 2 ) {
				return 0.0;
			}

			$b = array_pop( $stack );
			$a = array_pop( $stack );

			switch ( $token ) {
				case '+':
					$stack[] = $a + $b;
					break;
				case '-':
					$stack[] = $a - $b;
					break;
				case '*':
					$stack[] = $a * $b;
					break;
				case '/':
					if ( 0.0 === (float) $b ) {
						return 0.0;
					}
					$stack[] = $a / $b;
					break;
				default:
					return 0.0;
			}
		}

		if ( 1 !== count( $stack ) ) {
			return 0.0;
		}

		$result = (float) $stack[0];
		return is_finite( $result ) ? $result : 0.0;
	}
}
