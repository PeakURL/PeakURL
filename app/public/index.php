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

use PeakURL\Includes\Application;
use PeakURL\Includes\Connection;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Utils\Security;

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR,
	);
}

// ── Maintenance-mode guard ──────────────────────────────────────

if ( file_exists( ABSPATH . '.maintenance' ) ) {
	$maintenance_view_data = array(
		'htmlLang'   => 'en-US',
		'apiMessage' => 'PeakURL is updating. Please try again in a moment.',
	);
	$autoload_path         = __DIR__ . '/../vendor/autoload.php';

	if ( file_exists( $autoload_path ) ) {
		require_once $autoload_path;

		if ( function_exists( 'peakurl_get_maintenance_view_data' ) ) {
			try {
				$maintenance_view_data = peakurl_get_maintenance_view_data();
			} catch ( Throwable $exception ) {
				$maintenance_view_data = array(
					'htmlLang'   => 'en-US',
					'apiMessage' => 'PeakURL is updating. Please try again in a moment.',
				);
			}
		}
	}

	http_response_code( 503 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header(
		'Content-Language: ' .
		(string) ( $maintenance_view_data['htmlLang'] ?? 'en-US' ),
	);
	header( 'Retry-After: 60' );
	echo function_exists( 'peakurl_get_maintenance_api_payload' )
		? json_encode(
			peakurl_get_maintenance_api_payload( $maintenance_view_data ),
			JSON_PRETTY_PRINT,
		)
		: json_encode(
			array(
				'success' => false,
				'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is updating. Please try again in a moment.' ),
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

$config = RuntimeConfig::bootstrap( dirname( __DIR__ ) );
$origin = Security::resolve_allowed_origin( $config, $_SERVER );

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

$connection = new Connection( $config );
peakurl_bootstrap_i18n( $config, $connection );
header( 'Content-Language: ' . peakurl_get_html_lang_attribute() );
$application = new Application( $connection, $config );
$application->run();
