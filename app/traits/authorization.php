<?php
/**
 * Data store authorization trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AuthorizationTrait — shared permission helpers for Store.
 *
 * Keeps request-level capability policy and ownership scoping logic out of the
 * main store class so role policy stays modular and easier to reason about.
 *
 * @since 1.0.0
 */
trait AuthorizationTrait {

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
	 * @since 1.0.0
	 */
	private function validate_record_access(
		array $user,
		string $owner_user_id,
		string $own_capability,
		string $global_capability,
		string $message
	): void {
		if ( $this->roles->has_capability( $user, $global_capability ) ) {
			return;
		}

		if (
			(string) ( $user['id'] ?? '' ) === $owner_user_id &&
			$this->roles->has_capability( $user, $own_capability )
		) {
			return;
		}

		throw new ApiException( $message, 403 );
	}

	/**
	 * Add an ownership scope to URL queries for non-admin users.
	 *
	 * @param array<string, mixed>      $user       Current user.
	 * @param array<int, string>        $conditions SQL conditions array.
	 * @param array<string, string|int> $params     Bound parameter array.
	 * @param string                    $table_alias URL table alias.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view links.
	 * @since 1.0.0
	 */
	private function scope_link_visibility(
		array $user,
		array &$conditions,
		array &$params,
		string $table_alias = 'u'
	): void {
		if ( $this->roles->has_capability( $user, 'view_all_links' ) ) {
			return;
		}

		if ( $this->roles->has_capability( $user, 'view_own_links' ) ) {
			$conditions[]            = $table_alias . '.user_id = :scope_user_id';
			$params['scope_user_id'] = (string) $user['id'];
			return;
		}

		throw new ApiException(
			__( 'You do not have permission to view links.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Add an ownership scope to click analytics queries for non-admin users.
	 *
	 * @param array<string, mixed>      $user        Current user.
	 * @param string                    $join_sql    JOIN clause string.
	 * @param array<int, string>        $conditions  SQL conditions array.
	 * @param array<string, string|int> $params      Bound parameter array.
	 * @param string                    $click_alias Clicks table alias.
	 * @param string                    $url_alias   URLs table alias.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view analytics.
	 * @since 1.0.0
	 */
	private function scope_click_analytics(
		array $user,
		string &$join_sql,
		array &$conditions,
		array &$params,
		string $click_alias = 'c',
		string $url_alias = 'u'
	): void {
		if ( $this->roles->has_capability( $user, 'view_site_analytics' ) ) {
			return;
		}

		if ( $this->roles->has_capability( $user, 'view_own_analytics' ) ) {
			$join_sql               .=
				' INNER JOIN urls ' .
				$url_alias .
				' ON ' .
				$url_alias .
				'.id = ' .
				$click_alias .
				'.url_id';
			$conditions[]            = $url_alias . '.user_id = :scope_user_id';
			$params['scope_user_id'] = (string) $user['id'];
			return;
		}

		throw new ApiException(
			__( 'You do not have permission to view analytics.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Add click-analytics ownership scope when URLs are already joined.
	 *
	 * @param array<string, mixed>      $user       Current user.
	 * @param array<int, string>        $conditions SQL conditions array.
	 * @param array<string, string|int> $params     Bound parameter array.
	 * @param string                    $url_alias  URLs table alias.
	 * @return void
	 *
	 * @throws ApiException When the user cannot view analytics.
	 * @since 1.2.1
	 */
	private function scope_click_analytics_visibility(
		array $user,
		array &$conditions,
		array &$params,
		string $url_alias = 'u'
	): void {
		if ( $this->roles->has_capability( $user, 'view_site_analytics' ) ) {
			return;
		}

		if ( $this->roles->has_capability( $user, 'view_own_analytics' ) ) {
			$conditions[]            = $url_alias . '.user_id = :scope_user_id';
			$params['scope_user_id'] = (string) $user['id'];
			return;
		}

		throw new ApiException(
			__( 'You do not have permission to view analytics.', 'peakurl' ),
			403,
		);
	}

	/**
	 * Validate a user capability.
	 *
	 * @param array<string, mixed> $user          Current user.
	 * @param string  $capability    Required capability.
	 * @param string  $error_message Message returned on denial.
	 * @return void
	 *
	 * @throws ApiException When the capability is missing.
	 * @since 1.0.0
	 */
	private function validate_capability(
		array $user,
		string $capability,
		string $error_message
	): void {
		if ( ! $this->roles->has_capability( $user, $capability ) ) {
			throw new ApiException( $error_message, 403 );
		}
	}

	/**
	 * Return the current admin user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> The admin user's formatted profile.
	 *
	 * @throws ApiException When the user is not an admin.
	 * @since 1.0.0
	 */
	private function get_admin_user( Request $request ): array {
		$user = $this->get_current_user( $request );

		if ( ! $this->roles->is_admin( $user ) ) {
			throw new ApiException( __( 'Admin access is required.', 'peakurl' ), 403 );
		}

		return $user;
	}

	/**
	 * Count the number of admin users in the system.
	 *
	 * @return int Admin user count.
	 * @since 1.0.0
	 */
	private function count_admin_users(): int {
		return $this->users_api->count_admin_users();
	}

	/**
	 * Validate that a role change keeps at least one admin.
	 *
	 * @param string $target_user_id User being changed.
	 * @param string $current_role   Current role of the target user.
	 * @param string $next_role      Proposed new role.
	 * @param string $acting_user_id User performing the change.
	 * @return void
	 *
	 * @throws ApiException When the change would leave zero admins.
	 * @since 1.0.0
	 */
	private function validate_role_change(
		string $target_user_id,
		string $current_role,
		string $next_role,
		string $acting_user_id
	): void {
		$current_role = $this->roles->normalize_role( $current_role );

		if ( 'admin' !== $current_role ) {
			return;
		}

		if ( 'admin' === $next_role ) {
			return;
		}

		if ( $this->count_admin_users() > 1 ) {
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
