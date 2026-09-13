<?php
/**
 * PeakURL early runtime loader.
 *
 * Resolves the application runtime layout according to PEAKURL_DEV
 * before Composer autoloading is initialized.
 *
 * @package PeakURL
 * @since 1.6.3
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

$peakurl_raw_dev = getenv( 'PEAKURL_DEV' );

if ( false === $peakurl_raw_dev || '' === $peakurl_raw_dev ) {
	$peakurl_raw_dev = $_ENV['PEAKURL_DEV'] ?? ( $_SERVER['PEAKURL_DEV'] ?? false );
}

$is_peakurl_dev = true === filter_var(
	$peakurl_raw_dev,
	FILTER_VALIDATE_BOOLEAN,
	FILTER_NULL_ON_FAILURE,
);

$peakurl_source_root  = rtrim( ABSPATH, '/\\' );
$peakurl_runtime_root = $is_peakurl_dev ? $peakurl_source_root . '/server' : $peakurl_source_root;

if ( $is_peakurl_dev && ! is_dir( $peakurl_runtime_root ) ) {
	throw new \RuntimeException(
		sprintf(
			'PEAKURL_DEV is set to true, but the development directory "server/" was not found at %s.',
			$peakurl_source_root,
		),
	);
}

$peakurl_environment_file = $peakurl_runtime_root . '/core/config/environment.php';

if ( ! file_exists( $peakurl_environment_file ) ) {
	throw new \RuntimeException(
		sprintf(
			'Environment definition file not found at "%s".',
			$peakurl_environment_file,
		),
	);
}

require_once $peakurl_environment_file;

\PeakURL\Core\Config\Environment::initialize( $peakurl_source_root, $is_peakurl_dev );
