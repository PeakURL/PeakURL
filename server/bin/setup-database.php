<?php
/**
 * PeakURL command-line database provisioning script.
 *
 * Creates the target database (if it does not exist), applies the
 * SQL schema from `database/schema.sql`, and saves initial
 * site data via {@see Bootstrap::bootstrap_site()}.
 *
 * Intended for Docker/CI bootstrapping—not for production use.
 *
 * Usage:
 *   Source checkout: php server/bin/setup-database.php
 *   Installed release: php server/bin/setup-database.php
 *
 * @package PeakURL\Scripts
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Services\Database\Connection;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\RuntimeConfig;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Install\Bootstrap;

$is_server_subdir = 'server' === basename( dirname( __DIR__ ) );
$release_root     = $is_server_subdir ? dirname( __DIR__, 2 ) : dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		rtrim( $release_root, '/\\' ) . DIRECTORY_SEPARATOR,
	);
}

// ── Autoloader ──────────────────────────────────────────────────

$autoload_path = file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' )
	? dirname( __DIR__ ) . '/vendor/autoload.php'
	: $release_root . '/vendor/autoload.php';

if ( ! file_exists( $autoload_path ) ) {
	fwrite(
		STDERR,
		"Composer autoload file not found. Run `composer install` inside the PHP runtime directory.\n",
	);
	exit( 1 );
}

require $autoload_path;

// ── Load config and validate database name ──────────────────────

$base_path     = dirname( __DIR__ );
$config        = RuntimeConfig::bootstrap( $base_path );
$database_name = (string) $config[ Constants::DB_DATABASE ];

// ── Create the database if it does not exist ────────────────────

$server_dsn = sprintf(
	'mysql:host=%s;port=%d;charset=%s',
	(string) $config[ Constants::DB_HOST ],
	(int) $config[ Constants::DB_PORT ],
	(string) $config[ Constants::DB_CHARSET ],
);

$server = new \PDO(
	$server_dsn,
	(string) $config[ Constants::DB_USERNAME ],
	(string) $config[ Constants::DB_PASSWORD ],
	array(
		\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
		\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
		\PDO::ATTR_EMULATE_PREPARES   => false,
	),
);

$database_charset = preg_replace(
	'/[^A-Za-z0-9_]/',
	'',
	(string) $config[ Constants::DB_CHARSET ],
);

if ( ! is_string( $database_charset ) || '' === $database_charset ) {
	$database_charset = 'utf8mb4';
}

$server->exec(
	sprintf(
		'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE utf8mb4_unicode_ci',
		$database_name,
		$database_charset,
	),
);

// ── Apply schema and create site data ──────────────────────

$schema_path = $base_path . '/database/schema.sql';

if ( ! file_exists( $schema_path ) ) {
	fwrite( STDERR, "Schema file not found at {$schema_path}\n" );
	exit( 1 );
}

$connection_manager = new Connection( $config );
$schema_service     = new DatabaseSchema( $connection_manager, $schema_path );
$schema_service->upgrade();

Bootstrap::bootstrap_site( $connection_manager, $config );

fwrite( STDOUT, "Database ready: {$database_name}\n" );
