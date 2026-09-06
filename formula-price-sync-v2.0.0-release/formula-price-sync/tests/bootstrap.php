<?php
/**
 * Bootstrap file for running PHPUnit tests with WordPress and Brain\Monkey.
 */

// Load Brain\Monkey for WordPress mocking.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
\Brain\Monkey\Functions::setUp();
\Brain\Monkey\Hooks::setUp();
\Brain\Monkey\Actions::setUp();
\Brain\Monkey\Filters::setUp();

// Include test helper classes.
require_once dirname( __FILE__ ) . '/TestCase.php';

// Mock WordPress constants and functions commonly used.
function __return_true() {
    return true;
}

function __return_false() {
    return false;
}

function __return_empty_array() {
    return array();
}

function __return_zero() {
    return 0;
}

function __return_empty_string() {
    return '';
}

function __compress_whitespace( $str ) {
    return preg_replace( '/\s+/', ' ', trim( $str ) );
}