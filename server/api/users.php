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

use PeakURL\Services\Database\PeakURL_DB;

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
	public function get_owner_user(): ?array {
		return $this->db->get_row(
			'SELECT * FROM users ORDER BY FIELD(role, \'admin\', \'editor\'), created_at ASC LIMIT 1',
		);
	}

	/**
	 * Lock a single user row for update.
	 *
	 * @param string $user_id Target user ID.
	 * @return array<string, mixed>|null Locked row or null if not found.
	 * @since 1.7.2
	 */
	public function lock_user( string $user_id ): ?array {
		return $this->db->get_row(
			'SELECT * FROM users WHERE id = :id FOR UPDATE',
			array( 'id' => $user_id )
		);
	}

	/**
	 * Lock multiple user rows for update in deterministic ascending numeric ID order.
	 *
	 * Deduplicates user IDs and acquires exclusive row locks in sorted numeric order
	 * (matching InnoDB BIGINT UNSIGNED primary key ordering) to prevent AB-BA deadlocks.
	 *
	 * @param array<int|string, string> $user_ids List of user IDs.
	 * @return array<string, array<string, mixed>|null> Map of user ID to locked row (or null).
	 * @since 1.7.2
	 */
	public function lock_users( array $user_ids ): array {
		$unique_ids = array_values( array_unique( array_map( 'strval', $user_ids ) ) );

		usort(
			$unique_ids,
			function ( string $a, string $b ): int {
				$trimmed_a = ltrim( $a, '0' );
				$trimmed_b = ltrim( $b, '0' );
				$sa        = '' !== $trimmed_a ? $trimmed_a : '0';
				$sb        = '' !== $trimmed_b ? $trimmed_b : '0';
				$len_a     = strlen( $sa );
				$len_b     = strlen( $sb );
				if ( $len_a !== $len_b ) {
					return $len_a <=> $len_b;
				}
				return strcmp( $sa, $sb );
			}
		);

		$locked = array();
		foreach ( $unique_ids as $id ) {
			$locked[ $id ] = $this->lock_user( $id );
		}

		return $locked;
	}

	/**
	 * Count admin users.
	 *
	 * Performs an ordinary non-locking count of admin accounts. For exclusive locking
	 * during transactions, call count_admin_users_for_update() directly.
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

	/**
	 * Count admin users under exclusive row locks in deterministic ID order.
	 *
	 * @return int Number of locked admin rows.
	 * @since 1.7.2
	 */
	public function count_admin_users_for_update(): int {
		return count(
			$this->db->get_results(
				'SELECT id FROM users WHERE role = \'admin\' ORDER BY id ASC FOR UPDATE'
			)
		);
	}

	/**
	 * List all user rows ordered by role priority and creation date.
	 *
	 * @return array<int, array<string, mixed>> Result user rows.
	 * @since 1.0.0
	 */
	public function list_users(): array {
		return $this->db->get_results(
			'SELECT * FROM users ORDER BY FIELD(role, \'admin\', \'editor\'), created_at ASC'
		);
	}
}
