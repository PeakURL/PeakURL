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
use PeakURL\Api\UsersApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Roles — central role and capability definitions for site users.
 *
 * Mirrors the WordPress pattern of keeping role policy separate from the
 * persistence layer so stores, controllers, and UI payloads can all rely on a
 * single source of truth for user permissions and custom roles.
 *
 * @since 1.0.0
 */
class Roles {

	/**
	 * Canonical regular expression pattern for custom and registered role identifiers.
	 *
	 * Requires strict lowercase snake_case starting with an ASCII letter,
	 * containing only lowercase letters, digits, and single underscore separators.
	 *
	 * @var string
	 * @since 1.7.2
	 */
	public const string ROLE_ID_PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

	/**
	 * Maximum allowed length for role identifiers (matching users.role VARCHAR(32)).
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const int MAX_ROLE_ID_LENGTH = 32;

	/**
	 * Default shipped role capability maps for PeakURL users.
	 *
	 * Action-oriented capability model:
	 * - Administrators have complete site control: immutable superuser access
	 *   evaluated dynamically across all cataloged capabilities.
	 * - Editors have content management capabilities by default: creating, viewing,
	 *   and editing permitted links, trashing links, viewing analytics, and profile management.
	 *
	 * @var array<string, array<string, bool>>
	 * @since 1.0.0
	 */
	public const array ROLE_CAPABILITIES = array(
		'editor' => array(
			'create_links'   => true,
			'view_links'     => true,
			'edit_links'     => true,
			'trash_links'    => true,
			'view_analytics' => true,
		),
	);

