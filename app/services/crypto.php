<?php
/**
 * Runtime crypto helpers for auth keys, cookie signing, and stored credentials.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Crypto service for signed sessions and encrypted dashboard-managed settings.
 *
 * @since 1.0.0
 */
class Crypto {

	/**
	 * Runtime configuration values.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Create a new crypto service.
	 *
	 * @param array<string, mixed> $config Runtime configuration.
	 * @since 1.0.0
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Determine whether both auth key values are configured.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_auth_keys(): bool {
		return '' !== trim( (string) ( $this->config[ Constants::CONFIG_AUTH_KEY ] ?? '' ) ) &&
			'' !== trim( (string) ( $this->config[ Constants::CONFIG_AUTH_SALT ] ?? '' ) );
	}

	/**
	 * Ensure the auth key and salt exist and are persisted to runtime config.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return array{authKey: string, authSalt: string}
	 *
	 * @throws \RuntimeException When the keys cannot be persisted.
	 * @since 1.0.0
	 */
	public function ensure_persisted_auth_keys( string $app_path ): array {
		$auth_key  = trim( (string) ( $this->config[ Constants::CONFIG_AUTH_KEY ] ?? '' ) );
		$auth_salt = trim( (string) ( $this->config[ Constants::CONFIG_AUTH_SALT ] ?? '' ) );

		if ( '' === $auth_key ) {
			$auth_key = bin2hex( random_bytes( 32 ) );
		}

		if ( '' === $auth_salt ) {
			$auth_salt = bin2hex( random_bytes( 32 ) );
		}

		if ( $this->is_source_checkout() ) {
			SetupConfig::write_env_overrides(
				$app_path . '/.env',
				array(
					Constants::CONFIG_AUTH_KEY  => $auth_key,
					Constants::CONFIG_AUTH_SALT => $auth_salt,
				),
				'PeakURL could not update app/.env with the authentication keys.',
				'# PeakURL local development overrides'
			);
		} else {
			$values                                = SetupConfig::config_values_from_runtime_config( $this->config );
			$values[ Constants::CONFIG_AUTH_KEY ]  = $auth_key;
			$values[ Constants::CONFIG_AUTH_SALT ] = $auth_salt;
			SetupConfig::write_config_file( $app_path, $values );
		}

		$this->config[ Constants::CONFIG_AUTH_KEY ]  = $auth_key;
		$this->config[ Constants::CONFIG_AUTH_SALT ] = $auth_salt;

		return array(
			'authKey'  => $auth_key,
			'authSalt' => $auth_salt,
		);
	}

	/**
	 * Encrypt a value for storage in the settings table.
	 *
	 * @param string $value Raw value.
	 * @return string
	 *
	 * @throws \RuntimeException When encryption fails.
	 * @since 1.0.0
	 */
	public function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'The OpenSSL extension is required to encrypt stored credentials.' );
		}

		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$value,
			'aes-256-gcm',
			$this->derive_key( 'settings-encryption' ),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);

		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'PeakURL could not encrypt the stored credential.' );
		}

		return 'enc:v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a stored settings-table value.
	 *
	 * @param string $value Stored value or plain fallback value.
	 * @return string
	 *
	 * @throws \RuntimeException When the payload cannot be decrypted.
	 * @since 1.0.0
	 */
	public function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! str_starts_with( $value, 'enc:v1:' ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new \RuntimeException( 'The OpenSSL extension is required to decrypt stored credentials.' );
		}

		$decoded = base64_decode( substr( $value, 7 ), true );

		if ( false === $decoded || strlen( $decoded ) <= 28 ) {
			throw new \RuntimeException( 'PeakURL could not decode the stored credential payload.' );
		}

		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$this->derive_key( 'settings-encryption' ),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);

		if ( false === $plaintext ) {
			throw new \RuntimeException( 'PeakURL could not decrypt the stored credential.' );
		}

		return $plaintext;
	}

	/**
	 * Sign a raw session token for cookie storage.
	 *
	 * @param string $token Raw random session token.
	 * @return string
	 *
	 * @throws \RuntimeException When auth keys are missing.
	 * @since 1.0.0
	 */
	public function sign_session_token( string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		$signature = hash_hmac(
			'sha256',
			$token,
			$this->derive_key( 'session-cookie-signature' ),
		);

		return $token . '.' . $signature;
	}

	/**
	 * Verify a signed session cookie and return the raw token when valid.
	 *
	 * @param string $value Signed session cookie value.
	 * @return string|null
	 * @since 1.0.0
	 */
	public function verify_session_token( string $value ): ?string {
		if ( '' === $value || ! $this->has_auth_keys() ) {
			return null;
		}

		$separator = strrpos( $value, '.' );

		if ( false === $separator ) {
			return null;
		}

		$token     = substr( $value, 0, $separator );
		$signature = substr( $value, $separator + 1 );

		if ( '' === $token || '' === $signature ) {
			return null;
		}

		$expected = hash_hmac(
			'sha256',
			$token,
			$this->derive_key( 'session-cookie-signature' ),
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Build a stable 32-byte key for the given crypto context.
	 *
	 * @param string $context Key-derivation context label.
	 * @return string
	 *
	 * @throws \RuntimeException When auth keys are missing.
	 * @since 1.0.0
	 */
	private function derive_key( string $context ): string {
		$auth_key  = trim( (string) ( $this->config[ Constants::CONFIG_AUTH_KEY ] ?? '' ) );
		$auth_salt = trim( (string) ( $this->config[ Constants::CONFIG_AUTH_SALT ] ?? '' ) );

		if ( '' === $auth_key || '' === $auth_salt ) {
			throw new \RuntimeException( 'PeakURL is missing PEAKURL_AUTH_KEY or PEAKURL_AUTH_SALT.' );
		}

		return hash(
			'sha256',
			$context . '|' . $auth_key . '|' . $auth_salt,
			true,
		);
	}

	/**
	 * Determine whether PeakURL is running from the source checkout.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_source_checkout(): bool {
		return file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' );
	}
}
