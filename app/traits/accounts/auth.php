<?php
/**
 * Data store account authentication trait.
 *
 * @package PeakURL\Data
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Traits\Accounts;

use PeakURL\Includes\Constants;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Utils\Security;
use PeakURL\Utils\Secrets;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AuthTrait — registration, login, and password recovery methods.
 *
 * @since 1.0.14
 */
trait AuthTrait {

	/**
	 * Register a new user account.
	 *
	 * Validates email, username, and password; inserts the user row
	 * with an unverified email status; creates a session; and returns
	 * the hydrated user profile.
	 *
	 * @param Request              $request Incoming HTTP request (for session creation).
	 * @param array<string, mixed> $payload Body with `email`, `username`, `password`, optional `firstName`/`lastName`.
	 * @return array<string, mixed> Hydrated user profile.
	 *
	 * @throws ApiException On validation failure or duplicate email/username (422).
	 * @since 1.0.0
	 */
	public function register( Request $request, array $payload ): array {
		$email    = $this->validate_email( (string) ( $payload['email'] ?? '' ) );
		$username = $this->validate_username_required(
			(string) ( $payload['username'] ?? '' )
		);
		$password = $this->validate_password(
			(string) ( $payload['password'] ?? '' )
		);

		if ( $this->email_in_use( $email ) ) {
			throw new ApiException(
				__( 'Email address is already registered.', 'peakurl' ),
				422,
			);
		}

		if ( $this->username_in_use( $username ) ) {
			throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
		}

		$now                = $this->now();
		$verification_token = $this->issue_lookup_token();

		$this->db->insert(
			'users',
			array(
				'username'                      => $username,
				'email'                         => $email,
				'first_name'                    => trim( (string) ( $payload['firstName'] ?? 'New' ) ),
				'last_name'                     => trim( (string) ( $payload['lastName'] ?? 'User' ) ),
				'password_hash'                 => password_hash( $password, PASSWORD_DEFAULT ),
				'role'                          => 'editor',
				'is_email_verified'             => 0,
				'email_verification_token'      => $verification_token['hash'],
				'email_verification_expires_at' => gmdate(
					'Y-m-d H:i:s',
					strtotime( '+1 day' ),
				),
				'created_at'                    => $now,
				'updated_at'                    => $now,
			),
		);
		$user_id = $this->last_insert_id();

		$this->create_session_for_user( $request, $user_id );

		return $this->hydrate_user(
			$this->find_user_row_by_id( $user_id ),
			$request,
		);
	}

	/**
	 * Verify a user's email address via a token link.
	 *
	 * Marks the user as email-verified and clears the verification token.
	 *
	 * @param string $token Verification token from the email link.
	 * @return bool True if the token was valid and email verified; false otherwise.
	 *
	 * @throws ApiException When the token string is empty (422).
	 * @since 1.0.0
	 */
	public function verify_email( string $token ): bool {
		$token = trim( $token );

		if ( '' === $token ) {
			throw new ApiException( __( 'Verification token is required.', 'peakurl' ), 422 );
		}

		$token_hash = Secrets::hash_lookup_token( $token );

		$user = $this->query_one(
			'SELECT * FROM users
            WHERE email_verification_token = :token
            AND (email_verification_expires_at IS NULL OR email_verification_expires_at >= :now)
            LIMIT 1',
			array(
				'token' => $token_hash,
				'now'   => $this->now(),
			),
		);

		if ( ! $user ) {
			return false;
		}

		$this->execute(
			'UPDATE users
            SET is_email_verified = 1,
                email_verified_at = :email_verified_at,
                email_verification_token = NULL,
                email_verification_expires_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'email_verified_at' => $this->now(),
				'updated_at'        => $this->now(),
				'id'                => $user['id'],
			),
		);

