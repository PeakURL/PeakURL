<?php
/**
 * Data store credentials trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Store_Credentials_Trait — API key and backup-code helpers for Data_Store.
 *
 * @since 1.0.0
 */
trait Store_Credentials_Trait {

	/**
	 * Insert a new API key row for a user.
	 *
	 * @param string $user_id User row ID.
	 * @param string $label   Human-readable label.
	 * @return array<string, mixed> Created API key record with plain-text key.
	 * @since 1.0.0
	 */
	private function insert_api_key( string $user_id, string $label ): array {
		$row = array(
			'id'         => $this->generate_random_id(),
			'user_id'    => $user_id,
			'label'      => '' !== trim( $label ) ? trim( $label ) : 'Generated Key',
			'key_value'  => $this->generate_random_id( 16 ),
			'created_at' => $this->now(),
		);

		$this->execute(
			'INSERT INTO api_keys (id, user_id, label, key_value, created_at)
            VALUES (:id, :user_id, :label, :key_value, :created_at)',
			$row,
		);

		return array(
			'id'        => $row['id'],
			'label'     => $row['label'],
			'key'       => $row['key_value'],
			'createdAt' => $this->to_iso( $row['created_at'] ),
		);
	}

	/**
	 * List all API keys for a user.
	 *
	 * @param string $user_id User row ID.
	 * @return array<int, array<string, mixed>> API key records.
	 * @since 1.0.0
	 */
	private function list_api_keys( string $user_id ): array {
		$rows = $this->query_all(
			'SELECT * FROM api_keys WHERE user_id = :user_id ORDER BY created_at DESC',
			array( 'user_id' => $user_id ),
		);

		return array_map(
			fn( array $row ): array => array(
				'id'        => (string) $row['id'],
				'label'     => (string) $row['label'],
				'key'       => (string) $row['key_value'],
				'createdAt' => $this->to_iso( (string) $row['created_at'] ),
			),
			$rows,
		);
	}

	/**
	 * Generate and store a fresh set of backup codes for a user.
	 *
	 * @param string $user_id User row ID.
	 * @return array<int, string> Plain-text backup codes.
	 * @since 1.0.0
	 */
	private function replace_backup_codes( string $user_id ): array {
		$codes = $this->generate_backup_codes();

		$this->execute(
			'UPDATE users
			SET backup_codes_json = :backup_codes_json,
				backup_codes_generated_at = :backup_codes_generated_at,
				updated_at = :updated_at
			WHERE id = :id',
			array(
				'backup_codes_json'         => $this->encode_json( $codes ),
				'backup_codes_generated_at' => $this->now(),
				'updated_at'                => $this->now(),
				'id'                        => $user_id,
			),
		);

		return $codes;
	}

	/**
	 * List unused backup codes for a user.
	 *
	 * @param string $user_id User row ID.
	 * @return array<int, string> Backup code values.
	 * @since 1.0.0
	 */
	private function list_backup_codes( string $user_id ): array {
		$row = $this->query_one(
			'SELECT backup_codes_json FROM users WHERE id = :id LIMIT 1',
			array( 'id' => $user_id ),
		);

		if ( ! $row ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return strtoupper( trim( (string) $value ) );
					},
					$this->decode_json_array(
						(string) ( $row['backup_codes_json'] ?? '[]' ),
					),
				),
				static fn( string $value ): bool => '' !== $value,
			),
		);
	}

	/**
	 * Consume (mark as used) a backup code during 2FA login.
	 *
	 * @param string $user_id User row ID.
	 * @param string $token   Plain-text backup code.
	 * @return bool True if a valid, unused code was consumed.
	 * @since 1.0.0
	 */
	private function consume_backup_code( string $user_id, string $token ): bool {
		$backup_code = strtoupper( trim( $token ) );

		if ( '' === $backup_code ) {
			return false;
		}

		$this->db->begin_transaction();

		try {
			$codes = $this->list_backup_codes( $user_id );
			$index = array_search( $backup_code, $codes, true );

			if ( false === $index ) {
				$this->db->roll_back();
				return false;
			}

			unset( $codes[ $index ] );

			$this->execute(
				'UPDATE users
				SET backup_codes_json = :backup_codes_json,
					updated_at = :updated_at
				WHERE id = :id',
				array(
					'backup_codes_json' => $this->encode_json(
						array_values( $codes ),
					),
					'updated_at'        => $this->now(),
					'id'                => $user_id,
				),
			);

			$this->db->commit();
			return true;
		} catch ( Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Generate a set of random backup recovery codes.
	 *
	 * @return array<int, string> Eight uppercase hex codes.
	 * @since 1.0.0
	 */
	private function generate_backup_codes(): array {
		$codes = array();

		for ( $index = 0; $index < 8; $index++ ) {
			$codes[] = strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 8 ) );
		}

		return $codes;
	}
}
