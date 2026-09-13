<?php
/**
 * Global database helper functions.
 *
 * @package PeakURL\Services\Database
 * @since 1.2.2
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'get_peakurl_connection' ) ) {
	/**
	 * Get the shared database connection for the current request.
	 *
	 * @param array<string, mixed>|null $config Optional app config.
	 * @return Connection
	 * @since 1.2.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function get_peakurl_connection( ?array $config = null ): Connection {
		return Connection::get_instance( $config );
	}
}

if ( ! function_exists( 'get_settings_api' ) ) {
	/**
	 * Get the shared settings API for the current request.
	 *
	 * @param array<string, mixed>|null $config     Optional app config.
	 * @param Connection|null           $connection Optional reused connection.
	 * @return SettingsApi
	 * @since 1.2.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
	function get_settings_api(
		?array $config = null,
		?Connection $connection = null
	): SettingsApi {
		static $settings_api = null;
		static $cache_key    = null;

		$app_config     = $config ?? Configuration::get_current();
		$app_connection = $connection ?? Connection::get_instance( $app_config );
		$next_cache_key = Configuration::hash_keys(
			$app_config,
			Constants::DB_KEYS,
			array( 'connection' => spl_object_id( $app_connection ) ),
		);

		if ( $settings_api instanceof SettingsApi && $cache_key === $next_cache_key ) {
			return $settings_api;
		}

		$settings_api = new SettingsApi( new PeakURL_DB( $app_connection ) );
		$cache_key    = $next_cache_key;

		return $settings_api;
	}
}
