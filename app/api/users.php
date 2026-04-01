<?php
/**
 * Users data API.
 *
 * Centralises common users-table lookups so the higher-level data store can
 * reuse them without scattering direct queries across unrelated methods.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Api;

use PeakURL\Includes\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * UsersApi — small query helper for user rows.
 *
 * @since 1.0.0
 */
class UsersApi {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Create a new users API.
	 *
	 * @param PeakURL_DB $db Shared database wrapper.
	 * @since 1.0.0
	 */
	public function __construct( PeakURL_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Find a user row by primary ID.
	 *
	 * @param string $id User row ID.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	public function get_user( string $id ): ?array {
		return $this->db->get_row_by(
			'users',
			array( 'id' => $id ),
		);
	}

	/**
	 * Find a user row by email address.
	 *
	 * @param string $email Email to look up.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	public function get_user_by_email( string $email ): ?array {
		return $this->db->get_row_by(
			'users',
			array( 'email' => strtolower( $email ) ),
		);
	}

	/**
	 * Find a user row by username.
	 *
	 * @param string $username Username to look up.
	 * @return array<string, mixed>|null User row or null.
	 * @since 1.0.0
	 */
	public function get_user_by_username( string $username ): ?array {
		return $this->db->get_row_by(
			'users',
			array( 'username' => $username ),
		);
	}

	/**
	 * Get the first admin user, falling back to earliest editor.
	 *
	 * @return array<string, mixed>|null Owner row or null.
	 * @since 1.0.0
	 */
	public function get_workspace_owner_user(): ?array {
		return $this->db->get_row(
			'SELECT * FROM users ORDER BY FIELD(role, \'admin\', \'editor\'), created_at ASC LIMIT 1',
		);
	}

	/**
	 * Count admin users.
	 *
	 * @return int Number of admin rows.
	 * @since 1.0.0
	 */
	public function count_admin_users(): int {
		return $this->db->count(
			'users',
			array( 'role' => 'admin' ),
		);
	}
}
