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
		$result = $this->webhooks_service->process_pending_deliveries( 50 );

		if ( 0 === $result['processed'] ) {
			return ExecutionResult::success( 'No pending webhook deliveries.' );
		}

		$message = 1 === $result['processed']
			? sprintf(
				'Processed %1$d pending webhook delivery (%2$d delivered, %3$d queued for retry, %4$d failed).',
				$result['processed'],
				$result['delivered'],
				$result['retried'],
				$result['failed']
			)
			: sprintf(
				'Processed %1$d pending webhook deliveries (%2$d delivered, %3$d queued for retry, %4$d failed).',
				$result['processed'],
				$result['delivered'],
				$result['retried'],
				$result['failed']
			);

		return ExecutionResult::success(
			$message,
			$result
		);
	}
}
