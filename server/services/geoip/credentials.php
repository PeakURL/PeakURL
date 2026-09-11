<?php
/**
 * MaxMind credential management helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Services\Crypto;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Credentials — load, validate, and persist MaxMind credentials.
 *
 * @since 1.0.14
 */
class Credentials {

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new credentials helper.
	 *
	 * @param Context $context Shared GeoIP context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Refresh the active credentials from the settings table.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function refresh(): void {
		$credentials = $this->load_credentials();

		$this->context->set_credentials(
			$credentials['accountId'],
			$credentials['licenseKey'],
		);
	}

	/**
	 * Check whether both MaxMind credentials are configured.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_configured(): bool {
		return '' !== $this->context->get_account_id() && '' !== $this->context->get_license_key();
	}

	/**
	 * Return the masked MaxMind license key preview.
	 *
	 * @return string|null
	 * @since 1.0.14
	 */
	public function get_license_key_hint(): ?string {
		$license_key = $this->context->get_license_key();

		if ( '' === $license_key ) {
			return null;
		}

		if ( strlen( $license_key ) <= 8 ) {
			return str_repeat( '•', strlen( $license_key ) );
		}

		return substr( $license_key, 0, 4 ) .
			str_repeat( '•', strlen( $license_key ) - 8 ) .
			substr( $license_key, -4 );
	}

	/**
	 * Determine whether dashboard credential management is available.
	 *
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.0.14
	 */
	public function get_capability(): array {
		if ( ! $this->context->get_settings_api()->table_exists() ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The settings table is not available yet.', 'peakurl' ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Validate and persist MaxMind credentials.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $input    Submitted settings payload.
	 * @return void
	 *
	 * @throws \RuntimeException When the credentials are invalid.
	 * @since 1.0.14
	 */
	public function save( string $app_path, array $input ): void {
		$capability = $this->get_capability();

		if ( ! $capability['allowed'] ) {
			throw new \RuntimeException( (string) $capability['reason'] );
		}

		$account_id      = trim( (string) ( $input['accountId'] ?? '' ) );
		$license_key     = trim( (string) ( $input['licenseKey'] ?? '' ) );
		$current_account = $this->context->get_account_id();
		$current_license = $this->context->get_license_key();

		if ( '' !== $account_id && ! preg_match( '/^[0-9]{1,32}$/', $account_id ) ) {
			throw new \RuntimeException( __( 'MaxMind account ID must contain digits only.', 'peakurl' ) );
		}

		if ( '' !== $license_key && ! preg_match( '/^[A-Za-z0-9_]{6,255}$/', $license_key ) ) {
			throw new \RuntimeException(
				__( 'MaxMind license key must be 6-255 characters and may only use letters, numbers, and underscores.', 'peakurl' ),
			);
		}

		if (
			( '' === $account_id && '' !== $license_key ) ||
			( '' !== $account_id && '' === $license_key )
		) {
			if (
				'' !== $account_id &&
				'' === $license_key &&
				$account_id === $current_account &&
				'' !== $current_license
			) {
				$license_key = $current_license;
			} else {
				throw new \RuntimeException(
					__( 'Enter both the MaxMind account ID and license key, or leave both blank.', 'peakurl' ),
				);
			}
		}

		$crypto_service = $this->context->get_crypto_service();

		if ( '' !== $license_key && ! $crypto_service->is_configured() ) {
			$crypto_service = new Crypto( $this->context->get_config() );
			$crypto_service->persist_auth_keys( $app_path );
			$this->context->set_crypto_service( $crypto_service );
		}

		$updated_at = gmdate( 'Y-m-d H:i:s' );

		$this->context->get_settings_api()->update_option(
			'maxmind_account_id',
			$account_id,
			$updated_at,
			false,
		);
		$this->context->get_settings_api()->update_option(
			'maxmind_license_key_encrypted',
			'' === $license_key ? '' : $crypto_service->encrypt( $license_key ),
			$updated_at,
			false,
		);

		$this->context->set_credentials( $account_id, $license_key );
	}

	/**
	 * Load the active credentials from settings storage.
	 *
	 * @return array{accountId: string, licenseKey: string}
	 * @since 1.0.14
	 */
	private function load_credentials(): array {
		$options = $this->context->get_settings_api()->get_options(
			array(
				'maxmind_account_id',
				'maxmind_license_key_encrypted',
			),
		);

		return array(
			'accountId'  => trim( (string) ( $options['maxmind_account_id'] ?? '' ) ),
			'licenseKey' => trim(
				$this->decrypt_secret_value(
					(string) ( $options['maxmind_license_key_encrypted'] ?? '' ),
				),
			),
		);
	}

	/**
	 * Decrypt a stored secret value.
	 *
	 * @param string $value Stored encrypted value.
	 * @return string
	 * @since 1.0.14
	 */
	private function decrypt_secret_value( string $value ): string {
		try {
			return $this->context->get_crypto_service()->decrypt( $value );
		} catch ( \RuntimeException $exception ) {
			return '';
		}
	}
}
