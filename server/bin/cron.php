<?php
/**
 * PeakURL background cron runner.
 *
 * Lightweight server cron trigger for periodic maintenance. Discovers
 * and executes due background jobs through the canonical Scheduler.
 *
 * Usage:
 *   php bin/cron.php        (packaged release)
 *   php server/bin/cron.php (source checkout)
 *
 * @package PeakURL\Scripts
 * @since 1.7.0
 */

declare(strict_types=1);

use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Scheduler\BackgroundRunnerFactory;
use PeakURL\Services\Database\Connection;

$is_server_subdir = 'server' === basename( dirname( __DIR__ ) );
$release_root     = $is_server_subdir ? dirname( __DIR__, 2 ) : dirname( __DIR__ );

if ( $is_server_subdir && ( false === getenv( 'PEAKURL_DEV' ) || '' === getenv( 'PEAKURL_DEV' ) ) ) {
	putenv( 'PEAKURL_DEV=true' );
	$_ENV['PEAKURL_DEV']    = 'true';
	$_SERVER['PEAKURL_DEV'] = 'true';
}

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		rtrim( $release_root, '/\\' ) . DIRECTORY_SEPARATOR,
	);
}

require_once ABSPATH . 'load.php';

$environment = Environment::get_instance();
$environment->load_autoloader();
$runtime_root = $environment->get_runtime_root();

try {
	$config = Configuration::bootstrap( $runtime_root );
} catch ( \Throwable $exception ) {
	fwrite( STDERR, 'Bootstrap error: ' . $exception->getMessage() . "\n" );
	exit( 1 );
}

$connection = new Connection( $config );

$logger = function ( string $message ): void {
	$timestamp = gmdate( 'Y-m-d H:i:s' );
	fwrite( STDOUT, sprintf( "[%s UTC] %s\n", $timestamp, $message ) );
};

try {
	$background_runner = BackgroundRunnerFactory::create( $connection, $config, $logger );
	$scheduler         = $background_runner->get_scheduler();

	if ( null === $scheduler ) {
		fwrite( STDERR, "Scheduler could not be initialized.\n" );
		exit( 1 );
	}

	$logger( 'PeakURL background worker started.' );
	$logger( 'Processing due background jobs.' );

	$results     = $scheduler->run_due_jobs();
	$processed   = count( $results );
	$has_failure = false;

	foreach ( $results as $outcome ) {
		if ( 'failed' === ( $outcome['status'] ?? '' ) ) {
			$has_failure = true;
		}
	}

	$logger( sprintf( 'Processed %d due background job%s.', $processed, 1 === $processed ? '' : 's' ) );
	$logger( 'PeakURL background worker completed.' );

	exit( $has_failure ? 1 : 0 );
} catch ( \Throwable $exception ) {
	fwrite( STDERR, 'Fatal scheduler runner error: ' . $exception->getMessage() . "\n" );
	exit( 1 );
}
