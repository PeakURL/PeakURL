<?php
/**
 * Filesystem cache driver for PeakURL.
 *
 * Universal zero-configuration cache driver storing atomic JSON envelopes
 * inside the protected persistent content/cache directory.
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
 * FileCache — filesystem-backed transient/object cache.
 *
 * @since 1.6.0
 */
class FileCache implements CacheInterface {

	/**
	 * Absolute base directory for file cache storage.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private string $cache_dir;

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
	 * Create a new filesystem cache instance.
	 *
	 * @param string $content_dir Base content directory path.
	 * @param string $custom_path Optional custom directory override.
	 * @since 1.6.0
	 */
	public function __construct( string $content_dir, string $custom_path = '' ) {
		$this->cache_dir = ! empty( $custom_path )
			? rtrim( $custom_path, '/\\' )
			: rtrim( $content_dir, '/\\' ) . '/' . Constants::CACHE_DIRECTORY;

		$this->ensure_directory_structure();
	}

	/**
	 * Check whether the filesystem cache is usable in the current environment.
	 *
	 * @param string $content_dir Base content directory path.
	 * @param string $custom_path Optional custom directory override.
	 * @return bool True if directory is writable and read/write test passes.
	 * @since 1.6.0
	 */
	public static function is_usable( string $content_dir, string $custom_path = '' ): bool {
		$dir = ! empty( $custom_path )
			? rtrim( $custom_path, '/\\' )
			: rtrim( $content_dir, '/\\' ) . '/' . Constants::CACHE_DIRECTORY;

		if ( ! is_dir( $dir ) ) {
			if ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}

		if ( ! is_writable( $dir ) ) {
			return false;
		}

		// Perform read/write/delete test.
		$test_file = $dir . '/.peakurl_test_' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing writability.
		if ( false === @file_put_contents( $test_file, 'test' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing readability.
		$read_back = @file_get_contents( $test_file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning test file.
		@unlink( $test_file );

		return 'test' === $read_back;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		$path = $this->get_cache_file_path( $key );

		if ( ! file_exists( $path ) ) {
			++$this->stats['misses'];
			return null;
		}

		$raw = @file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			++$this->stats['misses'];
			@unlink( $path );
			return null;
		}

		try {
			$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			++$this->stats['errors'];
			++$this->stats['misses'];
			@unlink( $path );
			return null;
		}

		if ( ! is_array( $data ) || ! isset( $data['version'], $data['expires_at'], $data['value'] ) ) {
			++$this->stats['misses'];
			@unlink( $path );
			return null;
		}

		if ( Constants::CACHE_VERSION !== (int) $data['version'] ) {
			++$this->stats['misses'];
			@unlink( $path );
			return null;
		}

		$expires_at = (int) $data['expires_at'];
		if ( $expires_at > 0 && time() > $expires_at ) {
			++$this->stats['misses'];
			@unlink( $path );
			return null;
		}

		++$this->stats['hits'];
		return $data['value'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
		$path = $this->get_cache_file_path( $key );
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			++$this->stats['errors'];
			return false;
		}

		$envelope = array(
			'version'    => Constants::CACHE_VERSION,
			'created_at' => time(),
			'expires_at' => $ttl > 0 ? time() + $ttl : 0,
			'value'      => $value,
		);

		try {
			$encoded = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			++$this->stats['errors'];
			return false;
		}

		// Write atomically using a temporary file.
		$tmp_path = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing atomic file write.
		if ( false === @file_put_contents( $tmp_path, $encoded, LOCK_EX ) ) {
			++$this->stats['errors'];
			@unlink( $tmp_path );
			return false;
		}

		if ( ! @rename( $tmp_path, $path ) ) {
			// Fallback copy on systems where cross-device renames might fail.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback copy.
			if ( ! @copy( $tmp_path, $path ) ) {
				++$this->stats['errors'];
				@unlink( $tmp_path );
				return false;
			}
			@unlink( $tmp_path );
		}

		++$this->stats['sets'];
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		$path = $this->get_cache_file_path( $key );
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
		++$this->stats['deletes'];
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
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
		$this->recursive_delete_files( $this->cache_dir );
		$this->ensure_directory_structure();
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_driver_name(): string {
		return 'file';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stats(): array {
		return array_merge(
			array(
				'driver'    => 'file',
				'available' => $this->is_available(),
				'enabled'   => true,
				'path'      => $this->cache_dir,
				'writable'  => is_writable( $this->cache_dir ),
			),
			$this->stats
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return is_dir( $this->cache_dir ) && is_writable( $this->cache_dir );
	}

	/**
	 * Get the absolute cache directory path.
	 *
	 * @return string Directory path.
	 * @since 1.6.0
	 */
	public function get_cache_dir(): string {
		return $this->cache_dir;
	}

	/**
	 * Convert a logical cache key into a partitioned filesystem path.
	 *
	 * @param string $key Logical cache key.
	 * @return string Absolute file path.
	 * @since 1.6.0
	 */
	private function get_cache_file_path( string $key ): string {
		$hash      = md5( $key );
		$partition = substr( $hash, 0, 2 );

		// Determine subsystem subdirectory based on key prefix.
		$subsystem = 'general';
		if ( str_contains( $key, ':link:' ) ) {
			$subsystem = 'links';
		} elseif ( str_contains( $key, ':dashboard:' ) ) {
			$subsystem = 'dashboard';
		} elseif ( str_contains( $key, ':transient:' ) ) {
			$subsystem = 'transients';
		}

		return $this->cache_dir . '/' . $subsystem . '/' . $partition . '/' . $hash . '.cache';
	}

	/**
	 * Ensure the cache root directory and security guards exist.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function ensure_directory_structure(): void {
		if ( ! is_dir( $this->cache_dir ) ) {
			@mkdir( $this->cache_dir, 0755, true );
		}

		if ( is_dir( $this->cache_dir ) ) {
			// Web server access deny policy.
			$htaccess = $this->cache_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Writing htaccess guard.
				@file_put_contents(
					$htaccess,
					"# Protect PeakURL cache directory\n<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n"
				);
			}

			// Empty index.html for servers ignoring .htaccess.
			$index = $this->cache_dir . '/index.html';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Writing index guard.
				@file_put_contents( $index, "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>\n" );
			}
		}
	}

	/**
	 * Recursively delete cache files while preserving root security files.
	 *
	 * @param string $dir Directory to clean.
	 * @return void
	 * @since 1.6.0
	 */
	private function recursive_delete_files( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || '.htaccess' === $item || 'index.html' === $item || 'index.php' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_delete_files( $path );
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
	}
}
