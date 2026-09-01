<?php
/**
 * Cache status helper for system status.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Includes\Constants;
use PeakURL\Services\Cache\Drivers\ApcuCache;
use PeakURL\Services\Cache\Drivers\FileCache;
use PeakURL\Services\Cache\Drivers\RedisCache;

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
		$config            = $this->context->get_config();
		$content_dir       = (string) ( $config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );
		$enabled           = ! empty( $config[ Constants::CACHE_ENABLED ] );
		$configured_driver = strtolower( (string) ( $config[ Constants::CACHE_DRIVER ] ?? Constants::CACHE_DEFAULT_DRIVER ) );
		$custom_path       = (string) ( $config[ Constants::CACHE_PATH ] ?? '' );
		$cache_dir         = ! empty( $custom_path )
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

		return array(
			'enabled'          => $enabled,
			'status'           => $status,
			'activeDriver'     => $active_driver,
			'configuredDriver' => $configured_driver,
			'path'             => $cache_dir,
			'writable'         => $file_writable,
			'directoryExists'  => $file_exists,
			'defaultTtl'       => Constants::CACHE_LINK_TTL,
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
			),
		);
	}
}