		return true;
	}

	/**
	 * Resend the email verification token.
	 *
	 * Generates a new token with a 24-hour expiry for the given email.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with optional `email`.
	 * @return array<string, bool> `['resent' => true|false]`.
	 *
	 * @throws ApiException When no email can be determined (422).
	 * @since 1.0.0
	 */
	public function resend_verification( Request $request, array $payload ): array {
		$current_user = $this->resolve_current_user( $request, false );
		$email        = $this->normalize_email(
			(string) ( $payload['email'] ?? ( $current_user['email'] ?? '' ) )
		);

		if ( '' === $email ) {
			throw new ApiException( __( 'Email is required.', 'peakurl' ), 422 );
		}

		$user = $this->find_user_row_by_email( $email );

		if ( ! $user ) {
			return array( 'resent' => false );
		}

		$verification_token = $this->issue_lookup_token();

		$this->execute(
			'UPDATE users
            SET email_verification_token = :token,
                email_verification_expires_at = :expires_at,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'token'      => $verification_token['hash'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);

		return array( 'resent' => true );
	}

	/**
	 * Authenticate a user with email/username and password.
	 *
	 * If two-factor is enabled, returns a partial response with
	 * `requiresTwoFactor = true` unless a valid TOTP token or
	 * backup code is provided.
	 *
	 * @param Request              $request Incoming HTTP request (for session creation).
	 * @param array<string, mixed> $payload Body with `identifier`/`email`/`username`, `password`, optional `token`.
	 * @return array<string, mixed> Hydrated user + `requiresTwoFactor` flag.
	 *
	 * @throws ApiException On missing credentials (422) or invalid credentials (401).
	 * @since 1.0.0
	 */
	public function login( Request $request, array $payload ): array {
		$identifier = $this->normalize_email(
			(string) ( $payload['identifier'] ??
				( $payload['email'] ?? ( $payload['username'] ?? '' ) ) )
		);
		$password   = (string) ( $payload['password'] ?? '' );
		$token      = trim( (string) ( $payload['token'] ?? '' ) );

		if ( '' === $identifier || '' === $password ) {
			throw new ApiException(
				__( 'Email or username and password are required.', 'peakurl' ),
				422,
			);
		}

		$user = $this->query_one(
			'SELECT * FROM users
            WHERE LOWER(email) = :email_identifier OR LOWER(username) = :username_identifier
            LIMIT 1',
			array(
				'email_identifier'    => $identifier,
				'username_identifier' => $identifier,
			),
		);

		if (
			! $user ||
			! password_verify( $password, (string) $user['password_hash'] )
		) {
			throw new ApiException(
				__( 'Invalid email, username, or password.', 'peakurl' ),
				401,
			);
		}

		if ( ! empty( $user['two_factor_enabled'] ) ) {
			if ( '' === $token ) {
				return array(
					'user'              => $this->hydrate_user( $user, $request ),
					'requiresTwoFactor' => true,
				);
			}

			if (
				! $this->totp_service->verify_code(
					(string) $user['two_factor_secret'],
					$token,
				)
			) {
				if ( ! $this->consume_backup_code( (string) $user['id'], $token ) ) {
					throw new ApiException( __( 'Invalid two-factor code.', 'peakurl' ), 401 );
				}
			}
		}

		$this->create_session_for_user( $request, (string) $user['id'] );
		$this->db->update(
			'users',
			array(
				'last_login_at' => $this->now(),
				'updated_at'    => $this->now(),
			),
			array(
				'id' => $user['id'],
			),
		);

		return array(
			'user'              => $this->hydrate_user(
				$this->find_user_row_by_id( (string) $user['id'] ),
				$request,
			),
			'requiresTwoFactor' => false,
		);
	}

	/**
	 * Complete two-factor login after initial credentials were accepted.
	 *
	 * Delegates to login() and throws if 2FA is still pending.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `identifier`, `password`, and `token`.
	 * @return array<string, mixed> Hydrated user.
	 *
	 * @throws ApiException When the token is missing or invalid (422).
	 * @since 1.0.0
	 */
	public function verify_two_factor_login(
		Request $request,
		array $payload
	): array {
		$result = $this->login( $request, $payload );

		if ( ! empty( $result['requiresTwoFactor'] ) ) {
			throw new ApiException(
				__( 'Two-factor authentication is still required.', 'peakurl' ),
				422,
			);
		}

		return $result;
	}

	/**
	 * Log the current user out by revoking the active session.
	 *
	 * Marks the session row as revoked and expires the session cookie.
	 *
	 * @param Request $request Incoming HTTP request carrying the session cookie.
	 * @since 1.0.0
	 */
	public function logout( Request $request ): void {
		$session = $this->find_session_by_request( $request );

		if ( $session ) {
			$this->db->delete(
				'sessions',
				array(
					'id' => $session['id'],
				),
			);
		}

		$request->expire_cookie(
			(string) $this->config[ Constants::CONFIG_SESSION_COOKIE_NAME ],
			Security::session_cookie_options( $this->config, $request ),
		);
	}

	/**
	 * Initiate a password reset flow.
	 *
	 * Generates a one-hour reset token for the given email address or username.
	 * Always returns the supplied identifier to avoid user-enumeration leaks.
	 *
	 * @param array<string, mixed> $payload Body with `identifier` or `email`.
	 * @return array<string, string> `['identifier' => '…']`.
	 *
	 * @throws ApiException When the identifier is empty (422).
	 * @since 1.0.0
	 */
	public function forgot_password( array $payload ): array {
		$identifier = trim(
			(string) ( $payload['identifier'] ?? ( $payload['email'] ?? '' ) )
		);

		if ( '' === $identifier ) {
			throw new ApiException( __( 'Email or username is required.', 'peakurl' ), 422 );
		}

		$normalized_identifier = $this->normalize_email( $identifier );
		$user                  = filter_var(
			$normalized_identifier,
			FILTER_VALIDATE_EMAIL
		)
			? $this->find_user_row_by_email( $normalized_identifier )
			: $this->find_user_row_by_username( $identifier );
		$reset_token           = $this->issue_lookup_token();

		if ( ! $user ) {
			return array( 'identifier' => $identifier );
		}

		$this->execute(
			'UPDATE users
            SET password_reset_token = :token,
                password_reset_expires_at = :expires_at,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'token'      => $reset_token['hash'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);

		try {
			$this->notifications_service->send_password_reset_email(
				$user,
				$reset_token['raw'],
			);
		} catch ( \RuntimeException $exception ) {
			error_log(
				sprintf(
					'PeakURL mail error for password reset (%s): %s',
					(string) ( $user['email'] ?? 'unknown-email' ),
					$exception->getMessage(),
				),
			);
		}

		return array( 'identifier' => $identifier );
	}

	/**
	 * Reset a user's password using a valid reset token.
	 *
	 * @param string               $token   Password-reset token from the email link.
	 * @param array<string, mixed> $payload Body with `password` or `newPassword`.
	 * @return bool True if the token was valid and password updated.
	 *
	 * @throws ApiException When the new password is too short (422).
	 * @since 1.0.0
	 */
	public function reset_password( string $token, array $payload ): bool {
		$password = $this->validate_password(
			(string) ( $payload['password'] ?? ( $payload['newPassword'] ?? '' ) )
		);
		$token    = trim( $token );

		if ( '' === $token ) {
			return false;
		}

		$user = $this->query_one(
			'SELECT * FROM users
            WHERE password_reset_token = :token
            AND password_reset_expires_at >= :now
            LIMIT 1',
			array(
				'token' => Secrets::hash_lookup_token( $token ),
				'now'   => $this->now(),
			),
		);

		if ( ! $user ) {
			return false;
		}

		$this->execute(
			'UPDATE users
            SET password_hash = :password_hash,
                password_reset_token = NULL,
                password_reset_expires_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'password_hash' => password_hash( $password, PASSWORD_DEFAULT ),
				'updated_at'    => $this->now(),
				'id'            => $user['id'],
			),
		);
		$this->execute(
			'UPDATE sessions
			SET revoked_at = :revoked_at,
				revoked_reason = :revoked_reason
			WHERE user_id = :user_id
			AND revoked_at IS NULL',
			array(
				'revoked_at'     => $this->now(),
				'revoked_reason' => 'password_reset',
				'user_id'        => $user['id'],
			),
		);
		$this->send_password_changed_notification( $user );

		return true;
	}
}
