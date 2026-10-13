<?php
/**
 * PeakURL role and capability registry.
 *
 * @package PeakURL\Core\Auth
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Auth;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Utils\Date;

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
	 * Default shipped role capability maps for PeakURL users.
	 *
	 * Action-oriented capability model:
	 * - Administrators have complete site control by default: user management,
	 *   system settings, and destructive capabilities (permanent link deletion, empty trash).
	 * - Editors have content management capabilities by default: creating, viewing,
	 *   and editing permitted links, trashing links, and viewing analytics.
	 *
	 * @var array<string, array<string, bool>>
	 * @since 1.0.0
	 */
	public const ROLE_CAPABILITIES = array(
		'admin'  => array(
			'manage_users'         => true,
			'manage_site_settings' => true,
			'manage_mail_delivery' => true,
			'manage_location_data' => true,
			'manage_performance'   => true,
			'manage_updates'       => true,
			'manage_webhooks'      => true,
			'manage_api_keys'      => true,
			'manage_profile'       => true,
			'create_links'         => true,
			'view_links'           => true,
			'edit_links'           => true,
			'trash_links'          => true,
			'delete_links'         => true,
			'empty_trash'          => true,
			'view_analytics'       => true,
		),
		'editor' => array(
			'create_links'   => true,
			'view_links'     => true,
			'edit_links'     => true,
			'trash_links'    => true,
			'view_analytics' => true,
			'manage_profile' => true,
		),
	);

	/**
	 * Canonical role metadata for supported site roles.
	 *
	 * @var array<string, array<string, string>>
	 * @since 1.7.2
	 */
	public const ROLES_METADATA = array(
		'admin'  => array(
			'name'        => 'Administrator',
			'description' => 'Complete control over site settings, users, roles, and all short links.',
		),
		'editor' => array(
			'name'        => 'Editor',
			'description' => 'Manage short links, view analytics, and edit permitted link records.',
		),
	);

	/**
	 * Definitive catalog of all supported capabilities with grouping and protection metadata.
	 *
	 * @var array<string, array<string, mixed>>
	 * @since 1.7.2
	 */
	public const CAPABILITIES_CATALOG = array(
		'manage_users'         => array(
			'label'            => 'Manage Users',
			'description'      => 'Create, edit, and delete user accounts and assign roles.',
			'group'            => 'user_access',
			'group_label'      => 'User & Access',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(
				'admin' => 'Administrators must retain user management to prevent administrative lockout.',
			),
		),
		'manage_profile'       => array(
			'label'            => 'Manage Profile',
			'description'      => 'Update own personal profile credentials, name, email, and password.',
			'group'            => 'user_access',
			'group_label'      => 'User & Access',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(
				'admin' => 'Administrators must retain profile management.',
			),
		),
		'manage_api_keys'      => array(
			'label'            => 'Manage API Keys',
			'description'      => 'Generate, inspect, and revoke personal API authentication keys.',
			'group'            => 'user_access',
			'group_label'      => 'User & Access',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_site_settings' => array(
			'label'            => 'Manage Site Settings',
			'description'      => 'Configure general site settings, branding, timezone, and defaults.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(
				'admin' => 'Administrators must retain site configuration access.',
			),
		),
		'manage_mail_delivery' => array(
			'label'            => 'Manage Mail Delivery',
			'description'      => 'Configure mail delivery transport, SMTP credentials, and send test emails.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(),
		),
		'manage_location_data' => array(
			'label'            => 'Manage Location Data',
			'description'      => 'Configure MaxMind GeoIP database credentials and automatic updates.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(),
		),
		'manage_performance'   => array(
			'label'            => 'Manage Performance',
			'description'      => 'Configure cache drivers, default link TTLs, and cache purging.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(),
		),
		'manage_updates'       => array(
			'label'            => 'Manage System Updates',
			'description'      => 'Check for and apply application software and database upgrades.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(),
		),
		'manage_webhooks'      => array(
			'label'            => 'Manage Webhooks',
			'description'      => 'Create, test, view deliveries, and manage webhook subscriptions.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'create_links'         => array(
			'label'            => 'Create Links',
			'description'      => 'Create new short links and custom aliases.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'view_links'           => array(
			'label'            => 'View Links',
			'description'      => 'View short links, link details, and destination URLs.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'edit_links'           => array(
			'label'            => 'Edit Links',
			'description'      => 'Modify destination URLs, metadata, and link status for permitted links.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'trash_links'          => array(
			'label'            => 'Trash Links',
			'description'      => 'Move links to trash and restore trashed links.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'delete_links'         => array(
			'label'            => 'Permanently Delete Links',
			'description'      => 'Permanently remove individual short links.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'empty_trash'          => array(
			'label'            => 'Empty Trash',
			'description'      => 'Permanently remove all trashed links at once.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(
				'admin' => 'Administrator capability cannot be disabled.',
			),
		),
		'view_analytics'       => array(
			'label'            => 'View Analytics',
			'description'      => 'View click totals, device analytics, referrer statistics, and geo data.',
			'group'            => 'analytics',
			'group_label'      => 'Analytics',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
	);

	/**
	 * Settings API helper for reading and writing persisted role configurations.
	 *
	 * @var SettingsApi|null
	 * @since 1.7.2
	 */
	private ?SettingsApi $settings_api = null;

	/**
	 * In-memory memoized stored capabilities map.
	 *
	 * @var array<string, array<string, bool>>|null
	 * @since 1.7.2
	 */
	private ?array $cached_capabilities = null;

	/**
	 * Create a new Roles registry instance.
	 *
	 * @param SettingsApi|null $settings_api Optional Settings API for persisted capabilities.
	 * @since 1.0.0
	 */
	public function __construct( ?SettingsApi $settings_api = null ) {
		$this->settings_api = $settings_api;
	}

	/**
	 * Set or update the Settings API instance.
	 *
	 * @param SettingsApi|null $settings_api Settings API instance.
	 * @return void
	 * @since 1.7.2
	 */
	public function set_settings_api( ?SettingsApi $settings_api ): void {
		$this->settings_api        = $settings_api;
		$this->cached_capabilities = null;
	}

	/**
	 * Clear in-memory memoized capabilities.
	 *
	 * @return void
	 * @since 1.7.2
	 */
	public function clear_cache(): void {
		$this->cached_capabilities = null;
	}

	/**
	 * Normalise a user role string to a supported PeakURL role.
	 *
	 * @param string $role Raw role input.
	 * @return string Canonical role value.
	 * @since 1.0.0
	 */
	public function normalize_role( string $role ): string {
		$role = sanitize_key( $role );

		if ( in_array( $role, array( 'admin', 'editor' ), true ) ) {
			return $role;
		}

		return 'editor';
	}

	/**
	 * Authoritative parser and validator for the role_capabilities JSON storage format.
	 *
	 * Distinguishes an absent setting (returns null) from present valid JSON or corrupt storage.
	 * Enforces that the stored document is a JSON object ({}) rather than a JSON list ([]),
	 * and validates that each role entry is an associative capability map.
	 *
	 * @param string|null $raw Stored JSON string or null if absent.
	 * @return array<string, array<string, bool>>|null Parsed role configuration map or null if absent.
	 *
	 * @throws ApiException When stored JSON is malformed, top-level list, or corrupt.
	 * @since 1.7.2
	 */
	public function parse_stored_role_capabilities( ?string $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		if ( '' === trim( $raw ) ) {
			throw new ApiException(
				__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
				500
			);
		}

		$raw_obj = json_decode( $raw );
		if ( json_last_error() !== JSON_ERROR_NONE || ! ( $raw_obj instanceof \stdClass ) ) {
			throw new ApiException(
				__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
				500
			);
		}

		$result = array();
		foreach ( (array) $raw_obj as $role_key => $role_val ) {
			$role_key = (string) $role_key;
			if ( ! in_array( $role_key, array( 'admin', 'editor' ), true ) ) {
				throw new ApiException(
					__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
					500
				);
			}

			if ( ! ( $role_val instanceof \stdClass ) ) {
				throw new ApiException(
					__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
					500
				);
			}

			$role_caps = array();
			foreach ( (array) $role_val as $cap_id => $cap_val ) {
				$cap_id = (string) $cap_id;
				if ( ! is_bool( $cap_val ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				if ( ! array_key_exists( $cap_id, self::CAPABILITIES_CATALOG ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$meta             = self::CAPABILITIES_CATALOG[ $cap_id ];
				$applicable_roles = $meta['applicable_roles'] ?? array( 'admin', 'editor' );
				if ( ! in_array( $role_key, $applicable_roles, true ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				// Protected roles cannot disable protected capabilities in stored overrides
				if ( isset( $meta['protected_roles'][ $role_key ] ) && false === $cap_val ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$role_caps[ $cap_id ] = $cap_val;
			}

			$result[ $role_key ] = $role_caps;
		}

		return $result;
	}

	/**
	 * Fetch stored custom role capabilities from the settings table.
	 *
	 * @return array<string, array<string, bool>> Stored capability overrides keyed by role.
	 * @since 1.7.2
	 */
	public function get_stored_role_capabilities(): array {
		if ( null !== $this->cached_capabilities ) {
			return $this->cached_capabilities;
		}

		if ( null === $this->settings_api ) {
			$this->cached_capabilities = array();
			return array();
		}

		$raw    = $this->settings_api->get_option( Constants::SETTING_ROLE_CAPABILITIES );
		$parsed = $this->parse_stored_role_capabilities( $raw );

		$this->cached_capabilities = $parsed ?? array();
		return $this->cached_capabilities;
	}

	/**
	 * Return the effective capability map for a given role.
	 *
	 * Combines default shipped definitions with any persisted administrative
	 * overrides while ensuring immutable protections on the administrator role
	 * and strict enforcement of capability applicability across roles.
	 *
	 * @param string $role Raw role input.
	 * @return array<string, bool> Capability flags keyed by capability name.
	 * @since 1.0.0
	 */
	public function capabilities_for_role( string $role ): array {
		$role     = $this->normalize_role( $role );
		$defaults = self::ROLE_CAPABILITIES[ $role ] ?? array();
		$stored   = $this->get_stored_role_capabilities();

		$caps = array();
		foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
			$caps[ $key ] = ! empty( $defaults[ $key ] );
		}

		if ( isset( $stored[ $role ] ) && is_array( $stored[ $role ] ) ) {
			foreach ( $stored[ $role ] as $key => $val ) {
				if ( array_key_exists( $key, self::CAPABILITIES_CATALOG ) && is_bool( $val ) ) {
					$caps[ $key ] = $val;
				}
			}
		}

		// Enforce capability applicability: inapplicable capabilities always resolve to false
		foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
			$applicable_roles = $meta['applicable_roles'] ?? array( 'admin', 'editor' );
			if ( ! in_array( $role, $applicable_roles, true ) ) {
				$caps[ $key ] = false;
			}
		}

		// Enforce immutable protections on administrator role.
		if ( 'admin' === $role ) {
			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				if ( isset( $meta['protected_roles']['admin'] ) ) {
					$caps[ $key ] = true;
				}
			}
		}

		return $caps;
	}

	/**
	 * Return whether a user row has a specific capability.
	 *
	 * @param array<string, mixed> $user       Formatted or raw user row.
	 * @param string               $capability Capability name to test.
	 * @return bool True when the capability is granted.
	 * @since 1.0.0
	 */
	public function has_capability( array $user, string $capability ): bool {
		$capabilities = $this->capabilities_for_role(
			(string) ( $user['role'] ?? 'editor' ),
		);

		return ! empty( $capabilities[ $capability ] );
	}

	/**
	 * Determine whether a user is an admin-level account based strictly on canonical role.
	 *
	 * Decoupled from individual configurable capabilities to ensure that granting
	 * permissions to non-admin roles never escalates them to administrative status.
	 *
	 * @param array<string, mixed> $user Formatted or raw user row.
	 * @return bool True for admin users.
	 * @since 1.0.0
	 */
	public function is_admin( array $user ): bool {
		return 'admin' === ( $user['role'] ?? '' );
	}

	/**
	 * Return rich definition objects for all supported roles and their capabilities.
	 *
	 * Suitable for API responses and the dashboard Manage Roles & Capabilities drawer.
	 *
	 * @return array<int, array<string, mixed>> List of role definition objects.
	 * @since 1.7.2
	 */
	public function get_role_definitions(): array {
		$result = array();

		foreach ( array( 'admin', 'editor' ) as $role ) {
			$meta         = self::ROLES_METADATA[ $role ];
			$defaults     = self::ROLE_CAPABILITIES[ $role ] ?? array();
			$current_caps = $this->capabilities_for_role( $role );

			$is_custom = false;
			foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
				if ( ( $current_caps[ $cap_id ] ?? false ) !== ( $defaults[ $cap_id ] ?? false ) ) {
					$is_custom = true;
					break;
				}
			}

			$capabilities = array();
			foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
				$is_protected      = isset( $cap_meta['protected_roles'][ $role ] );
				$protected_reason  = $is_protected ? (string) $cap_meta['protected_roles'][ $role ] : null;
				$applicable_roles  = $cap_meta['applicable_roles'] ?? array( 'admin', 'editor' );
				$is_applicable     = in_array( $role, $applicable_roles, true );
				$applicable_reason = ! $is_applicable ? __( 'Reserved for administrators.', 'peakurl' ) : null;
				$default_val       = ! empty( $defaults[ $cap_id ] );
				$enabled_val       = ! empty( $current_caps[ $cap_id ] );

				$capabilities[] = array(
					'id'                => $cap_id,
					'label'             => $cap_meta['label'],
					'description'       => $cap_meta['description'],
					'group'             => $cap_meta['group'],
					'group_label'       => $cap_meta['group_label'],
					'enabled'           => $enabled_val,
					'default'           => $default_val,
					'protected'         => $is_protected,
					'protected_reason'  => $protected_reason,
					'applicable'        => $is_applicable,
					'applicable_reason' => $applicable_reason,
				);
			}

			$result[] = array(
				'id'            => $role,
				'name'          => $meta['name'],
				'description'   => $meta['description'],
				'is_customized' => $is_custom,
				'capabilities'  => $capabilities,
			);
		}

		return $result;
	}

	/**
	 * Update capability configuration for a specific role.
	 *
	 * Validates role identity, known capability identifiers, boolean values,
	 * immutable protections on administrator recovery capabilities, and applicability
	 * of capabilities to the target role. Persists changes atomically.
	 *
	 * @param string               $role    Target role identifier ('admin' or 'editor').
	 * @param array<string, mixed> $payload Input payload containing 'capabilities' map.
	 * @return array<string, mixed> Updated role definition object.
	 *
	 * @throws ApiException When role is invalid, keys are unknown, or values are invalid.
	 * @since 1.7.2
	 */
	public function update_role_capabilities( string $role, array $payload ): array {
		$role = sanitize_key( $role );
		if ( ! in_array( $role, array( 'admin', 'editor' ), true ) ) {
			throw new ApiException(
				__( 'Invalid role identifier.', 'peakurl' ),
				422
			);
		}

		if ( ! isset( $payload['capabilities'] ) || ! is_array( $payload['capabilities'] ) ) {
			throw new ApiException(
				__( 'Capabilities payload must be an object with boolean values.', 'peakurl' ),
				422
			);
		}

		$input_caps = $payload['capabilities'];
		$defaults   = self::ROLE_CAPABILITIES[ $role ] ?? array();

		foreach ( $input_caps as $key => $value ) {
			if ( ! array_key_exists( $key, self::CAPABILITIES_CATALOG ) ) {
				throw new ApiException(
					sprintf(
						/* translators: %s is the capability key */
						__( 'Unknown capability: %s', 'peakurl' ),
						$key
					),
					422
				);
			}

			if ( ! is_bool( $value ) ) {
				throw new ApiException(
					sprintf(
						/* translators: %s is the capability key */
						__( 'Capability value for %s must be a boolean.', 'peakurl' ),
						$key
					),
					422
				);
			}

			$cap_meta         = self::CAPABILITIES_CATALOG[ $key ];
			$applicable_roles = $cap_meta['applicable_roles'] ?? array( 'admin', 'editor' );

			if ( ! in_array( $role, $applicable_roles, true ) ) {
				throw new ApiException(
					sprintf(
						/* translators: %s is the capability key */
						__( 'Capability "%s" is reserved for administrators and cannot be assigned to editors.', 'peakurl' ),
						$key
					),
					422
				);
			}

			// Protected capabilities on admin cannot be disabled.
			if ( 'admin' === $role && false === $value ) {
				if ( isset( $cap_meta['protected_roles']['admin'] ) ) {
					throw new ApiException(
						__( 'Protected administrator capabilities cannot be disabled.', 'peakurl' ),
						422
					);
				}
			}
		}

		if ( null === $this->settings_api ) {
			throw new ApiException(
				__( 'Settings storage is unavailable.', 'peakurl' ),
				500
			);
		}

		$this->settings_api->update_option_with_lock(
			Constants::SETTING_ROLE_CAPABILITIES,
			function ( ?string $raw ) use ( $role, $input_caps, $defaults ): ?string {
				$current_stored = $this->parse_stored_role_capabilities( $raw ) ?? array();

				$role_caps = $current_stored[ $role ] ?? $defaults;
				foreach ( $input_caps as $k => $v ) {
					$role_caps[ $k ] = (bool) $v;
				}

				// Check if updated role capabilities effectively match shipped defaults
				$matches_defaults = true;
				foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
					$eff_val = ! empty( $role_caps[ $cap_id ] );
					$def_val = ! empty( $defaults[ $cap_id ] );
					if ( $eff_val !== $def_val ) {
						$matches_defaults = false;
						break;
					}
				}

				if ( $matches_defaults ) {
					unset( $current_stored[ $role ] );
				} else {
					$current_stored[ $role ] = $role_caps;
				}

				if ( empty( $current_stored ) ) {
					return null;
				}

				return \peakurl_json_encode( $current_stored );
			}
		);

		$this->clear_cache();

		$definitions = $this->get_role_definitions();
		foreach ( $definitions as $def ) {
			if ( $def['id'] === $role ) {
				return $def;
			}
		}

		return array();
	}

	/**
	 * Reset capability configuration for a specific role to shipped defaults.
	 *
	 * Does not affect customizations for other roles and performs atomic mutations.
	 *
	 * @param string $role Target role identifier ('admin' or 'editor').
	 * @return array<string, mixed> Reset role definition object.
	 *
	 * @throws ApiException When role is invalid or settings storage is unavailable.
	 * @since 1.7.2
	 */
	public function reset_role_capabilities( string $role ): array {
		$role = sanitize_key( $role );
		if ( ! in_array( $role, array( 'admin', 'editor' ), true ) ) {
			throw new ApiException(
				__( 'Invalid role identifier.', 'peakurl' ),
				422
			);
		}

		if ( null === $this->settings_api ) {
			throw new ApiException(
				__( 'Settings storage is unavailable.', 'peakurl' ),
				500
			);
		}

		$this->settings_api->update_option_with_lock(
			Constants::SETTING_ROLE_CAPABILITIES,
			function ( ?string $raw ) use ( $role ): ?string {
				$current_stored = $this->parse_stored_role_capabilities( $raw );
				if ( null === $current_stored ) {
					return null;
				}

				unset( $current_stored[ $role ] );

				if ( empty( $current_stored ) ) {
					return null;
				}

				return \peakurl_json_encode( $current_stored );
			}
		);

		$this->clear_cache();

		$definitions = $this->get_role_definitions();
		foreach ( $definitions as $def ) {
			if ( $def['id'] === $role ) {
				return $def;
			}
		}

		return array();
	}
}
