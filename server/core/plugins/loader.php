<?php
/**
 * Canonical plugin runtime loader.
 *
 * Coordinates execution of validated must-use and active regular plugins
 * in deterministic order across all application entry points.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

use PeakURL\Api\SettingsApi;
use PeakURL\Services\Database\Connection;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Loader — Runtime lifecycle orchestrator for installed plugins.
 *
 * @since 1.8.0
 */
class Loader {

	/**
	 * Singleton instance for the current process.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Plugin registry instance.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Settings API for active plugin persistence.
	 *
	 * @var SettingsApi|null
	 */
	private ?SettingsApi $settings_api;

	/**
	 * Whether the loader has already bootstrapped plugins in this process.
	 *
	 * @var bool
	 */
	private bool $bootstrapped = false;

	/**
	 * Whether regular plugins are bypassed via safe/recovery mode.
	 *
	 * @var bool
	 */
	private bool $safe_mode = false;

	/**
	 * Whether must-use plugins are bypassed via recovery mode.
	 *
	 * @var bool
	 */
	private bool $skip_mu = false;

	/**
	 * Map of loaded must-use plugin IDs to Metadata objects.
	 *
	 * @var array<string, Metadata>
	 */
	private array $loaded_mu = array();

	/**
	 * Map of loaded regular plugin IDs to Metadata objects.
	 *
	 * @var array<string, Metadata>
	 */
	private array $loaded_regular = array();

	/**
	 * List of active regular plugin identifiers loaded from settings.
	 *
	 * @var array<int, string>
	 */
	private array $active_regular_ids = array();

	/**
	 * Whether settings persistence was accessible when querying active plugins.
	 *
	 * @var bool
	 */
	private bool $settings_available = true;

	/**
	 * Diagnostic error message if settings persistence was inaccessible.
	 *
	 * @var string|null
	 */
	private ?string $settings_error = null;

	/**
	 * Create a new plugin loader instance.
	 *
	 * @param Registry|null    $registry     Optional plugin registry.
	 * @param SettingsApi|null $settings_api Optional settings API helper.
	 * @param bool|null        $safe_mode    Optional override for regular plugin bypass.
	 * @param bool|null        $skip_mu      Optional override for must-use plugin bypass.
	 */
	public function __construct(
		?Registry $registry = null,
		?SettingsApi $settings_api = null,
		?bool $safe_mode = null,
		?bool $skip_mu = null
	) {
		$this->registry     = $registry ?? new Registry();
		$this->settings_api = $settings_api;

		if ( null !== $safe_mode ) {
			$this->safe_mode = $safe_mode;
		} else {
			$skip_env        = getenv( 'PEAKURL_SKIP_PLUGINS' );
			$safe_env        = getenv( 'PEAKURL_SAFE_MODE' );
			$skip_const      = defined( 'PEAKURL_SKIP_PLUGINS' ) && (bool) constant( 'PEAKURL_SKIP_PLUGINS' );
			$this->safe_mode = ! empty( $skip_env ) || ! empty( $safe_env ) || $skip_const;
		}

		if ( null !== $skip_mu ) {
			$this->skip_mu = $skip_mu;
		} else {
			$skip_mu_env   = getenv( 'PEAKURL_SKIP_MU_PLUGINS' );
			$skip_mu_const = defined( 'PEAKURL_SKIP_MU_PLUGINS' ) && (bool) constant( 'PEAKURL_SKIP_MU_PLUGINS' );
			$this->skip_mu = ! empty( $skip_mu_env ) || $skip_mu_const;
		}
	}