	/**
	 * Canonical role metadata for built-in site roles.
	 *
	 * @var array<string, array<string, string>>
	 * @since 1.7.2
	 */
	public const array ROLES_METADATA = array(
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
	 * Definitive catalog of all supported capabilities with grouping and applicability metadata.
	 *
	 * @var array<string, array<string, mixed>>
	 * @since 1.7.2
	 */
	public const array CAPABILITIES_CATALOG = array(
		'manage_users'         => array(
			'label'            => 'Manage Users',
			'description'      => 'Create, edit, and delete user accounts and assign roles.',
			'group'            => 'user_access',
			'group_label'      => 'User & Access',
			'applicable_roles' => array( 'admin' ),
			'protected_roles'  => array(),
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
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_mail_delivery' => array(
			'label'            => 'Manage Mail Delivery',
			'description'      => 'Configure mail delivery transport, SMTP credentials, and send test emails.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_location_data' => array(
			'label'            => 'Manage Location Data',
			'description'      => 'Configure MaxMind GeoIP database credentials and automatic updates.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_performance'   => array(
			'label'            => 'Manage Performance',
			'description'      => 'Configure cache drivers, default link TTLs, and cache purging.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_updates'       => array(
			'label'            => 'Manage System Updates',
			'description'      => 'Check for and apply application software and database upgrades.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_integrations'  => array(
			'label'            => 'Manage Integrations',
			'description'      => 'Manage site-wide webhook subscriptions and deliveries, and configure CAPTCHA protection.',
			'group'            => 'site_integrations',
			'group_label'      => 'Site & Integrations',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'manage_mcp'           => array(
			'label'            => 'Manage MCP Server',
			'description'      => 'Configure Model Context Protocol (MCP) server integration, client connections, and permissions.',
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
			'label'            => 'Edit Own Links',
			'description'      => 'Edit short links owned by your account.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'edit_any_link'        => array(
			'label'            => 'Edit Links Across Accounts',
			'description'      => 'Also edit short links owned by other users, including administrators. Requires Edit Own Links.',
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
			'label'            => 'Permanently Delete Own Links',
			'description'      => 'Permanently remove short links owned by your account.',
			'group'            => 'links',
			'group_label'      => 'Links',
			'applicable_roles' => array( 'admin', 'editor' ),
			'protected_roles'  => array(),
		),
		'delete_any_link'      => array(
			'label'            => 'Permanently Delete Links Across Accounts',
			'description'      => 'Also permanently remove links owned by other users, including administrators. Requires Permanently Delete Own Links.',
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
			'protected_roles'  => array(),
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
	 * Users API helper for querying role user assignments.
	 *
	 * @var UsersApi|null
	 * @since 1.7.2
	 */
	private ?UsersApi $users_api = null;

	/**
	 * In-memory memoized stored capabilities map.
	 *
	 * @var array<string, mixed>|null
	 * @since 1.7.2
	 */
	private ?array $cached_capabilities = null;

	/**
	 * Create a new Roles registry instance.
	 *
	 * @param SettingsApi|null $settings_api Optional Settings API for persisted capabilities.
	 * @param UsersApi|null    $users_api    Optional Users API for user assignment queries.
	 * @since 1.0.0
	 * @since 1.7.2 Added optional UsersApi parameter.
	 */
	public function __construct( ?SettingsApi $settings_api = null, ?UsersApi $users_api = null ) {
		$this->settings_api = $settings_api;
		$this->users_api    = $users_api;
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
	 * Set or update the Users API instance.
	 *
	 * @param UsersApi|null $users_api Users API instance.
	 * @return void
	 * @since 1.7.2
	 */
	public function set_users_api( ?UsersApi $users_api ): void {
		$this->users_api = $users_api;
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
	 * Validate that a role identifier conforms to the canonical lowercase snake_case contract.
	 *
	 * - Length between 1 and 32 ASCII characters.
	 * - Starts with a lowercase letter.
	 * - Contains only lowercase letters, digits, and single underscore separators.
	 *
	 * @param string $role Role identifier to test.
	 * @return bool True if valid canonical role identifier syntax.
	 * @since 1.7.2
	 */
	public static function is_valid_role_id( string $role ): bool {
		$len = strlen( $role );
		if ( $len < 1 || $len > self::MAX_ROLE_ID_LENGTH ) {
			return false;
		}

		return 1 === preg_match( self::ROLE_ID_PATTERN, $role );
	}

	/**
	 * Check whether a role identifier represents a recognized system or custom role.
	 *
	 * @param string $role Role identifier to test.
	 * @return bool True if recognized as admin, editor, or an existing custom role.
	 * @since 1.7.2
	 */
	public function is_registered_role( string $role ): bool {
		if ( ! self::is_valid_role_id( $role ) ) {
			return false;
		}

		return in_array( $role, array( 'admin', 'editor' ), true ) || $this->is_custom_role( $role );
	}

	/**
	 * Determine whether a role identifier represents a registered custom role.
	 *
	 * @param string $role Role identifier to test.
	 * @return bool True if the role is a registered custom role.
	 * @since 1.7.2
	 */
	public function is_custom_role( string $role ): bool {
		if ( ! self::is_valid_role_id( $role ) ) {
			return false;
		}

		if ( in_array( $role, array( 'admin', 'editor' ), true ) ) {
			return false;
		}

		$custom_roles = $this->get_custom_roles();

		return isset( $custom_roles[ $role ] );
	}

	/**
	 * Return registered custom role definitions from storage.
	 *
	 * @return array<string, array<string, mixed>> Registered custom roles map keyed by slug.
	 * @since 1.7.2
	 */
	public function get_custom_roles(): array {
		$stored = $this->get_role_settings();

		return $stored['custom_roles'] ?? array();
	}

	/**
	 * Authoritative parser and validator for the role_capabilities JSON storage format.
	 *
	 * Storage shape:
	 * {
	 *   "editor": { "create_links": true, ... },
	 *   "custom_roles": {
	 *     "content_manager": {
	 *       "name": "Content Manager",
	 *       "description": "...",
	 *       "initial_capabilities": { ... },
	 *       "capabilities": { ... }
	 *     }
	 *   }
	 * }
	 *
	 * Rejects any unknown top-level keys or legacy keys.
	 * Enforces that non-admin capability overrides only map recognized, delegable capabilities.
	 * Requires \stdClass for capability maps (rejecting JSON arrays []).
	 *
	 * @param string|null $raw Stored JSON string or null if absent.
	 * @return array<string, mixed>|null Parsed role configuration map or null if absent.
	 *
	 * @throws ApiException When stored JSON is malformed, top-level list, or corrupt.
	 * @since 1.7.2
	 */
	public function parse_role_settings( ?string $raw ): ?array {
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

		$top_keys         = array_keys( (array) $raw_obj );
		$unknown_top_keys = array_diff( $top_keys, array( 'editor', 'custom_roles' ) );
		if ( ! empty( $unknown_top_keys ) ) {
			throw new ApiException(
				__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
				500
			);
		}

		$result = array();

		if ( property_exists( $raw_obj, 'editor' ) ) {
			if ( ! ( $raw_obj->editor instanceof \stdClass ) ) {
				throw new ApiException(
					__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
					500
				);
			}

			$role_caps = array();
			foreach ( (array) $raw_obj->editor as $cap_id => $cap_val ) {
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
				if ( ! in_array( 'editor', $applicable_roles, true ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$role_caps[ $cap_id ] = $cap_val;
			}

			$result['editor'] = $role_caps;
		}

		if ( property_exists( $raw_obj, 'custom_roles' ) ) {
			if ( ! ( $raw_obj->custom_roles instanceof \stdClass ) ) {
				throw new ApiException(
					__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
					500
				);
			}

			$custom_roles = array();
			foreach ( (array) $raw_obj->custom_roles as $slug => $entry ) {
				$slug = (string) $slug;
				if ( ! self::is_valid_role_id( $slug ) || in_array( $slug, array( 'admin', 'editor' ), true ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				if ( ! ( $entry instanceof \stdClass ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$entry_props   = array_keys( (array) $entry );
				$unknown_props = array_diff( $entry_props, array( 'name', 'description', 'initial_capabilities', 'capabilities' ) );
				if ( ! empty( $unknown_props ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				if ( ! property_exists( $entry, 'name' ) || ! is_string( $entry->name ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$name = trim( $entry->name );
				if ( '' === $name || mb_strlen( $name ) > 100 ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$description = '';
				if ( property_exists( $entry, 'description' ) ) {
					if ( ! is_string( $entry->description ) ) {
						throw new ApiException(
							__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
							500
						);
					}
					$description = trim( $entry->description );
					if ( mb_strlen( $description ) > 500 ) {
						throw new ApiException(
							__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
							500
						);
					}
				}

				if ( ! property_exists( $entry, 'initial_capabilities' ) || ! ( $entry->initial_capabilities instanceof \stdClass ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				if ( ! property_exists( $entry, 'capabilities' ) || ! ( $entry->capabilities instanceof \stdClass ) ) {
					throw new ApiException(
						__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
						500
					);
				}

				$parse_cap_map = function ( \stdClass $raw_map ): array {
					$parsed_caps = array();
					foreach ( (array) $raw_map as $c_id => $c_val ) {
						$c_id = (string) $c_id;
						if ( ! is_bool( $c_val ) ) {
							throw new ApiException(
								__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
								500
							);
						}

						if ( ! array_key_exists( $c_id, self::CAPABILITIES_CATALOG ) ) {
							throw new ApiException(
								__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
								500
							);
						}

						$meta             = self::CAPABILITIES_CATALOG[ $c_id ];
						$applicable_roles = $meta['applicable_roles'] ?? array( 'admin', 'editor' );
						if ( ! in_array( 'editor', $applicable_roles, true ) ) {
							throw new ApiException(
								__( 'Invalid or corrupted role capabilities configuration in storage.', 'peakurl' ),
								500
							);
						}

						$parsed_caps[ $c_id ] = $c_val;
					}

					return $parsed_caps;
				};

				$custom_roles[ $slug ] = array(
					'name'                 => $name,
					'description'          => $description,
					'initial_capabilities' => $parse_cap_map( $entry->initial_capabilities ),
					'capabilities'         => $parse_cap_map( $entry->capabilities ),
				);
			}

			$result['custom_roles'] = $custom_roles;
		}

		return $result;
	}

	/**
	 * Serialize role settings to JSON for storage.
	 *
	 * Guarantees that:
	 * - If settings are empty (no editor overrides and no custom roles), returns null.
	 * - Empty custom_roles is omitted (never serialized as []).
	 * - Editor overrides, custom_roles, capabilities, and initial_capabilities maps
	 *   serialize as JSON objects (\stdClass), never lists ([]).
	 *
	 * @param array<string, mixed> $settings Raw settings array.
	 * @return string|null JSON string or null if empty.
	 * @since 1.7.2
	 */
	public static function serialize_role_settings( array $settings ): ?string {
		$has_editor       = ! empty( $settings['editor'] ) && is_array( $settings['editor'] );
		$has_custom_roles = ! empty( $settings['custom_roles'] ) && is_array( $settings['custom_roles'] );

		if ( ! $has_editor && ! $has_custom_roles ) {
			return null;
		}

		$payload = new \stdClass();

		if ( $has_editor ) {
			$editor_obj = new \stdClass();
			foreach ( $settings['editor'] as $k => $v ) {
				$editor_obj->{ (string) $k} = (bool) $v;
			}
			$payload->editor = $editor_obj;
		}

		if ( $has_custom_roles ) {
			$roles_obj = new \stdClass();
			foreach ( $settings['custom_roles'] as $slug => $def ) {
				$role_obj              = new \stdClass();
				$role_obj->name        = (string) ( $def['name'] ?? '' );
				$role_obj->description = (string) ( $def['description'] ?? '' );

				$init_caps = new \stdClass();
				if ( ! empty( $def['initial_capabilities'] ) && is_array( $def['initial_capabilities'] ) ) {
					foreach ( $def['initial_capabilities'] as $ck => $cv ) {
						$init_caps->{ (string) $ck} = (bool) $cv;
					}
				}
				$role_obj->initial_capabilities = $init_caps;

				$caps = new \stdClass();
				if ( ! empty( $def['capabilities'] ) && is_array( $def['capabilities'] ) ) {
					foreach ( $def['capabilities'] as $ck => $cv ) {
						$caps->{ (string) $ck} = (bool) $cv;
					}
				}
				$role_obj->capabilities = $caps;

				$roles_obj->{ (string) $slug} = $role_obj;
			}
			$payload->custom_roles = $roles_obj;
		}

		return \peakurl_json_encode( $payload );
	}

	/**
	 * Fetch stored role capabilities and custom role definitions from the settings table.
	 *
	 * @return array<string, mixed> Stored role configurations map.
	 * @since 1.7.2
	 */
	public function get_role_settings(): array {
		if ( null !== $this->cached_capabilities ) {
			return $this->cached_capabilities;
		}

		if ( null === $this->settings_api ) {
			$this->cached_capabilities = array();
			return array();
		}

		$raw = $this->settings_api->get_option( Constants::SETTING_ROLE_CAPABILITIES );

		$parsed = $this->parse_role_settings( $raw );

		$this->cached_capabilities = $parsed ?? array();
		return $this->cached_capabilities;
	}

	/**
	 * Resolve the effective delegable Editor capabilities from shipped defaults and optional overrides.
	 *
	 * @param array<string, bool> $editor_overrides Persisted Editor overrides.
	 * @return array<string, bool> Delegable Editor capability map.
	 * @since 1.7.2
	 */
	public function resolve_editor_capabilities( array $editor_overrides = array() ): array {
		$defaults = self::ROLE_CAPABILITIES['editor'] ?? array();
		$resolved = array();

		foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
			$applicable_roles = $cap_meta['applicable_roles'] ?? array( 'admin', 'editor' );
			if ( ! in_array( 'editor', $applicable_roles, true ) ) {
				continue;
			}

			if ( isset( $editor_overrides[ $cap_id ] ) && is_bool( $editor_overrides[ $cap_id ] ) ) {
				$resolved[ $cap_id ] = $editor_overrides[ $cap_id ];
			} else {
				$resolved[ $cap_id ] = ! empty( $defaults[ $cap_id ] );
			}
		}

		if ( empty( $resolved['edit_links'] ) ) {
			$resolved['edit_any_link'] = false;
		}

		if ( empty( $resolved['delete_links'] ) ) {
			$resolved['delete_any_link'] = false;
		}

		return $resolved;
	}

	/**
	 * Return the effective capability map for a given role.
	 *
	 * - Administrators always have every recognized capability set to true.
	 *   Evaluated directly without reading stored non-admin JSON, so storage
	 *   corruption cannot lock an administrator out.
	 * - Editors merge shipped defaults with persisted capability overrides.
	 * - Custom roles evaluate against their persisted capability maps.
	 * - Inapplicable capabilities (such as manage_users or empty_trash) always resolve
	 *   to false for non-admin roles.
	 * - Unknown or deleted roles resolve to false for all capabilities (fail closed).
	 *
	 * @param string $role Raw role input.
	 * @return array<string, bool> Capability flags keyed by capability name.
	 * @since 1.0.0
	 * @since 1.7.2 Added custom role support and immutable admin resolution.
	 */
	public function capabilities_for_role( string $role ): array {
		if ( ! self::is_valid_role_id( $role ) ) {
			$caps = array();
			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				$caps[ $key ] = false;
			}
			return $caps;
		}

		// 1. Immutable Administrator superuser
		if ( 'admin' === $role ) {
			$caps = array();
			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				$caps[ $key ] = true;
			}
			return $caps;
		}

		$stored = $this->get_role_settings();

		// 2. Editor role resolution
		if ( 'editor' === $role ) {
			$editor_caps = $this->resolve_editor_capabilities( $stored['editor'] ?? array() );
			$caps        = array();

			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				$caps[ $key ] = $editor_caps[ $key ] ?? false;
			}

			return $caps;
		}

		// 3. Custom roles resolution
		$custom_roles = $stored['custom_roles'] ?? array();
		if ( isset( $custom_roles[ $role ] ) && is_array( $custom_roles[ $role ] ) ) {
			$custom_def = $custom_roles[ $role ];
			$caps       = array();

			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				$caps[ $key ] = false;
			}

			if ( isset( $custom_def['capabilities'] ) && is_array( $custom_def['capabilities'] ) ) {
				foreach ( $custom_def['capabilities'] as $key => $val ) {
					if ( array_key_exists( $key, self::CAPABILITIES_CATALOG ) && is_bool( $val ) ) {
						$caps[ $key ] = $val;
					}
				}
			}

			// Enforce capability applicability ceiling (same as editor)
			foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
				$applicable_roles = $meta['applicable_roles'] ?? array( 'admin', 'editor' );
				if ( ! in_array( 'editor', $applicable_roles, true ) ) {
					$caps[ $key ] = false;
				}
			}

			if ( empty( $caps['edit_links'] ) ) {
				$caps['edit_any_link'] = false;
			}

			if ( empty( $caps['delete_links'] ) ) {
				$caps['delete_any_link'] = false;
			}

			return $caps;
		}

		// 4. Unknown or deleted role: all capabilities false (fail closed)
		$caps = array();
		foreach ( self::CAPABILITIES_CATALOG as $key => $meta ) {
			$caps[ $key ] = false;
		}

		return $caps;
	}

	/**
	 * Return whether a user row has a specific capability.
	 *
	 * Canonical administrators always have all recognized capabilities without
	 * parsing stored JSON. For non-admin accounts, checks effective role capabilities.
	 * Unknown capability names always return false.
	 *
	 * @param array<string, mixed> $user       Formatted or raw user row.
	 * @param string               $capability Capability name to test.
	 * @return bool True when the capability is granted.
	 * @since 1.0.0
	 * @since 1.7.2 Fast-path immutable admin superuser check.
	 */
	public function has_capability( array $user, string $capability ): bool {
		$role = (string) ( $user['role'] ?? '' );

		if ( 'admin' === $role ) {
			return array_key_exists( $capability, self::CAPABILITIES_CATALOG );
		}

		$capabilities = $this->capabilities_for_role( $role );

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
	 * Determine whether a user can view analytics for a specific link.
	 *
	 * Administrators may view analytics for all links. Non-administrators require
	 * the 'view_analytics' capability AND must own the link (matching user_id).
	 * Fails closed when user ID or link user ID is absent or empty.
	 *
	 * @param array<string, mixed> $user         Authenticated user array.
	 * @param string|null          $link_user_id Link owner user ID.
	 * @return bool True if authorized to view analytics for the link.
	 * @since 1.7.2
	 */
	public function can_view_link_analytics( array $user, ?string $link_user_id ): bool {
		if ( $this->is_admin( $user ) ) {
			return true;
		}

		if ( ! $this->has_capability( $user, 'view_analytics' ) ) {
			return false;
		}

		$caller_id = (string) ( $user['id'] ?? '' );
		if ( '' === $caller_id || null === $link_user_id || '' === $link_user_id ) {
			return false;
		}

		return $caller_id === (string) $link_user_id;
	}

	/**
	 * Build structured capability descriptors for role definition API responses.
	 *
	 * @param array<string, bool> $current_caps  Effective capability map.
	 * @param array<string, bool> $baseline_caps Baseline capability map (shipped defaults or initial snapshot).
	 * @param bool                $is_admin      Whether building for an admin descriptor.
	 * @return array<int, array<string, mixed>> List of structured capability objects.
	 * @since 1.7.2
	 */
	private function get_capability_descriptors(
		array $current_caps,
		array $baseline_caps,
		bool $is_admin
	): array {
		$capabilities = array();

		foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
			$applicable_roles  = $cap_meta['applicable_roles'] ?? array( 'admin', 'editor' );
			$is_applicable     = $is_admin || in_array( 'editor', $applicable_roles, true );
			$applicable_reason = ! $is_applicable ? __( 'Reserved for administrators.', 'peakurl' ) : null;
			$default_val       = ! empty( $baseline_caps[ $cap_id ] );
			$enabled_val       = ! empty( $current_caps[ $cap_id ] );

			$capabilities[] = array(
				'id'                => $cap_id,
				'label'             => $cap_meta['label'],
				'description'       => $cap_meta['description'],
				'group'             => $cap_meta['group'],
				'group_label'       => $cap_meta['group_label'],
				'enabled'           => $enabled_val,
				'default'           => $default_val,
				'protected'         => false,
				'protected_reason'  => null,
				'applicable'        => $is_applicable,
				'applicable_reason' => $applicable_reason,
			);
		}

		return $capabilities;
	}

	/**
	 * Return rich definition objects for all supported roles and their capabilities.
	 *
	 * Returns:
	 * 1. Minimal read-only, non-configurable administrator descriptor.
	 * 2. Configurable Editor definition.
	 * 3. Configurable custom roles.
	 *
	 * Suitable for API responses and the dashboard user and role management screens.
	 *
	 * @return array<int, array<string, mixed>> List of role definition objects.
	 * @since 1.7.2
	 */
	public function get_role_definitions(): array {
		$result = array();

		// 1. Read-only non-configurable admin descriptor for user assignment/display
		$result[] = array(
			'id'              => 'admin',
			'name'            => self::ROLES_METADATA['admin']['name'],
			'description'     => self::ROLES_METADATA['admin']['description'],
			'is_builtin'      => true,
			'is_configurable' => false,
			'is_customized'   => false,
			'capabilities'    => array(),
			'user_count'      => $this->users_api ? $this->users_api->count_users_by_role( 'admin' ) : 0,
		);

		// 2. Configurable Editor definition
		$editor_defaults     = self::ROLE_CAPABILITIES['editor'] ?? array();
		$editor_current_caps = $this->capabilities_for_role( 'editor' );
		$editor_customized   = false;

		foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
			if ( ! in_array( 'editor', $cap_meta['applicable_roles'] ?? array(), true ) ) {
				continue;
			}
			if ( ( $editor_current_caps[ $cap_id ] ?? false ) !== ( $editor_defaults[ $cap_id ] ?? false ) ) {
				$editor_customized = true;
				break;
			}
		}

		$result[] = array(
			'id'              => 'editor',
			'name'            => self::ROLES_METADATA['editor']['name'],
			'description'     => self::ROLES_METADATA['editor']['description'],
			'is_builtin'      => true,
			'is_configurable' => true,
			'is_customized'   => $editor_customized,
			'capabilities'    => $this->get_capability_descriptors(
				$editor_current_caps,
				$editor_defaults,
				false
			),
			'user_count'      => $this->users_api ? $this->users_api->count_users_by_role( 'editor' ) : 0,
		);

		// 3. Registered custom roles
		$stored       = $this->get_role_settings();
		$custom_roles = $stored['custom_roles'] ?? array();

		foreach ( $custom_roles as $role_id => $custom_def ) {
			$initial_caps  = $custom_def['initial_capabilities'] ?? array();
			$current_caps  = $this->capabilities_for_role( $role_id );
			$is_customized = false;

			foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
				if ( ! in_array( 'editor', $cap_meta['applicable_roles'] ?? array(), true ) ) {
					continue;
				}
				if ( ( $current_caps[ $cap_id ] ?? false ) !== ( $initial_caps[ $cap_id ] ?? false ) ) {
					$is_customized = true;
					break;
				}
			}

			$result[] = array(
				'id'              => $role_id,
				'name'            => $custom_def['name'],
				'description'     => $custom_def['description'] ?? '',
				'is_builtin'      => false,
				'is_configurable' => true,
				'is_customized'   => $is_customized,
				'capabilities'    => $this->get_capability_descriptors(
					$current_caps,
					$initial_caps,
					false
				),
				'user_count'      => $this->users_api ? $this->users_api->count_users_by_role( $role_id ) : 0,
			);
		}

		return $result;
	}

	/**
	 * Return the definition object for a single role.
	 *
	 * @param string $role Target role identifier.
	 * @return array<string, mixed> Role definition object.
	 *
	 * @throws ApiException When role is not found.
	 * @since 1.7.2
	 */
	public function get_role_definition( string $role ): array {
		if ( ! self::is_valid_role_id( $role ) ) {
			throw new ApiException( __( 'Role not found.', 'peakurl' ), 404 );
		}

		foreach ( $this->get_role_definitions() as $def ) {
			if ( $def['id'] === $role ) {
				return $def;
			}
		}

		throw new ApiException( __( 'Role not found.', 'peakurl' ), 404 );
	}

	/**
	 * Get the human-readable display name for a role.
	 *
	 * @param string $role Role identifier.
	 * @return string Display name.
	 * @since 1.7.2
	 */
	public function get_role_name( string $role ): string {
		if ( 'admin' === $role ) {
			return __( 'Administrator', 'peakurl' );
		}

		if ( 'editor' === $role ) {
			return __( 'Editor', 'peakurl' );
		}

		if ( self::is_valid_role_id( $role ) ) {
			$stored = $this->get_role_settings();
			if ( isset( $stored['custom_roles'][ $role ]['name'] ) && '' !== trim( (string) $stored['custom_roles'][ $role ]['name'] ) ) {
				return trim( (string) $stored['custom_roles'][ $role ]['name'] );
			}
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $role ) );
	}

	/**
	 * Create a new custom role derived from the current Editor capabilities snapshot.
	 *
	 * @param string $id          Unique machine slug (max 32 lowercase letters/numbers/underscores).
	 * @param string $name        Display name (1-100 characters).
	 * @param string $description Optional description (max 500 characters).
	 * @return array<string, mixed> Created role definition object.
	 *
	 * @throws ApiException On validation failure (422), duplicate ID (409), or storage failure (500).
	 * @since 1.7.2
	 */
	public function create_custom_role( string $id, string $name, string $description = '' ): array {
		$name        = trim( $name );
		$description = trim( $description );

		if ( ! self::is_valid_role_id( $id ) ) {
			throw new ApiException(
				__( 'Role ID must be between 1 and 32 lowercase letters, numbers, and single underscores, starting with a letter.', 'peakurl' ),
				422
			);
		}

		if ( in_array( $id, array( 'admin', 'editor' ), true ) ) {
			throw new ApiException(
				__( 'The role ID is reserved.', 'peakurl' ),
				422
			);
		}

		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			throw new ApiException(
				__( 'Role name must be between 1 and 100 characters.', 'peakurl' ),
				422
			);
		}

		if ( mb_strlen( $description ) > 500 ) {
			throw new ApiException(
				__( 'Role description must not exceed 500 characters.', 'peakurl' ),
				422
			);
		}

		if ( null === $this->settings_api ) {
			throw new ApiException(
				__( 'Settings storage is unavailable.', 'peakurl' ),
				500
			);
		}

		$created_def = null;

		$this->settings_api->update_option_with_lock(
			Constants::SETTING_ROLE_CAPABILITIES,
			function ( ?string $raw ) use ( $id, $name, $description, &$created_def ): ?string {
				$stored_settings = $this->parse_role_settings( $raw ) ?? array();
				$custom_roles    = $stored_settings['custom_roles'] ?? array();

				if ( count( $custom_roles ) >= 50 ) {
					throw new ApiException(
						__( 'Maximum number of custom roles reached (50).', 'peakurl' ),
						422
					);
				}

				if ( isset( $custom_roles[ $id ] ) ) {
					throw new ApiException(
						sprintf(
							/* translators: %s is the role ID */
							__( 'A custom role with identifier "%s" already exists.', 'peakurl' ),
							$id
						),
						409
					);
				}

				$snapshot               = $this->resolve_editor_capabilities( $stored_settings['editor'] ?? array() );
				$snapshot['manage_mcp'] = false;

				$custom_roles[ $id ] = array(
					'name'                 => $name,
					'description'          => $description,
					'initial_capabilities' => $snapshot,
					'capabilities'         => $snapshot,
				);

				$stored_settings['custom_roles'] = $custom_roles;

				$created_def = array(
					'id'              => $id,
					'name'            => $name,
					'description'     => $description,
					'is_builtin'      => false,
					'is_configurable' => true,
					'is_customized'   => false,
					'capabilities'    => $this->get_capability_descriptors( $snapshot, $snapshot, false ),
					'user_count'      => 0,
				);

				return self::serialize_role_settings( $stored_settings );
			}
		);

		$this->clear_cache();

		return $created_def ?? $this->get_role_definition( $id );
	}

	/**
	 * Update metadata and/or capability configuration for a role.
	 *
	 * - Rejects admin role modifications with 403 (immutable superuser).
	 * - For Editor: updates delegable capabilities.
	 * - For custom roles: updates name, description, and/or capabilities.
	 *
	 * @param string               $role    Target role identifier.
	 * @param array<string, mixed> $payload Input payload containing 'capabilities', 'name', or 'description'.
	 * @return array<string, mixed> Updated role definition object.
	 *
	 * @throws ApiException On validation failure (422), permission failure (403), or not found (404).
	 * @since 1.7.2
	 */
	public function update_role_definition( string $role, array $payload ): array {
		if ( 'admin' === $role ) {
			throw new ApiException(
				__( 'Administrator capabilities are immutable.', 'peakurl' ),
				403
			);
		}

		if ( ! self::is_valid_role_id( $role ) || ( 'editor' !== $role && ! $this->is_custom_role( $role ) ) ) {
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

		$input_caps = null;
		if ( array_key_exists( 'capabilities', $payload ) ) {
			if ( ! is_array( $payload['capabilities'] ) ) {
				throw new ApiException(
					__( 'Capabilities payload must be an object with boolean values.', 'peakurl' ),
					422
				);
			}

			$input_caps = $payload['capabilities'];

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

				if ( ! in_array( 'editor', $applicable_roles, true ) ) {
					throw new ApiException(
						sprintf(
							/* translators: %s is the capability key */
							__( 'Capability "%s" is reserved for administrators and cannot be assigned to editors.', 'peakurl' ),
							$key
						),
						422
					);
				}
			}
		}

		$new_name = null;
		if ( array_key_exists( 'name', $payload ) ) {
			if ( 'editor' === $role ) {
				throw new ApiException(
					__( 'Built-in role name cannot be modified.', 'peakurl' ),
					422
				);
			}
			if ( ! is_string( $payload['name'] ) ) {
				throw new ApiException(
					__( 'Role name must be a string.', 'peakurl' ),
					422
				);
			}
			$name_str = trim( $payload['name'] );
			if ( '' === $name_str || mb_strlen( $name_str ) > 100 ) {
				throw new ApiException(
					__( 'Role name must be between 1 and 100 characters.', 'peakurl' ),
					422
				);
			}
			$new_name = $name_str;
		}

		$new_description = null;
		if ( array_key_exists( 'description', $payload ) ) {
			if ( 'editor' === $role ) {
				throw new ApiException(
					__( 'Built-in role description cannot be modified.', 'peakurl' ),
					422
				);
			}
			if ( ! is_string( $payload['description'] ) ) {
				throw new ApiException(
					__( 'Role description must be a string.', 'peakurl' ),
					422
				);
			}
			$desc_str = trim( $payload['description'] );
			if ( mb_strlen( $desc_str ) > 500 ) {
				throw new ApiException(
					__( 'Role description must not exceed 500 characters.', 'peakurl' ),
					422
				);
			}
			$new_description = $desc_str;
		}

		$this->settings_api->update_option_with_lock(
			Constants::SETTING_ROLE_CAPABILITIES,
			function ( ?string $raw ) use ( $role, $input_caps, $new_name, $new_description ): ?string {
				$stored_settings = $this->parse_role_settings( $raw ) ?? array();

				if ( 'editor' === $role ) {
					$defaults  = self::ROLE_CAPABILITIES['editor'] ?? array();
					$role_caps = $stored_settings['editor'] ?? $defaults;

					if ( null !== $input_caps ) {
						foreach ( $input_caps as $k => $v ) {
							$role_caps[ $k ] = (bool) $v;
						}
					}

					if ( empty( $role_caps['edit_links'] ) ) {
						$role_caps['edit_any_link'] = false;
					}
					if ( empty( $role_caps['delete_links'] ) ) {
						$role_caps['delete_any_link'] = false;
					}

					// Check if updated role capabilities effectively match shipped defaults
					$matches_defaults = true;
					foreach ( self::CAPABILITIES_CATALOG as $cap_id => $cap_meta ) {
						if ( ! in_array( 'editor', $cap_meta['applicable_roles'] ?? array(), true ) ) {
							continue;
						}
						$eff_val = ! empty( $role_caps[ $cap_id ] );
						$def_val = ! empty( $defaults[ $cap_id ] );
						if ( $eff_val !== $def_val ) {
							$matches_defaults = false;
							break;
						}
					}

					if ( $matches_defaults ) {
						unset( $stored_settings['editor'] );
					} else {
						$stored_settings['editor'] = $role_caps;
					}
				} else {
					$custom_roles = $stored_settings['custom_roles'] ?? array();
					if ( ! isset( $custom_roles[ $role ] ) ) {
						throw new ApiException( __( 'Role not found.', 'peakurl' ), 404 );
					}

					if ( null !== $new_name ) {
						$custom_roles[ $role ]['name'] = $new_name;
					}

					if ( null !== $new_description ) {
						$custom_roles[ $role ]['description'] = $new_description;
					}

					if ( null !== $input_caps ) {
						$caps = $custom_roles[ $role ]['capabilities'] ?? array();
						foreach ( $input_caps as $k => $v ) {
							$caps[ $k ] = (bool) $v;
						}
						if ( empty( $caps['edit_links'] ) ) {
							$caps['edit_any_link'] = false;
						}
						if ( empty( $caps['delete_links'] ) ) {
							$caps['delete_any_link'] = false;
						}
						$custom_roles[ $role ]['capabilities'] = $caps;
					}

					$stored_settings['custom_roles'] = $custom_roles;
				}

				if ( empty( $stored_settings['editor'] ) && empty( $stored_settings['custom_roles'] ) ) {
					return null;
				}

				return self::serialize_role_settings( $stored_settings );
			}
		);

		$this->clear_cache();

		return $this->get_role_definition( $role );
	}

	/**
	 * Reset capability configuration for a specific role.
	 *
	 * - Editor is restored to shipped Editor defaults.
	 * - Custom roles are restored to their own creation-time snapshot.
	 * - Administrator reset requests are rejected with 403 (immutable superuser).
	 *
	 * @param string $role Target role identifier.
	 * @return array<string, mixed> Reset role definition object.
	 *
	 * @throws ApiException When role is invalid, administrator, or settings unavailable.
	 * @since 1.7.2
	 */
	public function reset_role_capabilities( string $role ): array {
		if ( 'admin' === $role ) {
			throw new ApiException(
				__( 'Administrator capabilities are immutable.', 'peakurl' ),
				403
			);
		}

		if ( ! self::is_valid_role_id( $role ) || ( 'editor' !== $role && ! $this->is_custom_role( $role ) ) ) {
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
				$stored_settings = $this->parse_role_settings( $raw );
				if ( null === $stored_settings ) {
					return null;
				}

				if ( 'editor' === $role ) {
					unset( $stored_settings['editor'] );
				} else {
					$custom_roles = $stored_settings['custom_roles'] ?? array();
					if ( isset( $custom_roles[ $role ] ) ) {
						// Reset capabilities to its own creation snapshot
						$custom_roles[ $role ]['capabilities'] = $custom_roles[ $role ]['initial_capabilities'];
						$stored_settings['custom_roles']       = $custom_roles;
					}
				}

				if ( empty( $stored_settings['editor'] ) && empty( $stored_settings['custom_roles'] ) ) {
					return null;
				}

				return self::serialize_role_settings( $stored_settings );
			}
		);

		$this->clear_cache();

		return $this->get_role_definition( $role );
	}

	/**
	 * Delete a custom role from the registry.
	 *
	 * Built-in roles (admin, editor) cannot be deleted.
	 * Custom roles cannot be deleted while assigned to any users (409 Conflict).
	 *
	 * @param string $role Target custom role identifier.
	 * @return void
	 *
	 * @throws ApiException On built-in deletion (403), not found (404), assigned users (409), or storage unavailable (500).
	 * @since 1.7.2
	 */
	public function delete_custom_role( string $role ): void {
		if ( in_array( $role, array( 'admin', 'editor' ), true ) ) {
			throw new ApiException(
				__( 'Built-in roles cannot be deleted.', 'peakurl' ),
				403
			);
		}

		if ( ! self::is_valid_role_id( $role ) || ! $this->is_custom_role( $role ) ) {
			throw new ApiException(
				__( 'Role not found.', 'peakurl' ),
				404
			);
		}

		if ( null === $this->settings_api || null === $this->users_api ) {
			throw new ApiException(
				__( 'Required storage or user services are unavailable.', 'peakurl' ),
				500
			);
		}

		$this->settings_api->update_option_with_lock(
			Constants::SETTING_ROLE_CAPABILITIES,
			function ( ?string $raw ) use ( $role ): ?string {
				$stored_settings = $this->parse_role_settings( $raw ) ?? array();

				$custom_roles = $stored_settings['custom_roles'] ?? array();
				if ( ! isset( $custom_roles[ $role ] ) ) {
					throw new ApiException( __( 'Role not found.', 'peakurl' ), 404 );
				}

				$role_name = (string) ( $custom_roles[ $role ]['name'] ?? $role );

				$assigned_count = $this->users_api->count_role_assignments_for_update( $role );
				if ( $assigned_count > 0 ) {
					throw new ApiException(
						sprintf(
							/* translators: 1: role name, 2: user count */
							__( 'Cannot delete role "%1$s" because it is assigned to %2$d user(s). Reassign them first.', 'peakurl' ),
							$role_name,
							$assigned_count
						),
						409
					);
				}

				unset( $custom_roles[ $role ] );
				if ( empty( $custom_roles ) ) {
					unset( $stored_settings['custom_roles'] );
				} else {
					$stored_settings['custom_roles'] = $custom_roles;
				}

				if ( empty( $stored_settings['editor'] ) && empty( $stored_settings['custom_roles'] ) ) {
					return null;
				}

				return self::serialize_role_settings( $stored_settings );
			}
		);

		$this->clear_cache();
	}
}
