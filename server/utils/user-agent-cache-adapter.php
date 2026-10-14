<?php
/**
 * Matomo DeviceDetector cache adapter for PeakURL.
 *
 * Bridges Matomo's regex parsing cache to PeakURL's CacheInterface,
 * keeping regex tables in memory and in the persistent cache driver.
 *
 * @package PeakURL\Utils
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Utils;

use DeviceDetector\Cache\CacheInterface;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Cache adapter bridging DeviceDetector to PeakURL cache with process memory fallback.
 *
 * @since 1.7.2
 */
class UserAgentCacheAdapter implements CacheInterface {

	/**
	 * In-memory static cache for the current PHP process.
	 *
	 * @var array<string, mixed>
	 */
	private static array $memory_cache = array();

	/**
	 * Tracked keys saved in the current process.
	 *
	 * @var array<string, bool>
	 */
	private static array $saved_keys = array();

	/**
	 * Cached generation counter for the current process.
	 *
	 * @var int|null
	 */
	private static ?int $current_generation = null;

	/**
	 * Cache key prefix to isolate Matomo entries.
	 */
	private const KEY_PREFIX = 'matomo_dd_';

	/**
	 * Default persistent TTL for regex tables (30 days).
	 */
	private const DEFAULT_TTL = 2592000;

	/**
	 * Generation key TTL (1 year).
	 */
	private const GENERATION_TTL = 31536000;

	/**
	 * Retrieve the current cache generation number.
	 *
	 * @return int
	 */
	private static function get_generation(): int {
		if ( null !== self::$current_generation ) {
			return self::$current_generation;
		}

		$generation = 1;
		try {
			if ( function_exists( 'peakurl_cache_get' ) ) {
				$stored = peakurl_cache_get( self::KEY_PREFIX . 'gen', null );
				if ( is_numeric( $stored ) && (int) $stored > 0 ) {
					$generation = (int) $stored;
				}
			}
		} catch ( \Throwable ) {
			$generation = 1;
		}

		self::$current_generation = $generation;
		return $generation;
	}

	/**
	 * Fetch data from memory or PeakURL cache driver.
	 *
	 * @param string $id Cache key identifier.
	 * @return mixed Cached data, or false on cache miss.
	 */
	public function fetch( string $id ): mixed {
		if ( array_key_exists( $id, self::$memory_cache ) ) {
			return self::$memory_cache[ $id ];
		}

		try {
			if ( function_exists( 'peakurl_cache_get' ) ) {
				$gen    = self::get_generation();
				$cached = peakurl_cache_get( self::KEY_PREFIX . $gen . '_' . $id, null );
				if ( null !== $cached ) {
					self::$memory_cache[ $id ] = $cached;
					return $cached;
				}
			}
		} catch ( \Throwable ) {
			// Harmless cache driver miss.
		}

		return false;
	}

	/**
	 * Check whether the cache contains an entry for the given ID.
	 *
	 * @param string $id Cache key identifier.
	 * @return bool True if cached, false otherwise.
	 */
	public function contains( string $id ): bool {
		if ( array_key_exists( $id, self::$memory_cache ) ) {
			return true;
		}

		return false !== $this->fetch( $id );
	}

	/**
	 * Store data in memory and in PeakURL cache driver.
	 *
	 * @param string $id        Cache key identifier.
	 * @param mixed  $data      Data to cache.
	 * @param int    $life_time Lifetime in seconds (0 for default persistent 30 days).
	 * @return bool True on success, false on failure.
	 */
	public function save( string $id, mixed $data, int $life_time = 0 ): bool {
		self::$memory_cache[ $id ] = $data;
		self::$saved_keys[ $id ]   = true;

		$ttl = $life_time > 0 ? $life_time : self::DEFAULT_TTL;

		try {
			if ( function_exists( 'peakurl_cache_set' ) ) {
				$gen = self::get_generation();
				return (bool) peakurl_cache_set( self::KEY_PREFIX . $gen . '_' . $id, $data, $ttl );
			}
		} catch ( \Throwable ) {
			return false;
		}

		return true;
	}

	/**
	 * Delete a cache entry.
	 *
	 * @param string $id Cache key identifier.
	 * @return bool True on success.
	 */
	public function delete( string $id ): bool {
		unset( self::$memory_cache[ $id ], self::$saved_keys[ $id ] );

		try {
			if ( function_exists( 'peakurl_cache_delete' ) ) {
				$gen = self::get_generation();
				return (bool) peakurl_cache_delete( self::KEY_PREFIX . $gen . '_' . $id );
			}
		} catch ( \Throwable ) {
			return false;
		}

		return true;
	}

	/**
	 * Invalidate all Matomo cache entries across memory and persistent backend.
	 *
	 * Note: Bumps the persistent generation namespace and purges tracked keys,
	 * ensuring full invalidation without evicting unrelated application cache entries.
	 *
	 * @return bool True.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Required by DeviceDetector\Cache\CacheInterface.
	public function flushAll(): bool {
		self::$memory_cache = array();

		$current_gen = self::get_generation();

		// Clean up tracked keys from the persistent backend.
		if ( function_exists( 'peakurl_cache_delete' ) ) {
			foreach ( array_keys( self::$saved_keys ) as $key ) {
				try {
					peakurl_cache_delete( self::KEY_PREFIX . $current_gen . '_' . $key );
				} catch ( \Throwable ) {
					// Harmless error on key deletion.
				}
			}
		}
		self::$saved_keys = array();

		// Bump persistent generation counter.
		$new_gen = $current_gen + 1;

		if ( function_exists( 'peakurl_cache_set' ) ) {
			try {
				$persisted = (bool) peakurl_cache_set( self::KEY_PREFIX . 'gen', $new_gen, self::GENERATION_TTL );
				if ( ! $persisted ) {
					// Generation could not be persisted; cross-process invalidation cannot be guaranteed.
					return false;
				}
			} catch ( \Throwable ) {
				return false;
			}
		}

		self::$current_generation = $new_gen;
		return true;
	}
}
