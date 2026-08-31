<?php
/**
 * Shared runtime security helpers.
 *
 * Provides origin validation, session cookie option building, and secure
 * cookie mode detection used across controllers and the Application kernel.
 *
 * @package PeakURL\Utils
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Utils;

use PeakURL\Includes\Constants;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static runtime security utility methods.
 *
 * @since 1.0.0
 */
class Security {

	/**
	 * Resolve the Access-Control-Allow-Origin value for the response.
	 *
	 * Returns the request origin when it matches the configured site URL,
	 * when it belongs to a browser extension, or when Bearer token authorization
	 * is present. Falls back to the site origin. Returns '' when neither matches.
	 *
	 * @param array<string, mixed> $config        Merged runtime configuration.
	 * @param array<string, mixed> $server_params $_SERVER super-global.
	 * @return string Allowed origin URL, or '' for no match.
	 * @since 1.0.0
	 */
	public static function get_allowed_origin(
		array $config,
		array $server_params
	): string {
		$request_origin = self::extract_origin( (string) ( $server_params['HTTP_ORIGIN'] ?? '' ) );
		$site_origin    = self::extract_origin( (string) ( $config[ Constants::SITE_URL ] ?? '' ) );

		if ( '' === $request_origin ) {
			return $site_origin;
		}

		if (
			self::is_same_origin( $config, $request_origin ) ||
			self::is_extension_origin( $request_origin )
		) {
			return $request_origin;
		}

		$auth = (string) ( $server_params['HTTP_AUTHORIZATION'] ?? '' );
		if ( '' !== $auth && preg_match( '/^Bearer\s+/i', trim( $auth ) ) ) {
			return $request_origin;
		}

		return '';
	}

	/**
	 * Return the request origin from Origin or Referer headers.
	 *
	 * @param Request $request Incoming request.
	 * @return string|null Normalized origin, empty string when invalid, or null when absent.
	 * @since 1.1.1
	 */
	public static function get_request_origin( Request $request ): ?string {
		$origin = trim( (string) $request->get_header( 'Origin', '' ) );

		if ( '' === $origin ) {
			$origin = trim( (string) $request->get_header( 'Referer', '' ) );
		}

		if ( '' === $origin ) {
			return null;
		}

		return self::extract_origin( $origin );
	}

	/**
	 * Return the request origin from server header variables.
	 *
	 * @param array<string, mixed> $server_params $_SERVER super-global.
	 * @return string|null Normalized origin, empty string when invalid, or null when absent.
	 * @since 1.1.1
	 */
	public static function get_server_origin( array $server_params ): ?string {
		$origin = trim( (string) ( $server_params['HTTP_ORIGIN'] ?? '' ) );

		if ( '' === $origin ) {
			$origin = trim( (string) ( $server_params['HTTP_REFERER'] ?? '' ) );
		}

		if ( '' === $origin ) {
			return null;
		}

		return self::extract_origin( $origin );
	}

	/**
	 * Return whether a request origin matches the configured site origin.
	 *
	 * @param array<string, mixed> $config Runtime config.
	 * @param string               $origin Request origin URL.
	 * @return bool True when both origins match.
	 * @since 1.1.1
	 */
	public static function is_same_origin( array $config, string $origin ): bool {
		$request_origin = self::extract_origin( $origin );
		$site_origin    = self::extract_origin( (string) ( $config[ Constants::SITE_URL ] ?? '' ) );

		return '' !== $request_origin &&
			'' !== $site_origin &&
			hash_equals( $site_origin, $request_origin );
	}

	/**
	 * Return whether a request origin matches a browser extension origin.
	 *
	 * Supports Chrome/Edge (chrome-extension://), Firefox (moz-extension://),
	 * and Safari (safari-web-extension://).
	 *
	 * @param string $origin Request origin URL.
	 * @return bool True when the origin belongs to a browser extension.
	 * @since 1.5.4
	 */
	public static function is_extension_origin( string $origin ): bool {
		$origin = strtolower( trim( $origin ) );

		return 0 === strpos( $origin, 'chrome-extension://' ) ||
			0 === strpos( $origin, 'moz-extension://' ) ||
			0 === strpos( $origin, 'safari-web-extension://' );
	}

	/**
	 * Build session cookie options from the application configuration.
	 *
	 * Merges defaults (path, httponly, samesite, secure) with any overrides.
	 *
	 * @param array<string, mixed> $config    Merged runtime configuration.
	 * @param Request              $request   Current HTTP request.
	 * @param array<string, mixed> $overrides Additional cookie option overrides.
	 * @return array<string, mixed> Cookie option map for queue_cookie().
	 * @since 1.0.0
	 */
	public static function session_cookie_options(
		array $config,
		Request $request,
		array $overrides = array()
	): array {
		$options = array(
			'path'     => (string) ( $config[ Constants::SESSION_COOKIE_PATH ] ?? '/' ),
			'httponly' => true,
			'samesite' =>
				(string) ( $config[ Constants::SESSION_COOKIE_SAME_SITE ] ?? Constants::DEFAULT_SESSION_COOKIE_SAME_SITE ),
			'secure'   => self::use_secure_cookies( $config, $request ),
		);

		$domain = trim( (string) ( $config[ Constants::SESSION_COOKIE_DOMAIN ] ?? '' ) );

		if ( '' !== $domain ) {
			$options['domain'] = $domain;
		}

		return array_merge( $options, $overrides );
	}

	/**
	 * Check whether an IP address is publicly routable.
	 *
	 * @param string $ip_address Candidate IP address.
	 * @return bool True when the address is not private or reserved.
	 * @since 1.1.1
	 */
	public static function is_public_ip_address( string $ip_address ): bool {
		$ip_address = trim( $ip_address );

		if ( '' === $ip_address ) {
			return false;
		}

		return false !== filter_var(
			$ip_address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		);
	}

	/**
	 * Determine whether cookies should be sent with the Secure attribute.
	 *
	 * Supports explicit 'true'/'false' overrides and 'auto' mode that
	 * defers to Request::is_secure().
	 *
	 * @param array<string, mixed> $config  Merged runtime configuration.
	 * @param Request              $request Current HTTP request.
	 * @return bool True when the Secure flag should be set.
	 * @since 1.0.0
	 */
	private static function use_secure_cookies(
		array $config,
		Request $request
	): bool {
		$mode = strtolower(
			trim(
				(string) ( $config[ Constants::SESSION_COOKIE_SECURE ] ?? Constants::DEFAULT_SESSION_COOKIE_SECURE ),
			),
		);

		if ( in_array( $mode, array( 'true', '1', 'yes', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $mode, array( 'false', '0', 'no', 'off' ), true ) ) {
			return false;
		}

		return $request->is_secure();
	}

	/**
	 * Extract the origin (scheme + host + optional port) from a URL.
	 *
	 * @param string $url Full URL or empty string.
	 * @return string Origin string, or '' when the URL is invalid.
	 * @since 1.0.0
	 */
	private static function extract_origin( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		$parts = parse_url( $url );

		if (
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] )
		) {
			return '';
		}

		$origin =
			strtolower( (string) $parts['scheme'] ) .
			'://' .
			strtolower( (string) $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}
}
