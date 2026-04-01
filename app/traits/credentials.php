<?php
/**
 * Data store credentials trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CredentialsTrait — API key and backup-code helpers for Store.
 *
 * @since 1.0.0
 */
trait CredentialsTrait {

	/**
	 * Insert a new API key row for a user.
	 *
	 * @param string $user_id User row ID.
	 * @param string $label   Human-readable label.
	 * @return array<string, mixed> Created API key record with plain-text key.
	 * @since 1.0.0
	 */
	private function insert_api_key( string $user_id, string $label ): array {
		$plain_text_key = $this->generate_api_key_token();
		$row            = array(
			'id'            => $this->generate_random_id(),
			'user_id'       => $user_id,
			'label'         => '' !== trim( $label ) ? trim( $label ) : 'Generated Key',
			'key_hash'      => $this->hash_api_key( $plain_text_key ),
			'key_prefix'    => substr( $plain_text_key, 0, 16 ),
			'key_last_four' => substr( $plain_text_key, -4 ),
			'created_at'    => $this->now(),
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
				'createdAt' => $this->to_iso( (string) $row['created_at'] ),
			),
			$rows,
		);
	}

	/**
	 * Generate a new API key token for bearer authentication.
	 *
	 * @return string High-entropy token shown only once at creation time.
	 * @since 1.0.1
	 */
	private function generate_api_key_token(): string {
		do {
			$token = bin2hex( random_bytes( 24 ) );
			$hash  = $this->hash_api_key( $token );
		} while (
			$this->db->count(
				'api_keys',
				array( 'key_hash' => $hash ),
			) > 0
		);

		return $token;
	}

	/**
	 * Hash an API key token for secure database storage.
	 *
	 * @param string $token Plain-text API key token.
	 * @return string SHA-256 hex digest.
	 * @since 1.0.1
	 */
	private function hash_api_key( string $token ): string {
		return hash( 'sha256', trim( $token ) );
	}

	/**
	 * Build a masked preview string for list views and logs.
	 *
	 * @param string $prefix   Visible leading characters.
	 * @param string $last_four Visible trailing characters.
	 * @return string Masked preview.
	 * @since 1.0.1
	 */
	private function mask_api_key( string $prefix, string $last_four ): string {
		$clean_prefix    = trim( $prefix );
		$clean_last_four = trim( $last_four );

		if ( '' === $clean_prefix && '' === $clean_last_four ) {
			return '••••••••';
		}

		return $clean_prefix . '••••••••' . $clean_last_four;
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

		$this->db->update(
			'users',
			array(
				'backup_codes_json'         => $this->encode_json( $codes ),
				'backup_codes_generated_at' => $this->now(),
				'updated_at'                => $this->now(),
			),
			array(
				'id' => $user_id,
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
		$value = $this->db->get_var_by(
			'users',
			'backup_codes_json',
			array( 'id' => $user_id ),
		);

		if ( false === $value || null === $value ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return strtoupper( trim( (string) $value ) );
					},
					$this->decode_json_array( (string) $value ),
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

			$this->db->update(
				'users',
				array(
					'backup_codes_json' => $this->encode_json(
						array_values( $codes ),
					),
					'updated_at'        => $this->now(),
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
