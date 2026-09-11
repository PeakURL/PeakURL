<?php
/**
 * Global runtime configuration helper functions.
 *
 * @package PeakURL\Core\Config
 * @since 1.2.2
 */

declare(strict_types=1);

use PeakURL\Core\Config\RuntimeConfig;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'get_peakurl_config' ) ) {
	/**
	 * Get the shared PeakURL configuration for the current request.
	 *
	 * This keeps global helpers from repeating the same config bootstrapping work
	 * each time they need settings, URLs, mail, or i18n services.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function get_peakurl_config(): array {
		return RuntimeConfig::get_current();
	}
}

if ( ! function_exists( 'get_peakurl_config_hash' ) ) {
	/**
	 * Build a stable hash from selected runtime config values.
	 *
	 * Shared request helpers use this to decide when a cached service can be
	 * reused without keeping raw secret values in object state.
	 *
	 * @param array<string, mixed> $config Runtime config map.
	 * @param array<int, string>   $keys   Config keys that affect the service.
	 * @param array<string, mixed> $extra  Extra identity values, such as object IDs.
	 * @return string
	 * @since 1.2.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function get_peakurl_config_hash(
		array $config,
		array $keys,
		array $extra = array()
	): string {
		return RuntimeConfig::hash_keys( $config, $keys, $extra );
	}
}
