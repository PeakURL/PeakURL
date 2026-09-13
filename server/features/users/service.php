<?php
/**
 * Users domain service.
 *
 * Implements user profile lookups, updates, administration,
 * role validation, and related resource cleanup.
 *
 * @package PeakURL\Features\Users
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Users;

use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — User management and profile operations.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Database service.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Users data API.
	 *
	 * @var UsersApi
	 * @since 1.0.0
	 */
	private UsersApi $users_api;

	/**
	 * Auth domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Analytics domain service for activity logging.
	 *
	 * @var AnalyticsService
	 * @since 1.0.0
	 */
	private AnalyticsService $analytics_service;

	/**
	 * Input validator.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Authorization service.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Social preview service for thumbnail cleanup.
	 *
	 * @var SocialPreview
	 * @since 1.0.0
	 */
	private SocialPreview $social_preview;

	/**
	 * Create a new Users domain service instance.
	 *
	 * @param PeakURL_DB       $db                Database service.
	 * @param UsersApi         $users_api         Users API helper.
	 * @param AuthService      $auth_service      Auth domain service.
	 * @param AnalyticsService $analytics_service Analytics domain service.
	 * @param Validator        $validator         Input validator.
	 * @param Roles            $roles             Roles registry.
	 * @param Authorization    $authorization     Authorization service.
	 * @param SocialPreview    $social_preview    Social preview service.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		UsersApi $users_api,
		AuthService $auth_service,
		AnalyticsService $analytics_service,
		Validator $validator,
		Roles $roles,
		Authorization $authorization,
		SocialPreview $social_preview
	) {
		$this->db                = $db;
		$this->users_api         = $users_api;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->validator         = $validator;
		$this->roles             = $roles;
		$this->authorization     = $authorization;
		$this->social_preview    = $social_preview;
	}

	/**
	 * Format a raw user row into an API-ready user array.
	 *
	 * @param array<string, mixed>|null $row     Raw user database row.
	 * @param Request|null              $request Optional request for session context.
	 * @return array<string, mixed> Formatted user profile.
	 * @since 1.0.0
	 */
	public function format_user( ?array $row, ?Request $request = null ): array {
		return $this->auth_service->format_user( $row, $request );
	}

	/**
	 * Return the currently authenticated user's profile.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Formatted user profile.
	 *
	 * @throws ApiException When no valid session or API key exists (401).
	 * @since 1.0.0
	 */
	public function get_current_profile( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );

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
	 * Supports name, email, phone, company, job title, bio, and password changes.
	 * Validates email uniqueness.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $changes Partial profile payload.
	 * @return array<string, mixed> Formatted updated user profile.
	 *
	 * @throws ApiException On validation failures (422).
	 * @since 1.0.0
	 */
	public function update_current_user( Request $request, array $changes ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_profile',
			__( 'You do not have permission to update this profile.', 'peakurl' ),
		);
		$user_id          = (string) $user['id'];
		$user_row         = $this->users_api->get_user( $user_id );
		$user_email       = sanitize_email( (string) ( $user['email'] ?? '' ) );
		$updates          = array();
		$params           = array( 'id' => $user_id );
		$password_changed = false;

		if ( array_key_exists( 'username', $changes ) ) {
			throw new ApiException( __( 'Username cannot be changed.', 'peakurl' ), 422 );
		}

		$field_map = array(
			'firstName'   => 'first_name',
			'lastName'    => 'last_name',
			'displayName' => 'display_name',
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
				$value = sanitize_email( $value );

				if ( $value !== $user_email ) {
					$value = $this->validator->validate_email( $value );

					if ( $this->auth_service->email_in_use( $value, $user_id ) ) {
						throw new ApiException(
							__( 'Email address is already in use.', 'peakurl' ),
							422,
						);
					}
				}
			}

			$updates[]         = $column . ' = :' . $column;
			$params[ $column ] = $value;
		}

		if (
			array_key_exists( 'password', $changes ) &&
			'' !== (string) $changes['password']
		) {
			$password = $this->validator->validate_password( (string) $changes['password'] );

			$this->auth_service->confirm_current_password(
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
			return $this->format_user(
				$this->users_api->get_user( $user_id ),
				$request,
			);
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = Date::now();

		$this->db->query(
			'UPDATE users SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);

		if ( $password_changed && $user_row ) {
			$this->auth_service->send_password_changed( $user_row );
		}

		return $this->format_user(
			$this->users_api->get_user( $user_id ),
			$request,
		);
	}

	/**
	 * List all users (admin only).
	 *
	 * @param Request $request Incoming HTTP request (must be admin).
	 * @return array<int, array<string, mixed>> Formatted user rows.
	 * @since 1.0.0
	 */
	public function get_all_users( Request $request ): array {
		$this->auth_service->get_admin_user( $request );

		return array_map(
			fn( array $row ): array => $this->format_user( $row ),
			$this->db->get_results(
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
	 * @return array<string, mixed> Formatted new user.
	 *
	 * @throws ApiException On validation or uniqueness failure (422).
	 * @since 1.0.0
	 */
	public function create_user( Request $request, array $payload ): array {
		$current_user = $this->auth_service->get_admin_user( $request );

		$email      = $this->validator->validate_email( (string) ( $payload['email'] ?? '' ) );
		$username   = $this->validator->validate_user_login(
			(string) ( $payload['username'] ?? '' )
		);
		$password   = $this->validator->validate_password(
			(string) ( $payload['password'] ?? '' )
		);
		$first_name = trim( (string) ( $payload['firstName'] ?? '' ) );
		$last_name  = trim( (string) ( $payload['lastName'] ?? '' ) );
		$role       = $this->roles->normalize_role(
			(string) ( $payload['role'] ?? 'editor' ),
		);
		$now        = Date::now();

		if ( '' === $first_name || '' === $last_name ) {
			throw new ApiException( __( 'First and last name are required.', 'peakurl' ), 422 );
		}

		if ( $this->auth_service->email_in_use( $email ) ) {
			throw new ApiException(
				__( 'Email address is already registered.', 'peakurl' ),
				422,
			);
		}

		if ( $this->auth_service->username_in_use( $username ) ) {
			throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
		}

		$this->db->insert(
			'users',
			array(
				'username'          => $username,
				'email'             => $email,
				'first_name'        => $first_name,
				'last_name'         => $last_name,
				'display_name'      => isset( $payload['displayName'] ) ? trim( (string) $payload['displayName'] ) : null,
				'password_hash'     => password_hash( $password, PASSWORD_DEFAULT ),
				'role'              => $role,
				'is_email_verified' => 1,
				'email_verified_at' => $now,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
		);
		$user_id = (string) $this->db->insert_id();
		$user    = $this->users_api->get_user( $user_id );

		if ( $user ) {
			$this->analytics_service->record_activity(
				'user_created',
				'Created user ' . (string) ( $user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->get_user_activity_meta( $user ),
				),
			);
		}

		return $this->format_user( $user );
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
	 * @return array<string, mixed>|null Formatted user or null if not found.
	 *
	 * @throws ApiException On validation or role-change violation (422).
	 * @since 1.0.0
	 */
	public function update_user_by_username(
		Request $request,
		string $username,
		array $changes
	): ?array {
		$current_user = $this->auth_service->get_admin_user( $request );
		$user         = $this->users_api->get_user_by_username( $username );

		if ( ! $user ) {
			return null;
		}

		$user_id          = (string) $user['id'];
		$user_email       = sanitize_email( (string) ( $user['email'] ?? '' ) );
		$updates          = array();
		$params           = array( 'id' => $user_id );
		$password_changed = false;

		foreach (
			array(
				'firstName'   => 'first_name',
				'lastName'    => 'last_name',
				'displayName' => 'display_name',
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
			$new_username = $this->validator->validate_user_login(
				(string) $changes['username']
			);

			if ( $this->auth_service->username_in_use( $new_username, $user_id ) ) {
				throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
			}

			$updates[]          = 'username = :username';
			$params['username'] = $new_username;
		}

		if ( array_key_exists( 'email', $changes ) ) {
			$email = sanitize_email( (string) $changes['email'] );

			if ( $email !== $user_email ) {
				$email = $this->validator->validate_email( $email );

				if ( $this->auth_service->email_in_use( $email, $user_id ) ) {
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
			$this->authorization->validate_role_change(
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
				$password                = $this->validator->validate_password( $password );
				$updates[]               = 'password_hash = :password_hash';
				$params['password_hash'] = password_hash(
					$password,
					PASSWORD_DEFAULT,
				);
				$password_changed        = true;
			}
		}

		if ( empty( $updates ) ) {
			return $this->format_user(
				$this->users_api->get_user( $user_id ),
			);
		}

		$updates[]            = 'updated_at = :updated_at';
		$params['updated_at'] = Date::now();

		$this->db->query(
			'UPDATE users SET ' . implode( ', ', $updates ) . ' WHERE id = :id',
			$params,
		);

		if ( ! empty( $password_changed ) ) {
			$this->auth_service->send_password_changed( $user );
		}

		$updated_user = $this->users_api->get_user( $user_id );

		if ( $updated_user ) {
			$this->analytics_service->record_activity(
				'user_updated',
				'Updated user ' . (string) ( $updated_user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->get_user_activity_meta( $updated_user ),
				),
			);
		}

		return $this->format_user( $updated_user );
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
		$current_user = $this->auth_service->get_admin_user( $request );
		$user         = $this->users_api->get_user_by_username( $username );

		if ( ! $user ) {
			return false;
		}

		if ( (string) $user['id'] === (string) $current_user['id'] ) {
			throw new ApiException( __( 'You cannot delete the current user.', 'peakurl' ), 422 );
		}

		$this->authorization->validate_role_change(
			(string) $user['id'],
			(string) $user['role'],
			'deleted',
			(string) $current_user['id'],
		);

		$link_cleanup = array(
			'ids'         => array(),
			'image_paths' => array(),
		);

		$this->db->begin_transaction();

		try {
			$link_cleanup = $this->get_user_link_cleanup_data( (string) $user['id'] );

			$this->prune_user_activity( (string) $user['id'] );
			$this->delete_user_links( $link_cleanup['ids'] );

			$this->analytics_service->record_activity(
				'user_deleted',
				'Deleted user ' . (string) ( $user['username'] ?? $username ) . '.',
				(string) $current_user['id'],
				null,
				array(
					'user' => $this->get_user_activity_meta( $user ),
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

		$this->social_preview->delete_link_images(
			$link_cleanup['image_paths'],
		);

		return true;
	}

	/**
	 * Return link rows that need database and file cleanup before user deletion.
	 *
	 * @param string $user_id Target user ID.
	 * @return array{ids: array<int, string>, image_paths: array<int, string>}
	 * @since 1.0.0
	 */
	private function get_user_link_cleanup_data( string $user_id ): array {
		$rows = $this->db->get_results_by(
			'urls',
			array(
				'user_id' => $user_id,
			),
			array( 'id', 'social_image_path' ),
		);

		$ids         = array();
		$image_paths = array();

		foreach ( $rows as $row ) {
			$id = trim( (string) ( $row['id'] ?? '' ) );

			if ( '' !== $id ) {
				$ids[] = $id;
			}

			$image_path = trim( (string) ( $row['social_image_path'] ?? '' ) );

			if ( '' !== $image_path ) {
				$image_paths[] = $image_path;
			}
		}

		return array(
			'ids'         => $ids,
			'image_paths' => $image_paths,
		);
	}

	/**
	 * Delete all link-owned rows for a user before the user row is removed.
	 *
	 * This keeps cleanup explicit even on installs where foreign-key repair has
	 * not run yet.
	 *
	 * @param array<int, string> $link_ids Link IDs owned by the user.
	 * @return void
	 * @since 1.0.0
	 */
	private function delete_user_links( array $link_ids ): void {
		if ( empty( $link_ids ) ) {
			return;
		}

		$this->db->delete_where_in(
			'clicks',
			'url_id',
			$link_ids,
		);

		$this->db->delete_where_in(
			'audit_logs',
			'link_id',
			$link_ids,
		);

		$this->db->delete_where_in(
			'urls',
			'id',
			$link_ids,
		);
	}

	/**
	 * Remove historical audit rows that belong to a soon-to-be-deleted user.
	 *
	 * Keeps the final `user_deleted` entry intact by running before that
	 * terminal event is recorded.
	 *
	 * @param string $user_id Target user ID.
	 * @return void
	 * @since 1.0.0
	 */
	private function prune_user_activity( string $user_id ): void {
		$this->db->query(
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
	 * Return lightweight user metadata for audit-log payloads.
	 *
	 * @param array<string, mixed> $user Raw user row.
	 * @return array<string, string|null>
	 * @since 1.0.0
	 */
	private function get_user_activity_meta( array $user ): array {
		$first_name   = trim( (string) ( $user['first_name'] ?? '' ) );
		$last_name    = trim( (string) ( $user['last_name'] ?? '' ) );
		$display_name = trim( (string) ( $user['display_name'] ?? '' ) );
		$username     = trim( (string) ( $user['username'] ?? '' ) );
		$email        = sanitize_email( (string) ( $user['email'] ?? '' ) );
		$role         = trim( (string) ( $user['role'] ?? '' ) );

		return array(
			'id'          => (string) ( $user['id'] ?? '' ),
			'firstName'   => '' !== $first_name ? $first_name : null,
			'lastName'    => '' !== $last_name ? $last_name : null,
			'displayName' => '' !== $display_name ? $display_name : null,
			'username'    => '' !== $username ? $username : null,
			'email'       => '' !== $email ? $email : null,
			'role'        => '' !== $role ? $role : null,
		);
	}
}
