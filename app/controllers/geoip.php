<?php
/**
 * GeoIP configuration endpoints.
 *
 * Provides admin-only handlers for viewing MaxMind status, saving
 * credentials, and downloading the local GeoLite2 City database.
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
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
 * GeoIP controller for the settings dashboard.
 *
 * @since 1.0.0
 */
class GeoipController {

	/**
	 * Shared data-store dependency.
	 *
	 * @var Store
	 * @since 1.0.0
	 */
	private Store $data_store;

	/**
	 * Create a new controller instance.
	 *
	 * @param Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Return the current GeoIP integration status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function status( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_geoip_status( $request ),
			__( 'GeoIP status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save MaxMind credentials into the runtime config.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->save_geoip_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'GeoIP settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Download or refresh the GeoLite2 City database.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function download( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->download_geoip_database( $request ),
			__( 'GeoIP database updated.', 'peakurl' ),
		);
	}
}
