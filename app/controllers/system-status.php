<?php
/**
 * System status endpoint.
 *
 * @package PeakURL\Controllers
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;
use PeakURL\Store;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SystemStatusController — dashboard health and runtime status handlers.
 *
 * Routes registered by Application::register_routes():
 *  GET /api/v1/system/status → status
 *
 * @since 1.0.3
 */
class SystemStatusController {

	/**
	 * Shared data-store dependency.
	 *
	 * @var Store
	 * @since 1.0.3
	 */
	private Store $data_store;

	/**
	 * Create a new controller instance.
	 *
	 * @param Store $data_store Shared store dependency.
	 * @since 1.0.3
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Return the aggregated dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function status( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_system_status( $request ),
			__( 'System status loaded.', 'peakurl' ),
		);
	}
}
