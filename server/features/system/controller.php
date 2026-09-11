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
}
