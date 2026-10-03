<?php
/**
 * PeakURL command-line cron and background jobs runner.
 *
 * Discovers and executes due background maintenance jobs. Designed for
 * shared-hosting and cPanel cron configurations.
 *
 * Usage:
 *   Run all due jobs:
 *     php server/bin/cron.php
 *     php bin/cron.php (installed release)
 *
 *   List registered jobs and schedules:
 *     php server/bin/cron.php --list
 *
 *   Run a specific job immediately:
 *     php server/bin/cron.php --job=peakurl_session_cleanup --force
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

$background_runner = BackgroundRunnerFactory::create( $connection, $config, $logger );
$scheduler         = $background_runner->get_scheduler();

// ── Command line argument parsing ─────────────────────────────────

$options = getopt( '', array( 'list', 'job:', 'force', 'help' ) );

if ( isset( $options['help'] ) ) {
	fwrite(
		STDOUT,
		"Usage:\n" .
		"  php cron.php                  Run all due jobs\n" .
		"  php cron.php --list           List registered background jobs\n" .
		"  php cron.php --job=<id>       Run a specific job\n" .
		"  php cron.php --job=<id> --force Run a specific job immediately\n"
	);
	exit( 0 );
}

if ( isset( $options['list'] ) ) {
	$status = $scheduler->get_status();
	$jobs   = $status['jobs'] ?? array();

	$format_cadence = function ( int $seconds, ?string $pref_time = null ): string {
		$time_suffix = ! empty( $pref_time ) ? ' @ ' . $pref_time : '';
		switch ( $seconds ) {
			case 300:
				return 'Every 5m';
			case 900:
				return 'Every 15m';
			case 1800:
				return 'Every 30m';
			case 3600:
				return 'Hourly';
			case 7200:
				return 'Every 2h';
			case 21600:
				return 'Every 6h';
			case 43200:
				return 'Every 12h';
			case 86400:
				return 'Daily' . $time_suffix;
			case 604800:
				return 'Weekly' . $time_suffix;
			default:
				if ( 0 === $seconds % 86400 ) {
					return sprintf( 'Every %dd%s', (int) ( $seconds / 86400 ), $time_suffix );
				}
				if ( 0 === $seconds % 3600 ) {
					return sprintf( 'Every %dh', (int) ( $seconds / 3600 ) );
				}
				if ( 0 === $seconds % 60 ) {
					return sprintf( 'Every %dm', (int) ( $seconds / 60 ) );
				}
				return $seconds . 's';
		}
	};

	fwrite( STDOUT, sprintf( "%-30s %-10s %-24s %-20s %-20s\n", 'Job ID', 'Status', 'Schedule', 'Next Run (UTC)', 'Last Run (UTC)' ) );
	fwrite( STDOUT, str_repeat( '-', 108 ) . "\n" );

	foreach ( $jobs as $job ) {
		$next         = ! empty( $job['next_run_at'] ) ? substr( (string) $job['next_run_at'], 0, 19 ) : 'N/A';
		$last         = ! empty( $job['last_run_at'] ) ? substr( (string) $job['last_run_at'], 0, 19 ) : 'Never';
		$status_label = empty( $job['is_enabled'] ) ? 'disabled' : (string) ( $job['status'] ?? 'idle' );
		$cur_schedule = $format_cadence( (int) $job['interval_seconds'], $job['preferred_run_time'] ?? null );
		$is_custom    = ! empty( $job['is_customized'] );
		$sched_label  = $is_custom ? $cur_schedule . ' (Custom)' : $cur_schedule;

		fwrite(
			STDOUT,
			sprintf(
				"%-30s %-10s %-24s %-20s %-20s\n",
				(string) $job['id'],
				$status_label,
				$sched_label,
				$next,
				$last
			)
		);
	}
	exit( 0 );
}

if ( isset( $options['job'] ) ) {
	$job_id = (string) $options['job'];
	$force  = isset( $options['force'] );

	if ( ! $scheduler->get_registry()->has( $job_id ) ) {
		fwrite( STDERR, sprintf( "Error: Unknown background job [%s]. Run with --list to view registered jobs.\n", $job_id ) );
		exit( 2 );
	}

	try {
		$result = $scheduler->run_job( $job_id, $force );

		if ( $result->is_failure() ) {
			fwrite( STDERR, sprintf( "Job [%s] failed: %s\n", $job_id, (string) $result->get_error() ) );
			exit( 1 );
		}

		exit( 0 );
	} catch ( \Throwable $exception ) {
		fwrite( STDERR, sprintf( "Execution exception for [%s]: %s\n", $job_id, $exception->getMessage() ) );
		exit( 1 );
	}
}

// Default: Run all due jobs.
try {
	$results     = $scheduler->run_due_jobs();
	$has_failure = false;

	foreach ( $results as $job_id => $outcome ) {
		if ( 'failed' === ( $outcome['status'] ?? '' ) ) {
			$has_failure = true;
		}
	}

	$count = count( $results );
	if ( 0 === $count ) {
		$logger( 'No background jobs currently due.' );
	} else {
		$logger( sprintf( 'Completed %d due background job(s).', $count ) );
	}

	exit( $has_failure ? 1 : 0 );
} catch ( \Throwable $exception ) {
	fwrite( STDERR, 'Fatal scheduler runner error: ' . $exception->getMessage() . "\n" );
	exit( 1 );
}
