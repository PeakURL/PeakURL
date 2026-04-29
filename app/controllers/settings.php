<?php
/**
 * General settings endpoints.
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
 * SettingsController — general site-settings handlers.
 *
 * Routes registered by Application::register_routes():
 *  GET  /api/v1/system/i18n     → i18n
 *  GET  /api/v1/system/general  → general
 *  POST /api/v1/system/general  → update_general
 *
 * @since 1.0.3
 */
class SettingsController extends BaseController {

	/**
	 * Return the public dashboard locale/catalog payload.
	 *
	 * @param Request $request Incoming request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function i18n( Request $request ): array {
		return $this->success_response(
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
		return $this->success_response(
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
		return $this->success_response(
			$this->data_store->save_general_settings(
				$request,
				$request->get_body_params(),
			),
			__( 'General settings saved.', 'peakurl' ),
		);
	}
}
