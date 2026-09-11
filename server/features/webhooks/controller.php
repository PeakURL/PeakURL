<?php
/**
 * Webhook endpoints controller.
 *
 * Manages webhook registrations that receive POST notifications
 * when short-link events occur (e.g. link created, clicked).
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Thin HTTP entry adapter delegating to Webhooks Service.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * Webhooks domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $webhooks_service;

	/**
	 * Create a new Webhooks controller instance.
	 *
	 * @param Service $webhooks_service Webhooks domain service.
	 * @since 1.0.0
	 */
	public function __construct( Service $webhooks_service ) {
		$this->webhooks_service = $webhooks_service;
	}

	/**
	 * List all webhooks for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with webhook list.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		return $this->success_response(
			$this->webhooks_service->list_webhooks( $request ),
			__( 'Webhooks loaded.', 'peakurl' ),
		);
	}

	/**
	 * Register a new webhook.
	 *
	 * Accepts `url` and `events` in the request body.
	 *
	 * @param Request $request Incoming HTTP request with webhook payload.
	 * @return array<string, mixed> JSON envelope with the created webhook (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return $this->success_response(
			$this->webhooks_service->create_webhook(
				$request,
				$request->get_body_params(),
			),
			__( 'Webhook created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Update an existing webhook.
	 *
	 * Accepts `url`, `events`, or `isActive` in the request body.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope with the updated webhook.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		return $this->success_response(
			$this->webhooks_service->update_webhook(
				$request,
				$this->route_param( $request, 'id' ),
				$request->get_body_params(),
			),
			__( 'Webhook updated.', 'peakurl' ),
		);
	}

	/**
	 * Send a test ping to a registered webhook endpoint.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope with test delivery results.
	 * @since 1.0.0
	 */
	public function test( Request $request ): array {
		return $this->success_response(
			$this->webhooks_service->test_webhook(
				$request,
				$this->route_param( $request, 'id' ),
			),
			__( 'Webhook test dispatched.', 'peakurl' ),
		);
	}

	/**
	 * Delete a webhook by ID.
	 *
	 * Returns 404 if the webhook does not exist or does not
	 * belong to the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope confirming deletion or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->webhooks_service->delete_webhook(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->delete_response(
			$deleted,
			__( 'Webhook not found.', 'peakurl' ),
			__( 'Webhook deleted.', 'peakurl' ),
		);
	}
}
