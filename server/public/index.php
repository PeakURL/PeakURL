<?php
/**
 * PeakURL PHP app API entry point.
 *
 * Initializes Composer autoloading, configures CORS headers, and hands
 * control to the Application router.  Requests that arrive while a
 * `.maintenance` flag file exists receive a 503 JSON response.
 *
 * @package PeakURL
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Core\Application;
use PeakURL\Services\Database\Connection;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Security\Security;

$is_public_dir = 'public' === basename( __DIR__ );
$release_root  = $is_public_dir ? dirname( __DIR__, 2 ) : dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define(
		'ABSPATH',
		rtrim( $release_root, '/\\' ) . DIRECTORY_SEPARATOR,
	);
}

require_once ABSPATH . 'load.php';

$environment  = \PeakURL\Core\Config\Environment::get_instance();
$runtime_root = $environment->get_runtime_root();

// ── Maintenance-mode guard ──────────────────────────────────────

if ( file_exists( ABSPATH . '.maintenance' ) ) {
	$maintenance_view_data = array(
		'htmlLang'   => 'en-US',
		'apiMessage' => 'PeakURL is briefly unavailable right now. Please try again in a moment.',
	);
	$autoload_path         = $environment->get_vendor_autoload_path();

	if ( file_exists( $autoload_path ) ) {
		require_once $autoload_path;

		if ( function_exists( 'get_maintenance_view_data' ) ) {
			try {
				$maintenance_view_data = get_maintenance_view_data();
			} catch ( Throwable $exception ) {
				$maintenance_view_data = array(
					'htmlLang'   => 'en-US',
					'apiMessage' => 'PeakURL is briefly unavailable right now. Please try again in a moment.',
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
	echo function_exists( 'get_maintenance_api_payload' )
		? json_encode(
			get_maintenance_api_payload( $maintenance_view_data ),
			JSON_PRETTY_PRINT,
		)
		: json_encode(
			array(
				'success' => false,
				'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is briefly unavailable right now. Please try again in a moment.' ),
				'data'    => array(
					'maintenance' => true,
				),
			),
			JSON_PRETTY_PRINT,
		);
	exit();
}

// ── Autoloader ──────────────────────────────────────────────────

$environment->load_autoloader();

// ── CORS headers ────────────────────────────────────────────────

$config = Configuration::bootstrap( $runtime_root );
$origin = Security::get_allowed_origin( $config, $_SERVER );

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

// ── Initialize application ──────────────────────────────────────

$connection = new Connection( $config );
load_i18n( $config, $connection );

/**
 * Fires after PeakURL has loaded configuration, translations, and shared helpers.
 *
 * This is the main request-level initialization hook for custom PHP code.
 *
 * @since 1.2.2
 */
do_action( 'init' );

header( 'Content-Language: ' . get_html_lang_attribute() );
$application = new Application( $connection, $config );
$application->run();
