<?php
/**
 * Plugin extension registry.
 *
 * Manages plugin-registered admin pages, settings schemas, backend actions,
 * scoped browser assets, and native dashboard UI extension points.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Extensions — Central registry for plugin runtime UI and API extensions.
 *
 * @since 1.8.0
 */
class Extensions {

	/**
	 * Core protected navigation route slugs that plugins cannot override.
	 *
	 * @var array<int, string>
	 */
	public const RESERVED_SLUGS = array(
		'overview',
		'links',
		'users',
		'plugins',
		'tools',
		'settings',
		'login',
		'register',
		'api',
		'dashboard',
		'setup',
		'mcp',
		'auth',
		'add',
		'page',
	);

	/**
	 * Allowed icon identifiers for plugin navigation and actions.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_ICONS = array(
		'settings',
		'tool',
		'share',
		'shield',
		'link',
		'sparkles',
		'sliders',
		'file-text',
		'globe',
		'database',
		'zap',
		'bell',
		'terminal',
		'folder',
		'chart',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered admin pages.
	 *
	 * Keyed by "{$plugin_slug}:{$page_id}".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $pages = array();

	/**
	 * Registered settings schemas.
	 *
	 * Keyed by plugin slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $settings_schemas = array();

	/**
	 * Registered backend actions.
	 *
	 * Keyed by "{$plugin_slug}:{$action_id}".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $actions = array();

	/**
	 * Registered scoped browser assets.
	 *
	 * Keyed by plugin slug, containing array of asset definitions.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $assets = array();

	/**
	 * Registered native UI extension points.
	 *
	 * Keyed by extension ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $ui_extensions = array();

	/**
	 * Identifier of the currently executing plugin entrypoint.
	 *
	 * Used to enforce that plugins cannot register extensions outside their namespace.
	 *
	 * @var string|null
	 */
	private ?string $current_plugin = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Set or replace the singleton instance (for testing).
	 *
	 * @param self|null $instance Instance to set.
	 * @return void
	 */
	public static function set_instance( ?self $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Reset the singleton instance to null.
	 *
	 * @return void
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Clear all in-memory registrations.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->pages            = array();
		$this->settings_schemas = array();
		$this->actions          = array();
		$this->assets           = array();
		$this->ui_extensions    = array();
		$this->current_plugin   = null;
	}

	/**
	 * Set or clear the currently executing plugin identifier.
	 *
	 * @param string|null $plugin_slug Plugin identifier or null when no plugin is executing.
	 * @return void
	 */
	public function set_current_plugin( ?string $plugin_slug ): void {
		$this->current_plugin = $plugin_slug;
	}

	/**
	 * Get the currently executing plugin identifier.
	 *
	 * @return string|null
	 */
	public function get_current_plugin(): ?string {
		return $this->current_plugin;
	}

	/**
	 * Verify that a registration attempt matches the currently executing plugin.
	 *
	 * @param string $plugin_slug Target plugin identifier.
	 * @return void
	 * @throws ApiException When a plugin attempts to register under a foreign namespace.
	 */
	private function verify_plugin_namespace( string $plugin_slug ): void {
		if ( null !== $this->current_plugin && $this->current_plugin !== $plugin_slug ) {
			throw new ApiException(
				sprintf(
					/* translators: 1: Calling plugin slug, 2: Target plugin slug */
					__( 'Plugin \'%1$s\' cannot register extensions under namespace \'%2$s\'.', 'peakurl' ),
					$this->current_plugin,
					$plugin_slug
				),
				403
			);
		}
	}

