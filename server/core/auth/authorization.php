<?php
/**
 * Authorization and permission checking primitives.
 *
 * @package PeakURL\Core\Auth
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Auth;

use PeakURL\Core\Errors\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Authorization service for capability and ownership enforcement.
 *
 * @since 1.0.0
 */
class Authorization {

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 */
	private Roles $roles;

	/**
	 * Create an Authorization instance.
	 *
	 * @param Roles|null $roles Optional Roles instance.
	 */
	public function __construct( ?Roles $roles = null ) {
		$this->roles = $roles ?? new Roles();
	}

	/**
	 * Return the underlying Roles registry.
	 *
	 * @return Roles
	 */
	public function get_roles(): Roles {
		return $this->roles;
	}

	/**
	 * Require a capability on a user or throw a 403 ApiException.
	 *
	 * @param array<string, mixed> $user       User row.
	 * @param string               $capability Capability name.
	 * @param string|null          $message    Optional custom error message.
	 * @return void
	 *
	 * @throws ApiException When permission is denied.
	 */
	public function require_capability( array $user, string $capability, ?string $message = null ): void {
		if ( ! $this->roles->has_capability( $user, $capability ) ) {
			throw new ApiException(
				$message ?? __( 'You do not have permission to perform this action.', 'peakurl' ),
				403
			);
		}
	}

	/**
	 * Require that the user has admin role or throw a 403 ApiException.
	 *
	 * @param array<string, mixed> $user    User row.
	 * @param string|null          $message Optional custom error message.
	 * @return void
	 *
	 * @throws ApiException When the user is not an admin.
	 */
	public function require_admin( array $user, ?string $message = null ): void {
		if ( ! $this->roles->is_admin( $user ) ) {
			throw new ApiException(
				$message ?? __( 'Administrator access required.', 'peakurl' ),
				403
			);
		}
	}

	/**
	 * Check whether a user is authorized for record access by ownership or global capability.
	 *
	 * @param array<string, mixed> $user              Current user.
	 * @param string               $owner_user_id     Record owner user ID.
	 * @param string               $own_capability    Capability allowed for owned records.
	 * @param string               $global_capability Capability allowed site-wide.
	 * @return bool True if authorized.
	 */
	public function can_access_record(
		array $user,
		string $owner_user_id,
		string $own_capability,
		string $global_capability
	): bool {
		if ( $this->roles->has_capability( $user, $global_capability ) ) {
			return true;
		}

		return (string) ( $user['id'] ?? '' ) === $owner_user_id
			&& $this->roles->has_capability( $user, $own_capability );
	}

	/**
	 * Validate owner-or-admin access against a record's user_id column.
	 *
	 * @param array<string, mixed> $user              Current user.
	 * @param string               $owner_user_id     Record owner user ID.
	 * @param string               $own_capability    Capability allowed for owned records.
	 * @param string               $global_capability Capability allowed site-wide.
	 * @param string               $message           Error message for denied access.
	 * @return void
	 *
	 * @throws ApiException When the user cannot access the record.
	 */
	public function validate_record_access(
		array $user,
		string $owner_user_id,
		string $own_capability,
		string $global_capability,
		string $message
	): void {
		if ( ! $this->can_access_record( $user, $owner_user_id, $own_capability, $global_capability ) ) {
			throw new ApiException( $message, 403 );
		}
	}

	/**
	 * Validate a user capability.
	 *
	 * @param array<string, mixed> $user          Current user.
	 * @param string               $capability    Required capability.
	 * @param string|null          $error_message Message returned on denial.
	 * @return void
	 *
	 * @throws ApiException When the capability is missing.
	 */
	public function validate_capability(
		array $user,
		string $capability,
		?string $error_message = null
	): void {
		$this->require_capability( $user, $capability, $error_message );
	}

	/**
	 * Check whether the user has permission to view all links site-wide.
	 *
	 * @param array<string, mixed> $user User row.
	 * @return bool True if authorized.
	 * @since 1.2.3
	 */
	public function can_view_all_links( array $user ): bool {
		return $this->roles->has_capability( $user, 'view_all_links' );
	}

	/**
	 * Check whether the user has permission to view their own links.
	 *
	 * @param array<string, mixed> $user User row.
	 * @return bool True if authorized.
	 * @since 1.2.3
	 */
	public function can_view_own_links( array $user ): bool {
		return $this->roles->has_capability( $user, 'view_own_links' );
	}

	/**
	 * Require that the user can view links (either site-wide or own) or throw 403.
	 *
	 * @param array<string, mixed> $user    User row.
	 * @param string|null          $message Optional custom error message.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view links.
	 * @since 1.2.3
	 */
	public function require_view_links( array $user, ?string $message = null ): void {
		if ( $this->can_view_all_links( $user ) || $this->can_view_own_links( $user ) ) {
			return;
		}

		throw new ApiException(
			$message ?? __( 'You do not have permission to view links.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Check whether the user has permission to view site-wide analytics.
	 *
	 * @param array<string, mixed> $user User row.
	 * @return bool True if authorized.
	 * @since 1.2.3
	 */
	public function can_view_site_analytics( array $user ): bool {
		return $this->roles->has_capability( $user, 'view_site_analytics' );
	}

	/**
	 * Check whether the user has permission to view their own analytics.
	 *
	 * @param array<string, mixed> $user User row.
	 * @return bool True if authorized.
	 * @since 1.2.3
	 */
	public function can_view_own_analytics( array $user ): bool {
		return $this->roles->has_capability( $user, 'view_own_analytics' );
	}

	/**
	 * Require that the user can view analytics (either site-wide or own) or throw 403.
	 *
	 * @param array<string, mixed> $user    User row.
	 * @param string|null          $message Optional custom error message.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view analytics.
	 * @since 1.2.3
	 */
	public function require_view_analytics( array $user, ?string $message = null ): void {
		if ( $this->can_view_site_analytics( $user ) || $this->can_view_own_analytics( $user ) ) {
			return;
		}

		throw new ApiException(
			$message ?? __( 'You do not have permission to view analytics.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Validate that a role change keeps at least one admin.
	 *
	 * @param string        $target_user_id    User being changed.
	 * @param string        $current_role      Current role of the target user.
	 * @param string        $next_role         Proposed new role.
	 * @param string        $acting_user_id    User performing the change.
	 * @param callable|null $admin_count_check Callable returning total active admin count.
	 * @return void
	 *
	 * @throws ApiException When the change would leave zero admins.
	 */
	public function validate_role_change(
		string $target_user_id,
		string $current_role,
		string $next_role,
		string $acting_user_id,
		?callable $admin_count_check = null
	): void {
		$current_role = $this->roles->normalize_role( $current_role );

		if ( 'admin' !== $current_role ) {
			return;
		}

		if ( 'admin' === $next_role ) {
			return;
		}

		$admin_count = $admin_count_check ? (int) $admin_count_check() : 1;

		if ( $admin_count > 1 ) {
			return;
		}

		if ( 'deleted' === $next_role ) {
			throw new ApiException(
				__( 'At least one admin account must remain on the site.', 'peakurl' ),
				422,
			);
		}

		if ( $target_user_id === $acting_user_id ) {
			throw new ApiException(
				__( 'You cannot demote the only remaining admin account.', 'peakurl' ),
				422,
			);
		}

		throw new ApiException(
			__( 'At least one admin account must remain on the site.', 'peakurl' ),
			422,
		);
	}
}
