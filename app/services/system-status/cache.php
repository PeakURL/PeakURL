<?php
/**
 * Cache status helper for system status.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use FilesystemIterator;
use PeakURL\Includes\Constants;
use PeakURL\Services\Cache\Drivers\ApcuCache;
use PeakURL\Services\Cache\Drivers\FileCache;
use PeakURL\Services\Cache\Drivers\RedisCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Cache — inspect the active and configured cache backends.
 *
 * @since 1.6.0
 */
class Cache {

	/**
	 * System-status context.
	 *
	 * @var Context
	 * @since 1.6.0
	 */
	private Context $context;

	/**
	 * Create a new cache status helper.
	 *
	 * @param Context $context System-status context.
	 * @since 1.6.0
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Get the complete cache status payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function cache_status(): array {
		$config       = $this->context->get_config();
		$settings_api = $this->context->get_settings_api();

		$content_dir = (string) ( $config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );

		// Check settings table overrides before runtime config.
		$stored_enabled = $settings_api->get_option( Constants::SETTING_CACHE_ENABLED );
		$enabled        = null !== $stored_enabled
			? (bool) filter_var( $stored_enabled, FILTER_VALIDATE_BOOLEAN )
			: ! empty( $config[ Constants::CACHE_ENABLED ] );

		$stored_driver     = $settings_api->get_option( Constants::SETTING_CACHE_DRIVER );
		$configured_driver = strtolower(
			(string) ( $stored_driver ?? $config[ Constants::CACHE_DRIVER ] ?? Constants::CACHE_DEFAULT_DRIVER )
		);

		$stored_default_ttl = $settings_api->get_option( Constants::SETTING_CACHE_DEFAULT_TTL );
		$default_ttl        = null !== $stored_default_ttl && '' !== trim( $stored_default_ttl )
			? (int) $stored_default_ttl
			: Constants::CACHE_LINK_TTL;

		$stored_negative_ttl = $settings_api->get_option( Constants::SETTING_CACHE_NEGATIVE_TTL );
		$negative_ttl        = null !== $stored_negative_ttl && '' !== trim( $stored_negative_ttl )
			? (int) $stored_negative_ttl
			: Constants::CACHE_NEGATIVE_TTL;

		$custom_path = (string) ( $config[ Constants::CACHE_PATH ] ?? '' );
		$cache_dir   = ! empty( $custom_path )
			? rtrim( $custom_path, '/\\' )
			: rtrim( $content_dir, '/\\' ) . '/' . Constants::CACHE_DIRECTORY;

		$redis_host       = ! empty( $config[ Constants::REDIS_HOST ] ) ? (string) $config[ Constants::REDIS_HOST ] : '127.0.0.1';
		$redis_port       = ! empty( $config[ Constants::REDIS_PORT ] ) ? (int) $config[ Constants::REDIS_PORT ] : Constants::DEFAULT_REDIS_PORT;
		$redis_available  = RedisCache::is_usable( $config );
		$redis_configured = ! empty( $config[ Constants::REDIS_HOST ] );

		$apcu_available = ApcuCache::is_usable();
		$file_usable    = FileCache::is_usable( $content_dir, $custom_path );
		$file_exists    = is_dir( $cache_dir );
		$file_writable  = is_writable( $cache_dir );

		// Determine active driver.
		$active_driver = 'none';
		if ( $enabled ) {
			if ( 'redis' === $configured_driver && $redis_available ) {
				$active_driver = 'redis';
			} elseif ( 'apcu' === $configured_driver && $apcu_available ) {
				$active_driver = 'apcu';
			} elseif ( ( 'file' === $configured_driver || 'filesystem' === $configured_driver ) && $file_usable ) {
				$active_driver = 'file';
			} elseif ( 'auto' === $configured_driver ) {
				if ( $redis_available ) {
					$active_driver = 'redis';
				} elseif ( $apcu_available ) {
					$active_driver = 'apcu';
				} elseif ( $file_usable ) {
					$active_driver = 'file';
				}
			}
		}

		$status = 'active';
		if ( ! $enabled ) {
			$status = 'disabled';
		} elseif ( 'none' === $active_driver ) {
			$status = 'error';
		} elseif ( 'auto' !== $configured_driver && $active_driver !== $configured_driver ) {
			$status = 'fallback';
		}

		$redis_info = null;
		if ( $redis_available && extension_loaded( 'redis' ) && class_exists( '\Redis' ) ) {
			try {
				$r = new \Redis();
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing connection without triggering unhandled warnings.
				if ( @$r->connect( $redis_host, $redis_port, 0.5 ) ) {
					if ( ! empty( $config[ Constants::REDIS_PASSWORD ] ) ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe password check.
						@$r->auth( (string) $config[ Constants::REDIS_PASSWORD ] );
					}
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe info retrieval.
					$redis_info = @$r->info( 'server' );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe disconnect.
					@$r->close();
				}
			} catch ( \Throwable ) {
				$redis_info = null;
			}
		}

		$metrics = $this->get_cache_metrics( $cache_dir );

		return array(
			'enabled'          => $enabled,
			'status'           => $status,
			'activeDriver'     => $active_driver,
			'configuredDriver' => $configured_driver,
			'path'             => $cache_dir,
			'writable'         => $file_writable,
			'directoryExists'  => $file_exists,
			'defaultTtl'       => $default_ttl,
			'negativeTtl'      => $negative_ttl,
			'sizeBytes'        => $metrics['sizeBytes'],
			'fileCount'        => $metrics['fileCount'],
			'redis'            => array(
				'configured'    => $redis_configured,
				'host'          => $redis_host,
				'port'          => $redis_port,
				'available'     => $redis_available,
				'serverVersion' => is_array( $redis_info ) ? (string) ( $redis_info['redis_version'] ?? '' ) : null,
			),
			'apcu'             => array(
				'extensionLoaded' => extension_loaded( 'apcu' ),
				'enabled'         => (bool) filter_var( ini_get( 'apc.enabled' ), FILTER_VALIDATE_BOOLEAN ),
				'available'       => $apcu_available,
			),
			'file'             => array(
				'path'      => $cache_dir,
				'exists'    => $file_exists,
				'writable'  => $file_writable,
				'available' => $file_usable,
				'sizeBytes' => $metrics['sizeBytes'],
				'fileCount' => $metrics['fileCount'],
			),
		);
	}

	/**
	 * Compute the storage size and item count of the cache directory.
	 *
	 * @param string $path Absolute cache directory path.
	 * @return array{sizeBytes: int|null, fileCount: int}
	 * @since 1.6.0
	 */
	public function get_cache_metrics( string $path ): array {
		if ( '' === trim( $path ) || ! is_dir( $path ) ) {
			return array(
				'sizeBytes' => null,
				'fileCount' => 0,
			);
		}

		$total_size = 0;
		$file_count = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$path,
					FilesystemIterator::SKIP_DOTS,
				),
			);
		} catch ( \UnexpectedValueException ) {
			return array(
				'sizeBytes' => null,
				'fileCount' => 0,
			);
		}

		foreach ( $iterator as $file_info ) {
			if (
				! $file_info instanceof SplFileInfo ||
				$file_info->isDir() ||
				$file_info->isLink()
			) {
				continue;
			}

			$filename = $file_info->getFilename();
			if ( '.htaccess' === $filename || 'index.php' === $filename || 'index.html' === $filename ) {
				continue;
			}

			$file_size = $file_info->getSize();
			if ( $file_size > 0 ) {
				$total_size += $file_size;
			}
			++$file_count;
		}

		return array(
			'sizeBytes' => $total_size,
			'fileCount' => $file_count,
		);
	}
}
