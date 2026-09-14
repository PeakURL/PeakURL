<?php
/**
 * System controller.
 *
 * REST API handlers for dashboard admin notices, system status checks,
 * and updater management.
 *
 * @package PeakURL\Features\System
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\System;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — System status, notices, and updates endpoints.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * System domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $system_service;

	/**
	 * Create a new System controller instance.
	 *
	 * @param Service $system_service System domain service.
	 * @since 1.0.0
	 */
	public function __construct( Service $system_service ) {
		$this->system_service = $system_service;
	}

	/**
	 * Return the current dashboard admin notices.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function notices( Request $request ): array {
		return $this->success_response(
			array(
				'items' => $this->system_service->get_admin_notices( $request ),
			),
			__( 'Admin notices loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the aggregated dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_system_status( $request ),
			__( 'System status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the cached update status.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function update_status( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_update_status( $request ),
			__( 'Update status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Refresh available updates from the remote manifest.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function update_check( Request $request ): array {
		return $this->success_response(
			$this->system_service->refresh_update_status( $request ),
			__( 'Update status refreshed.', 'peakurl' ),
		);
	}

	/**
	 * Apply a pending update release package.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function update_apply( Request $request ): array {
		return $this->success_response(
			$this->system_service->apply_update( $request ),
			__( 'Update applied.', 'peakurl' ),
		);
	}

	/**
	 * Reinstall the current release package.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function update_reinstall( Request $request ): array {
		return $this->success_response(
			$this->system_service->reinstall_update( $request ),
			__( 'Release reinstalled.', 'peakurl' ),
		);
	}

	/**
	 * Apply the managed database upgrade / repair flow.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.0.0
	 */
	public function upgrade_database( Request $request ): array {
		return $this->success_response(
			$this->system_service->upgrade_database_schema( $request ),
			__( 'Database upgrade complete.', 'peakurl' ),
		);
	}

	/**
	 * Return the registered background jobs and current schedule status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_status( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_cron_status( $request ),
			__( 'Cron status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Run a registered background job or all due jobs immediately.
	 *
	 * @param Request     $request Incoming HTTP request (admin-only).
	 * @param string|null $job_id  Optional job identifier override.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_run_now( Request $request, ?string $job_id = null ): array {
		if ( null === $job_id || '' === trim( $job_id ) ) {
			$job_id = $request->get_route_param( 'id' );
		}

		if ( null === $job_id || '' === trim( (string) $job_id ) ) {
			$payload = $request->json_data();
			$job_id  = is_array( $payload ) ? (string) ( $payload['job_id'] ?? $payload['id'] ?? '' ) : '';
		}

		return $this->success_response(
			$this->system_service->run_cron_job( $request, '' !== $job_id ? $job_id : null ),
			__( 'Background job executed.', 'peakurl' ),
		);
	}

	/**
	 * Update background job scheduler settings (e.g. retention policy).
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_settings_update( Request $request ): array {
		return $this->success_response(
			$this->system_service->update_cron_settings( $request ),
			__( 'Scheduler settings updated.', 'peakurl' ),
		);
	}

	/**
	 * Clear background job execution history.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_clear_history( Request $request ): array {
		return $this->success_response(
			$this->system_service->clear_cron_history( $request ),
			__( 'Execution history cleared.', 'peakurl' ),
		);
	}

	/**
	 * Update background job schedule settings.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_job_update( Request $request ): array {
		return $this->success_response(
			$this->system_service->update_cron_job( $request ),
			__( 'Job schedule updated.', 'peakurl' ),
		);
	}

	/**
	 * Reset background job schedule to recommended defaults.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON success response.
	 * @since 1.7.0
	 */
	public function cron_job_reset( Request $request ): array {
		return $this->success_response(
			$this->system_service->reset_cron_job( $request ),
			__( 'Job schedule reset to recommended defaults.', 'peakurl' ),
		);
	}
}
