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

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * GeoIP controller for the settings dashboard.
 *
 * @since 1.0.0
 */
class Geoip_Controller {

	/**
	 * Shared data-store dependency.
	 *
	 * @var Data_Store
	 * @since 1.0.0
	 */
	private Data_Store $data_store;

	/**
	 * Create a new controller instance.
	 *
	 * @param Data_Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Data_Store $data_store ) {
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
		return Json_Response::success(
			$this->data_store->get_geoip_status( $request ),
			'GeoIP status loaded.',
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
		return Json_Response::success(
			$this->data_store->save_geoip_configuration(
				$request,
				$request->get_body_params(),
			),
			'GeoIP settings saved.',
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
		return Json_Response::success(
			$this->data_store->download_geoip_database( $request ),
			'GeoIP database updated.',
		);
	}
}
