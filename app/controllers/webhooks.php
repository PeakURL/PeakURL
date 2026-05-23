<?php
/**
 * Webhook endpoints.
 *
 * Manages webhook registrations that receive POST notifications
 * when short-link events occur (e.g. link created, clicked).
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * WebhooksController — REST handlers for webhook CRUD.
 *
 * Routes registered by Application::register_routes():
 *  GET    /api/v1/webhooks     → index
 *  POST   /api/v1/webhooks     → create
 *  DELETE /api/v1/webhooks/{id} → delete
 *
 * @since 1.0.0
 */
class WebhooksController extends BaseController {

	/**
	 * List all webhooks for the authenticated user (GET /api/v1/webhooks).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with webhook list.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		return $this->success_response(
			$this->data_store->list_webhooks( $request ),
			__( 'Webhooks loaded.', 'peakurl' ),
		);
	}

	/**
	 * Register a new webhook (POST /api/v1/webhooks).
	 *
	 * Accepts `url` and `events` in the request body.
	 *
	 * @param Request $request Incoming HTTP request with webhook payload.
	 * @return array<string, mixed> JSON envelope with the created webhook (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return $this->success_response(
			$this->data_store->create_webhook(
				$request,
				$request->get_body_params(),
			),
			__( 'Webhook created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Delete a webhook by ID (DELETE /api/v1/webhooks/{id}).
	 *
	 * Returns 404 if the webhook does not exist or does not
	 * belong to the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope confirming deletion or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->data_store->delete_webhook(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $deleted ) {
			return $this->not_found_response( __( 'Webhook not found.', 'peakurl' ) );
		}

		return $this->boolean_response( 'deleted', __( 'Webhook deleted.', 'peakurl' ) );
	}
}
