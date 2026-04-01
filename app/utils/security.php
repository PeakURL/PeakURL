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
	 * Returns the request origin when it matches the configured SITE_URL,
	 * or falls back to the site origin. Returns '' when neither matches.
	 *
	 * @param array<string, mixed> $config        Merged runtime configuration.
	 * @param array<string, mixed> $server_params $_SERVER super-global.
	 * @return string Allowed origin URL, or '' for no match.
	 * @since 1.0.0
	 */
	public static function resolve_allowed_origin(
		array $config,
		array $server_params
	): string {
		$request_origin = trim( (string) ( $server_params['HTTP_ORIGIN'] ?? '' ) );
		$site_origin    = self::extract_origin( (string) ( $config['SITE_URL'] ?? '' ) );

		if ( '' === $request_origin ) {
			return $site_origin;
		}

		if ( '' !== $site_origin && $request_origin === $site_origin ) {
			return $request_origin;
		}

		return '';
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
			'path'     => (string) ( $config[ Constants::CONFIG_SESSION_COOKIE_PATH ] ?? '/' ),
			'httponly' => true,
			'samesite' =>
				(string) ( $config[ Constants::CONFIG_SESSION_COOKIE_SAME_SITE ] ?? Constants::DEFAULT_SESSION_COOKIE_SAME_SITE ),
			'secure'   => self::should_use_secure_cookies( $config, $request ),
		);

		$domain = trim( (string) ( $config[ Constants::CONFIG_SESSION_COOKIE_DOMAIN ] ?? '' ) );

		if ( '' !== $domain ) {
			$options['domain'] = $domain;
		}

		return array_merge( $options, $overrides );
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
	private static function should_use_secure_cookies(
		array $config,
		Request $request
	): bool {
		$mode = strtolower(
			trim(
				(string) ( $config[ Constants::CONFIG_SESSION_COOKIE_SECURE ] ?? Constants::DEFAULT_SESSION_COOKIE_SECURE ),
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
