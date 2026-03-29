<?php
/**
 * Data store authorization trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Store_Authorization_Trait — shared permission helpers for Data_Store.
 *
 * Keeps request-level capability checks and ownership scoping logic out of the
 * main store class so role policy stays modular and easier to reason about.
 *
 * @since 1.0.0
 */
trait Store_Authorization_Trait {

	/**
	 * Enforce owner-or-admin access against a record's user_id column.
	 *
	 * @param array<string, mixed> $user              Current user.
	 * @param string               $owner_user_id     Record owner user ID.
	 * @param string               $own_capability    Capability allowed for owned records.
	 * @param string               $global_capability Capability allowed site-wide.
	 * @param string               $message           Error message for denied access.
	 * @return void
	 *
	 * @throws Api_Exception When the user cannot access the record.
	 * @since 1.0.0
	 */
	private function assert_record_access(
		array $user,
		string $owner_user_id,
		string $own_capability,
		string $global_capability,
		string $message
	): void {
		if ( $this->roles->user_can( $user, $global_capability ) ) {
			return;
		}

		if (
			(string) ( $user['id'] ?? '' ) === $owner_user_id &&
			$this->roles->user_can( $user, $own_capability )
		) {
			return;
		}

		throw new Api_Exception( $message, 403 );
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
	 * @throws Api_Exception When the user cannot view links.
	 * @since 1.0.0
	 */
	private function add_link_visibility_scope(
		array $user,
		array &$conditions,
		array &$params,
		string $table_alias = 'u'
	): void {
		if ( $this->roles->user_can( $user, 'view_all_links' ) ) {
			return;
		}

		if ( $this->roles->user_can( $user, 'view_own_links' ) ) {
			$conditions[]            = $table_alias . '.user_id = :scope_user_id';
			$params['scope_user_id'] = (string) $user['id'];
			return;
		}

		throw new Api_Exception(
			'You do not have permission to view links.',
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
	 * @throws Api_Exception When the user cannot view analytics.
	 * @since 1.0.0
	 */
	private function add_click_analytics_scope(
		array $user,
		string &$join_sql,
		array &$conditions,
		array &$params,
		string $click_alias = 'c',
		string $url_alias = 'u'
	): void {
		if ( $this->roles->user_can( $user, 'view_site_analytics' ) ) {
			return;
		}

		if ( $this->roles->user_can( $user, 'view_own_analytics' ) ) {
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

		throw new Api_Exception(
			'You do not have permission to view analytics.',
			403,
		);
	}

	/**
	 * Assert the current request belongs to a user with a capability.
	 *
	 * @param Request $request       Incoming HTTP request.
	 * @param string  $capability    Required capability.
	 * @param string  $error_message Message returned on denial.
	 * @return array<string, mixed> Hydrated current user.
	 *
	 * @throws Api_Exception When the capability is missing.
	 * @since 1.0.0
	 */
	private function assert_request_capability(
		Request $request,
		string $capability,
		string $error_message
	): array {
		$user = $this->get_current_user( $request );

		if ( ! $this->roles->user_can( $user, $capability ) ) {
			throw new Api_Exception( $error_message, 403 );
		}

		return $user;
	}

	/**
	 * Assert the current request belongs to an admin user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> The admin user's hydrated profile.
	 *
	 * @throws Api_Exception When the user is not an admin.
	 * @since 1.0.0
	 */
	private function assert_admin_request( Request $request ): array {
		return $this->assert_request_capability(
			$request,
			'manage_users',
			'Admin access is required.',
		);
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
	 * Guard against demoting or deleting the last remaining admin.
	 *
	 * @param string $target_user_id User being changed.
	 * @param string $current_role   Current role of the target user.
	 * @param string $next_role      Proposed new role.
	 * @param string $acting_user_id User performing the change.
	 * @return void
	 *
	 * @throws Api_Exception When the change would leave zero admins.
	 * @since 1.0.0
	 */
	private function assert_admin_role_change_is_allowed(
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
			throw new Api_Exception(
				'At least one admin account must remain on the site.',
				422,
			);
		}

		if ( $target_user_id === $acting_user_id ) {
			throw new Api_Exception(
				'You cannot demote the only remaining admin account.',
				422,
			);
		}

		throw new Api_Exception(
			'At least one admin account must remain on the site.',
			422,
		);
	}
}
