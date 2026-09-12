<?php
/**
 * Auth credentials domain model.
 *
 * Manages API keys and 2FA backup codes.
 *
 * @package PeakURL\Features\Auth
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Auth;

use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Credentials — API keys and two-factor backup codes manager.
 *
 * @since 1.0.0
 */
class Credentials {

	/**
	 * Database service.
	 *
	 * @var PeakURL_DB
	 */
	private PeakURL_DB $db;

	/**
	 * Create a Credentials instance.
	 *
	 * @param PeakURL_DB $db Database connection wrapper.
	 */
	public function __construct( PeakURL_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Insert a new API key row for a user.
	 *
	 * @param string $user_id User row ID.
	 * @param string $label   Human-readable label.
	 * @return array<string, mixed> Created API key record with plain-text key.
	 * @since 1.0.0
	 */
	public function insert_api_key( string $user_id, string $label ): array {
		$plain_text_key = $this->generate_api_key_token();
		$row            = array(
			'id'            => Str::random_id(),
			'user_id'       => $user_id,
			'label'         => '' !== trim( $label ) ? trim( $label ) : 'Generated Key',
			'key_hash'      => $this->hash_api_key( $plain_text_key ),
			'key_prefix'    => substr( $plain_text_key, 0, 16 ),
			'key_last_four' => substr( $plain_text_key, -4 ),
			'created_at'    => Date::now(),
		);

		$this->db->insert( 'api_keys', $row );

		return array(
			'id'        => $row['id'],
			'label'     => $row['label'],
			'key'       => $plain_text_key,
			'maskedKey' => $this->mask_api_key(
				(string) $row['key_prefix'],
				(string) $row['key_last_four'],
			),
			'prefix'    => (string) $row['key_prefix'],
			'lastFour'  => (string) $row['key_last_four'],
			'createdAt' => Date::to_iso( $row['created_at'] ),
		);
	}

	/**
	 * List all API keys for a user.
	 *
	 * @param string $user_id User row ID.
	 * @return array<int, array<string, mixed>> API key records.
	 * @since 1.0.0
	 */
	public function list_api_keys( string $user_id ): array {
		$rows = $this->db->get_results(
			'SELECT id, label, key_prefix, key_last_four, created_at
			FROM api_keys
			WHERE user_id = :user_id
			ORDER BY created_at DESC',
			array( 'user_id' => $user_id ),
		);

		return array_map(
			fn( array $row ): array => array(
				'id'        => (string) $row['id'],
				'label'     => (string) $row['label'],
				'prefix'    => (string) ( $row['key_prefix'] ?? '' ),
				'lastFour'  => (string) ( $row['key_last_four'] ?? '' ),
				'maskedKey' => $this->mask_api_key(
					(string) ( $row['key_prefix'] ?? '' ),
					(string) ( $row['key_last_four'] ?? '' ),
				),
				'createdAt' => Date::to_iso( (string) $row['created_at'] ),
			),
			$rows,
		);
	}

	/**
	 * Revoke an API key.
	 *
	 * @param string $user_id User row ID.
	 * @param string $key_id  API key row ID.
	 * @return bool True if deleted.
	 * @since 1.0.0
	 */
	public function revoke_api_key( string $user_id, string $key_id ): bool {
		$result = $this->db->query(
			'DELETE FROM api_keys WHERE id = :id AND user_id = :user_id',
			array(
				'id'      => $key_id,
				'user_id' => $user_id,
			),
		);

		return $result > 0;
	}

	/**
	 * Replace a user's backup codes with a freshly generated set.
	 *
	 * @param string $user_id User row ID.
	 * @return array<int, string> Plain-text backup codes shown once to the user.
	 * @since 1.0.0
	 */
	public function replace_backup_codes( string $user_id ): array {
		$codes = $this->generate_backup_codes();
		$now   = Date::now();

		$this->db->update(
			'users',
			array(
				'backup_codes_json'         => peakurl_json_encode( $codes ),
				'backup_codes_generated_at' => $now,
				'updated_at'                => $now,
			),
			array( 'id' => $user_id ),
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
	public function list_backup_codes( string $user_id ): array {
		$value = $this->db->get_var_by(
			'users',
			'backup_codes_json',
			array( 'id' => $user_id ),
		);

		if ( false === $value || null === $value ) {
			return array();
		}

		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $code ): string {
						return strtoupper( trim( (string) $code ) );
					},
					$decoded,
				),
				static fn( string $code ): bool => '' !== $code,
			),
		);
	}

	/**
	 * Verify and consume a backup code.
	 *
	 * @param string $user_id User row ID.
	 * @param string $token   Plain-text backup code.
	 * @return bool True if valid and consumed.
	 * @since 1.0.0
	 */
	public function verify_backup_code( string $user_id, string $token ): bool {
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

			$this->db->update(
				'users',
				array(
					'backup_codes_json' => peakurl_json_encode(
						array_values( $codes ),
					),
					'updated_at'        => Date::now(),
				),
				array( 'id' => $user_id ),
			);

			$this->db->commit();
			return true;
		} catch ( \Throwable $exception ) {
			if ( $this->db->in_transaction() ) {
				$this->db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Generate a random hex token for lookup-style flows.
	 *
	 * @param int $bytes Random-byte length before hex encoding.
	 * @return string
	 * @since 1.0.3
	 */
	public static function generate_lookup_token( int $bytes = 20 ): string {
		$bytes = $bytes > 0 ? $bytes : 20;

		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Hash a raw token for database storage or lookup comparisons.
	 *
	 * @param string $token Raw token.
	 * @return string
	 * @since 1.1.1
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', trim( $token ) );
	}

	/**
	 * Hash a raw lookup token for database storage.
	 *
	 * @param string $token Raw token.
	 * @return string
	 * @since 1.0.3
	 */
	public static function hash_lookup_token( string $token ): string {
		return self::hash_token( $token );
	}

	/**
	 * Hash an API key for storage.
	 *
	 * @param string $token Plain-text key token.
	 * @return string SHA-256 hash.
	 * @since 1.0.0
	 */
	public function hash_api_key( string $token ): string {
		return self::hash_token( $token );
	}

	/**
	 * Mask an API key for safe display.
	 *
	 * @param string $prefix    Key prefix.
	 * @param string $last_four Key last 4 chars.
	 * @return string Masked key string.
	 * @since 1.0.0
	 */
	public function mask_api_key( string $prefix, string $last_four ): string {
		return $prefix . '...' . $last_four;
	}

	/**
	 * Generate a random API key token.
	 *
	 * @return string Hex random token.
	 * @since 1.0.0
	 */
	private function generate_api_key_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