	/**
	 * Retrieve or initialize the process singleton instance.
	 *
	 * @param Registry|null    $registry     Optional plugin registry.
	 * @param SettingsApi|null $settings_api Optional settings API.
	 * @return self
	 */
	public static function get_instance(
		?Registry $registry = null,
		?SettingsApi $settings_api = null
	): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $registry, $settings_api );
		}

		return self::$instance;
	}

	/**
	 * Replace the singleton instance (primarily for tests).
	 *
	 * @param self|null $instance Loader instance or null to clear.
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
	 * Bootstrap and execute active plugins across all application lifecycles.
	 *
	 * Idempotent: Subsequent calls in the same request/process do not re-execute.
	 *
	 * @param array<string, mixed>|null $config       Runtime configuration.
	 * @param Connection|null           $connection   Database connection.
	 * @param SettingsApi|null          $settings_api Optional settings API helper.
	 * @param Registry|null             $registry     Optional plugin registry.
	 * @return self
	 */
	public static function bootstrap(
		?array $config = null,
		?Connection $connection = null,
		?SettingsApi $settings_api = null,
		?Registry $registry = null
	): self {
		if ( null === $settings_api && function_exists( 'get_settings_api' ) ) {
			try {
				$settings_api = get_settings_api( $config, $connection );
			} catch ( \Throwable $e ) {
				// Settings table or database may not yet be initialized.
				$settings_api = null;
			}
		}

		$loader = self::get_instance( $registry, $settings_api );
		if ( null === $loader->settings_api && null !== $settings_api ) {
			$loader->settings_api = $settings_api;
		}

		$loader->load_plugins();

		return $loader;
	}

	/**
	 * Execute validated must-use plugins and active regular plugins.
	 *
	 * @return void
	 */
	public function load_plugins(): void {
		if ( $this->bootstrapped ) {
			return;
		}

		$this->bootstrapped = true;

		// ── Phase 1: Must-Use Plugins ─────────────────────────────────────
		if ( ! $this->skip_mu ) {
			$mu_plugins = $this->registry->must_use();
			ksort( $mu_plugins, SORT_STRING );

			foreach ( $mu_plugins as $id => $metadata ) {
				if ( ! $metadata->is_valid() || ! $metadata->is_compatible() ) {
					continue;
				}

				$entry_point = $metadata->plugin_path() . '/plugin.php';
				if ( is_file( $entry_point ) && is_readable( $entry_point ) ) {
					$this->execute_entry_point( $entry_point );
					$this->loaded_mu[ $id ] = $metadata->with_active( true )->with_loaded( true );
				}
			}

			if ( function_exists( 'do_action' ) ) {
				\do_action( 'muplugins_loaded' );
			}
		}

		// ── Phase 2: Regular Plugins ──────────────────────────────────────
		if ( ! $this->safe_mode ) {
			$this->active_regular_ids = $this->fetch_active_plugin_ids();
			$regular_plugins          = $this->registry->regular();

			foreach ( $this->active_regular_ids as $id ) {
				$metadata = $regular_plugins[ $id ] ?? null;
				if ( null === $metadata || ! $metadata->is_valid() || ! $metadata->is_compatible() ) {
					continue;
				}

				$entry_point = $metadata->plugin_path() . '/plugin.php';
				if ( is_file( $entry_point ) && is_readable( $entry_point ) ) {
					$this->execute_entry_point( $entry_point );
					$this->loaded_regular[ $id ] = $metadata->with_active( true )->with_loaded( true );
				}
			}

			if ( function_exists( 'do_action' ) ) {
				\do_action( 'plugins_loaded' );
			}
		}
	}

	/**
	 * Safely execute a validated plugin entry point.
	 *
	 * @param string $entry_point Absolute path to plugin.php.
	 * @return void
	 */
	private function execute_entry_point( string $entry_point ): void {
		require $entry_point;
	}

	/**
	 * Fetch and decode active regular plugin IDs from persistent settings.
	 *
	 * Explicitly tracks and logs settings persistence availability failures
	 * rather than silently claiming successful startup.
	 *
	 * @return array<int, string>
	 */
	public function fetch_active_plugin_ids(): array {
		if ( null === $this->settings_api ) {
			$this->settings_available = false;
			$this->settings_error     = 'Settings API helper is unavailable.';
			error_log( 'PeakURL Plugin Loader: Settings API helper is unavailable; regular plugins skipped.' );
			return array();
		}

		try {
			$raw = $this->settings_api->get_option( 'active_plugins' );
		} catch ( \Throwable $e ) {
			$this->settings_available = false;
			$this->settings_error     = $e->getMessage();
			error_log( sprintf( 'PeakURL Plugin Loader: Failed to query active_plugins option: %s', $e->getMessage() ) );
			return array();
		}

		$this->settings_available = true;
		$this->settings_error     = null;

		if ( null === $raw || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$active = array();
		foreach ( $decoded as $id ) {
			if ( is_string( $id ) && '' !== trim( $id ) ) {
				$active[] = trim( $id );
			}
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Check whether a regular plugin ID is marked active.
	 *
	 * @param string $id Plugin identifier.
	 * @return bool
	 */
	public function is_active( string $id ): bool {
		return in_array( $id, $this->active_regular_ids, true );
	}

	/**
	 * Check whether a plugin (MU or regular) has been executed by this loader.
	 *
	 * @param string $id Plugin identifier.
	 * @return bool
	 */
	public function is_loaded( string $id ): bool {
		return isset( $this->loaded_mu[ $id ] ) || isset( $this->loaded_regular[ $id ] );
	}

	/**
	 * Return all loaded plugin metadata instances keyed by identifier.
	 *
	 * @return array<string, Metadata>
	 */
	public function get_loaded(): array {
		return array_merge( $this->loaded_mu, $this->loaded_regular );
	}

	/**
	 * Return all currently active regular plugin IDs.
	 *
	 * @return array<int, string>
	 */
	public function get_active_plugins(): array {
		return $this->active_regular_ids;
	}

	/**
	 * Whether safe mode is engaged (regular plugins skipped).
	 *
	 * @return bool
	 */
	public function is_safe_mode(): bool {
		return $this->safe_mode;
	}

	/**
	 * Whether must-use plugins are skipped via recovery flag.
	 *
	 * @return bool
	 */
	public function is_skip_mu(): bool {
		return $this->skip_mu;
	}

	/**
	 * Whether bootstrap has already been executed.
	 *
	 * @return bool
	 */
	public function is_bootstrapped(): bool {
		return $this->bootstrapped;
	}

	/**
	 * Whether settings persistence was available when querying active plugins.
	 *
	 * @return bool
	 */
	public function is_settings_available(): bool {
		return $this->settings_available;
	}

	/**
	 * Get the diagnostic error message if settings persistence failed.
	 *
	 * @return string|null
	 */
	public function get_settings_error(): ?string {
		return $this->settings_error;
	}

	/**
	 * Return detailed diagnostic startup status for the loader.
	 *
	 * @return array<string, mixed>
	 */
	public function get_startup_status(): array {
		return array(
			'bootstrapped'         => $this->bootstrapped,
			'safe_mode'            => $this->safe_mode,
			'skip_mu'              => $this->skip_mu,
			'settings_available'   => $this->settings_available,
			'settings_error'       => $this->settings_error,
			'loaded_mu_count'      => count( $this->loaded_mu ),
			'loaded_regular_count' => count( $this->loaded_regular ),
		);
	}
}
