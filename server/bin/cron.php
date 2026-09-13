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

use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Scheduler\SchedulerFactory;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Services\Cache\CacheManager;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Services\Update\Manager as UpdateManager;

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
$db_prefix  = (string) ( $config[ Constants::DB_PREFIX ] ?? '' );
$db         = new PeakURL_DB( $connection, $db_prefix );

$content_dir   = (string) ( $config[ Constants::CONTENT_DIR ] ?? $environment->get_content_path() );
$settings_api  = new SettingsApi( $db );
$cache_service = CacheManager::resolve( $config, $content_dir );
$crypto        = new Crypto( $config );
$geoip         = new Geoip( $config, $settings_api, $crypto );
$roles         = new Roles();
$authorization = new Authorization( $roles );

$auth_service = new AuthService(
	$db,
	new UsersApi( $db ),
	new AuthCredentials( $db ),
	new AuthValidator(),
	new Totp(),
	new Notifications(),
	$crypto,
	$roles,
	$authorization,
	$geoip,
	$config
);

$webhooks_service = new WebhooksService(
	$db,
	new WebhooksValidator(),
	$auth_service,
	$roles,
	$authorization,
	$config
);

$update_manager = new UpdateManager( $config );

$social_preview    = new SocialPreview( $config, $settings_api );
$captcha           = new Captcha( $config, $settings_api, $crypto );
$links_api         = new LinksApi( $db, $cache_service );
$analytics_repo    = new AnalyticsRepository(
	$db,
	$settings_api,
	$geoip,
	$roles,
	$authorization,
	$webhooks_service,
	null,
	$config
);
$analytics_service = new AnalyticsService(
	$analytics_repo,
	$db,
	$auth_service,
	$roles,
	$authorization,
	$config,
	$links_api
);
$links_repo        = new LinksRepository(
	$db,
	$links_api,
	$authorization
);
$links_service     = new LinksService(
	$links_repo,
	new LinksValidator(),
	$settings_api,
	$auth_service,
	$analytics_service,
	$webhooks_service,
	$social_preview,
	$captcha,
	$roles,
	$authorization,
	$config
);
$analytics_repo->set_link_formatter( array( $links_service, 'format_url' ) );

$logger = function ( string $message ): void {
	$timestamp = gmdate( 'Y-m-d H:i:s' );
	fwrite( STDOUT, sprintf( "[%s UTC] %s\n", $timestamp, $message ) );
};

$scheduler = SchedulerFactory::create(
	$db,
	$config,
	$settings_api,
	$cache_service,
	$geoip,
	$webhooks_service,
	$update_manager,
	$logger,
	$links_api,
	$auth_service,
	$links_service,
	$analytics_service
);

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

	fwrite( STDOUT, sprintf( "%-30s %-10s %-12s %-20s %-20s\n", 'Job ID', 'Status', 'Interval', 'Next Run (UTC)', 'Last Run (UTC)' ) );
	fwrite( STDOUT, str_repeat( '-', 96 ) . "\n" );

	foreach ( $jobs as $job ) {
		$next = ! empty( $job['next_run_at'] ) ? substr( (string) $job['next_run_at'], 0, 19 ) : 'N/A';
		$last = ! empty( $job['last_run_at'] ) ? substr( (string) $job['last_run_at'], 0, 19 ) : 'Never';
		fwrite(
			STDOUT,
			sprintf(
				"%-30s %-10s %-12s %-20s %-20s\n",
				(string) $job['id'],
				(string) $job['status'],
				$job['interval_seconds'] . 's',
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
