<?php
/**
 * Dashboard admin notice endpoint.
 *
 * @package PeakURL\Controllers
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AdminNoticesController — dashboard notice handlers.
 *
 * Routes registered by Application::register_routes():
 *  GET /api/v1/system/notices → index
 *
 * @since 1.0.3
 */
class AdminNoticesController extends BaseController {

	/**
	 * Return the current dashboard admin notices.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function index( Request $request ): array {
		return $this->success_response(
			array(
				'items' => $this->data_store->get_admin_notices( $request ),
			),
			__( 'Admin notices loaded.', 'peakurl' ),
		);
	}
}
