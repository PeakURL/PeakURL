<?php
/**
 * User endpoints controller.
 *
 * Handles CRUD operations for site users as well as the
 * authenticated user's own profile (me / update_me).
 *
 * @package PeakURL\Features\Users
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Users;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Thin HTTP entry adapter delegating to Users Service.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * Users domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $users_service;

	/**
	 * Create a new Users controller instance.
	 *
	 * @param Service $users_service Users domain service.
	 * @since 1.0.0
	 */
	public function __construct( Service $users_service ) {
		$this->users_service = $users_service;
	}

	/**
	 * List all users.
	 *
	 * Supports pagination and sorting via query parameters.
	 * Restricted to admin-role users.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with paginated user list.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		return $this->success_response(
			$this->users_service->get_all_users( $request ),
			__( 'Users loaded.', 'peakurl' ),
		);
	}

	/**
	 * Create a new user account.
	 *
	 * Admin-only. Accepts username, email, password, and role in the body.
	 *
	 * @param Request $request Incoming HTTP request with user payload.
	 * @return array<string, mixed> JSON envelope with the created user (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return $this->success_response(
			$this->users_service->create_user(
				$request,
				$request->get_body_params(),
			),
			__( 'User created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Return the currently authenticated user.
	 *
	 * @param Request $request Incoming HTTP request (session-authenticated).
	 * @return array<string, mixed> JSON envelope with the current user profile.
	 * @since 1.0.0
	 */
	public function me( Request $request ): array {
		return $this->success_response(
			$this->users_service->get_current_profile( $request ),
			__( 'Current user loaded.', 'peakurl' ),
		);
	}

	/**
	 * Update the authenticated user's own profile.
	 *
	 * Accepts email, display name, and password change fields. Password
	 * changes must include the current password for confirmation.
	 *
	 * @param Request $request Incoming HTTP request with profile payload.
	 * @return array<string, mixed> JSON envelope with updated profile.
	 * @since 1.0.0
	 */
	public function update_me( Request $request ): array {
		return $this->success_response(
			$this->users_service->update_current_user(
				$request,
				$request->get_body_params(),
			),
			__( 'Profile updated.', 'peakurl' ),
		);
	}

	/**
	 * Update a user by username.
	 *
	 * Admin-only. Returns 404 if the username does not exist.
	 *
	 * @param Request $request Incoming HTTP request with route param `username`.
	 * @return array<string, mixed> JSON envelope with updated user or 404 error.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		$username = $this->route_param( $request, 'username' );
		$user     = $this->users_service->update_user_by_username(
			$request,
			$username,
			$request->get_body_params(),
		);

		return $this->found_response(
			$user,
			__( 'User not found.', 'peakurl' ),
			__( 'User updated.', 'peakurl' ),
		);
	}

	/**
	 * Delete a user by username.
	 *
	 * Admin-only. Returns 404 if the username does not exist.
	 *
	 * @param Request $request Incoming HTTP request with route param `username`.
	 * @return array<string, mixed> JSON envelope confirming deletion or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->users_service->delete_user_by_username(
			$request,
			$this->route_param( $request, 'username' ),
		);

		return $this->delete_response(
			$deleted,
			__( 'User not found.', 'peakurl' ),
			__( 'User deleted.', 'peakurl' ),
		);
	}
}
