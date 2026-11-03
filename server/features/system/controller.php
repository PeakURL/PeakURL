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
	 * Return installed plugins from regular and must-use directories.
	 *
	 * Metadata-only discovery. Never executes plugin code.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.8.0
	 */
	public function plugins( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_installed_plugins( $request ),
			__( 'Installed plugins loaded.', 'peakurl' ),
		);
	}

	/**
	 * Activate a regular plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function activate_plugin( Request $request ): array {
		return $this->success_response(
			$this->system_service->activate_plugin( $request ),
			__( 'Plugin activated.', 'peakurl' ),
		);
	}

	/**
	 * Deactivate a regular plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function deactivate_plugin( Request $request ): array {
		return $this->success_response(
			$this->system_service->deactivate_plugin( $request ),
			__( 'Plugin deactivated.', 'peakurl' ),
		);
	}

	/**
	 * Safely delete an inactive regular plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function delete_plugin( Request $request ): array {
		return $this->success_response(
			$this->system_service->delete_plugin( $request ),
			__( 'Plugin deleted.', 'peakurl' ),
		);
	}

	/**
	 * Upload and install a regular plugin archive.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function upload_plugin( Request $request ): array {
		$result    = $this->system_service->upload_plugin( $request );
		$is_update = ! empty( $result['updated'] );
		$message   = $is_update
			? __( 'Plugin updated successfully.', 'peakurl' )
			: __( 'Plugin installed successfully.', 'peakurl' );

		return $this->success_response(
			$result,
			$message,
			$is_update ? 200 : 201
		);
	}

	/**
	 * Retrieve registered pages and UI extensions visible to the current user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function plugin_extensions( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_plugin_extensions( $request ),
			__( 'Plugin extensions retrieved.', 'peakurl' ),
		);
	}

	/**
	 * Retrieve a specific plugin admin page descriptor.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function get_plugin_page( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_plugin_page( $request ),
			__( 'Plugin page retrieved.', 'peakurl' ),
		);
	}

	/**
	 * Retrieve stored settings for a plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function get_plugin_settings( Request $request ): array {
		return $this->success_response(
			$this->system_service->get_plugin_settings( $request ),
			__( 'Plugin settings retrieved.', 'peakurl' ),
		);
	}

	/**
	 * Update stored settings for a plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function update_plugin_settings( Request $request ): array {
		return $this->success_response(
			$this->system_service->update_plugin_settings( $request ),
			__( 'Plugin settings updated successfully.', 'peakurl' ),
		);
	}

	/**
	 * Dispatch an authorized plugin backend action callback.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON response.
	 * @since 1.8.0
	 */
	public function dispatch_plugin_action( Request $request ): array {
		return $this->success_response(
			$this->system_service->dispatch_plugin_action( $request ),
			__( 'Plugin action executed successfully.', 'peakurl' ),
		);
	}

	/**
	 * Serve an approved, scoped browser asset registered by an active plugin.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Raw HTTP response.
	 * @since 1.8.0
	 */
	public function serve_plugin_asset( Request $request ): array {
		return $this->system_service->serve_plugin_asset( $request );
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
