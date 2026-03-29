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

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Mail controller for dashboard-managed email delivery settings.
 *
 * @since 1.0.0
 */
class Mail_Controller {

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
	 * Return the current mail delivery configuration status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function status( Request $request ): array {
		return Json_Response::success(
			$this->data_store->get_mail_status( $request ),
			'Mail delivery status loaded.',
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
		return Json_Response::success(
			$this->data_store->save_mail_configuration(
				$request,
				$request->get_body_params(),
			),
			'Mail delivery settings saved.',
		);
	}
}
