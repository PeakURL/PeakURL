<?php
/**
 * APCu in-memory cache driver for PeakURL.
 *
 * Provides high-speed shared memory object caching when APCu is available.
 *
 * @package PeakURL\Services\Cache\Drivers
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Cache\Drivers;

use PeakURL\Services\Cache\CacheInterface;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * ApcuCache — APCu in-memory cache implementation.
 *
 * @since 1.6.0
 */
class ApcuCache implements CacheInterface {

	/**
	 * In-memory operational metrics for the current request cycle.
	 *
	 * @var array<string, int>
	 * @since 1.6.0
	 */
	private array $stats = array(
		'hits'    => 0,
		'misses'  => 0,
		'sets'    => 0,
		'deletes' => 0,
		'errors'  => 0,
	);

	/**
	 * Determine if APCu is installed, enabled, and functioning.
	 *
	 * @return bool True if APCu is usable.
	 * @since 1.6.0
	 */
	public static function is_usable(): bool {
		if ( ! extension_loaded( 'apcu' ) ) {
			return false;
		}

		if ( ! filter_var( ini_get( 'apc.enabled' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}

		if ( 'cli' === PHP_SAPI && ! filter_var( ini_get( 'apc.enable_cli' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}

		// Perform real store/fetch/delete test.
		$test_key = 'peakurl:test:' . bin2hex( random_bytes( 4 ) );
		if ( ! apcu_store( $test_key, 'ok', 5 ) ) {
			return false;
		}

		$success = false;
		$value   = apcu_fetch( $test_key, $success );
		apcu_delete( $test_key );

		return $success && 'ok' === $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		$success = false;
		$value   = apcu_fetch( $key, $success );

		if ( ! $success ) {
			++$this->stats['misses'];
			return null;
		}

		++$this->stats['hits'];
		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
		$result = apcu_store( $key, $value, $ttl );
		if ( $result ) {
			++$this->stats['sets'];
		} else {
			++$this->stats['errors'];
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		$result = apcu_delete( $key );
		++$this->stats['deletes'];
		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		return apcu_exists( $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed {
		$cached = $this->get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );
		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): bool {
		return apcu_clear_cache();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_driver_name(): string {
		return 'apcu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stats(): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe APCu memory inspection.
		$memory_info = function_exists( 'apcu_sma_info' ) ? @apcu_sma_info( true ) : null;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe APCu cache inspection.
		$cache_info = function_exists( 'apcu_cache_info' ) ? @apcu_cache_info( true ) : null;

		return array_merge(
			array(
				'driver'      => 'apcu',
				'available'   => self::is_usable(),
				'enabled'     => true,
				'memory_info' => $memory_info,
				'cache_info'  => $cache_info,
			),
			$this->stats
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return self::is_usable();
	}
}
