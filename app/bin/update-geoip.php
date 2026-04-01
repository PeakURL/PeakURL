<?php
/**
 * PeakURL command-line GeoLite2 database updater.
 *
 * Downloads or refreshes the local MaxMind GeoLite2 City database
 * using the credentials stored in PeakURL settings.
 *
 * Usage:
 *   Source checkout: php app/bin/update-geoip.php
 *   Installed release: php app/bin/update-geoip.php
 *
 * @package PeakURL\Scripts
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR,
	);
}

$autoload_path = __DIR__ . '/../vendor/autoload.php';

if ( ! file_exists( $autoload_path ) ) {
	fwrite(
		STDERR,
		"Composer autoload file not found. Run `composer install` inside the PHP runtime directory.\n",
	);
	exit( 1 );
}

require $autoload_path;

$config     = RuntimeConfig::bootstrap( dirname( __DIR__ ) );
$connection = new Connection( $config );
$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
$crypto     = new Crypto( $config );
$geoip      = new Geoip( $config, $settings, $crypto );

try {
	$status = $geoip->download_database();
	fwrite(
		STDOUT,
		sprintf(
			"GeoLite2 City database ready at %s\n",
			(string) ( $status['databasePath'] ?? 'unknown path' ),
		),
	);
	exit( 0 );
} catch ( \Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
