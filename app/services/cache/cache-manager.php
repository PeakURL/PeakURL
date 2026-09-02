<?php
/**
 * Central cache resolution manager for PeakURL.
 *
 * Resolves the appropriate cache driver based on configuration and server
 * runtime capabilities, following the contract:
 * Redis (healthy) -> APCu (healthy) -> Filesystem (writable) -> NullCache.
 *
 * @package PeakURL\Services\Cache
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Cache;

use PeakURL\Includes\Constants;
use PeakURL\Services\Cache\Drivers\ApcuCache;
use PeakURL\Services\Cache\Drivers\FileCache;
use PeakURL\Services\Cache\Drivers\NullCache;
use PeakURL\Services\Cache\Drivers\RedisCache;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CacheManager — factory for resolving the active cache driver.
 *
 * @since 1.6.0
 */
final class CacheManager {

	/**
	 * Prevent instantiation.
	 *
	 * @since 1.6.0
	 */
	private function __construct() {}

	/**
	 * Resolve the active cache driver instance.
	 *
	 * @param array<string, mixed> $config      Merged application configuration.
	 * @param string               $content_dir Absolute path to persistent content directory.
	 * @return CacheInterface Active cache driver instance.
	 * @since 1.6.0
	 */
	public static function resolve( array $config, string $content_dir ): CacheInterface {
		$enabled = ! empty( $config[ Constants::CACHE_ENABLED ] );
		if ( ! $enabled ) {
			return new NullCache();
		}

		$driver      = strtolower( (string) ( $config[ Constants::CACHE_DRIVER ] ?? Constants::CACHE_DEFAULT_DRIVER ) );
		$custom_path = (string) ( $config[ Constants::CACHE_PATH ] ?? '' );

		if ( 'none' === $driver || 'null' === $driver ) {
			return new NullCache();
		}

		if ( 'redis' === $driver ) {
			if ( RedisCache::is_usable( $config ) ) {
				return new RedisCache( $config );
			}
			// If explicitly requested Redis is unreachable, fall back to auto hierarchy safely.
		} elseif ( 'apcu' === $driver ) {
			if ( ApcuCache::is_usable() ) {
				return new ApcuCache();
			}
		} elseif ( 'file' === $driver || 'filesystem' === $driver ) {
			if ( FileCache::is_usable( $content_dir, $custom_path ) ) {
				return new FileCache( $content_dir, $custom_path );
			}
			return new NullCache();
		}

		// Automatic resolution hierarchy.
		if ( RedisCache::is_usable( $config ) ) {
			return new RedisCache( $config );
		}

		if ( ApcuCache::is_usable() ) {
			return new ApcuCache();
		}

		if ( FileCache::is_usable( $content_dir, $custom_path ) ) {
			return new FileCache( $content_dir, $custom_path );
		}

		return new NullCache();
	}
}
