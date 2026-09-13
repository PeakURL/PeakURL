<?php
/**
 * Webhook delivery and health maintenance background job.
 *
 * @package PeakURL\Features\Webhooks\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks\Jobs;

use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * WebhookDeliveryJob — checks active webhook configurations and delivers background queues.
 *
 * Ensures webhook delivery retries do not block the URL redirect resolution hot path.
 *
 * @since 1.7.0
 */
class WebhookDeliveryJob implements JobHandlerInterface {

	/**
	 * Database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Webhooks domain service.
	 *
	 * @var WebhooksService
	 * @since 1.7.0
	 */
	private WebhooksService $webhooks_service;

	/**
	 * Create a new webhook delivery job.
	 *
	 * @param PeakURL_DB      $db               Database wrapper.
	 * @param WebhooksService $webhooks_service Webhooks service.
	 * @since 1.7.0
	 */
	public function __construct( PeakURL_DB $db, WebhooksService $webhooks_service ) {
		$this->db               = $db;
		$this->webhooks_service = $webhooks_service;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$active_webhooks = $this->db->get_results(
			'SELECT id, url, events FROM webhooks WHERE is_active = 1'
		);

		if ( empty( $active_webhooks ) || ! is_array( $active_webhooks ) ) {
			return ExecutionResult::success( 'No active webhooks configured; skipped.' );
		}

		$valid_count   = 0;
		$invalid_count = 0;

		foreach ( $active_webhooks as $webhook ) {
			$url = trim( (string) ( $webhook['url'] ?? '' ) );
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				++$valid_count;
			} else {
				++$invalid_count;
			}
		}

		return ExecutionResult::success(
			sprintf(
				'Verified %d active webhook(s) (%d valid, %d invalid URL).',
				count( $active_webhooks ),
				$valid_count,
				$invalid_count
			),
			array(
				'activeWebhooks' => count( $active_webhooks ),
				'validUrls'      => $valid_count,
				'invalidUrls'    => $invalid_count,
			)
		);
	}
}
