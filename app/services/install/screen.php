<?php
/**
 * Release installer screen helpers.
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
 * Screen — shared URL, request, and escaping helpers for installer screens.
 *
 * @since 1.0.14
 */
class Screen {

	/**
	 * Derive the URL base path from SCRIPT_NAME.
	 *
	 * @param string $script_name Value of $_SERVER['SCRIPT_NAME'].
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_base_path( string $script_name ): string {
		$base_path = str_replace( '\\', '/', dirname( $script_name ) );

		if ( '.' === $base_path || '/' === $base_path ) {
			return '';
		}

		return rtrim( $base_path, '/' );
	}

	/**
	 * Build a URL by combining the base path, suffix, and query arguments.
	 *
	 * @param string              $base_path Base path (may be empty).
	 * @param string              $suffix    Suffix to append.
	 * @param array<string, mixed> $query    Query arguments to append.
	 * @return string
	 * @since 1.0.14
	 */
	public static function build_url(
		string $base_path,
		string $suffix,
		array $query = array()
	): string {
		$normalized_suffix = '/' . ltrim( $suffix, '/' );
		$url               = '' === $base_path
			? $normalized_suffix
			: $base_path . $normalized_suffix;
		$query             = array_filter(
			$query,
			static function ( $value ): bool {
				return '' !== trim( (string) $value );
			},
		);

		if ( empty( $query ) ) {
			return $url;
		}

		return $url . '?' . http_build_query( $query );
	}

	/**
	 * Return an escaped form field value for safe HTML output.
	 *
	 * @param array<string, string> $values Current form values.
	 * @param string                $key    Field name.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_escaped_value(
		array $values,
		string $key
	): string {
		return htmlspecialchars(
			(string) ( $values[ $key ] ?? '' ),
			ENT_QUOTES,
			'UTF-8',
		);
	}

	/**
	 * Detect the installer site URL from the current server variables.
	 *
	 * @param string               $base_path Request base path.
	 * @param array<string, mixed> $server    Request server variables.
	 * @return string
	 * @since 1.0.14
	 */
	public static function detect_site_url(
		string $base_path,
		array $server
	): string {
		$scheme = self::is_secure_request( $server ) ? 'https' : 'http';
		$host   = self::get_request_host( $server );

		return $scheme . '://' . $host . $base_path;
	}

	/**
	 * Return the sanitized request host used for installer defaults.
	 *
	 * @param array<string, mixed> $server Request server variables.
	 * @return string
	 * @since 1.0.14
	 */
	private static function get_request_host( array $server ): string {
		$host = trim( (string) ( $server['HTTP_HOST'] ?? '' ) );

		if ( '' === $host ) {
			return 'localhost';
		}

		$parts = parse_url( 'http://' . $host );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'localhost';
		}

		$normalized_host = (string) $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$normalized_host .= ':' . (string) (int) $parts['port'];
		}

		return $normalized_host;
	}

	/**
	 * Determine whether the current request should be treated as secure.
	 *
	 * @param array<string, mixed> $server Request server variables.
	 * @return bool
	 * @since 1.0.14
	 */
	private static function is_secure_request( array $server ): bool {
		return (
			( ! empty( $server['HTTPS'] ) &&
				'off' !== strtolower( (string) $server['HTTPS'] ) ) ||
			'https' === strtolower( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) )
		);
	}
}
