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

use PeakURL\Api\LinksApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
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
	 * Webhooks domain service.
	 *
	 * @var WebhooksService
	 * @since 1.7.1
	 */
	private WebhooksService $webhooks_service;

	/**
	 * Links data API.
	 *
	 * @var LinksApi
	 * @since 1.7.2
	 */
	private LinksApi $links_api;

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
	 * @param WebhooksService  $webhooks_service  Webhooks domain service.
	 * @param LinksApi         $links_api         Links API helper.
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
		SocialPreview $social_preview,
		WebhooksService $webhooks_service,
		LinksApi $links_api
	) {
		$this->db                = $db;
		$this->users_api         = $users_api;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->validator         = $validator;
		$this->roles             = $roles;
		$this->authorization     = $authorization;
		$this->social_preview    = $social_preview;
		$this->webhooks_service  = $webhooks_service;
		$this->links_api         = $links_api;
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
		$user             = $this->auth_service->get_current_user( $request );
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

		$data = array();

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

			$data[ $column ] = $value;
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

			$data['password_hash'] = password_hash(
				$password,
				PASSWORD_DEFAULT,
			);
			$password_changed      = true;
		}

		if ( empty( $data ) ) {
			return $this->format_user(
				$this->users_api->get_user( $user_id ),
				$request,
			);
		}

		$data['updated_at'] = Date::now();

		$affected = $this->db->update( 'users', $data, array( 'id' => $user_id ) );

		$updated_user = $this->users_api->get_user( $user_id );

		if ( ! $updated_user ) {
			throw new ApiException(
				__( 'User not found.', 'peakurl' ),
				404,
			);
		}

		if ( $affected > 0 ) {
			if ( $password_changed && $user_row ) {
				try {
					$this->auth_service->send_password_changed( $user_row );
				} catch ( \Throwable $mail_error ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\error_log( sprintf( 'Failed to send password changed notification: %s', get_class( $mail_error ) ) );
				}

				$this->auth_service->notify_password_changed(
					$updated_user,
					'profile',
					(string) $data['updated_at']
				);
			}

			try {
				$this->webhooks_service->dispatch_user_event(
					'user.updated',
					$updated_user,
					$user_row
				);
			} catch ( \Throwable $webhook_error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( sprintf( 'Failed to dispatch user.updated webhook: %s', get_class( $webhook_error ) ) );
			}

			$this->notify_updated(
				$updated_user,
				$user_row ?? array(),
				$user
			);
		}

		return $this->format_user(
			$updated_user,
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
			$this->users_api->list_users(),
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
		if ( array_key_exists( 'role', $payload ) ) {
			if ( ! is_string( $payload['role'] ) ) {
				throw new ApiException( __( 'Invalid role identifier.', 'peakurl' ), 422 );
			}
			$role = $payload['role'];
		} else {
			$role = 'editor';
		}
		if ( ! $this->roles->is_registered_role( $role ) ) {
			throw new ApiException( __( 'Invalid role identifier.', 'peakurl' ), 422 );
		}
		$now = Date::now();

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

		if ( $this->roles->is_custom_role( $role ) ) {
			$this->db->begin_transaction();
			try {
				$this->db->get_row(
					'SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE',
					array( Constants::SETTING_ROLE_CAPABILITIES )
				);
				$this->roles->clear_cache();
				if ( ! $this->roles->is_custom_role( $role ) ) {
					throw new ApiException( __( 'The selected custom role no longer exists.', 'peakurl' ), 422 );
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
				$this->db->commit();
			} catch ( \Throwable $e ) {
				if ( $this->db->in_transaction() ) {
					$this->db->roll_back();
				}
				throw $e;
			}
		} else {
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
		}
		$user = $this->users_api->get_user( $user_id );

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

			$this->webhooks_service->dispatch_user_event( 'user.created', $user );
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
		$data             = array();
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

			$data[ $column ] = trim( (string) $changes[ $input_key ] );
		}

		if ( array_key_exists( 'username', $changes ) ) {
			$new_username = $this->validator->validate_user_login(
				(string) $changes['username']
			);

			if ( $this->auth_service->username_in_use( $new_username, $user_id ) ) {
				throw new ApiException( __( 'Username is already taken.', 'peakurl' ), 422 );
			}

			$data['username'] = $new_username;
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

			$data['email'] = $email;
		}

		if ( array_key_exists( 'role', $changes ) ) {
			if ( ! is_string( $changes['role'] ) ) {
				throw new ApiException( __( 'Invalid role identifier.', 'peakurl' ), 422 );
			}
			$proposed = $changes['role'];
			if ( ! $this->roles->is_registered_role( $proposed ) ) {
				throw new ApiException( __( 'Invalid role identifier.', 'peakurl' ), 422 );
			}
			$data['role'] = $proposed;
		}

		if ( array_key_exists( 'password', $changes ) ) {
			$password = trim( (string) $changes['password'] );

			if ( '' !== $password ) {
				$password              = $this->validator->validate_password( $password );
				$data['password_hash'] = password_hash(
					$password,
					PASSWORD_DEFAULT,
				);
				$password_changed      = true;
			}
		}

		if ( empty( $data ) ) {
			return $this->format_user(
				$this->users_api->get_user( $user_id ),
			);
		}

		$role_supplied = array_key_exists( 'role', $data );

		if ( $role_supplied && $this->db->in_transaction() ) {
			throw new \LogicException( 'Cannot execute role modification within an active external transaction.' );
		}

		$max_attempts  = $role_supplied ? 3 : 1;
		$attempt       = 0;
		$affected      = 0;
		$previous_user = $user;
		$role_changed  = false;
		$old_role      = '';
		$new_role      = '';

		while ( $attempt < $max_attempts ) {
			++$attempt;
			$did_begin   = false;
			$locked_user = null;

			if ( $role_supplied ) {
				$this->db->begin_transaction();
				$did_begin = true;
			}

			try {
				if ( $role_supplied ) {
					$acting_admin_id = (string) $current_user['id'];
					$target_user_id  = (string) $user['id'];

					if ( $this->roles->is_custom_role( (string) $data['role'] ) ) {
						$this->db->get_row(
							'SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE',
							array( Constants::SETTING_ROLE_CAPABILITIES )
						);
						$this->roles->clear_cache();
						if ( ! $this->roles->is_custom_role( (string) $data['role'] ) ) {
							throw new ApiException( __( 'The selected custom role no longer exists.', 'peakurl' ), 422 );
						}
					}

					$locked       = $this->users_api->lock_users( array( $acting_admin_id, $target_user_id ) );
					$acting_admin = $locked[ $acting_admin_id ] ?? null;
					$locked_user  = $locked[ $target_user_id ] ?? null;

					if ( null === $locked_user ) {
						throw new ApiException( __( 'User not found.', 'peakurl' ), 404 );
					}

					if ( null === $acting_admin || 'admin' !== (string) ( $acting_admin['role'] ?? '' ) ) {
						throw new ApiException( __( 'Administrator access required.', 'peakurl' ), 403 );
					}

					$actual_current_role   = (string) $locked_user['role'];
					$proposed_role         = (string) $data['role'];
					$is_actual_role_change = ( $actual_current_role !== $proposed_role );

					if ( $is_actual_role_change ) {
						$this->authorization->validate_role_change(
							(string) $locked_user['id'],
							$actual_current_role,
							$proposed_role,
							(string) $acting_admin['id'],
							fn(): int => $this->users_api->count_admin_users_for_update(),
						);
					}
				}

				$data['updated_at'] = Date::now();

				$affected_rows = $this->db->update( 'users', $data, array( 'id' => $user_id ) );

				if ( $did_begin ) {
					$this->db->commit();
				}

				$affected = $affected_rows;
				if ( $role_supplied && $locked_user ) {
					$previous_user = $locked_user;
					if ( $is_actual_role_change ) {
						$role_changed = true;
						$old_role     = $actual_current_role;
						$new_role     = $proposed_role;
					}
				}
				break;
			} catch ( \Throwable $exception ) {
				if ( $did_begin && $this->db->in_transaction() ) {
					$this->db->roll_back();
				}

				if ( $role_supplied && $attempt < $max_attempts && $this->is_lock_conflict( $exception ) ) {
					usleep( 50000 * $attempt );
					continue;
				}

				if ( $this->is_lock_conflict( $exception ) ) {
					throw new ApiException(
						__( 'Database lock conflict occurred. Please retry the operation.', 'peakurl' ),
						409,
					);
				}

				throw $exception;
			}
		}

		$updated_user = $this->users_api->get_user( $user_id );

		if ( ! $updated_user ) {
			throw new ApiException(
				__( 'User not found.', 'peakurl' ),
				404,
			);
		}

		if ( $affected > 0 ) {
			if ( ! empty( $password_changed ) ) {
				try {
					$this->auth_service->send_password_changed( $previous_user );
				} catch ( \Throwable $mail_error ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\error_log( sprintf( 'Failed to send password changed notification: %s', get_class( $mail_error ) ) );
				}

				$this->auth_service->notify_password_changed(
					$updated_user,
					'admin',
					(string) $data['updated_at']
				);
			}

			try {
				$this->analytics_service->record_activity(
					'user_updated',
					'Updated user ' . (string) ( $updated_user['username'] ?? $username ) . '.',
					(string) $current_user['id'],
					null,
					array(
						'user' => $this->get_user_activity_meta( $updated_user ),
					),
				);
			} catch ( \Throwable $activity_error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( sprintf( 'Failed to record user_updated activity: %s', get_class( $activity_error ) ) );
			}

			try {
				$this->webhooks_service->dispatch_user_event(
					'user.updated',
					$updated_user,
					$previous_user
				);
			} catch ( \Throwable $webhook_error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( sprintf( 'Failed to dispatch user.updated webhook: %s', get_class( $webhook_error ) ) );
			}

			$this->notify_updated(
				$updated_user,
				$previous_user,
				$current_user
			);

			if ( $role_changed ) {
				$this->notify_role_changed(
					$updated_user,
					$new_role,
					$old_role,
					$current_user
				);
			}
		}

		return $this->format_user( $updated_user );
	}

	/**
	 * Delete a user by their username (admin only).
	 *
	 * Prevents self-deletion, enforces minimum-admin constraints under lock,
	 * transfers owned links to the acting admin, and removes access credentials.
	 *
	 * @param Request $request  Incoming HTTP request (must be admin).
	 * @param string  $username Target user's username.
	 * @return bool True if deleted; false if username not found.
	 *
	 * @throws ApiException When deleting self or last admin (422).
	 * @throws \LogicException When called inside an existing transaction.
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

		if ( $this->db->in_transaction() ) {
			throw new \LogicException( 'Cannot execute user deletion within an active external transaction.' );
		}

		$transferred_links = array();
		$target_user       = null;
		$max_attempts      = 3;
		$attempt           = 0;

		while ( $attempt < $max_attempts ) {
			++$attempt;
			$this->db->begin_transaction();

			try {
				$acting_admin_id = (string) $current_user['id'];
				$target_user_id  = (string) $user['id'];

				$locked       = $this->users_api->lock_users( array( $acting_admin_id, $target_user_id ) );
				$acting_admin = $locked[ $acting_admin_id ] ?? null;
				$target_user  = $locked[ $target_user_id ] ?? null;

				if ( null === $target_user ) {
					$this->db->roll_back();
					return false;
				}

				if ( null === $acting_admin || 'admin' !== (string) ( $acting_admin['role'] ?? '' ) ) {
					throw new ApiException( __( 'Administrator access required.', 'peakurl' ), 403 );
				}

				if ( (string) $target_user['id'] === (string) $acting_admin['id'] ) {
					throw new ApiException( __( 'You cannot delete the current user.', 'peakurl' ), 422 );
				}

				$this->authorization->validate_role_change(
					(string) $target_user['id'],
					(string) $target_user['role'],
					'deleted',
					(string) $acting_admin['id'],
					fn(): int => $this->users_api->count_admin_users_for_update(),
				);

				$transferred_links = $this->transfer_user_links(
					(string) $target_user['id'],
					(string) $acting_admin['id']
				);

				$this->cleanup_user_credentials( (string) $target_user['id'] );
				$this->prune_user_activity( (string) $target_user['id'] );

				$deleted_count = $this->db->delete(
					'users',
					array(
						'id' => $target_user['id'],
					)
				);

				if ( 0 === $deleted_count ) {
					throw new ApiException( __( 'User could not be deleted.', 'peakurl' ), 500 );
				}

				// Record terminal user_deleted audit activity inside the transaction for durable consistency
				$this->analytics_service->record_activity(
					'user_deleted',
					'Deleted user ' . (string) ( $target_user['username'] ?? $username ) . '.',
					(string) $acting_admin['id'],
					null,
					array(
						'user' => $this->get_user_activity_meta( $target_user ),
					),
				);

				$this->db->commit();
				break;
			} catch ( \Throwable $exception ) {
				if ( $this->db->in_transaction() ) {
					$this->db->roll_back();
				}

				if ( $attempt < $max_attempts && $this->is_lock_conflict( $exception ) ) {
					usleep( 50000 * $attempt );
					continue;
				}

				if ( $this->is_lock_conflict( $exception ) ) {
					throw new ApiException(
						__( 'Database lock conflict occurred. Please retry the operation.', 'peakurl' ),
						409,
					);
				}

				throw $exception;
			}
		}

		// Invalidate transferred link caches per link; errors logged without failing the committed deletion or skipping remaining links.
		foreach ( $transferred_links as $link_row ) {
			try {
				$this->links_api->invalidate_link_cache( $link_row );
			} catch ( \Throwable $cache_error ) {
				\error_log( 'Failed to invalidate transferred link cache: ' . $cache_error->getMessage() );
			}
		}

		// Dispatch user.deleted webhook independently; secondary error does not hide deletion success.
		try {
			if ( $target_user ) {
				$this->webhooks_service->dispatch_user_event( 'user.deleted', $target_user );
			}
		} catch ( \Throwable $webhook_error ) {
			\error_log( 'Failed to dispatch user.deleted webhook: ' . $webhook_error->getMessage() );
		}

		if ( $target_user ) {
			$this->notify_deleted(
				$target_user,
				$current_user,
				count( $transferred_links )
			);
		}

		return true;
	}

	/**
	 * Transfer all URLs owned by a user to another user.
	 *
	 * Preserves short codes, aliases, destinations, status, clicks, and analytics.
	 * Returns the transferred link rows for post-commit cache invalidation.
	 *
	 * @param string $source_user_id User currently owning the links.
	 * @param string $target_user_id User to receive ownership.
	 * @return array<int, array<string, mixed>> Transferred link rows.
	 * @throws ApiException When transfer verification fails.
	 * @since 1.7.2
	 */
	private function transfer_user_links( string $source_user_id, string $target_user_id ): array {
		$rows = $this->db->get_results_by(
			'urls',
			array( 'user_id' => $source_user_id ),
			array( 'id', 'short_code', 'alias' ),
			array( 'id' => 'ASC' )
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$this->db->update(
			'urls',
			array( 'user_id' => $target_user_id ),
			array( 'user_id' => $source_user_id ),
		);

		$remaining = (int) $this->db->count(
			'urls',
			array( 'user_id' => $source_user_id ),
		);

		if ( $remaining > 0 ) {
			throw new ApiException(
				__( 'Failed to transfer user links.', 'peakurl' ),
				500
			);
		}

		return $rows;
	}

	/**
	 * Determine whether an exception was caused by a database lock conflict.
	 *
	 * Detects InnoDB deadlocks (1213), lock wait timeouts (1205), and SQLSTATE 40001 serialization failures.
	 *
	 * @param \Throwable $exception Caught error.
	 * @return bool True if retryable lock conflict.
	 * @since 1.7.2
	 */
	private function is_lock_conflict( \Throwable $exception ): bool {
		if ( $exception instanceof \PDOException ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$driver_code = (int) ( $exception->errorInfo[1] ?? 0 );
			if ( 1213 === $driver_code || 1205 === $driver_code ) {
				return true;
			}
			if ( '40001' === (string) $exception->getCode() ) {
				return true;
			}
		}

		$msg = $exception->getMessage();
		return str_contains( $msg, '1213 Deadlock' ) || str_contains( $msg, '1205 Lock wait timeout' );
	}

	/**
	 * Clean up active sessions and API keys for a deleted user.
	 *
	 * @param string $user_id Target user ID.
	 * @return void
	 * @since 1.7.2
	 */
	private function cleanup_user_credentials( string $user_id ): void {
		$this->db->delete(
			'sessions',
			array( 'user_id' => $user_id ),
		);

		$this->db->delete(
			'api_keys',
			array( 'user_id' => $user_id ),
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

	/**
	 * Format a user row for lifecycle hooks.
	 *
	 * @param array<string, mixed> $user Raw user database row.
	 * @return array<string, string> Bounded user details.
	 * @since 1.8.0
	 */
	private function user_details( array $user ): array {
		return array(
			'id'          => (string) ( $user['id'] ?? '' ),
			'username'    => (string) ( $user['username'] ?? '' ),
			'email'       => (string) ( $user['email'] ?? '' ),
			'role'        => (string) ( $user['role'] ?? '' ),
			'displayName' => (string) ( $user['display_name'] ?? '' ),
			'updatedAt'   => (string) ( $user['updated_at'] ?? '' ),
		);
	}

	/**
	 * Format an actor dictionary for lifecycle hooks.
	 *
	 * @param array<string, mixed> $actor Raw or formatted actor array.
	 * @return array<string, string> Bounded actor details.
	 * @since 1.8.0
	 */
	private function actor_details( array $actor ): array {
		return array(
			'id'       => (string) ( $actor['id'] ?? '' ),
			'username' => (string) ( $actor['username'] ?? '' ),
			'role'     => (string) ( $actor['role'] ?? '' ),
		);
	}

	/**
	 * Dispatch user_updated lifecycle action.
	 *
	 * @param array<string, mixed> $user          Updated user row.
	 * @param array<string, mixed> $previous_user User row before update.
	 * @param array<string, mixed> $actor         Acting user.
	 * @since 1.8.0
	 */
	private function notify_updated(
		array $user,
		array $previous_user,
		array $actor
	): void {
		if ( ! \has_action( 'user_updated' ) ) {
			return;
		}

		$payload  = $this->user_details( $user );
		$previous = $this->user_details( $previous_user );
		$actor    = $this->actor_details( $actor );

		try {
			/**
			 * Fires after a user record has been successfully updated.
			 *
			 * @since 1.8.0
			 *
			 * @param array<string, string> $payload  Bounded updated user payload.
			 * @param array<string, string> $previous Bounded previous user payload.
			 * @param array<string, string> $actor    Bounded acting user.
			 */
			\do_action( 'user_updated', $payload, $previous, $actor );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( sprintf( 'PeakURL user_updated observer error: %s', get_class( $e ) ) );
		}
	}

	/**
	 * Dispatch user_role_changed lifecycle action.
	 *
	 * @param array<string, mixed> $user     Updated user row.
	 * @param string               $new_role Confirmed new role.
	 * @param string               $old_role Previous role from locked record.
	 * @param array<string, mixed> $actor    Acting administrator.
	 * @since 1.8.0
	 */
	private function notify_role_changed(
		array $user,
		string $new_role,
		string $old_role,
		array $actor
	): void {
		if ( ! \has_action( 'user_role_changed' ) ) {
			return;
		}

		$payload = $this->user_details( $user );
		$actor   = $this->actor_details( $actor );

		try {
			/**
			 * Fires after a user's role has been successfully changed.
			 *
			 * @since 1.8.0
			 *
			 * @param array<string, string> $payload  Bounded updated user payload.
			 * @param string               $new_role Confirmed new role.
			 * @param string               $old_role Previous role from locked record.
			 * @param array<string, string> $actor    Bounded acting administrator.
			 */
			\do_action( 'user_role_changed', $payload, $new_role, $old_role, $actor );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( sprintf( 'PeakURL user_role_changed observer error: %s', get_class( $e ) ) );
		}
	}

	/**
	 * Dispatch user_deleted lifecycle action.
	 *
	 * @param array<string, mixed> $user              Target deleted user row.
	 * @param array<string, mixed> $actor             Acting administrator.
	 * @param int                  $transferred_links Confirmed number of transferred links.
	 * @since 1.8.0
	 */
	private function notify_deleted(
		array $user,
		array $actor,
		int $transferred_links
	): void {
		if ( ! \has_action( 'user_deleted' ) ) {
			return;
		}

		$payload = array(
			'id'       => (string) ( $user['id'] ?? '' ),
			'username' => (string) ( $user['username'] ?? '' ),
			'email'    => (string) ( $user['email'] ?? '' ),
			'role'     => (string) ( $user['role'] ?? '' ),
		);

		$context = array(
			'actor'                 => $this->actor_details( $actor ),
			'transferredLinksCount' => $transferred_links,
		);

		try {
			/**
			 * Fires after a user has been permanently deleted.
			 *
			 * @since 1.8.0
			 *
			 * @param array<string, string> $payload Bounded deleted user payload.
			 * @param array<string, mixed>  $context Bounded deletion context (actor, transferredLinksCount).
			 */
			\do_action( 'user_deleted', $payload, $context );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( sprintf( 'PeakURL user_deleted observer error: %s', get_class( $e ) ) );
		}
	}
}
