<?php
/**
 * GeoIP database refresh background job.
 *
 * @package PeakURL\Features\System\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System\Jobs;

use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Services\Geoip;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * GeoipUpdateJob — downloads or refreshes the GeoLite2 City database.
 *
 * Uses direct MaxMind when credentials are configured; uses R2 when
 * credentials are absent. Safe and idempotent.
 * Never interferes with normal URL resolution.
 *
 * @since 1.7.0
 */
class GeoipUpdateJob implements JobHandlerInterface {

	/**
	 * GeoIP service instance.
	 *
	 * @var Geoip
	 * @since 1.7.0
	 */
	private Geoip $geoip_service;

	/**
	 * Create a new GeoIP update job.
	 *
	 * @param Geoip $geoip_service GeoIP domain service.
	 * @since 1.7.0
	 */
	public function __construct( Geoip $geoip_service ) {
		$this->geoip_service = $geoip_service;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$status = $this->geoip_service->get_status();

		$has_credentials = ! empty( $status['credentialsConfigured'] );

		if ( $has_credentials && ! empty( $status['is_installed'] ) && empty( $status['is_outdated'] ) && ! $context->is_forced() ) {
			return ExecutionResult::skipped( 'GeoIP database is already up to date' );
		}

		try {
			$result = $this->geoip_service->download_database( $context->is_forced() );

			if ( true !== ( $result['activated'] ?? null ) ) {
				return ExecutionResult::skipped( 'GeoIP database is already up to date' );
			}

			return ExecutionResult::success(
				sprintf(
					'GeoLite2 City database refreshed at %s',
					(string) ( $result['databasePath'] ?? 'default path' )
				),
				$result
			);
		} catch ( \Throwable $exception ) {
			return ExecutionResult::failure(
				sprintf(
					'GeoIP update failed: %s',
					$exception->getMessage()
				)
			);
		}
	}
}
