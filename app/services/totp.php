<?php
/**
 * Small TOTP helper for self-hosted 2FA setup.
 *
 * Implements the RFC 6238 Time-Based One-Time Password (TOTP)
 * algorithm used for two-factor authentication in the dashboard.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Totp_Service — generates secrets, builds otpauth URIs, and verifies TOTP codes.
 *
 * @since 1.0.0
 */
class Totp_Service {

	/** @var string Base32 alphabet used for secret generation and decoding. */
	private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Generate a random Base32-encoded secret.
	 *
	 * @param int $length Secret length in characters (default 32).
	 * @return string Base32 secret suitable for authenticator apps.
	 * @since 1.0.0
	 */
	public function generate_secret( int $length = 32 ): string {
		$secret = '';

		while ( strlen( $secret ) < $length ) {
			$secret .=
				self::BASE32_ALPHABET[ random_int( 0, strlen( self::BASE32_ALPHABET ) - 1 ) ];
		}

		return substr( $secret, 0, $length );
	}

	/**
	 * Build an otpauth URI for authenticator apps and QR generation.
	 *
	 * @param string $issuer Issuer name (e.g. 'PeakURL').
	 * @param string $label  Account label (e.g. user email).
	 * @param string $secret Base32-encoded TOTP secret.
	 * @return string Complete otpauth:// URI.
	 * @since 1.0.0
	 */
	public function build_otpauth_url(
		string $issuer,
		string $label,
		string $secret
	): string {
		return sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
			rawurlencode( $issuer ),
			rawurlencode( $label ),
			rawurlencode( $secret ),
			rawurlencode( $issuer ),
		);
	}

	/**
	 * Verify a 6-digit TOTP code against the secret.
	 *
	 * Checks the current time slice plus a configurable window to
	 * account for clock drift.
	 *
	 * @param string $secret Base32-encoded TOTP secret.
	 * @param string $code   6-digit code from the authenticator app.
	 * @param int    $window Number of 30-second windows to check each direction.
	 * @return bool True when the code is valid within the window.
	 * @since 1.0.0
	 */
	public function verify_code(
		string $secret,
		string $code,
		int $window = 1
	): bool {
		$code = preg_replace( '/\s+/', '', $code );

		if ( ! is_string( $code ) ) {
			$code = '';
		}

		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$time_slice = (int) floor( time() / 30 );

		for ( $offset = -( 1 * $window ); $offset <= $window; $offset++ ) {
			if (
				hash_equals(
					$this->calculate_code( $secret, $time_slice + $offset ),
					$code,
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate the expected TOTP code for a given time slice.
	 *
	 * @param string $secret     Base32-encoded TOTP secret.
	 * @param int    $time_slice UNIX time divided by 30-second period.
	 * @return string Zero-padded 6-digit TOTP code.
	 * @since 1.0.0
	 */
	private function calculate_code( string $secret, int $time_slice ): string {
		$secret_key = $this->base32_decode( $secret );
		$time       = pack( 'N*', 0 ) . pack( 'N*', $time_slice );
		$hash       = hash_hmac( 'sha1', $time, $secret_key, true );
		$offset     = ord( substr( $hash, -1 ) ) & 0x0f;
		$truncated  =
			( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
			( ord( $hash[ $offset + 3 ] ) & 0xff );

		return str_pad( (string) ( $truncated % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Decode a Base32-encoded string into raw binary.
	 *
	 * @param string $secret Base32 input.
	 * @return string Raw binary bytes.
	 * @since 1.0.0
	 */
	private function base32_decode( string $secret ): string {
		$secret      = strtoupper( $secret );
		$secret      = preg_replace( '/[^A-Z2-7]/', '', $secret );
		$binary      = '';
		$buffer      = 0;
		$buffer_size = 0;

		if ( ! is_string( $secret ) ) {
			$secret = '';
		}

		for (
			$index = 0, $length = strlen( $secret );
			$index < $length;
			$index++
		) {
			$value = strpos( self::BASE32_ALPHABET, $secret[ $index ] );

			if ( false === $value ) {
				continue;
			}

			$buffer       = ( $buffer << 5 ) | $value;
			$buffer_size += 5;

			if ( $buffer_size >= 8 ) {
				$buffer_size -= 8;
				$binary      .= chr( ( $buffer >> $buffer_size ) & 0xff );
			}
		}

		return $binary;
	}
}
