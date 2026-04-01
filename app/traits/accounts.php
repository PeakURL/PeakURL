<?php
/**
 * Data store accounts trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AccountsTrait — auth, users, and security methods for Store.
 *
 * @since 1.0.0
 */
trait AccountsTrait {

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
		$email    = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$username = trim( (string) ( $payload['username'] ?? '' ) );
		$password = (string) ( $payload['password'] ?? '' );

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new ApiException( 'A valid email address is required.', 422 );
		}

		if ( '' === $username ) {
			throw new ApiException( 'Username is required.', 422 );
		}

		if ( strlen( $password ) < 8 ) {
			throw new ApiException(
				'Password must be at least 8 characters.',
				422,
			);
		}

		if ( $this->find_user_row_by_email( $email ) ) {
			throw new ApiException(
				'Email address is already registered.',
				422,
			);
		}

		if ( $this->find_user_row_by_username( $username ) ) {
			throw new ApiException( 'Username is already taken.', 422 );
		}

		$now                = $this->now();
		$verification_token = bin2hex( random_bytes( 20 ) );

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
				'email_verification_token'      => $verification_token,
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
			throw new ApiException( 'Verification token is required.', 422 );
		}

		$user = $this->query_one(
			'SELECT * FROM users
            WHERE email_verification_token = :token
            AND (email_verification_expires_at IS NULL OR email_verification_expires_at >= :now)
            LIMIT 1',
			array(
				'token' => $token,
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
		$email        = strtolower(
			trim(
				(string) ( $payload['email'] ?? ( $current_user['email'] ?? '' ) ),
			),
		);

		if ( '' === $email ) {
			throw new ApiException( 'Email is required.', 422 );
		}

		$user = $this->find_user_row_by_email( $email );

		if ( ! $user ) {
			return array( 'resent' => false );
		}

		$this->execute(
			'UPDATE users
            SET email_verification_token = :token,
                email_verification_expires_at = :expires_at,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'token'      => bin2hex( random_bytes( 20 ) ),
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
		$identifier = strtolower(
			trim(
				(string) ( $payload['identifier'] ??
					( $payload['email'] ?? ( $payload['username'] ?? '' ) ) ),
			),
		);
		$password   = (string) ( $payload['password'] ?? '' );
		$token      = trim( (string) ( $payload['token'] ?? '' ) );

		if ( '' === $identifier || '' === $password ) {
			throw new ApiException(
				'Email or username and password are required.',
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
				'Invalid email, username, or password.',
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
					throw new ApiException( 'Invalid two-factor code.', 401 );
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
				'Two-factor authentication is still required.',
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
			$this->db->update(
				'sessions',
				array(
					'revoked_at'     => $this->now(),
					'revoked_reason' => 'logout',
				),
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
			throw new ApiException( 'Email or username is required.', 422 );
		}

		$normalized_identifier = strtolower( $identifier );
		$user                  = filter_var(
			$normalized_identifier,
			FILTER_VALIDATE_EMAIL
		)
			? $this->find_user_row_by_email( $normalized_identifier )
			: $this->find_user_row_by_username( $identifier );
		$reset_token           = bin2hex( random_bytes( 20 ) );

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
				'token'      => $reset_token,
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);

		try {
			$this->mailer_service->send_password_reset_email(
				$user,
				$reset_token,
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
		$password =
			(string) ( $payload['password'] ?? ( $payload['newPassword'] ?? '' ) );

		if ( strlen( $password ) < 8 ) {
			throw new ApiException(
				'Password must be at least 8 characters.',
				422,
			);
		}

		$user = $this->query_one(
			'SELECT * FROM users
            WHERE password_reset_token = :token
            AND password_reset_expires_at >= :now
            LIMIT 1',
			array(
				'token' => trim( $token ),
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

		return true;
	}

	/**
	 * Return the currently authenticated user's profile.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Hydrated user profile.
	 *
	 * @throws ApiException When no valid session or API key exists (401).
	 * @since 1.0.0
	 */
	public function get_current_user( Request $request ): array {
		$user = $this->resolve_current_user( $request, true );

		if ( ! $user ) {
			throw new ApiException( 'Authentication required.', 401 );
		}

		return $user;
	}

	/**
	 * Update the authenticated user's own profile fields.
	 *
	 * Supports name, username, email, phone, company, job title,
	 * bio, and password changes. Validates uniqueness of email/username.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $changes Partial profile payload.
	 * @return array<string, mixed> Hydrated updated user profile.
	 *
	 * @throws ApiException On validation failures (422).
	 * @since 1.0.0
	 */
	public function update_current_user( Request $request, array $changes ): array {
		$user       = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to update this profile.',
		);
		$user_id    = (string) $user['id'];
		$user_email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );
		$updates    = array();
		$params     = array( 'id' => $user_id );

		$field_map = array(
			'firstName'   => 'first_name',
			'lastName'    => 'last_name',
			'username'    => 'username',
			'email'       => 'email',
			'phoneNumber' => 'phone_number',
			'company'     => 'company',
			'jobTitle'    => 'job_title',
			'bio'         => 'bio',
		);

		foreach ( $field_map as $input_key => $column ) {
			if ( ! array_key_exists( $input_key, $changes ) ) {
				continue;
			}

			$value = trim( (string) $changes[ $input_key ] );

			if ( 'email' === $input_key ) {
				$value = strtolower( $value );

				if (
					$value !== $user_email &&
					! filter_var( $value, FILTER_VALIDATE_EMAIL )
				) {
					throw new ApiException(
						'A valid email address is required.',
						422,
					);
				}

				$existing = $this->find_user_row_by_email( $value );

				if (
					$value !== $user_email &&
					$existing &&
					(string) $existing['id'] !== $user_id
				) {
					throw new ApiException(
						'Email address is already in use.',
						422,
					);
				}
			}

			if ( 'username' === $input_key && '' === $value ) {
				throw new ApiException( 'Username cannot be empty.', 422 );
			}

			if ( 'username' === $input_key ) {
				$existing = $this->find_user_row_by_username( $value );

				if ( $existing && (string) $existing['id'] !== $user_id ) {
					throw new ApiException( 'Username is already taken.', 422 );
				}
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = $value;
		}

		if (
			array_key_exists( 'password', $changes ) &&
			'' !== trim( (string) $changes['password'] )
		) {
			$password = (string) $changes['password'];

			if ( strlen( $password ) < 8 ) {
				throw new ApiException(
					'Password must be at least 8 characters.',
					422,
				);
			}

			$updates[]               = 'password_hash = :password_hash';
			$params['password_hash'] = password_hash(
				$password,
				PASSWORD_DEFAULT,
			);
		}

		if ( empty( $updates ) ) {
			return $this->hydrate_user(
				$this->find_user_row_by_id( $user_id ),
				$request,
			);
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = $this->now();

		$this->execute(
			'UPDATE users SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);

		return $this->hydrate_user(
			$this->find_user_row_by_id( $user_id ),
			$request,
		);
	}

	/**
	 * List all users (admin only).
	 *
	 * @param Request $request Incoming HTTP request (must be admin).
	 * @return array<int, array<string, mixed>> Hydrated user rows.
	 * @since 1.0.0
	 */
	public function get_all_users( Request $request ): array {
		$this->assert_admin_request( $request );

		return array_map(
			fn( array $row ): array => $this->hydrate_user( $row ),
			$this->query_all(
				'SELECT * FROM users
				ORDER BY FIELD(role, \'admin\', \'editor\'), created_at ASC',
			),
		);
	}

	/**
	 * Create a new user (admin only).
	 *
	 * Validates all required fields, checks uniqueness of email/username,
	 * and inserts a pre-verified user row.
	 *
	 * @param Request              $request Incoming HTTP request (must be admin).
	 * @param array<string, mixed> $payload User creation payload.
	 * @return array<string, mixed> Hydrated new user.
	 *
	 * @throws ApiException On validation or uniqueness failure (422).
	 * @since 1.0.0
	 */
	public function create_user( Request $request, array $payload ): array {
		$this->assert_admin_request( $request );

		$email      = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$username   = trim( (string) ( $payload['username'] ?? '' ) );
		$password   = (string) ( $payload['password'] ?? '' );
		$first_name = trim( (string) ( $payload['firstName'] ?? '' ) );
		$last_name  = trim( (string) ( $payload['lastName'] ?? '' ) );
		$role       = $this->roles->normalize_role(
			(string) ( $payload['role'] ?? 'editor' ),
		);
		$now        = $this->now();

		if ( '' === $first_name || '' === $last_name ) {
			throw new ApiException( 'First and last name are required.', 422 );
		}

		if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,120}$/', $username ) ) {
			throw new ApiException(
				'Username must be 3-120 characters using letters, numbers, dots, dashes, underscores, or @.',
				422,
			);
		}

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new ApiException( 'A valid email address is required.', 422 );
		}

		if ( strlen( $password ) < 8 ) {
			throw new ApiException(
				'Password must be at least 8 characters.',
				422,
			);
		}

		if ( $this->find_user_row_by_email( $email ) ) {
			throw new ApiException(
				'Email address is already registered.',
				422,
			);
		}

		if ( $this->find_user_row_by_username( $username ) ) {
			throw new ApiException( 'Username is already taken.', 422 );
		}

		$this->db->insert(
			'users',
			array(
				'username'          => $username,
				'email'             => $email,
				'first_name'        => $first_name,
				'last_name'         => $last_name,
				'password_hash'     => password_hash( $password, PASSWORD_DEFAULT ),
				'role'              => $role,
				'is_email_verified' => 1,
				'email_verified_at' => $now,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
		);
		$user_id = $this->last_insert_id();

		return $this->hydrate_user( $this->find_user_row_by_id( $user_id ) );
	}

	/**
	 * Update a user by their username (admin only).
	 *
	 * Supports partial updates of profile fields, email, username,
	 * role, and password. Enforces admin-role-change safety checks.
	 *
	 * @param Request              $request  Incoming HTTP request (must be admin).
	 * @param string               $username Target user's username.
	 * @param array<string, mixed> $changes  Partial update payload.
	 * @return array<string, mixed>|null Hydrated user or null if not found.
	 *
	 * @throws ApiException On validation or role-change violation (422).
	 * @since 1.0.0
	 */
	public function update_user_by_username(
		Request $request,
		string $username,
		array $changes
	): ?array {
		$current_user = $this->assert_admin_request( $request );
		$user         = $this->find_user_row_by_username( $username );

		if ( ! $user ) {
			return null;
		}

		$user_id    = (string) $user['id'];
		$user_email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );
		$updates    = array();
		$params     = array( 'id' => $user_id );

		foreach (
			array(
				'firstName'   => 'first_name',
				'lastName'    => 'last_name',
				'phoneNumber' => 'phone_number',
				'company'     => 'company',
				'jobTitle'    => 'job_title',
				'bio'         => 'bio',
			)
			as $input_key => $column
		) {
			if ( ! array_key_exists( $input_key, $changes ) ) {
				continue;
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = trim( (string) $changes[ $input_key ] );
		}

		if ( array_key_exists( 'username', $changes ) ) {
			$new_username = trim( (string) $changes['username'] );

			if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,120}$/', $new_username ) ) {
				throw new ApiException(
					'Username must be 3-120 characters using letters, numbers, dots, dashes, underscores, or @.',
					422,
				);
			}

			$existing_user = $this->find_user_row_by_username( $new_username );

			if ( $existing_user && (string) $existing_user['id'] !== $user_id ) {
				throw new ApiException( 'Username is already taken.', 422 );
			}

			$updates[]          = 'username = :username';
			$params['username'] = $new_username;
		}

		if ( array_key_exists( 'email', $changes ) ) {
			$email = strtolower( trim( (string) $changes['email'] ) );

			if (
				$email !== $user_email &&
				! filter_var( $email, FILTER_VALIDATE_EMAIL )
			) {
				throw new ApiException(
					'A valid email address is required.',
					422,
				);
			}

			$existing_user = $this->find_user_row_by_email( $email );

			if (
				$email !== $user_email &&
				$existing_user &&
				(string) $existing_user['id'] !== $user_id
			) {
				throw new ApiException(
					'Email address is already in use.',
					422,
				);
			}

			$updates[]       = 'email = :email';
			$params['email'] = $email;
		}

		if ( array_key_exists( 'role', $changes ) ) {
			$role = $this->roles->normalize_role( (string) $changes['role'] );
			$this->assert_admin_role_change_is_allowed(
				(string) $user['id'],
				(string) $user['role'],
				$role,
				(string) $current_user['id'],
			);
			$updates[]      = 'role = :role';
			$params['role'] = $role;
		}

		if ( array_key_exists( 'password', $changes ) ) {
			$password = trim( (string) $changes['password'] );

			if ( '' !== $password ) {
				if ( strlen( $password ) < 8 ) {
					throw new ApiException(
						'Password must be at least 8 characters.',
						422,
					);
				}

				$updates[]               = 'password_hash = :password_hash';
				$params['password_hash'] = password_hash(
					$password,
					PASSWORD_DEFAULT,
				);
			}
		}

		if ( empty( $updates ) ) {
			return $this->hydrate_user(
				$this->find_user_row_by_id( $user_id ),
			);
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = $this->now();

		$this->execute(
			'UPDATE users SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);

		return $this->hydrate_user(
			$this->find_user_row_by_id( $user_id ),
		);
	}

	/**
	 * Delete a user by their username (admin only).
	 *
	 * Prevents self-deletion and enforces minimum-admin constraints.
	 *
	 * @param Request $request  Incoming HTTP request (must be admin).
	 * @param string  $username Target user's username.
	 * @return bool True if deleted; false if username not found.
	 *
	 * @throws ApiException When deleting self or last admin (422).
	 * @since 1.0.0
	 */
	public function delete_user_by_username(
		Request $request,
		string $username
	): bool {
		$current_user = $this->assert_admin_request( $request );
		$user         = $this->find_user_row_by_username( $username );

		if ( ! $user ) {
			return false;
		}

		if ( (string) $user['id'] === (string) $current_user['id'] ) {
			throw new ApiException( 'You cannot delete the current user.', 422 );
		}

		$this->assert_admin_role_change_is_allowed(
			(string) $user['id'],
			(string) $user['role'],
			'deleted',
			(string) $current_user['id'],
		);

		$this->db->delete(
			'users',
			array(
				'id' => $user['id'],
			)
		);
		return true;
	}

	/**
	 * Generate and store a new API key for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $label   Human-readable label for the key.
	 * @return array<string, mixed> The created API key record.
	 * @since 1.0.0
	 */
	public function add_api_key( Request $request, string $label ): array {
		$user = $this->assert_request_capability(
			$request,
			'manage_api_keys',
			'You do not have permission to manage API keys.',
		);
		return $this->insert_api_key( (string) $user['id'], $label );
	}

	/**
	 * Delete an API key belonging to the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      API key row ID.
	 * @return bool True if a key was deleted.
	 * @since 1.0.0
	 */
	public function delete_api_key( Request $request, string $id ): bool {
		$user    = $this->assert_request_capability(
			$request,
			'manage_api_keys',
			'You do not have permission to manage API keys.',
		);
		$deleted = $this->db->delete(
			'api_keys',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		return $deleted > 0;
	}

	/**
	 * Return the security dashboard for the authenticated user.
	 *
	 * Includes two-factor status, active sessions, API keys,
	 * and backup-code availability.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Security settings payload.
	 * @since 1.0.0
	 */
	public function get_security_settings( Request $request ): array {
		$user         = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$sessions     = $this->list_user_sessions( (string) $user['id'], $request );
		$backup_codes = $this->list_backup_codes( (string) $user['id'] );
		$row          = $this->find_user_row_by_id( (string) $user['id'] );

		return array(
			'twoFactorEnabled'     => ! empty( $row['two_factor_enabled'] ),
			'hasPendingSetup'      => ! empty( $row['two_factor_pending_secret'] ),
			'backupCodesRemaining' => count( $backup_codes ),
			'backupCodes'          => $backup_codes,
			'sessions'             => $sessions,
		);
	}

	/**
	 * Begin two-factor authentication setup.
	 *
	 * Generates a TOTP secret and a provisioning URI for authenticator
	 * apps. The secret is stored but 2FA is not yet enabled.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Secret, provisioning URI, and backup codes.
	 * @since 1.0.0
	 */
	public function start_two_factor_setup( Request $request ): array {
		$user   = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$secret = $this->totp_service->generate_secret();

		$this->db->update(
			'users',
			array(
				'two_factor_pending_secret' => $secret,
				'updated_at'                => $this->now(),
			),
			array(
				'id' => $user['id'],
			),
		);

		$label       = ! empty( $user['email'] ) ? $user['email'] : $user['username'];
		$otpauth_url = $this->totp_service->build_otpauth_url(
			'PeakURL',
			(string) $label,
			$secret,
		);

		return array(
			'secret'     => $secret,
			'otpauthUrl' => $otpauth_url,
		);
	}

	/**
	 * Confirm two-factor setup by verifying an initial TOTP token.
	 *
	 * Enables 2FA on the user account and generates backup codes.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $token   TOTP code from the authenticator app.
	 * @return array<string, mixed> Confirmation with backup codes.
	 *
	 * @throws ApiException When the token is invalid (422).
	 * @since 1.0.0
	 */
	public function verify_two_factor( Request $request, string $token ): array {
		$user           = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$row            = $this->find_user_row_by_id( (string) $user['id'] );
		$pending_secret = (string) ( $row['two_factor_pending_secret'] ?? '' );

		if ( '' === $pending_secret ) {
			throw new ApiException(
				'No two-factor setup is pending for this account.',
				422,
			);
		}

		if ( ! $this->totp_service->verify_code( $pending_secret, $token ) ) {
			throw new ApiException( 'Invalid verification code.', 422 );
		}

		$backup_codes = $this->replace_backup_codes( (string) $user['id'] );

		$this->execute(
			'UPDATE users
            SET two_factor_enabled = 1,
                two_factor_secret = :secret,
                two_factor_pending_secret = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'secret'     => $pending_secret,
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);

		return $backup_codes;
	}

	/**
	 * Disable two-factor authentication for the authenticated user.
	 *
	 * Clears the TOTP secret, disables the flag, and removes backup codes.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @since 1.0.0
	 */
	public function disable_two_factor( Request $request ): void {
		$user = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);

		$this->execute(
			'UPDATE users
            SET two_factor_enabled = 0,
                two_factor_secret = NULL,
                two_factor_pending_secret = NULL,
                backup_codes_json = NULL,
                backup_codes_generated_at = NULL,
                updated_at = :updated_at
            WHERE id = :id',
			array(
				'updated_at' => $this->now(),
				'id'         => $user['id'],
			),
		);
	}

	/**
	 * Regenerate backup codes for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> New set of backup codes.
	 * @since 1.0.0
	 */
	public function regenerate_backup_codes( Request $request ): array {
		$user = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		return $this->replace_backup_codes( (string) $user['id'] );
	}

	/**
	 * Revoke (log out) a specific session by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Session row ID.
	 * @return bool True if a session was revoked.
	 * @since 1.0.0
	 */
	public function revoke_session( Request $request, string $id ): bool {
		$user = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);

		return $this->execute_statement(
			'UPDATE sessions
            SET revoked_at = :revoked_at,
                revoked_reason = :revoked_reason
            WHERE id = :id
            AND user_id = :user_id
            AND revoked_at IS NULL',
			array(
				'revoked_at'     => $this->now(),
				'revoked_reason' => 'manual',
				'id'             => $id,
				'user_id'        => $user['id'],
			),
		) > 0;
	}

	/**
	 * Revoke every active session except the current browser session.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of revoked sessions.
	 *
	 * @throws ApiException When the current session cannot be identified.
	 * @since 1.0.0
	 */
	public function revoke_other_sessions( Request $request ): int {
		$user            = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to manage account security.',
		);
		$current_session = $this->find_session_by_request( $request );

		if ( ! $current_session ) {
			throw new ApiException(
				'PeakURL could not identify the current session.',
				422,
			);
		}

		return $this->execute_statement(
			'UPDATE sessions
			SET revoked_at = :revoked_at,
				revoked_reason = :revoked_reason
			WHERE user_id = :user_id
			AND id <> :current_session_id
			AND revoked_at IS NULL',
			array(
				'revoked_at'         => $this->now(),
				'revoked_reason'     => 'manual',
				'user_id'            => $user['id'],
				'current_session_id' => $current_session['id'],
			),
		);
	}
}
