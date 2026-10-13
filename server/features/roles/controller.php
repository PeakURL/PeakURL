<?php
/**
 * Roles endpoints controller.
 *
 * Handles HTTP requests for listing, updating, and resetting role capabilities.
 *
 * @package PeakURL\Features\Roles
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Roles;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Thin HTTP entry adapter delegating to Roles domain Service.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.7.2
 */
class Controller extends BaseController {

	/**
	 * Roles domain service.
	 *
	 * @var Service
	 * @since 1.7.2
	 */
	private Service $roles_service;

	/**
	 * Create a new Roles controller instance.
	 *
	 * @param Service $roles_service Roles domain service.
	 * @since 1.7.2
	 */
	public function __construct( Service $roles_service ) {
		$this->roles_service = $roles_service;
	}

	/**
	 * List all role definitions with capability statuses.
	 *
	 * Admin-only endpoint.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with role definitions.
	 * @since 1.7.2
	 */
	public function index( Request $request ): array {
		return $this->success_response(
			$this->roles_service->get_roles( $request ),
			__( 'Roles loaded.', 'peakurl' ),
		);
	}

	/**
	 * Update capability configuration for a role.
	 *
	 * Admin-only endpoint.
	 *
	 * @param Request $request Incoming HTTP request with route param `role`.
	 * @return array<string, mixed> JSON envelope with updated role definition.
	 * @since 1.7.2
	 */
	public function update( Request $request ): array {
		$role = $this->route_param( $request, 'role' );

		return $this->success_response(
			$this->roles_service->update_role(
				$request,
				$role,
				$request->get_body_params(),
			),
			__( 'Role capabilities updated.', 'peakurl' ),
		);
	}

	/**
	 * Reset capability configuration for a role to shipped defaults.
	 *
	 * Admin-only endpoint.
	 *
	 * @param Request $request Incoming HTTP request with route param `role`.
	 * @return array<string, mixed> JSON envelope with reset role definition.
	 * @since 1.7.2
	 */
	public function reset( Request $request ): array {
		$role = $this->route_param( $request, 'role' );

		return $this->success_response(
			$this->roles_service->reset_role(
				$request,
				$role,
			),
			__( 'Role capabilities reset to defaults.', 'peakurl' ),
		);
	}
}
