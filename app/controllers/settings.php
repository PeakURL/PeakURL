<?php
/**
 * General settings endpoints.
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
 * SettingsController — general site-settings handlers.
 *
 * Routes registered by Application::register_routes():
 *  GET  /api/v1/system/i18n     → i18n
 *  GET  /api/v1/system/general  → general
 *  POST /api/v1/system/general  → update_general
 *
 * @since 1.0.3
 */
class SettingsController {

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
	 * Return the public dashboard locale/catalog payload.
	 *
	 * @param Request $request Incoming request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function i18n( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_public_i18n_payload(),
			__( 'Dashboard translation catalog loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the general-settings payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function general( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_general_settings( $request ),
			__( 'General settings loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save the site language from the general settings screen.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function update_general( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->save_general_settings(
				$request,
				$request->get_body_params(),
			),
			__( 'General settings saved.', 'peakurl' ),
		);
	}
}
