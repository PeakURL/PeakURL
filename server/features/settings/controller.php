<?php
/**
 * Settings controller.
 *
 * REST API handlers for general site settings, cache management, CAPTCHA,
 * GeoIP configuration, and mail delivery.
 *
 * @package PeakURL\Features\Settings
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Settings;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Settings REST endpoints.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * Settings domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $settings_service;

	/**
	 * Create a new Settings controller instance.
	 *
	 * @param Service $settings_service Settings domain service.
	 * @since 1.0.0
	 */
	public function __construct( Service $settings_service ) {
		$this->settings_service = $settings_service;
	}

	/**
	 * Return the public dashboard app-data payload.
	 *
	 * @param Request $request Incoming request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function i18n( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_public_i18n_payload(),
			__( 'Dashboard app data loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the general-settings payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function general( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_general_settings( $request ),
			__( 'General settings loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save general site settings.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function update_general( Request $request ): array {
		return $this->success_response(
			$this->settings_service->save_general_settings(
				$request,
				$request->get_body_params(),
			),
			__( 'General settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Return the current cache status and diagnostic metrics.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function cache_status( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_cache_status( $request ),
			__( 'Cache status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save cache and performance settings.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function cache_update( Request $request ): array {
		return $this->success_response(
			$this->settings_service->save_cache_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'Cache settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Clear all cached objects.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function cache_clear( Request $request ): array {
		return $this->success_response(
			$this->settings_service->clear_cache( $request ),
			__( 'Object cache purged successfully.', 'peakurl' ),
		);
	}

	/**
	 * Return the current CAPTCHA provider status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function captcha_status( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_captcha_status( $request ),
			__( 'CAPTCHA settings loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save encrypted CAPTCHA provider credentials.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function captcha_update( Request $request ): array {
		return $this->success_response(
			$this->settings_service->save_captcha_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'CAPTCHA settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Return the current GeoIP integration status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function geoip_status( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_geoip_status( $request ),
			__( 'GeoIP status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save encrypted MaxMind credentials into settings storage.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function geoip_update( Request $request ): array {
		return $this->success_response(
			$this->settings_service->save_geoip_configuration(
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
	public function geoip_download( Request $request ): array {
		return $this->success_response(
			$this->settings_service->download_geoip_database( $request ),
			__( 'GeoIP database updated.', 'peakurl' ),
		);
	}

	/**
	 * Return the current mail delivery configuration status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function mail_status( Request $request ): array {
		return $this->success_response(
			$this->settings_service->get_mail_status( $request ),
			__( 'Mail delivery status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save the current mail delivery configuration.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function mail_update( Request $request ): array {
		return $this->success_response(
			$this->settings_service->save_mail_configuration(
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
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function mail_test( Request $request ): array {
		return $this->success_response(
			$this->settings_service->send_test_email( $request ),
			__( 'Test email sent.', 'peakurl' ),
		);
	}
}
