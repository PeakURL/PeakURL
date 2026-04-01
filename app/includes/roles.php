<?php
/**
 * PeakURL role and capability registry.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Includes;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Roles — central role and capability definitions for site users.
 *
 * Mirrors the WordPress pattern of keeping role policy separate from the
 * persistence layer so stores, controllers, and UI payloads can all rely on a
 * single source of truth for admin/editor permissions.
 *
 * @since 1.0.0
 */
class Roles {

	/**
	 * WordPress-style role capability map for PeakURL users.
	 *
	 * Admins have complete site control. Editors can manage site links and
	 * view site analytics, but cannot manage users or site-wide services.
	 *
	 * @var array<string, array<string, bool>>
	 * @since 1.0.0
	 */
	private const ROLE_CAPABILITIES = array(
		'admin'  => array(
			'manage_users'         => true,
			'manage_site_settings' => true,
			'manage_mail_delivery' => true,
			'manage_location_data' => true,
			'manage_updates'       => true,
			'view_all_links'       => true,
			'edit_all_links'       => true,
			'delete_all_links'     => true,
			'view_site_analytics'  => true,
			'manage_webhooks'      => true,
			'manage_api_keys'      => true,
			'manage_profile'       => true,
			'create_links'         => true,
		),
		'editor' => array(
			'view_all_links'      => true,
			'view_own_links'      => true,
			'edit_all_links'      => true,
			'edit_own_links'      => true,
			'delete_all_links'    => true,
			'delete_own_links'    => true,
			'view_site_analytics' => true,
			'view_own_analytics'  => true,
			'manage_profile'      => true,
			'create_links'        => true,
		),
	);

	/**
	 * Normalise a user role string to a supported PeakURL role.
	 *
	 * @param string $role Raw role input.
	 * @return string Canonical role value.
	 * @since 1.0.0
	 */
	public function normalize_role( string $role ): string {
		$role = strtolower( trim( $role ) );

		if ( in_array( $role, array( 'admin', 'editor' ), true ) ) {
			return $role;
		}

		return 'editor';
	}

	/**
	 * Return the capability map for a given role.
	 *
	 * @param string $role Raw role input.
	 * @return array<string, bool> Capability flags keyed by capability name.
	 * @since 1.0.0
	 */
	public function capabilities_for_role( string $role ): array {
		$role = $this->normalize_role( $role );

		return self::ROLE_CAPABILITIES[ $role ] ?? array();
	}

	/**
	 * Check whether a user row has a specific capability.
	 *
	 * @param array<string, mixed> $user       Hydrated or raw user row.
	 * @param string               $capability Capability name to test.
	 * @return bool True when the capability is granted.
	 * @since 1.0.0
	 */
	public function user_can( array $user, string $capability ): bool {
		$capabilities = $this->capabilities_for_role(
			(string) ( $user['role'] ?? 'editor' ),
		);

		return ! empty( $capabilities[ $capability ] );
	}

	/**
	 * Determine whether a user is an admin-level account.
	 *
	 * @param array<string, mixed> $user Hydrated or raw user row.
	 * @return bool True for admin users.
	 * @since 1.0.0
	 */
	public function is_admin( array $user ): bool {
		return $this->user_can( $user, 'manage_users' );
	}
}