	/**
	 * Register an administrative dashboard page descriptor.
	 *
	 * @param array<string, mixed> $descriptor Page configuration.
	 * @return bool True if registered, false on error.
	 * @throws ApiException When descriptor validation fails.
	 */
	public function register_page( array $descriptor ): bool {
		$plugin_slug = (string) ( $descriptor['plugin_slug'] ?? '' );
		$page_id     = (string) ( $descriptor['page_id'] ?? '' );
		$title       = (string) ( $descriptor['title'] ?? '' );
		$menu_title  = (string) ( $descriptor['menu_title'] ?? $title );
		$capability  = (string) ( $descriptor['capability'] ?? 'manage_site_settings' );
		$icon        = (string) ( $descriptor['icon'] ?? 'settings' );
		$order       = (int) ( $descriptor['order'] ?? 50 );
		$sections    = (array) ( $descriptor['sections'] ?? array() );
		$actions     = (array) ( $descriptor['actions'] ?? array() );

		if ( '' === $plugin_slug || ! preg_match( '/^[a-z0-9_-]+$/', $plugin_slug ) ) {
			throw new ApiException( __( 'Invalid plugin slug for admin page registration.', 'peakurl' ), 400 );
		}

		$this->verify_plugin_namespace( $plugin_slug );

		if ( '' === $page_id || ! preg_match( '/^[a-z0-9_-]+$/', $page_id ) ) {
			throw new ApiException( __( 'Invalid page ID for admin page registration.', 'peakurl' ), 400 );
		}

		if ( in_array( $page_id, self::RESERVED_SLUGS, true ) || in_array( $plugin_slug, self::RESERVED_SLUGS, true ) ) {
			throw new ApiException( __( 'Plugin page identifier is not available. Please choose another.', 'peakurl' ), 400 );
		}

		$key = $plugin_slug . ':' . $page_id;
		if ( isset( $this->pages[ $key ] ) ) {
			throw new ApiException(
				sprintf(
					/* translators: 1: Plugin slug, 2: Page ID */
					__( 'Admin page \'%2$s\' is already registered for plugin \'%1$s\'.', 'peakurl' ),
					$plugin_slug,
					$page_id
				),
				409
			);
		}

		if ( '' === $title ) {
			throw new ApiException( __( 'Page title is required.', 'peakurl' ), 400 );
		}

		if ( ! in_array( $icon, self::ALLOWED_ICONS, true ) ) {
			$icon = 'settings';
		}

		$this->pages[ $key ] = array(
			'plugin_slug' => $plugin_slug,
			'page_id'     => $page_id,
			'title'       => $title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'icon'        => $icon,
			'order'       => $order,
			'sections'    => $sections,
			'actions'     => $actions,
		);

		return true;
	}

	/**
	 * Retrieve all registered admin pages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_pages(): array {
		return $this->pages;
	}

	/**
	 * Retrieve a specific registered admin page.
	 *
	 * @param string $plugin_slug Plugin identifier.
	 * @param string $page_id     Page identifier.
	 * @return array<string, mixed>|null Page descriptor or null if not found.
	 */
	public function get_page( string $plugin_slug, string $page_id ): ?array {
		$key = $plugin_slug . ':' . $page_id;

		return $this->pages[ $key ] ?? null;
	}

	/**
	 * Register a plugin settings schema.
	 *
	 * @param string               $plugin_slug Plugin identifier.
	 * @param array<string, mixed> $schema      Schema configuration.
	 * @return bool True if registered, false on error.
	 * @throws ApiException When schema validation fails.
	 */
	public function register_settings( string $plugin_slug, array $schema ): bool {
		if ( '' === $plugin_slug || ! preg_match( '/^[a-z0-9_-]+$/', $plugin_slug ) ) {
			throw new ApiException( __( 'Invalid plugin slug for settings registration.', 'peakurl' ), 400 );
		}

		$this->verify_plugin_namespace( $plugin_slug );

		if ( isset( $this->settings_schemas[ $plugin_slug ] ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: Plugin slug */
					__( 'Settings schema is already registered for plugin \'%s\'.', 'peakurl' ),
					$plugin_slug
				),
				409
			);
		}

		$capability = (string) ( $schema['capability'] ?? 'manage_site_settings' );
		$fields     = (array) ( $schema['fields'] ?? array() );

		$this->settings_schemas[ $plugin_slug ] = array(
			'plugin_slug' => $plugin_slug,
			'capability'  => $capability,
			'fields'      => $fields,
		);

