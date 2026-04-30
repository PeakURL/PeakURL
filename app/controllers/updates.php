<?php
/**
 * Dashboard updater endpoints.
 *
 * Provides the update status, remote refresh, and apply actions
 * that power the admin-only "Updates" settings tab.
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
 * UpdatesController — REST handlers for system updates.
 *
 * Routes registered by Application::register_routes():
 *  GET  /api/v1/system/update       → status
 *  POST /api/v1/system/update/check → refresh
 *  POST /api/v1/system/update/apply → apply
 *  POST /api/v1/system/update/reinstall → reinstall
 *  POST /api/v1/system/update/database → upgrade_database
 *
 * @since 1.0.0
 */
class UpdatesController extends BaseController {

	/**
	 * Return the cached update status (GET /api/v1/system/update).
	 *
	 * Returns current version, latest available version, and
	 * whether an update is available.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with update status.
	 * @since 1.0.0
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_update_status( $request ),
			__( 'Update status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Refresh available updates (POST /api/v1/system/update/check).
	 *
	 * Fetches the remote update manifest and compares it against the
	 * current runtime version. Caches the result in settings.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON envelope with refreshed status.
	 * @since 1.0.0
	 */
	public function refresh( Request $request ): array {
		return $this->success_response(
			$this->data_store->refresh_update_status( $request ),
			__( 'Update status refreshed.', 'peakurl' ),
		);
	}

	/**
	 * Apply a pending update (POST /api/v1/system/update/apply).
	 *
	 * Downloads and extracts the release archive, then reconciles the
	 * database schema. Only allowed from packaged release installs.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON envelope confirming the update.
	 * @since 1.0.0
	 */
	public function apply( Request $request ): array {
		return $this->success_response(
			$this->data_store->apply_update( $request ),
			__( 'Update applied.', 'peakurl' ),
		);
	}

	/**
	 * Reinstall the current release package (POST /api/v1/system/update/reinstall).
	 *
	 * Downloads and extracts the current release archive again so packaged
	 * files can be restored without waiting for a newer version.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON envelope confirming the reinstall.
	 * @since 1.0.5
	 */
	public function reinstall( Request $request ): array {
		return $this->success_response(
			$this->data_store->reinstall_update( $request ),
			__( 'Release reinstalled.', 'peakurl' ),
		);
	}

	/**
	 * Apply the managed database upgrade / repair flow.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function upgrade_database( Request $request ): array {
		return $this->success_response(
			$this->data_store->upgrade_database_schema( $request ),
			__( 'Database upgrade complete.', 'peakurl' ),
		);
	}
}
