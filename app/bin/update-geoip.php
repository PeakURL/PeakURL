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

$config   = Runtime_Config::bootstrap( dirname( __DIR__ ) );
$database = new Database( $config );
$settings = new Settings_API( new PeakURL_DB( $database ) );
$crypto   = new Crypto_Service( $config );
$geoip    = new Geoip_Service( $config, $settings, $crypto );

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
} catch ( Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
