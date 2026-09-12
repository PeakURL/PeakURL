<?php
/**
 * PeakURL command-line database provisioning script.
 *
 * Creates the target database (if it does not exist), applies the
 * SQL schema from `database/schema.sql`, and saves initial
 * site data via {@see Initializer::initialize_site()}.
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

use PeakURL\Services\Database\Connection;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Database\Schema as DatabaseSchema;
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

$environment = \PeakURL\Core\Config\Environment::get_instance();
$environment->load_autoloader();
$runtime_root  = $environment->get_runtime_root();
$config        = Configuration::bootstrap( $runtime_root );
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

$schema_path = $environment->get_database_schema_path();

if ( ! file_exists( $schema_path ) ) {
	fwrite( STDERR, "Schema file not found at {$schema_path}\n" );
	exit( 1 );
}

$connection_manager = new Connection( $config );
$schema_service     = new DatabaseSchema( $connection_manager, $schema_path );
$schema_service->upgrade();

Initializer::bootstrap_site( $connection_manager, $config );

fwrite( STDOUT, "Database ready: {$database_name}\n" );
