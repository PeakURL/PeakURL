<?php
/**
 * Roles domain service.
 *
 * Implements administrative role definitions retrieval, capability updating,
 * and per-role resetting to shipped defaults.
 *
 * @package PeakURL\Features\Roles
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Roles;

use PeakURL\Core\Auth\Roles;
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
	 * Create a new Roles domain service instance.
	 *
	 * @param Roles       $roles        Roles registry.
	 * @param AuthService $auth_service Auth domain service.
	 * @since 1.7.2
	 */
	public function __construct(
		Roles $roles,
		AuthService $auth_service
	) {
		$this->roles        = $roles;
		$this->auth_service = $auth_service;
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
	 * Update capability configuration for a specific role.
	 *
	 * Admin-only operation.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $role    Target role identifier ('admin' or 'editor').
	 * @param array<string, mixed> $payload Request body with 'capabilities' map.
	 * @return array<string, mixed> Updated role definition object.
	 * @since 1.7.2
	 */
	public function update_role( Request $request, string $role, array $payload ): array {
		$this->auth_service->get_admin_user( $request );

		return $this->roles->update_role_capabilities( $role, $payload );
	}

	/**
	 * Reset capability configuration for a specific role to shipped defaults.
	 *
	 * Admin-only operation.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $role    Target role identifier ('admin' or 'editor').
	 * @return array<string, mixed> Reset role definition object.
	 * @since 1.7.2
	 */
	public function reset_role( Request $request, string $role ): array {
		$this->auth_service->get_admin_user( $request );

		return $this->roles->reset_role_capabilities( $role );
	}
}
