<?php
/**
 * Analytics and trash retention background job.
 *
 * @package PeakURL\Features\Analytics\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Analytics\Jobs;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AnalyticsRetentionJob — enforces data retention policies on analytics and trashed links.
 *
 * Permanently purges links trashed longer than trash_retention_days (default 30 days)
 * and purges click records older than analytics_retention_days if configured.
 *
 * @since 1.7.0
 */
class AnalyticsRetentionJob implements JobHandlerInterface {

	/**
	 * Database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Settings API.
	 *
	 * @var SettingsApi
	 * @since 1.7.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Create a new retention job.
	 *
	 * @param PeakURL_DB  $db           Database wrapper.
	 * @param SettingsApi $settings_api Settings API instance.
	 * @since 1.7.0
	 */
	public function __construct( PeakURL_DB $db, SettingsApi $settings_api ) {
		$this->db           = $db;
		$this->settings_api = $settings_api;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$trash_days   = (int) ( $this->settings_api->get_option( 'trash_retention_days' ) ?? 30 );
		$purged_links = 0;

		if ( $trash_days > 0 ) {
			$trash_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $trash_days * 86400 ) );

			// Query up to 100 eligible trashed links to purge in bounded batches.
			$expired_trashed = $this->db->get_results(
				'SELECT id FROM urls
				WHERE status = :trashed_status
				AND updated_at <= :trash_cutoff
				LIMIT 100',
				array(
					'trashed_status' => 'trashed',
					'trash_cutoff'   => $trash_cutoff,
				)
			);

			if ( is_array( $expired_trashed ) && ! empty( $expired_trashed ) ) {
				$link_ids = array_filter(
					array_map(
						function ( array $row ): string {
							return (string) ( $row['id'] ?? '' );
						},
						$expired_trashed
					)
				);

				if ( ! empty( $link_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '?' ) );
					$stmt         = $this->db->query(
						"DELETE FROM urls WHERE id IN ($placeholders)",
						array_values( $link_ids )
					);
					$purged_links = $stmt ? $stmt->rowCount() : count( $link_ids );
				}
			}
		}

		$analytics_days = (int) ( $this->settings_api->get_option( 'analytics_retention_days' ) ?? 0 );
		$purged_clicks  = 0;

		if ( $analytics_days > 0 ) {
			$analytics_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $analytics_days * 86400 ) );

			$stmt          = $this->db->query(
				'DELETE FROM clicks
				WHERE clicked_at < :cutoff
				LIMIT 1000',
				array(
					'cutoff' => $analytics_cutoff,
				)
			);
			$purged_clicks = $stmt ? $stmt->rowCount() : 0;
		}

		return ExecutionResult::success(
			sprintf(
				'Retention enforced: %d trashed link(s) purged, %d old click(s) purged.',
				$purged_links,
				$purged_clicks
			),
			array(
				'purgedTrashedLinks' => $purged_links,
				'purgedClicks'       => $purged_clicks,
			)
		);
	}
}
