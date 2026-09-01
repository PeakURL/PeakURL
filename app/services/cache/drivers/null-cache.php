<?php
/**
 * Null/No-op cache driver for PeakURL.
 *
 * Used when caching is explicitly disabled or when no cache backend is usable.
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
 * NullCache — no-op cache implementation.
 *
 * @since 1.6.0
 */
class NullCache implements CacheInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $key, int $ttl, callable $callback ): mixed {
		return $callback();
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_driver_name(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stats(): array {
		return array(
			'driver'    => 'none',
			'available' => true,
			'enabled'   => false,
			'hits'      => 0,
			'misses'    => 0,
			'sets'      => 0,
			'deletes'   => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}
}
