<?php
/**
 * Authentication primitives and token helpers.
 *
 * @package PeakURL\Core\Auth
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Auth;

use PeakURL\Core\Config\Constants;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Authentication helper for request credential extraction.
 *
 * @since 1.0.0
 */
class Authentication {

	/**
	 * Extract Bearer token from the Authorization header.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return string|null Bearer token or null if not present.
	 */
	public static function get_bearer_token( Request $request ): ?string {
		$auth = trim( (string) $request->get_header( 'Authorization', '' ) );

		if ( '' === $auth && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = trim( (string) $_SERVER['HTTP_AUTHORIZATION'] );
		}

		if ( '' !== $auth && preg_match( '/^Bearer\s+(.+)$/i', $auth, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Extract session ID from the session cookie.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $config  Runtime configuration.
	 * @return string|null Session token or null if not found.
	 */
	public static function get_session_cookie( Request $request, array $config = array() ): ?string {
		$cookie_name = (string) ( $config[ Constants::SESSION_COOKIE_NAME ] ?? Constants::DEFAULT_SESSION_COOKIE_NAME );
		$cookie_val  = $request->get_cookie( $cookie_name );

		if ( is_string( $cookie_val ) && '' !== trim( $cookie_val ) ) {
			return trim( $cookie_val );
		}

		return null;
	}
}
