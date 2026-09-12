<?php
/**
 * Global cache and transient helper functions.
 *
 * Provides WordPress-style transient functions and low-level cache driver
 * access methods for PeakURL.
 *
 * @package PeakURL\Services\Cache
 * @since 1.6.0
 */

declare(strict_types=1);

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\CacheKey;
use PeakURL\Services\Cache\CacheManager;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'peakurl_cache' ) ) {
	/**
	 * Get the shared cache driver instance for the current request.
	 *
	 * @param array<string, mixed>|null $config Optional config map.
	 * @return CacheInterface Active cache driver instance.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache( ?array $config = null ): CacheInterface {
		static $cache = null;

		if ( null === $cache ) {
			$app_config  = $config ?? get_peakurl_config();
			$content_dir = (string) ( $app_config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
			$cache       = CacheManager::resolve( $app_config, $content_dir );
		}

		return $cache;
	}
}

if ( ! function_exists( 'peakurl_cache_get' ) ) {
	/**
	 * Retrieve a cached value by key.
	 *
	 * @param string $key      Unique cache key.
	 * @param mixed  $fallback Default fallback value to return on cache miss (default null).
	 * @return mixed Cached value, or $fallback on miss.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache_get( string $key, mixed $fallback = null ): mixed {
		$value = peakurl_cache()->get( $key );
		return null !== $value ? $value : $fallback;
	}
}

if ( ! function_exists( 'peakurl_cache_set' ) ) {
	/**
	 * Set a cached value by key with a given TTL.
	 *
	 * @param string $key   Unique cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds (default 3600).
	 * @return bool True on success, false on failure.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache_set( string $key, mixed $value, int $ttl = 3600 ): bool {
		return peakurl_cache()->set( $key, $value, $ttl );
	}
}

if ( ! function_exists( 'peakurl_cache_delete' ) ) {
	/**
	 * Delete an item from the cache by key.
	 *
	 * @param string $key Unique cache key.
	 * @return bool True on success, false on failure.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache_delete( string $key ): bool {
		return peakurl_cache()->delete( $key );
	}
}

if ( ! function_exists( 'peakurl_cache_remember' ) ) {
	/**
	 * Retrieve a cached value or compute and store it if missing.
	 *
	 * @param string   $key      Unique cache key.
	 * @param int      $ttl      Time to live in seconds.
	 * @param callable $callback Value generator callback.
	 * @return mixed Cached or freshly computed value.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache_remember( string $key, int $ttl, callable $callback ): mixed {
		return peakurl_cache()->remember( $key, $ttl, $callback );
	}
}

if ( ! function_exists( 'peakurl_cache_flush' ) ) {
	/**
	 * Flush all cached entries from the active cache driver.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function peakurl_cache_flush(): bool {
		return peakurl_cache()->clear();
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Get the value of a WordPress-style transient.
	 *
	 * @param string $transient Transient name.
	 * @return mixed Value of transient, or false if not set/expired.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function get_transient( string $transient ): mixed {
		$key   = CacheKey::transient( $transient );
		$value = peakurl_cache()->get( $key );
		return null !== $value ? $value : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Set/update the value of a WordPress-style transient.
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Time until expiration in seconds (0 for default 3600).
	 * @return bool True if value was set, false otherwise.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$key = CacheKey::transient( $transient );
		$ttl = $expiration > 0 ? $expiration : Constants::CACHE_LINK_TTL;
		return peakurl_cache()->set( $key, $value, $ttl );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Delete a WordPress-style transient.
	 *
	 * @param string $transient Transient name.
	 * @return bool True if successful, false otherwise.
	 * @since 1.6.0
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function delete_transient( string $transient ): bool {
		$key = CacheKey::transient( $transient );
		return peakurl_cache()->delete( $key );
	}
}
