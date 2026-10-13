<?php
/**
 * Roles domain service.
 *
 * Implements administrative role definitions retrieval, capability updating,
 * custom role lifecycle management, and per-role resetting.
 *
 * @package PeakURL\Features\Roles
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Roles;

use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Roles and capabilities management.
 *
 * Enforces administrator-only authentication at the domain service boundary
 * and coordinates role capability mutations with the centralized Roles registry.
 *
 * @since 1.7.2
 */
class Service {

	/**
	 * Central Roles registry.
	 *
	 * @var Roles
	 * @since 1.7.2
	 */
	private Roles $roles;

	/**
	 * Auth domain service.
	 *
	 * @var AuthService
	 * @since 1.7.2
	 */
	private AuthService $auth_service;

	/**
	 * Users API helper.
	 *
	 * @var UsersApi
	 * @since 1.7.2
	 */
	private UsersApi $users_api;

	/**
	 * Create a new Roles domain service instance.
	 *
	 * @param Roles       $roles        Roles registry.
	 * @param AuthService $auth_service Auth domain service.
	 * @param UsersApi    $users_api    Users API helper.
	 * @since 1.7.2
	 */
	public function __construct(
		Roles $roles,
		AuthService $auth_service,
		UsersApi $users_api
	) {
		$this->roles        = $roles;
		$this->auth_service = $auth_service;
		$this->users_api    = $users_api;

		$this->roles->set_users_api( $users_api );
	}

	/**
	 * List all role definitions with their capability catalog and current states.
	 *
	 * Admin-only operation.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> List of role definition objects.
	 * @since 1.7.2
	 */
	public function get_roles( Request $request ): array {
		$this->auth_service->get_admin_user( $request );

		return $this->roles->get_role_definitions();
	}

	/**
	 * Create a new custom role derived from the current Editor capabilities snapshot.
	 *
	 * Admin-only operation.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Request body containing id, name, and optional description.
	 * @return array<string, mixed> Created role definition object.
	 * @since 1.7.2
	 */
	public function create_role( Request $request, array $payload ): array {
		$this->auth_service->get_admin_user( $request );

		if ( ! array_key_exists( 'id', $payload ) || ! is_string( $payload['id'] ) ) {
			throw new ApiException(
				__( 'Role ID must be a string.', 'peakurl' ),
				422
			);
		}

		if ( ! array_key_exists( 'name', $payload ) || ! is_string( $payload['name'] ) ) {
			throw new ApiException(
				__( 'Role name must be a string.', 'peakurl' ),
				422
			);
		}

		$description = '';
		if ( array_key_exists( 'description', $payload ) ) {
			if ( ! is_string( $payload['description'] ) ) {
				throw new ApiException(
					__( 'Role description must be a string.', 'peakurl' ),
					422
				);
			}
			$description = $payload['description'];
		}

		return $this->roles->create_custom_role( $payload['id'], $payload['name'], $description );
	}

	/**
	 * Update capability configuration and/or metadata for a specific role.
	 *
	 * Admin-only operation.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $role    Target role identifier.
	 * @param array<string, mixed> $payload Request body with 'capabilities', 'name', or 'description'.
	 * @return array<string, mixed> Updated role definition object.
	 * @since 1.7.2
	 */
	public function update_role( Request $request, string $role, array $payload ): array {
		$this->auth_service->get_admin_user( $request );

		return $this->roles->update_role_definition( $role, $payload );
	}

	/**
	 * Reset capability configuration for a specific role to baseline defaults.
	 *
	 * Admin-only operation.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $role    Target role identifier.
	 * @return array<string, mixed> Reset role definition object.
	 * @since 1.7.2
	 */
	public function reset_role( Request $request, string $role ): array {
		$this->auth_service->get_admin_user( $request );

		return $this->roles->reset_role_capabilities( $role );
	}

	/**
	 * Delete a custom role from the registry.
	 *
	 * Admin-only operation. Fails with 409 if any users are currently assigned.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $role    Target custom role identifier.
	 * @return array<string, mixed> Success status payload.
	 * @since 1.7.2
	 */
	public function delete_role( Request $request, string $role ): array {
		$this->auth_service->get_admin_user( $request );

		$this->roles->delete_custom_role( $role );

		return array(
			'id'      => $role,
			'deleted' => true,
		);
	}
}
