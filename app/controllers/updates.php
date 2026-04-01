<?php
/**
 * Dashboard updater endpoints.
 *
 * Provides the update status, remote-check, and apply actions
 * that power the admin-only "Updates" settings tab.
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
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
 * UpdatesController — REST handlers for system updates.
 *
 * Routes registered by Application::register_routes():
 *  GET  /api/v1/system/update       → status
 *  POST /api/v1/system/update/check → check
 *  POST /api/v1/system/update/apply → apply
 *
 * @since 1.0.0
 */
class UpdatesController {

	/**
	 * Persistence layer shared across controllers.
	 *
	 * @var Store
	 * @since 1.0.0
	 */
	private Store $data_store;

	/**
	 * Create a new UpdatesController instance.
	 *
	 * @param Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

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
		return JsonResponse::success(
			$this->data_store->get_update_status( $request ),
			'Update status loaded.',
		);
	}

	/**
	 * Check for available updates (POST /api/v1/system/update/check).
	 *
	 * Fetches the remote update manifest and compares it against the
	 * current runtime version. Caches the result in settings.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> JSON envelope with check result.
	 * @since 1.0.0
	 */
	public function check( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->check_for_updates( $request ),
			'Update check complete.',
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
		return JsonResponse::success(
			$this->data_store->apply_update( $request ),
			'Update applied.',
		);
	}
}
