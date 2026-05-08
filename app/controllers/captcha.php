<?php
/**
 * CAPTCHA configuration endpoints.
 *
 * Provides admin-only handlers for viewing and saving the CAPTCHA provider
 * used by public short links.
 *
 * @package PeakURL\Controllers
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CAPTCHA controller for dashboard-managed protection settings.
 *
 * @since 1.2.0
 */
class CaptchaController extends BaseController {

	/**
	 * Return the current CAPTCHA provider status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.2.0
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_captcha_status( $request ),
			__( 'CAPTCHA settings loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save encrypted CAPTCHA provider credentials.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.2.0
	 */
	public function update( Request $request ): array {
		return $this->success_response(
			$this->data_store->save_captcha_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'CAPTCHA settings saved.', 'peakurl' ),
		);
	}
}
