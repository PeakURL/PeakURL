<?php
/**
 * Release installer site URL helpers.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Site — shared site URL validation and cookie-path helpers for installs.
 *
 * @since 1.0.14
 */
class Site {

	/**
	 * Validate and normalize a release site URL.
	 *
	 * @param string $site_url Raw site URL input.
	 * @return string
	 *
	 * @throws \RuntimeException When the URL is invalid.
	 * @since 1.0.14
	 */
	public static function normalize_url( string $site_url ): string {
		$site_url = trim( $site_url );

		if ( '' === $site_url ) {
			throw new \RuntimeException( __( 'Site URL is required.', 'peakurl' ) );
		}

		if ( ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
			throw new \RuntimeException( __( 'Site URL must be a valid URL.', 'peakurl' ) );
		}

		$parts = parse_url( $site_url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			throw new \RuntimeException( __( 'Site URL must include a valid host.', 'peakurl' ) );
		}

		if ( ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			throw new \RuntimeException(
				__( 'Site URL cannot contain a query string or fragment.', 'peakurl' ),
			);
		}

		return rtrim( $site_url, '/' );
	}

	/**
	 * Derive the session cookie path for the given site URL.
	 *
	 * @param string $site_url Validated site URL.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_cookie_path( string $site_url ): string {
		$path = parse_url( $site_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return '/';
		}

		return rtrim( $path, '/' ) . '/';
	}
}
