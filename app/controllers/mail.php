<?php
/**
 * Mail delivery configuration endpoints.
 *
 * Provides admin-only handlers for viewing and saving the mail
 * transport settings used by password-reset emails.
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
 * Mail controller for dashboard-managed email delivery settings.
 *
 * @since 1.0.0
 */
class MailController extends BaseController {

	/**
	 * Return the current mail delivery configuration status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_mail_status( $request ),
			__( 'Mail delivery status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save the current mail delivery configuration.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		return $this->success_response(
			$this->data_store->save_mail_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'Mail delivery settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Send a test email through the active mail transport.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	public function test( Request $request ): array {
		return $this->success_response(
			$this->data_store->send_test_email( $request ),
			__( 'Test email sent.', 'peakurl' ),
		);
	}
}
