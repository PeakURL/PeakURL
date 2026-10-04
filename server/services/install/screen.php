<?php
/**
 * Release installer screen helpers.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Security\Security;

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
	 * Get a URL by combining the base path, suffix, and query arguments.
	 *
	 * @param string              $base_path Base path (may be empty).
	 * @param string              $suffix    Suffix to append.
	 * @param array<string, mixed> $query    Query arguments to append.
	 * @return string
	 * @since 1.0.14
	 */
	public static function format_url(
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
	 * Sanitize an internal redirect target path.
	 *
	 * Rejects external schemes, protocol-relative paths, backslash variants,
	 * CRLF characters, and malformed targets, ensuring only safe internal paths are used.
	 *
	 * @param string $target   Candidate redirect path.
	 * @param string $fallback Fallback internal path if target is unsafe.
	 * @return string Safe relative target path.
	 * @since 1.7.1
	 */
	public static function sanitize_redirect_target( string $target, string $fallback = '/dashboard' ): string {
		$target = trim( $target );

		if ( '' === $target ) {
			return $fallback;
		}

		if ( preg_match( '/[\r\n\x00-\x1F\x7F]/', $target ) ) {
			return $fallback;
		}

		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) || str_contains( $target, '\\' ) ) {
			return $fallback;
		}

		$decoded = rawurldecode( $target );
		if (
			preg_match( '/[\r\n\x00-\x1F\x7F]/', $decoded ) ||
			! str_starts_with( $decoded, '/' ) ||
			str_starts_with( $decoded, '//' ) ||
			str_contains( $decoded, '\\' )
		) {
			return $fallback;
		}

		$parts = parse_url( $target );
		if ( false === $parts || ! empty( $parts['scheme'] ) || ! empty( $parts['host'] ) ) {
			return $fallback;
		}

		$decoded_parts = parse_url( $decoded );
		if ( false === $decoded_parts || ! empty( $decoded_parts['scheme'] ) || ! empty( $decoded_parts['host'] ) ) {
			return $fallback;
		}

		return $target;
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
	 * Validate a browser-originating installer POST.
	 *
	 * Non-browser clients without Origin/Referer are allowed; browser posts
	 * must come from the detected site origin.
	 *
	 * @param string               $site_url Detected installer site URL.
	 * @param array<string, mixed> $server   Request server variables.
	 * @return void
	 *
	 * @throws \RuntimeException When the origin does not match the installer site.
	 * @since 1.1.1
	 */
	public static function validate_post_origin(
		string $site_url,
		array $server
	): void {
		$origin = Security::get_server_origin( $server );

		if ( null === $origin ) {
			return;
		}

		if ( Security::is_same_origin( array( Constants::SITE_URL => $site_url ), $origin ) ) {
			return;
		}

		throw new \RuntimeException(
			__( 'Request origin is not allowed.', 'peakurl' ),
		);
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
