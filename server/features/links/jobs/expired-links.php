<?php
/**
 * Expired links processing background job.
 *
 * @package PeakURL\Features\Links\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Jobs;

use PeakURL\Api\LinksApi;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\CacheKey;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * ExpiredLinksJob — transitions short URLs past their expiration date to 'expired'.
 *
 * Uses the existing link status enum ('expired') and processes in bounded batches
 * to prevent runaway queries on large installations.
 *
 * @since 1.7.0
 */
class ExpiredLinksJob implements JobHandlerInterface {

	/**
	 * Database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Optional cache driver for invalidation.
	 *
	 * @var CacheInterface|null
	 * @since 1.7.0
	 */
	private ?CacheInterface $cache;

	/**
	 * Optional LinksApi for link cache invalidation.
	 *
	 * @var LinksApi|null
	 * @since 1.7.0
	 */
	private ?LinksApi $links_api;

	/**
	 * Optional Links domain service.
	 *
	 * @var LinksService|null
	 * @since 1.7.0
	 */
	private ?LinksService $links_service;

	/**
	 * Maximum number of expired links to transition per batch.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $batch_limit;

	/**
	 * Create a new expired links processing job.
	 *
	 * @param PeakURL_DB         $db            Database wrapper.
	 * @param CacheInterface|null $cache         Optional cache service.
	 * @param int                $batch_limit   Batch processing limit.
	 * @param LinksApi|null      $links_api     Optional links API dependency.
	 * @param LinksService|null  $links_service Optional links domain service.
	 * @since 1.7.0
	 */
	public function __construct(
		PeakURL_DB $db,
		?CacheInterface $cache = null,
		int $batch_limit = 100,
		?LinksApi $links_api = null,
		?LinksService $links_service = null
	) {
		$this->db            = $db;
		$this->cache         = $cache;
		$this->batch_limit   = max( 1, min( 500, $batch_limit ) );
		$this->links_api     = $links_api;
		$this->links_service = $links_service;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		if ( null !== $this->links_service ) {
			$expired_count = $this->links_service->expire_due_links( $this->batch_limit );

			if ( 0 === $expired_count ) {
				return ExecutionResult::success( 'No expired links due for processing.' );
			}

			$message = 1 === $expired_count
				? sprintf( 'Processed %d expired link.', $expired_count )
				: sprintf( 'Processed %d expired links.', $expired_count );

			return ExecutionResult::success(
				$message,
				array( 'expiredCount' => $expired_count )
			);
		}

		$now = Date::now();

		$due_links = $this->db->get_results(
			'SELECT id, short_code, alias FROM urls
			WHERE status = :active_status
			AND expires_at IS NOT NULL
			AND expires_at <= :cutoff_time
			LIMIT ' . $this->batch_limit,
			array(
				'active_status' => 'active',
				'cutoff_time'   => $now,
			)
		);

		if ( empty( $due_links ) || ! is_array( $due_links ) ) {
			return ExecutionResult::success( 'No expired links due for processing.' );
		}

		$ids = array();
		foreach ( $due_links as $link ) {
			$id = (string) ( $link['id'] ?? '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return ExecutionResult::success( 'No expired links due for processing.' );
		}

		$this->db->update_where_in(
			'urls',
			array(
				'status'     => 'expired',
				'updated_at' => $now,
			),
			'id',
			$ids
		);

		if ( null !== $this->links_api ) {
			foreach ( $due_links as $link ) {
				$this->links_api->invalidate_link_cache( $link );
			}
		} elseif ( null !== $this->cache ) {
			foreach ( $due_links as $link ) {
				$id    = (string) ( $link['id'] ?? '' );
				$code  = (string) ( $link['short_code'] ?? '' );
				$alias = (string) ( $link['alias'] ?? '' );
				if ( '' !== $id ) {
					$this->cache->delete( CacheKey::link_id( $id ) );
				}
				if ( '' !== $code ) {
					$this->cache->delete( CacheKey::link_lookup( $code ) );
					$this->cache->delete( CacheKey::link_missing( $code ) );
				}
				if ( '' !== $alias && $alias !== $code ) {
					$this->cache->delete( CacheKey::link_lookup( $alias ) );
					$this->cache->delete( CacheKey::link_missing( $alias ) );
				}
			}
		}

		$due_count = count( $ids );
		$message   = 1 === $due_count
			? sprintf( 'Processed %d expired link.', $due_count )
			: sprintf( 'Processed %d expired links.', $due_count );

		return ExecutionResult::success(
			$message,
			array( 'expiredCount' => $due_count )
		);
	}
}
