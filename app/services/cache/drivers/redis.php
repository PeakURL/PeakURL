<?php
/**
 * Redis cache driver for PeakURL.
 *
 * Provides high-performance in-memory object caching for multi-worker and
 * multi-server environments when Redis is configured and available.
 *
 * @package PeakURL\Services\Cache\Drivers
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Cache\Drivers;

use PeakURL\Includes\Constants;
use PeakURL\Services\Cache\CacheInterface;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * RedisCache — Redis-backed object and transient cache.
 *
 * @since 1.6.0
 */
class RedisCache implements CacheInterface {

	/**
	 * Connected Redis instance.
	 *
	 * @var \Redis|null
	 * @since 1.6.0
	 */
	private ?\Redis $client = null;

	/**
	 * Redis connection configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.6.0
	 */
	private array $config;

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
	 * Create a new Redis cache instance.
	 *
	 * @param array<string, mixed> $config Application configuration map.
	 * @since 1.6.0
	 */
	public function __construct( array $config ) {
		$this->config = $config;
		$this->connect();
	}

	/**
	 * Check whether Redis is installed, reachable, and usable.
	 *
	 * @param array<string, mixed> $config Application configuration map.
	 * @return bool True if Redis passes connection and read/write tests.
	 * @since 1.6.0
	 */
	public static function is_usable( array $config ): bool {
		if ( ! extension_loaded( 'redis' ) || ! class_exists( '\Redis' ) ) {
			return false;
		}

		$host     = ! empty( $config[ Constants::REDIS_HOST ] ) ? (string) $config[ Constants::REDIS_HOST ] : '127.0.0.1';
		$port     = ! empty( $config[ Constants::REDIS_PORT ] ) ? (int) $config[ Constants::REDIS_PORT ] : Constants::DEFAULT_REDIS_PORT;
		$database = isset( $config[ Constants::REDIS_DATABASE ] ) ? (int) $config[ Constants::REDIS_DATABASE ] : Constants::DEFAULT_REDIS_DATABASE;
		$password = ! empty( $config[ Constants::REDIS_PASSWORD ] ) ? (string) $config[ Constants::REDIS_PASSWORD ] : '';

		try {
			$redis = new \Redis();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing connection safely.
			$ok = @$redis->connect( $host, $port, 1.0 );
			if ( ! $ok ) {
				return false;
			}

			if ( '' !== $password ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing password safely.
				if ( ! @$redis->auth( $password ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe close.
					@$redis->close();
					return false;
				}
			}

			if ( $database > 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe select.
				@$redis->select( $database );
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe ping.
			$pong = @$redis->ping();
			if ( ! $pong ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe close.
				@$redis->close();
				return false;
			}

			// Test read/write/delete.
			$test_key = 'peakurl:test:' . bin2hex( random_bytes( 4 ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe test write.
			@$redis->setex( $test_key, 5, 'ok' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe test read.
			$val = @$redis->get( $test_key );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe test delete.
			@$redis->del( $test_key );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe close.
			@$redis->close();

			return 'ok' === $val;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Establish connection to the Redis server.
	 *
	 * @return bool True on success.
	 * @since 1.6.0
	 */
	private function connect(): bool {
		if ( null !== $this->client ) {
			return true;
		}

		if ( ! extension_loaded( 'redis' ) || ! class_exists( '\Redis' ) ) {
			return false;
		}

		$host     = ! empty( $this->config[ Constants::REDIS_HOST ] ) ? (string) $this->config[ Constants::REDIS_HOST ] : '127.0.0.1';
		$port     = ! empty( $this->config[ Constants::REDIS_PORT ] ) ? (int) $this->config[ Constants::REDIS_PORT ] : Constants::DEFAULT_REDIS_PORT;
		$database = isset( $this->config[ Constants::REDIS_DATABASE ] ) ? (int) $this->config[ Constants::REDIS_DATABASE ] : Constants::DEFAULT_REDIS_DATABASE;
		$password = ! empty( $this->config[ Constants::REDIS_PASSWORD ] ) ? (string) $this->config[ Constants::REDIS_PASSWORD ] : '';

		try {
			$redis = new \Redis();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe connection.
			$ok = @$redis->connect( $host, $port, 1.0 );
			if ( ! $ok ) {
				return false;
			}

			if ( '' !== $password ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe auth.
				if ( ! @$redis->auth( $password ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe close.
					@$redis->close();
					return false;
				}
			}

			if ( $database > 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe select.
				@$redis->select( $database );
			}

			$this->client = $redis;
			return true;
		} catch ( \Throwable ) {
			$this->client = null;
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		if ( ! $this->connect() || null === $this->client ) {
			++$this->stats['misses'];
			return null;
		}

		try {
			$raw = $this->client->get( $key );
			if ( false === $raw || null === $raw ) {
				++$this->stats['misses'];
				return null;
			}

			$data = json_decode( (string) $raw, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) || ! isset( $data['value'] ) ) {
				++$this->stats['misses'];
				return null;
			}

			++$this->stats['hits'];
			return $data['value'];
		} catch ( \Throwable ) {
			++$this->stats['errors'];
			++$this->stats['misses'];
			return null;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
		if ( ! $this->connect() || null === $this->client ) {
			++$this->stats['errors'];
			return false;
		}

		$envelope = array(
			'version'    => Constants::CACHE_VERSION,
			'created_at' => time(),
			'value'      => $value,
		);

		try {
			$encoded = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			$res     = $ttl > 0
				? (bool) $this->client->setex( $key, $ttl, $encoded )
				: (bool) $this->client->set( $key, $encoded );

			if ( $res ) {
				++$this->stats['sets'];
			} else {
				++$this->stats['errors'];
			}
			return $res;
		} catch ( \Throwable ) {
			++$this->stats['errors'];
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		if ( ! $this->connect() || null === $this->client ) {
			return false;
		}

		try {
			$this->client->del( $key );
			++$this->stats['deletes'];
			return true;
		} catch ( \Throwable ) {
			++$this->stats['errors'];
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		if ( ! $this->connect() || null === $this->client ) {
			return false;
		}

		try {
			return (bool) $this->client->exists( $key );
		} catch ( \Throwable ) {
			return false;
		}
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
		if ( ! $this->connect() || null === $this->client ) {
			return false;
		}

		try {
			return (bool) $this->client->flushDB();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_driver_name(): string {
		return 'redis';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stats(): array {
		$info = null;
		if ( $this->connect() && null !== $this->client ) {
			try {
				$info = $this->client->info();
			} catch ( \Throwable ) {
				$info = null;
			}
		}

		return array_merge(
			array(
				'driver'     => 'redis',
				'available'  => $this->is_available(),
				'enabled'    => true,
				'host'       => ! empty( $this->config[ Constants::REDIS_HOST ] ) ? (string) $this->config[ Constants::REDIS_HOST ] : '127.0.0.1',
				'port'       => ! empty( $this->config[ Constants::REDIS_PORT ] ) ? (int) $this->config[ Constants::REDIS_PORT ] : Constants::DEFAULT_REDIS_PORT,
				'redis_info' => $info,
			),
			$this->stats
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->connect() && null !== $this->client;
	}
}
