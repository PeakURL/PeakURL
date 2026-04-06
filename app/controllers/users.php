<?php
/**
 * User endpoints.
 *
 * Handles CRUD operations for site users as well as the
 * authenticated user's own profile (me / update_me).
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
 * UsersController — REST handlers for the users resource.
 *
 * Routes registered by Application::register_routes():
 *  GET    /api/v1/users          → index
 *  POST   /api/v1/users          → create
 *  GET    /api/v1/users/me       → me
 *  PUT    /api/v1/users/me       → update_me
 *  PUT    /api/v1/users/:username → update
 *  DELETE /api/v1/users/:username → delete
 *
 * @since 1.0.0
 */
class UsersController {

	/**
	 * Persistence layer for user data.
	 *
	 * @var Store
	 * @since 1.0.0
	 */
	private Store $data_store;

	/**
	 * Create a new UsersController instance.
	 *
	 * @param Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * List all users (GET /api/v1/users).
	 *
	 * Supports pagination and sorting via query parameters.
	 * Restricted to admin-role users.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with paginated user list.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_all_users( $request ),
			__( 'Users loaded.', 'peakurl' ),
		);
	}

	/**
	 * Create a new user account (POST /api/v1/users).
	 *
	 * Admin-only. Accepts username, email, password, and role in the body.
	 *
	 * @param Request $request Incoming HTTP request with user payload.
	 * @return array<string, mixed> JSON envelope with the created user (201).
	 * @since 1.0.0
	 */
	public function create( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->create_user(
				$request,
				$request->get_body_params(),
			),
			__( 'User created.', 'peakurl' ),
			201,
		);
	}

	/**
	 * Return the currently authenticated user (GET /api/v1/users/me).
	 *
	 * @param Request $request Incoming HTTP request (session-authenticated).
	 * @return array<string, mixed> JSON envelope with the current user profile.
	 * @since 1.0.0
	 */
	public function me( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->get_current_user( $request ),
			__( 'Current user loaded.', 'peakurl' ),
		);
	}

	/**
	 * Update the authenticated user's own profile (PUT /api/v1/users/me).
	 *
	 * Accepts email, display name, and password change fields. Password
	 * changes must include the current password for confirmation.
	 *
	 * @param Request $request Incoming HTTP request with profile payload.
	 * @return array<string, mixed> JSON envelope with updated profile.
	 * @since 1.0.0
	 */
	public function update_me( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->update_current_user(
				$request,
				$request->get_body_params(),
			),
			__( 'Profile updated.', 'peakurl' ),
		);
	}

	/**
	 * Update a user by username (PUT /api/v1/users/:username).
	 *
	 * Admin-only. Returns 404 if the username does not exist.
	 *
	 * @param Request $request Incoming HTTP request with route param `username`.
	 * @return array<string, mixed> JSON envelope with updated user or 404 error.
	 * @since 1.0.0
	 */
	public function update( Request $request ): array {
		$username = (string) $request->get_route_param( 'username' );
		$user     = $this->data_store->update_user_by_username(
			$request,
			$username,
			$request->get_body_params(),
		);

		if ( ! $user ) {
			return JsonResponse::error( __( 'User not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( $user, __( 'User updated.', 'peakurl' ) );
	}

	/**
	 * Delete a user by username (DELETE /api/v1/users/:username).
	 *
	 * Admin-only. Returns 404 if the username does not exist.
	 *
	 * @param Request $request Incoming HTTP request with route param `username`.
	 * @return array<string, mixed> JSON envelope confirming deletion or 404 error.
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->data_store->delete_user_by_username(
			$request,
			(string) $request->get_route_param( 'username' ),
		);

		if ( ! $deleted ) {
			return JsonResponse::error( __( 'User not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( array( 'deleted' => true ), __( 'User deleted.', 'peakurl' ) );
	}
}
