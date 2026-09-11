<?php
/**
 * PHPUnit test bootstrap for PeakURL backend test suite.
 *
 * @package PeakURL\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
