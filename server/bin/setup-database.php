<?php
/**
 * PeakURL command-line database provisioning script.
 *
 * Initializes the database schema and bootstraps initial site data
 * via {@see Initializer::initialize_schema()} and {@see Initializer::bootstrap_site()}.
 *
 * Intended for Docker/CI bootstrapping—not for production use.
 *
 * Usage:
 *   Source checkout: php server/bin/setup-database.php
 *   Installed release: php bin/setup-database.php
 *
 * @package PeakURL\Scripts
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Install\Initializer;

$is_server_subdir = 'server' === basename( dirname( __DIR__ ) );
$release_root     = $is_server_subdir ? dirname( __DIR__, 2 ) : dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		rtrim( $release_root, '/\\' ) . DIRECTORY_SEPARATOR,
	);
}

require_once ABSPATH . 'load.php';

$environment = Environment::get_instance();
$environment->load_autoloader();
$runtime_root  = $environment->get_runtime_root();
$config        = Configuration::bootstrap( $runtime_root );
$database_name = (string) $config[ Constants::DB_DATABASE ];

try {
	Initializer::initialize_schema( $config, $runtime_root );

	$connection = new Connection( $config );
	Initializer::bootstrap_site( $connection, $config );

	fwrite( STDOUT, "Database ready: {$database_name}\n" );
	exit( 0 );
} catch ( \Throwable $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
