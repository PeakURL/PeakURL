<?php
/**
 * Shared secret-storage helpers.
 *
 * @package PeakURL\Utils
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Utils;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Helpers for hashing and verifying stored secrets.
 *
 * @since 1.0.3
 */
class Secrets {

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
	 * Hash a raw lookup token for database storage.
	 *
	 * @param string $token Raw token.
	 * @return string
	 * @since 1.0.3
	 */
	public static function hash_lookup_token( string $token ): string {
		return hash( 'sha256', trim( $token ) );
	}

	/**
	 * Hash a protected-link password for database storage.
	 *
	 * @param string $password Raw password.
	 * @return string
	 *
	 * @throws \RuntimeException When the password cannot be hashed.
	 * @since 1.0.3
	 */
	public static function hash_link_password( string $password ): string {
		$hash = password_hash( $password, PASSWORD_DEFAULT );

		if ( false === $hash ) {
			throw new \RuntimeException(
				__( 'PeakURL could not hash the protected-link password.', 'peakurl' ),
			);
		}

		return $hash;
	}

	/**
	 * Verify a protected-link password against stored secret material.
	 *
	 * @param string $password      Raw password input.
	 * @param string $stored_secret Stored password value.
	 * @return bool
	 * @since 1.0.3
	 */
	public static function verify_link_password(
		string $password,
		string $stored_secret
	): bool {
		$stored_secret = trim( $stored_secret );

		if ( '' === $password || '' === $stored_secret ) {
			return false;
		}

		return password_verify( $password, $stored_secret );
	}
}
