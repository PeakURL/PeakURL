<?php
/**
 * Data store account users trait.
 *
 * @package PeakURL\Data
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Traits\Accounts;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * UsersTrait — profile and admin user-management methods.
 *
 * @since 1.0.14
 */
trait UsersTrait {

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
			throw new ApiException( __( 'Authentication required.', 'peakurl' ), 401 );
		}

		return array_merge(
			$user,
			array(
				'siteUrl'    => \get_site_url(),
				'baseApiUrl' => \get_api_base_url(),
			),
		);
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
		$user             = $this->assert_request_capability(
			$request,
			'manage_profile',
			'You do not have permission to update this profile.',
		);
		$user_id          = (string) $user['id'];
		$user_row         = $this->find_user_row_by_id( $user_id );
		$user_email       = $this->normalize_email( (string) ( $user['email'] ?? '' ) );
		$updates          = array();
		$params           = array( 'id' => $user_id );
		$password_changed = false;

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
				$value = $this->normalize_email( $value );

				if ( $value !== $user_email ) {
					$value = $this->validate_email( $value );

					if ( $this->email_in_use( $value, $user_id ) ) {
						throw new ApiException(
							__( 'Email address is already in use.', 'peakurl' ),
							422,
						);
					}
				}
			}

			if ( 'username' === $input_key ) {
				$value = $this->validate_username_required(
					$value,
					__( 'Username cannot be empty.', 'peakurl' ),
				);

				if ( $this->username_in_use( $value, $user_id ) ) {
					throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
				}
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = $value;
		}

		if (
			array_key_exists( 'password', $changes ) &&
			'' !== (string) $changes['password']
		) {
			$password = $this->validate_password( (string) $changes['password'] );

			$this->assert_password_confirmation_for_user(
				$user_row,
				(string) ( $changes['currentPassword'] ?? '' ),
				__(
					'Current password is required to change your password.',
					'peakurl',
				),
			);

			$updates[]               = 'password_hash = :password_hash';
			$params['password_hash'] = password_hash(
				$password,
				PASSWORD_DEFAULT,
			);
			$password_changed        = true;
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

		if ( $password_changed && $user_row ) {
			$this->send_password_changed_notification( $user_row );
		}

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
		$current_user = $this->assert_admin_request( $request );

		$email      = $this->validate_email( (string) ( $payload['email'] ?? '' ) );
		$username   = $this->validate_username_pattern(
			(string) ( $payload['username'] ?? '' )
		);
		$password   = $this->validate_password(
			(string) ( $payload['password'] ?? '' )
		);
		$first_name = trim( (string) ( $payload['firstName'] ?? '' ) );
		$last_name  = trim( (string) ( $payload['lastName'] ?? '' ) );
		$role       = $this->roles->normalize_role(
			(string) ( $payload['role'] ?? 'editor' ),
		);
		$now        = $this->now();

		if ( '' === $first_name || '' === $last_name ) {
			throw new ApiException( __( 'First and last name are required.', 'peakurl' ), 422 );
		}

		if ( $this->email_in_use( $email ) ) {
			throw new ApiException(
				__( 'Email address is already registered.', 'peakurl' ),
				422,
			);
		}

		if ( $this->username_in_use( $username ) ) {
			throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
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
		$user    = $this->find_user_row_by_id( $user_id );

		if ( $user ) {
			$this->record_activity(
				'user_created',
				'Created user ' . (string) ( $user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->build_activity_user_metadata( $user ),
				),
			);
		}

		return $this->hydrate_user( $user );
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

		$user_id          = (string) $user['id'];
		$user_email       = $this->normalize_email( (string) ( $user['email'] ?? '' ) );
		$updates          = array();
		$params           = array( 'id' => $user_id );
		$password_changed = false;

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
			$new_username = $this->validate_username_pattern(
				(string) $changes['username']
			);

			if ( $this->username_in_use( $new_username, $user_id ) ) {
				throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
			}

			$updates[]          = 'username = :username';
			$params['username'] = $new_username;
		}

		if ( array_key_exists( 'email', $changes ) ) {
			$email = $this->normalize_email( (string) $changes['email'] );

			if ( $email !== $user_email ) {
				$email = $this->validate_email( $email );

				if ( $this->email_in_use( $email, $user_id ) ) {
					throw new ApiException(
						__( 'Email address is already in use.', 'peakurl' ),
						422,
					);
				}
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
				$password                = $this->validate_password( $password );
				$updates[]               = 'password_hash = :password_hash';
				$params['password_hash'] = password_hash(
					$password,
					PASSWORD_DEFAULT,
				);
				$password_changed        = true;
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

		if ( ! empty( $password_changed ) ) {
			$this->send_password_changed_notification( $user );
		}

		$updated_user = $this->find_user_row_by_id( $user_id );

		if ( $updated_user ) {
			$this->record_activity(
				'user_updated',
				'Updated user ' . (string) ( $updated_user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->build_activity_user_metadata( $updated_user ),
				),
			);
		}

		return $this->hydrate_user( $updated_user );
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
			throw new ApiException( __( 'You cannot delete the current user.', 'peakurl' ), 422 );
		}

		$this->assert_admin_role_change_is_allowed(
			(string) $user['id'],
			(string) $user['role'],
			'deleted',
			(string) $current_user['id'],
		);

		$this->db->begin_transaction();

		try {
			$this->prune_activity_logs_for_user( (string) $user['id'] );

			$this->record_activity(
				'user_deleted',
				'Deleted user ' . (string) ( $user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->build_activity_user_metadata( $user ),
				),
			);
			$this->db->delete(
				'users',
				array(
					'id' => $user['id'],
				)
			);
			$this->db->commit();
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}

		return true;
	}

	/**
	 * Remove historical audit rows that belong to a soon-to-be-deleted user.
	 *
	 * Keeps the final `user_deleted` entry intact by running before that
	 * terminal event is recorded.
	 *
	 * @param string $user_id Target user ID.
	 * @return void
	 * @since 1.0.6
	 */
	private function prune_activity_logs_for_user( string $user_id ): void {
		$this->execute(
			"DELETE FROM audit_logs
			WHERE user_id = :user_id
			OR metadata LIKE :metadata_user_pattern ESCAPE '\\\\'",
			array(
				'user_id'               => $user_id,
				'metadata_user_pattern' => '%"user":{"id":"' . $this->db->esc_like( $user_id ) . '"%',
			),
		);
	}

	/**
	 * Build lightweight user metadata for audit-log payloads.
	 *
	 * @param array<string, mixed> $user Raw user row.
	 * @return array<string, string|null>
	 * @since 1.0.4
	 */
	private function build_activity_user_metadata( array $user ): array {
		$first_name = trim( (string) ( $user['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $user['last_name'] ?? '' ) );
		$username   = trim( (string) ( $user['username'] ?? '' ) );
		$email      = $this->normalize_email( (string) ( $user['email'] ?? '' ) );
		$role       = trim( (string) ( $user['role'] ?? '' ) );

		return array(
			'id'        => (string) ( $user['id'] ?? '' ),
			'firstName' => '' !== $first_name ? $first_name : null,
			'lastName'  => '' !== $last_name ? $last_name : null,
			'username'  => '' !== $username ? $username : null,
			'email'     => '' !== $email ? $email : null,
			'role'      => '' !== $role ? $role : null,
		);
	}
}
