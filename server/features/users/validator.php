<?php
/**
 * Users input validator.
 *
 * Validates user profiles, logins, credentials, and role payloads.
 *
 * @package PeakURL\Features\Users
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Users;

use PeakURL\Core\Errors\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — User input validation logic.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Validate an email address and return its normalized value.
	 *
	 * @param string $email Raw email input.
	 * @return string Normalized email address.
	 *
	 * @throws ApiException When the email is invalid.
	 * @since 1.0.0
	 */
	public function validate_email( string $email ): string {
		$email = sanitize_email( $email );

		if ( false === is_email( $email ) ) {
			throw new ApiException( __( 'A valid email address is required.', 'peakurl' ), 422 );
		}

		return $email;
	}

	/**
	 * Validate a username against the account rules.
	 *
	 * Enforces professional username format: lowercase alphanumeric, hyphens, and underscores.
	 *
	 * @param string $username Raw username input.
	 * @return string Validated and normalized lowercase username.
	 *
	 * @throws ApiException When the username format is invalid.
	 * @since 1.0.0
	 */
	public function validate_user_login( string $username ): string {
		$username = strtolower( trim( $username ) );

		if ( ! preg_match( '/^[a-z0-9_-]{3,120}$/', $username ) ) {
			throw new ApiException(
				__( 'Username must be 3-120 characters using lowercase letters, numbers, hyphens, and underscores.', 'peakurl' ),
				422,
			);
		}

		return $username;
	}

	/**
	 * Validate a password against the current account rules.
	 *
	 * @param string $password Plain-text password.
	 * @return string Validated password.
	 *
	 * @throws ApiException When the password is too short.
	 * @since 1.0.0
	 */
	public function validate_password( string $password ): string {
		if ( strlen( $password ) < 8 ) {
			throw new ApiException(
				__( 'Password must be at least 8 characters.', 'peakurl' ),
				422,
			);
		}

		return $password;
	}
}
