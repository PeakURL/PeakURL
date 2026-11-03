<?php
/**
 * Global plugin registration and lifecycle helpers.
 *
 * Exposes documented helpers for active plugins to register admin pages,
 * settings, backend actions, scoped assets, and native UI extension points.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

use PeakURL\Core\Plugins\Extensions;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'register_plugin_page' ) ) {
	/**
	 * Register an administrative dashboard page descriptor for an active plugin.
	 *
	 * @param array<string, mixed> $descriptor Page descriptor configuration.
	 * @return bool True if registered.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function register_plugin_page( array $descriptor ): bool {
		return Extensions::get_instance()->register_page( $descriptor );
	}
}

if ( ! function_exists( 'register_plugin_settings' ) ) {
	/**
	 * Register a settings schema for an active plugin.
	 *
	 * @param string               $plugin_slug Plugin identifier.
	 * @param array<string, mixed> $schema      Schema definition with fields and capability.
	 * @return bool True if registered.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function register_plugin_settings( string $plugin_slug, array $schema ): bool {
		return Extensions::get_instance()->register_settings( $plugin_slug, $schema );
	}
}

if ( ! function_exists( 'register_plugin_action' ) ) {
	/**
	 * Register an authenticated backend API action callback.
	 *
	 * @param string   $plugin_slug Plugin identifier.
	 * @param string   $action_id   Action identifier.
	 * @param callable $handler     Callback accepting (Request $request, array $params).
	 * @param string   $capability  Required capability (default: 'manage_site_settings').
	 * @return bool True if registered.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function register_plugin_action(
		string $plugin_slug,
		string $action_id,
		callable $handler,
		string $capability = 'manage_site_settings'
	): bool {
		return Extensions::get_instance()->register_action(
			$plugin_slug,
			$action_id,
			$handler,
			$capability
		);
	}
}

if ( ! function_exists( 'register_plugin_asset' ) ) {
	/**
	 * Register an approved browser asset (CSS or JS) in the plugin assets/ directory.
	 *
	 * @param string               $plugin_slug   Plugin identifier.
	 * @param string               $relative_path Path relative to plugin assets/ directory.
	 * @param string               $type          'script' or 'style'.
	 * @param array<string, mixed> $options       Optional asset options (scope, page_id, version).
	 * @return bool True if registered.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function register_plugin_asset(
		string $plugin_slug,
		string $relative_path,
		string $type = 'script',
		array $options = array()
	): bool {
		return Extensions::get_instance()->register_asset(
			$plugin_slug,
			$relative_path,
			$type,
			$options
		);
	}
}

if ( ! function_exists( 'register_plugin_ui_extension' ) ) {
	/**
	 * Register a native UI extension point on an existing dashboard screen.
	 *
	 * @param array<string, mixed> $descriptor UI extension descriptor.
	 * @return bool True if registered.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function register_plugin_ui_extension( array $descriptor ): bool {
		return Extensions::get_instance()->register_ui_extension( $descriptor );
	}
}

if ( ! function_exists( 'get_plugin_setting' ) ) {
	/**
	 * Retrieve a stored setting for a plugin.
	 *
	 * @param string $plugin_slug Plugin identifier.
	 * @param string $key         Setting key.
	 * @param mixed  $fallback    Default fallback value.
	 * @return mixed Stored value or fallback.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function get_plugin_setting( string $plugin_slug, string $key, mixed $fallback = null ): mixed {
		try {
			if ( ! function_exists( 'get_settings_api' ) ) {
				return $fallback;
			}
			$settings_api = get_settings_api();
			$raw          = $settings_api->get_option( 'plugin_' . $plugin_slug . '_settings' );
			if ( null === $raw || '' === trim( $raw ) ) {
				return $fallback;
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return $fallback;
			}

			return $decoded[ $key ] ?? $fallback;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}
}

if ( ! function_exists( 'update_plugin_setting' ) ) {
	/**
	 * Update a stored setting for a plugin.
	 *
	 * @param string $plugin_slug Plugin identifier.
	 * @param string $key         Setting key.
	 * @param mixed  $value       Setting value.
	 * @return bool True on success, false on error.
	 * @since 1.8.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Public plugin API helper naming.
	function update_plugin_setting( string $plugin_slug, string $key, mixed $value ): bool {
		try {
			if ( ! function_exists( 'get_settings_api' ) ) {
				return false;
			}
			$settings_api = get_settings_api();
			$option_key   = 'plugin_' . $plugin_slug . '_settings';
			$raw          = $settings_api->get_option( $option_key );
			$existing     = ( null !== $raw && '' !== trim( $raw ) ) ? json_decode( $raw, true ) : array();
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$merged         = $existing;
			$merged[ $key ] = $value;

			if ( method_exists( $settings_api, 'update_option_with_lock' ) ) {
				$settings_api->update_option_with_lock(
					$option_key,
					static function () use ( $merged ): string {
						return (string) json_encode( $merged );
					}
				);
			} else {
				$settings_api->update_option( $option_key, (string) json_encode( $merged ), gmdate( 'Y-m-d H:i:s' ), false );
			}

			if ( function_exists( 'do_action' ) ) {
				\do_action( 'plugin_settings_updated', $plugin_slug, $merged, $existing );
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
