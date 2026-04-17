<?php
/**
 * Data store account support trait.
 *
 * @package PeakURL\Data
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Traits\Accounts;

use PeakURL\Http\ApiException;
use PeakURL\Utils\Secrets;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SupportTrait — shared account validation and notification helpers.
 *
 * @since 1.0.14
 */
trait SupportTrait {

	/**
	 * Normalize an email address for account lookups and writes.
	 *
	 * @param string $email Raw email input.
	 * @return string
	 * @since 1.0.14
	 */
	private function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Validate an email address and return its normalized value.
	 *
	 * @param string $email Raw email input.
	 * @return string
	 *
	 * @throws ApiException When the email is invalid.
	 * @since 1.0.14
	 */
	private function validate_email( string $email ): string {
		$email = $this->normalize_email( $email );

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new ApiException( __( 'A valid email address is required.', 'peakurl' ), 422 );
		}

		return $email;
	}

	/**
	 * Validate a required username field.
	 *
	 * @param string $username Raw username input.
	 * @param string $message  Message shown when the username is empty.
	 * @return string
	 *
	 * @throws ApiException When the username is empty.
	 * @since 1.0.14
	 */
	private function validate_username_required(
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
	 * @return string
	 *
	 * @throws ApiException When the username format is invalid.
	 * @since 1.0.14
	 */
	private function validate_username_pattern( string $username ): string {
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
	 * @return string
	 *
	 * @throws ApiException When the password is too short.
	 * @since 1.0.14
	 */
	private function validate_password( string $password ): string {
		if ( strlen( $password ) < 8 ) {
			throw new ApiException(
				__( 'Password must be at least 8 characters.', 'peakurl' ),
				422,
			);
		}

		return $password;
	}

	/**
	 * Check whether an email address already belongs to another user.
	 *
	 * @param string      $email           Normalized email address.
	 * @param string|null $exclude_user_id Optional user ID to ignore.
	 * @return bool
	 * @since 1.0.14
	 */
	private function email_in_use(
		string $email,
		?string $exclude_user_id = null
	): bool {
		$user = $this->find_user_row_by_email( $email );

		if ( ! $user ) {
			return false;
		}

		if ( null === $exclude_user_id ) {
			return true;
		}

		return (string) $user['id'] !== $exclude_user_id;
	}

	/**
	 * Check whether a username already belongs to another user.
	 *
	 * @param string      $username        Username to check.
	 * @param string|null $exclude_user_id Optional user ID to ignore.
	 * @return bool
	 * @since 1.0.14
	 */
	private function username_in_use(
		string $username,
		?string $exclude_user_id = null
	): bool {
		$user = $this->find_user_row_by_username( $username );

		if ( ! $user ) {
			return false;
		}

		if ( null === $exclude_user_id ) {
			return true;
		}

		return (string) $user['id'] !== $exclude_user_id;
	}

	/**
	 * Issue a raw + hashed lookup token pair for user account flows.
	 *
	 * Verification and password-reset links still send the raw token to the
	 * browser, but the database only stores its SHA-256 hash.
	 *
	 * @return array{raw: string, hash: string}
	 * @since 1.0.3
	 */
	private function issue_lookup_token(): array {
		$raw_token = Secrets::generate_lookup_token();

		return array(
			'raw'  => $raw_token,
			'hash' => Secrets::hash_lookup_token( $raw_token ),
		);
	}

	/**
	 * Ensure a sensitive account action is confirmed with the current password.
	 *
	 * @param array<string, mixed>|null $user_row         User database row.
	 * @param string                    $current_password Plain-text current password.
	 * @param string                    $missing_message  Validation message for missing input.
	 * @return array<string, mixed> Validated user row.
	 *
	 * @throws ApiException When the password is missing or incorrect.
	 * @since 1.0.6
	 */
	private function assert_password_confirmation_for_user(
		?array $user_row,
		string $current_password,
		string $missing_message
	): array {
		if ( ! $user_row ) {
			throw new ApiException(
				__( 'User account could not be loaded.', 'peakurl' ),
				404,
			);
		}

		if ( '' === $current_password ) {
			throw new ApiException( $missing_message, 422 );
		}

		if (
			! password_verify(
				$current_password,
				(string) ( $user_row['password_hash'] ?? '' ),
			)
		) {
			throw new ApiException(
				__( 'Current password is incorrect.', 'peakurl' ),
				422,
			);
		}

		return $user_row;
	}

	/**
	 * Ensure backup-code actions only run when 2FA is enabled.
	 *
	 * @param array<string, mixed> $user_row User database row.
	 * @return void
	 *
	 * @throws ApiException When 2FA is not enabled.
	 * @since 1.0.6
	 */
	private function assert_two_factor_is_enabled_for_user( array $user_row ): void {
		if ( empty( $user_row['two_factor_enabled'] ) ) {
			throw new ApiException(
				__(
					'Two-factor authentication is not enabled for this account.',
					'peakurl',
				),
				422,
			);
		}
	}

	/**
	 * Send a password-changed notification without blocking the write path.
	 *
	 * @param array<string, mixed> $user User database row.
	 * @return void
	 * @since 1.0.6
	 */
	private function send_password_changed_notification( array $user ): void {
		try {
			$this->notifications_service->send_password_changed_email( $user );
		} catch ( \RuntimeException $exception ) {
			error_log(
				sprintf(
					'PeakURL mail error for password changed (%s): %s',
					(string) ( $user['email'] ?? 'unknown-email' ),
					$exception->getMessage(),
				),
			);
		}
	}
}
