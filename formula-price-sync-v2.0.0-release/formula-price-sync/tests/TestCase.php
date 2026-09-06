<?php
/**
 * Base Test Case for Formula Price Sync tests.
 *
 * Provides common setup, teardown, and helper methods.
 */

namespace FormulaPriceSync\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Hooks;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Class TestCase
 *
 * Base test case with Brain\Monkey integration for WordPress function mocking.
 */
class TestCase extends PHPUnitTestCase {
    /**
     * Set up the test environment.
     */
    protected function setUp(): void {
        parent::setUp();

        // Set up Brain\Monkey.
        Monkey::setUp();
        Hooks::setUp();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void {
        // Clean up Brain\Monkey.
        Monkey::tearDown();

        parent::tearDown();
    }

    /**
     * Assert that two arrays are equal (order-independent for associative arrays).
     */
    protected function assertArraysEqual( array $expected, array $actual, string $message = '' ): void {
        $this->assertEquals(
            $this->normalizeArray( $expected ),
            $this->normalizeArray( $actual ),
            $message
        );
    }

    /**
     * Normalize array for comparison (sort associative arrays by key).
     */
    private function normalizeArray( array $array ): array {
        $result = array();
        foreach ( $array as $key => $value ) {
            if ( is_array( $value ) ) {
                $result[ $key ] = $this->normalizeArray( $value );
            } else {
                $result[ $key ] = $value;
            }
        }
        ksort( $result );
        return $result;
    }

    /**
     * Helper to mock get_post_meta with multiple values.
     *
     * @param array $meta_map Array of [post_id, meta_key, meta_value].
     */
    protected function mockPostMeta( array $meta_map ): void {
        foreach ( $meta_map as $entry ) {
            Functions::when( 'get_post_meta' )
                ->with( $entry[0], $entry[1], true )
                ->thenReturn( $entry[2] );
        }
    }

    /**
     * Create a mock WP_Error instance.
     */
    protected function createWPError( string $code, string $message ): \WP_Error {
        $error = $this->createMock( \WP_Error::class );
        $error->method( 'get_error_message' )->willReturn( $message );
        $error->method( 'get_error_code' )->willReturn( $code );
        return $error;
    }
}