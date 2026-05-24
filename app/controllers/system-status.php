<?php
/**
 * System status endpoint.
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
 * SystemStatusController — dashboard health and runtime status handlers.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.0.3
 */
class SystemStatusController extends BaseController {

	/**
	 * Return the aggregated dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_system_status( $request ),
			__( 'System status loaded.', 'peakurl' ),
		);
	}
}
