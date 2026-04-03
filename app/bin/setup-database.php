<?php
/**
 * PeakURL command-line database provisioning script.
 *
 * Creates the target database (if it does not exist), applies the
 * SQL schema from `database/schema.sql`, and seeds initial
 * workspace data via {@see Store::bootstrap_workspace()}.
 *
 * Intended for Docker/CI bootstrapping—not for production use.
 *
 * Usage:
 *   Source checkout: php app/bin/setup-database.php
 *   Installed release: php app/bin/setup-database.php
 *
 * @package PeakURL\Scripts
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Includes\Connection;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\DatabaseSchema;
use PeakURL\Store;

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR,
	);
}

// ── Autoloader ──────────────────────────────────────────────────

$autoload_path = __DIR__ . '/../vendor/autoload.php';

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
$database_name = preg_replace(
	'/[^A-Za-z0-9_]/',
	'',
	(string) $config['DB_DATABASE'],
);

if ( ! is_string( $database_name ) ) {
	$database_name = '';
}

if ( '' === $database_name ) {
	fwrite( STDERR, "Invalid database name.\n" );
	exit( 1 );
}

// ── Create the database if it does not exist ────────────────────

$server_dsn = sprintf(
	'mysql:host=%s;port=%d;charset=%s',
	(string) $config['DB_HOST'],
	(int) $config['DB_PORT'],
	(string) $config['DB_CHARSET'],
);

$server = new \PDO(
	$server_dsn,
	(string) $config['DB_USERNAME'],
	(string) $config['DB_PASSWORD'],
	array(
		\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
		\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
		\PDO::ATTR_EMULATE_PREPARES   => false,
	),
);

$database_charset = preg_replace(
	'/[^A-Za-z0-9_]/',
	'',
	(string) $config['DB_CHARSET'],
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

// ── Apply schema and seed workspace ─────────────────────────────

$schema_path = $base_path . '/database/schema.sql';

if ( ! file_exists( $schema_path ) ) {
	fwrite( STDERR, "Schema file not found at {$schema_path}\n" );
	exit( 1 );
}

$connection_manager = new Connection( $config );
$schema_service     = new DatabaseSchema( $connection_manager, $schema_path );
$schema_service->upgrade();

$data_store = new Store( $connection_manager, $config );
$data_store->bootstrap_workspace();

fwrite( STDOUT, "Database ready: {$database_name}\n" );