		return true;
	}

	/**
	 * Retrieve registered settings schema for a plugin.
	 *
	 * @param string $plugin_slug Plugin identifier.
	 * @return array<string, mixed>|null Schema or null if not found.
	 */
	public function get_settings_schema( string $plugin_slug ): ?array {
		return $this->settings_schemas[ $plugin_slug ] ?? null;
	}

	/**
	 * Register a backend action callback for a plugin.
	 *
	 * @param string   $plugin_slug Plugin identifier.
	 * @param string   $action_id   Action identifier.
	 * @param callable $handler     Callback handler accepting (Request, array $context).
	 * @param string   $capability  Capability required to dispatch this action.
	 * @return bool True if registered.
	 * @throws ApiException When action parameters are invalid.
	 */
	public function register_action(
		string $plugin_slug,
		string $action_id,
		callable $handler,
		string $capability = 'manage_site_settings'
	): bool {
		if ( '' === $plugin_slug || ! preg_match( '/^[a-z0-9_-]+$/', $plugin_slug ) ) {
			throw new ApiException( __( 'Invalid plugin slug for action registration.', 'peakurl' ), 400 );
		}

		$this->verify_plugin_namespace( $plugin_slug );

		if ( '' === $action_id || ! preg_match( '/^[a-z0-9_-]+$/', $action_id ) ) {
			throw new ApiException( __( 'Invalid action ID for action registration.', 'peakurl' ), 400 );
		}

		$key = $plugin_slug . ':' . $action_id;
		if ( isset( $this->actions[ $key ] ) ) {
			throw new ApiException(
				sprintf(
					/* translators: 1: Plugin slug, 2: Action ID */
					__( 'Action \'%2$s\' is already registered for plugin \'%1$s\'.', 'peakurl' ),
					$plugin_slug,
					$action_id
				),
				409
			);
		}

		$this->actions[ $key ] = array(
			'plugin_slug' => $plugin_slug,
			'action_id'   => $action_id,
			'handler'     => $handler,
			'capability'  => $capability,
		);

		return true;
	}

	/**
	 * Retrieve a registered backend action.
	 *
	 * @param string $plugin_slug Plugin identifier.
	 * @param string $action_id   Action identifier.
	 * @return array<string, mixed>|null Action definition or null.
	 */
	public function get_action( string $plugin_slug, string $action_id ): ?array {
		$key = $plugin_slug . ':' . $action_id;

		return $this->actions[ $key ] ?? null;
	}

	/**
	 * Register an approved browser asset for a plugin.
	 *
	 * @param string               $plugin_slug   Plugin identifier.
	 * @param string               $relative_path Relative path inside plugin assets/ directory.
	 * @param string               $type          Asset type ('script' or 'style').
	 * @param array<string, mixed> $options       Optional configuration (scope, page_id, version).
	 * @return bool True if registered.
	 * @throws ApiException When asset path or parameters are invalid.
	 */
	public function register_asset(
		string $plugin_slug,
		string $relative_path,
		string $type = 'script',
		array $options = array()
	): bool {
		if ( '' === $plugin_slug || ! preg_match( '/^[a-z0-9_-]+$/', $plugin_slug ) ) {
			throw new ApiException( __( 'Invalid plugin slug for asset registration.', 'peakurl' ), 400 );
		}

		$this->verify_plugin_namespace( $plugin_slug );

		// Strictly reject directory traversal, absolute paths, or hidden files.
		$normalized = str_replace( '\\', '/', $relative_path );
		$normalized = ltrim( $normalized, '/' );

		if (
			str_contains( $normalized, '..' ) ||
			str_starts_with( $normalized, '.' ) ||
			str_contains( $normalized, '/.' )
		) {
			throw new ApiException( __( 'Invalid asset path: traversal and hidden files are forbidden.', 'peakurl' ), 400 );
		}

		// Ensure asset path is inside or relative to assets/ directory.
		if ( str_starts_with( $normalized, 'assets/' ) ) {
			$clean_path = substr( $normalized, 7 );
		} else {
			$clean_path = $normalized;
		}

		if ( '' === $clean_path ) {
			throw new ApiException( __( 'Asset path cannot be empty.', 'peakurl' ), 400 );
		}

		// Disallow PHP files, configuration files, or source maps.
		$ext = strtolower( pathinfo( $clean_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'php', 'phtml', 'phar', 'json', 'env', 'config', 'lock', 'sql', 'map' ), true ) ) {
			throw new ApiException( __( 'Executable, map, and configuration files cannot be registered as browser assets.', 'peakurl' ), 400 );
		}

		// Check for duplicate asset registration for this plugin and file.
		if ( isset( $this->assets[ $plugin_slug ] ) ) {
			foreach ( $this->assets[ $plugin_slug ] as $existing_asset ) {
				if ( $existing_asset['file'] === $clean_path ) {
					throw new ApiException(
						sprintf(
							/* translators: 1: Plugin slug, 2: File path */
							__( 'Asset \'%2$s\' is already registered for plugin \'%1$s\'.', 'peakurl' ),
							$plugin_slug,
							$clean_path
						),
						409
					);
				}
			}
		}

		if ( ! in_array( $type, array( 'script', 'style' ), true ) ) {
			$type = 'css' === $ext ? 'style' : 'script';
		}

		if ( ! isset( $this->assets[ $plugin_slug ] ) ) {
			$this->assets[ $plugin_slug ] = array();
		}

		$page_id = isset( $options['page_id'] ) ? (string) $options['page_id'] : null;
		$scope   = (string) ( $options['scope'] ?? 'page' );
		if ( 'plugin_page' === $scope ) {
			$scope = 'page';
		}

		$this->assets[ $plugin_slug ][] = array(
			'plugin_slug' => $plugin_slug,
			'file'        => $clean_path,
			'url'         => '/api/v1/system/plugins/' . rawurlencode( $plugin_slug ) . '/assets/' . $clean_path,
			'type'        => $type,
			'scope'       => $scope,
			'page_id'     => $page_id,
			'version'     => isset( $options['version'] ) ? (string) $options['version'] : '1.0.0',
		);

		return true;
	}

	/**
	 * Retrieve registered assets for a plugin.
	 *
	 * @param string      $plugin_slug Plugin identifier.
	 * @param string|null $scope       Optional scope filter ('page' or 'links_overview').
	 * @param string|null $page_id     Optional page ID filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_assets(
		string $plugin_slug,
		?string $scope = null,
		?string $page_id = null
	): array {
		$list = $this->assets[ $plugin_slug ] ?? array();

		if ( null === $scope && null === $page_id ) {
			return $list;
		}

		return array_values(
			array_filter(
				$list,
				static function ( array $asset ) use ( $scope, $page_id ): bool {
					if ( null !== $scope && $asset['scope'] !== $scope ) {
						return false;
					}
					if ( null !== $page_id && null !== $asset['page_id'] && $asset['page_id'] !== $page_id ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Register a native UI extension point.
	 *
	 * @param array<string, mixed> $descriptor UI extension point descriptor.
	 * @return bool True if registered.
	 * @throws ApiException When descriptor is invalid.
	 */
	public function register_ui_extension( array $descriptor ): bool {
		$id          = (string) ( $descriptor['id'] ?? '' );
		$plugin_slug = (string) ( $descriptor['plugin_slug'] ?? '' );
		$location    = (string) ( $descriptor['location'] ?? '' );
		$label       = (string) ( $descriptor['label'] ?? '' );
		$action_id   = (string) ( $descriptor['action_id'] ?? '' );
		$capability  = (string) ( $descriptor['capability'] ?? 'manage_site_settings' );
		$icon        = (string) ( $descriptor['icon'] ?? 'sparkles' );
		$confirm     = isset( $descriptor['confirm'] ) ? (string) $descriptor['confirm'] : null;

		if ( '' === $id || ! preg_match( '/^[a-z0-9_-]+$/', $id ) ) {
			throw new ApiException( __( 'Invalid UI extension ID.', 'peakurl' ), 400 );
		}

		if ( '' === $plugin_slug || ! preg_match( '/^[a-z0-9_-]+$/', $plugin_slug ) ) {
			throw new ApiException( __( 'Invalid plugin slug for UI extension.', 'peakurl' ), 400 );
		}

		$this->verify_plugin_namespace( $plugin_slug );

		if ( isset( $this->ui_extensions[ $id ] ) ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: UI extension ID */
					__( 'UI extension \'%s\' is already registered.', 'peakurl' ),
					$id
				),
				409
			);
		}

		$valid_locations = array( 'links_overview_actions' );
		if ( ! in_array( $location, $valid_locations, true ) ) {
			throw new ApiException( __( 'Unsupported UI extension location. Only \'links_overview_actions\' is supported.', 'peakurl' ), 400 );
		}

		if ( '' === $label ) {
			throw new ApiException( __( 'UI extension label is required.', 'peakurl' ), 400 );
		}

		if ( '' === $action_id ) {
			throw new ApiException( __( 'UI extension action_id is required.', 'peakurl' ), 400 );
		}

		$invalidates = array();
		if ( isset( $descriptor['invalidates'] ) && is_array( $descriptor['invalidates'] ) ) {
			$allowed_tags = array( 'Plugins', 'Analytics', 'Urls' );
			foreach ( $descriptor['invalidates'] as $tag ) {
				foreach ( $allowed_tags as $allowed ) {
					if ( strcasecmp( (string) $tag, $allowed ) === 0 && ! in_array( $allowed, $invalidates, true ) ) {
						$invalidates[] = $allowed;
						break;
					}
				}
			}
		}

		$this->ui_extensions[ $id ] = array(
			'id'          => $id,
			'plugin_slug' => $plugin_slug,
			'location'    => $location,
			'label'       => $label,
			'action_id'   => $action_id,
			'capability'  => $capability,
			'icon'        => in_array( $icon, self::ALLOWED_ICONS, true ) ? $icon : 'sparkles',
			'confirm'     => $confirm,
			'invalidates' => $invalidates,
		);

		return true;
	}

	/**
	 * Retrieve registered UI extensions.
	 *
	 * @param string|null $location Optional location filter.
	 * @return array<string, array<string, mixed>>
	 */
	public function get_ui_extensions( ?string $location = null ): array {
		if ( null === $location ) {
			return $this->ui_extensions;
		}

		return array_filter(
			$this->ui_extensions,
			static function ( array $ext ) use ( $location ): bool {
				return $ext['location'] === $location;
			}
		);
	}
}
