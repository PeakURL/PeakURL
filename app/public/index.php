<?php
/**
 * PeakURL PHP app API entry point.
 *
 * Bootstraps Composer autoloading, configures CORS headers, and hands
 * control to the Application router.  Requests that arrive while a
 * `.maintenance` flag file exists receive a 503 JSON response.
 *
 * @package PeakURL\App
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR,
	);
}

// ── Maintenance-mode guard ──────────────────────────────────────

if ( file_exists( ABSPATH . '.maintenance' ) ) {
	http_response_code( 503 );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'success' => false,
			'message' => 'PeakURL is updating. Please try again in a moment.',
			'data'    => array(
				'maintenance' => true,
			),
		),
		JSON_PRETTY_PRINT,
	);
	exit();
}

// ── Autoloader ──────────────────────────────────────────────────

$autoload_path = __DIR__ . '/../vendor/autoload.php';

if ( ! file_exists( $autoload_path ) ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'success' => false,
			'message' =>
				'Composer autoload file not found. Run `composer install` inside the PHP runtime directory.',
		),
		JSON_PRETTY_PRINT,
	);
	exit();
}

require_once $autoload_path;

// ── CORS headers ────────────────────────────────────────────────

$config = Config::load( dirname( __DIR__ ) );
$origin = Security_Utils::resolve_allowed_origin( $config, $_SERVER );

if ( '' !== $origin ) {
	header( 'Access-Control-Allow-Origin: ' . $origin );
}

header( 'Vary: Origin' );
header(
	'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With',
);
header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
header( 'Access-Control-Allow-Credentials: true' );

if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
	http_response_code( 204 );
	exit();
}

// ── Bootstrap application ───────────────────────────────────────

$database    = new Database( $config );
$application = new Application( $database, $config );
$application->run();
