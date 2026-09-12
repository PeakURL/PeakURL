<?php
/**
 * PeakURL command-line GeoLite2 database updater.
 *
 * Downloads or refreshes the local MaxMind GeoLite2 City database
 * using the credentials stored in PeakURL settings.
 *
 * Usage:
 *   Source checkout: php server/bin/update-geoip.php
 *   Installed release: php server/bin/update-geoip.php
 *
 * @package PeakURL\Scripts
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;

$is_server_subdir = 'server' === basename( dirname( __DIR__ ) );
$release_root     = $is_server_subdir ? dirname( __DIR__, 2 ) : dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		rtrim( $release_root, '/\\' ) . DIRECTORY_SEPARATOR,
	);
}

require_once ABSPATH . 'load.php';

$environment = \PeakURL\Core\Config\Environment::get_instance();
$environment->load_autoloader();
$runtime_root = $environment->get_runtime_root();
$config       = Configuration::bootstrap( $runtime_root );
$connection   = new Connection( $config );
$settings     = new SettingsApi( new PeakURL_DB( $connection ) );
$crypto       = new Crypto( $config );
$geoip        = new Geoip( $config, $settings, $crypto );

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
