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
	 * Validate a required username field.
	 *
	 * @param string $username Raw username input.
	 * @param string $message  Message shown when the username is empty.
	 * @return string Cleaned username.
	 *
	 * @throws ApiException When the username is empty.
	 * @since 1.0.0
	 */
	public function validate_username(
		string $username,
		string $message = ''
	): string {
		$username = trim( $username );

		if ( '' === $message ) {
			$message = __( 'Username is required.', 'peakurl' );
		}

		if ( '' === $username ) {
			throw new ApiException( $message, 422 );
		}

		return $username;
	}

	/**
	 * Validate a username against the admin user-management rules.
	 *
	 * @param string $username Raw username input.
	 * @return string Validated username.
	 *
	 * @throws ApiException When the username format is invalid.
	 * @since 1.0.0
	 */
	public function validate_user_login( string $username ): string {
		$username = trim( $username );

		if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,120}$/', $username ) ) {
			throw new ApiException(
				__( 'Username must be 3-120 characters using letters, numbers, dots, dashes, underscores, or @.', 'peakurl' ),
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
