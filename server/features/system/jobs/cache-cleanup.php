<?php
/**
 * Cache cleanup background job.
 *
 * @package PeakURL\Features\System\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System\Jobs;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\Drivers\FileCache;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CacheCleanupJob — purges expired filesystem cache files.
 *
 * Driver-agnostic: gracefully inspects the active cache driver. For FileCache,
 * cleans expired cache entries while leaving active cache and security files
 * (.htaccess, index.html) intact.
 *
 * @since 1.7.0
 */
class CacheCleanupJob implements JobHandlerInterface {

	/**
	 * Active cache driver.
	 *
	 * @var CacheInterface
	 * @since 1.7.0
	 */
	private CacheInterface $cache;

	/**
	 * Create a new cache cleanup job.
	 *
	 * @param CacheInterface $cache Active cache service.
	 * @since 1.7.0
	 */
	public function __construct( CacheInterface $cache ) {
		$this->cache = $cache;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$driver_name = $this->cache->get_driver_name();

		if ( 'file' !== $driver_name || ! ( $this->cache instanceof FileCache ) ) {
			return ExecutionResult::success(
				sprintf(
					'Cache driver "%s" manages expiration automatically; no filesystem sweep required.',
					$driver_name
				),
				array( 'driver' => $driver_name )
			);
		}

		$purged_count = $this->cache->purge_expired();
		$message      = 1 === $purged_count
			? sprintf( 'Purged %d expired file cache item.', $purged_count )
			: sprintf( 'Purged %d expired file cache items.', $purged_count );

		return ExecutionResult::success(
			$message,
			array(
				'driver'      => 'file',
				'purgedFiles' => $purged_count,
			)
		);
	}
}
