<?php
/**
 * Generic cache interface for PeakURL.
 *
 * Defines the contract for all cache backend drivers (Redis, APCu,
 * Filesystem, NullCache).
 *
 * @package PeakURL\Services\Cache
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Cache;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CacheInterface — provider-agnostic caching contract.
 *
 * @since 1.6.0
 */
interface CacheInterface {

	/**
	 * Retrieve an item from the cache by key.
	 *
	 * @param string $key Unique cache key.
	 * @return mixed Cached value, or null on cache miss or expiration.
	 * @since 1.6.0
	 */
	public function get( string $key ): mixed;

	/**
	 * Store an item in the cache for a given number of seconds.
	 *
	 * @param string $key   Unique cache key.
	 * @param mixed  $value Data payload to store.
	 * @param int    $ttl   Time-to-live in seconds (default 3600).
	 * @return bool True on success, false on failure.
	 * @since 1.6.0
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): bool;

	/**
	 * Delete an item from the cache by key.
	 *
	 * @param string $key Unique cache key.
	 * @return bool True if deleted or missing, false on error.
	 * @since 1.6.0
	 */
	public function delete( string $key ): bool;

	/**
	 * Determine if an item exists in the cache and has not expired.
	 *
	 * @param string $key Unique cache key.
	 * @return bool True if cached and unexpired.
	 * @since 1.6.0
	 */
	public function has( string $key ): bool;

	/**
	 * Get an item from the cache, or execute the given callback and store the result.
	 *
	 * @param string   $key      Unique cache key.
	 * @param int      $ttl      Time-to-live in seconds.
	 * @param callable $callback Generator callback returning the fresh value.
	 * @return mixed Cached or freshly computed value.
	 * @since 1.6.0
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed;

	/**
	 * Wipe all cached items managed by this driver.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.6.0
	 */
	public function clear(): bool;

	/**
	 * Get the canonical driver name (e.g. 'redis', 'apcu', 'file', 'none').
	 *
	 * @return string Driver identifier.
	 * @since 1.6.0
	 */
	public function get_driver_name(): string;

	/**
	 * Get operational statistics and diagnostics for this cache driver.
	 *
	 * @return array<string, mixed> Driver diagnostic details.
	 * @since 1.6.0
	 */
	public function get_stats(): array;

	/**
	 * Check whether this cache driver is currently healthy and operational.
	 *
	 * @return bool True if the driver is operational.
	 * @since 1.6.0
	 */
	public function is_available(): bool;
}
