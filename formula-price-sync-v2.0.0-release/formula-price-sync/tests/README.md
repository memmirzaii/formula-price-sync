# Test Suite — Formula Price Sync (طلا ارز پرو)

## Overview

This test suite provides unit and integration tests for the critical components of the Formula Price Sync plugin, using **PHPUnit 9.5** with **Brain\Monkey** for WordPress function mocking.

## Test Structure

```
tests/
├── bootstrap.php                    # PHPUnit bootstrap, loads Brain\Monkey
├── TestCase.php                     # Base TestCase with Brain\Monkey setup/teardown
├── Unit/                              # Unit tests (no WordPress DB/Plugin dependencies)
│   ├── CalculatorTest.php             # Price calculation engine
│   ├── FormulaParserTest.php          # Safe formula parser (no eval)
│   ├── NumberToWordsTest.php          # Persian number conversion
│   ├── RoundingTest.php               # Rounding rules
│   ├── FormatterTest.php              # Price formatting helpers
│   └── CircuitBreakerTest.php         # Rate deviation detection
└── Integration/                        # Integration tests (with mocked WP functions)
    ├── NotifierTest.php               # Notification dispatch/Telegram/SMS
    ├── ActionSchedulerHandlerTest.php  # Bulk sync + LEFT JOIN price lock logic
    └── DBInstallerTest.php            # Migration logic (migrate_missing_lock_meta)
```

## Key Testing Strategies

### Brain\Monkey for WordPress Dependencies

All tests use `Brain\Monkey\Functions::when()` to mock WordPress core functions.
This is achieved in the `TestCase.php` base class:

- `setUp()`: Calls `Monkey::setUp()`
- `tearDown()`: Calls `Monkey::tearDown()`
- No real WordPress environment is required
- No real database is touched; all DB interactions are mocked

### LEFT JOIN Price Lock Logic

The `Action_Scheduler_Handler::get_enabled_product_ids()` method uses a critical
LEFT JOIN query to identify products with `_fps_enable = 'yes'` but WITHOUT
`_fps_price_locked = 'yes'`. This LEFT JOIN behavior is tested:

1. **Missing lock meta → treated as unlocked** (product is included)
2. **Explicit lock meta = 'yes' → excluded** (product is filtered out)
3. **Not enabled → excluded** (product doesn't meet the enable filter)

### No Network Dependencies

- Telegram/SMS API calls are mocked via `wp_remote_post()` return values
- API provider fetches (TGJU, Navasan, Nobitex) are never called in tests
- All `WP_Error` returns are mocked directly

## Running Tests

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run only unit tests
vendor/bin/phpunit --testsuite unit

# Run only integration tests
vendor/bin/phpunit --testsuite integration

# Run with coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/
```

## Acceptance Criteria Verification

| Criteria | How to Verify |
|----------|--------------|
| Tests run with `phpunit` | Run `vendor/bin/phpunit` |
| Minimum 80% coverage | Run `vendor/bin/phpunit --coverage-text=coverage/coverage.txt` and check output |
| No network dependencies | All `wp_remote_post()` calls are mocked via Brain\Monkey |
| Standardized folder structure | `tests/Unit/` and `tests/Integration/` directories separate concerns |
| No real database pollution | `$wpdb` is mocked; `get_option`, `update_option`, `get_transient`, `set_transient` are all mocked |

## Test Classes Coverage Mapping

| Class Under Test | Test File | Coverage Areas |
|-----------------|-----------|----------------|
| `Engine\Calculator` | `Unit/CalculatorTest.php` | Gold/currency/custom formula calculation, tax rule (tax only on wage+profit), rounding, edge cases |
| `Engine\Formula_Parser` | `Unit/FormulaParserTest.php` | Addition/subtraction/multiplication/division, parenthetical precedence, security (no eval), unknown var rejection |
| `Helpers\NumberToWords` | `Unit/NumberToWordsTest.php` | Digits 0-999, thousands, millions, billions, negative, Persian digits, comma separators, unit suffix |
| `Helpers\Formatter` | `Unit/FormatterTest.php` | Persian digit conversion, price formatting, number-to-words delegation |
| `Engine\Rounding` | `Unit/RoundingTest.php` | All rules (none, ceil_1000, round_1000, round_10000), edge cases |
| `API\Circuit_Breaker` | `Unit/CircuitBreakerTest.php` | First run accept, 25% threshold boundary, rejection logic, zero/old value handling |
| `Integrations\Notifier` | `Integration/NotifierTest.php` | dispatch() filter gate, Telegram/SMS success/error paths, provider routing |
| `Queue\Action_Scheduler_Handler` | `Integration/ActionSchedulerHandlerTest.php` | LEFT JOIN price lock filter, async availability, resolve_rate for all source types |
| `Core\DB_Installer` | `Integration/DBInstallerTest.php` | migrate_missing_lock_meta with empty results, version constants |

## Writing New Tests

1. Extend `FormulaPriceSync\Tests\TestCase`
2. Use `Functions::when()` for WordPress function mocking
3. Use `$this->createMock(\wpdb::class)` for database mocking
4. Set `global $wpdb` in the test if testing DB-interacting methods
5. Follow the existing test file naming convention: `ClassNameTest.php`
