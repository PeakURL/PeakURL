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

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Users_Controller — REST handlers for the users resource.
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
class Users_Controller {

	/**
	 * Persistence layer for user data.
	 *
	 * @var Data_Store
	 * @since 1.0.0
	 */
	private Data_Store $data_store;

	/**
	 * Create a new Users_Controller instance.
	 *
	 * @param Data_Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Data_Store $data_store ) {
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
		return Json_Response::success(
			$this->data_store->get_all_users( $request ),
			'Users loaded.',
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
		return Json_Response::success(
			$this->data_store->create_user(
				$request,
				$request->get_body_params(),
			),
			'User created.',
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
		return Json_Response::success(
			$this->data_store->get_current_user( $request ),
			'Current user loaded.',
		);
	}

	/**
	 * Update the authenticated user's own profile (PUT /api/v1/users/me).
	 *
	 * Accepts email, display name, and password change fields.
	 *
	 * @param Request $request Incoming HTTP request with profile payload.
	 * @return array<string, mixed> JSON envelope with updated profile.
	 * @since 1.0.0
	 */
	public function update_me( Request $request ): array {
		return Json_Response::success(
			$this->data_store->update_current_user(
				$request,
				$request->get_body_params(),
			),
			'Profile updated.',
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
			return Json_Response::error( 'User not found.', 404 );
		}

		return Json_Response::success( $user, 'User updated.' );
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
			return Json_Response::error( 'User not found.', 404 );
		}

		return Json_Response::success( array( 'deleted' => true ), 'User deleted.' );
	}
}
