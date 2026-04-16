<?php
/**
 * Shared GeoIP service context.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;
use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;
use PeakURL\Services\Crypto;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Context — shared state and dependencies for GeoIP services.
 *
 * @since 1.0.14
 */
class Context {

	/**
	 * Runtime configuration values.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Settings API dependency.
	 *
	 * @var SettingsApi
	 * @since 1.0.14
	 */
	private SettingsApi $settings_api;

	/**
	 * Crypto helper dependency.
	 *
	 * @var Crypto
	 * @since 1.0.14
	 */
	private Crypto $crypto_service;

	/**
	 * Persistent content directory.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $content_directory;

	/**
	 * Local GeoLite2 database path.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $database_path;

	/**
	 * Current PeakURL version.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $peakurl_version;

	/**
	 * Configured MaxMind account ID.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $account_id = '';

	/**
	 * Configured MaxMind license key.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $license_key = '';

	/**
	 * Cached MaxMind reader instance.
	 *
	 * @var Reader|null
	 * @since 1.0.14
	 */
	private ?Reader $reader = null;

	/**
	 * Whether the reader has already been initialized for this request.
	 *
	 * @var bool
	 * @since 1.0.14
	 */
	private bool $reader_initialized = false;

	/**
	 * Create a new GeoIP context.
	 *
	 * @param array<string, mixed> $config         Runtime configuration.
	 * @param SettingsApi          $settings_api   Settings API dependency.
	 * @param Crypto               $crypto_service Crypto helper dependency.
	 * @since 1.0.14
	 */
	public function __construct(
		array $config,
		SettingsApi $settings_api,
		Crypto $crypto_service
	) {
		$this->config            = $config;
		$this->settings_api      = $settings_api;
		$this->crypto_service    = $crypto_service;
		$this->content_directory = trim(
			(string) ( $config['PEAKURL_CONTENT_DIR'] ?? ABSPATH . 'content' ),
		);
		$this->database_path     = trim(
			(string) ( $config['PEAKURL_GEOIP_DB_PATH'] ?? '' ),
		);
		$this->peakurl_version   = trim(
			(string) ( $config[ Constants::CONFIG_VERSION ] ?? Constants::DEFAULT_VERSION ),
		);
	}

	/**
	 * Return the runtime configuration map.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Return the settings API dependency.
	 *
	 * @return SettingsApi
	 * @since 1.0.14
	 */
	public function get_settings_api(): SettingsApi {
		return $this->settings_api;
	}

	/**
	 * Return the crypto helper dependency.
	 *
	 * @return Crypto
	 * @since 1.0.14
	 */
	public function get_crypto_service(): Crypto {
		return $this->crypto_service;
	}

	/**
	 * Replace the crypto helper dependency.
	 *
	 * @param Crypto $crypto_service Fresh crypto helper.
	 * @return void
	 * @since 1.0.14
	 */
	public function set_crypto_service( Crypto $crypto_service ): void {
		$this->crypto_service = $crypto_service;
	}

	/**
	 * Return the persistent content directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_content_directory(): string {
		return $this->content_directory;
	}

	/**
	 * Return the configured database path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_database_path(): string {
		return $this->database_path;
	}

	/**
	 * Return the current PeakURL version.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_peakurl_version(): string {
		return $this->peakurl_version;
	}

	/**
	 * Return the current MaxMind account ID.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_account_id(): string {
		return $this->account_id;
	}

	/**
	 * Return the current MaxMind license key.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_license_key(): string {
		return $this->license_key;
	}

	/**
	 * Update the active MaxMind credentials.
	 *
	 * @param string $account_id  MaxMind account ID.
	 * @param string $license_key MaxMind license key.
	 * @return void
	 * @since 1.0.14
	 */
	public function set_credentials( string $account_id, string $license_key ): void {
		$this->account_id  = trim( $account_id );
		$this->license_key = trim( $license_key );
	}

	/**
	 * Return the cached MaxMind reader.
	 *
	 * @return Reader|null
	 * @since 1.0.14
	 */
	public function get_reader(): ?Reader {
		return $this->reader;
	}

	/**
	 * Store the cached MaxMind reader.
	 *
	 * @param Reader|null $reader Reader instance.
	 * @return void
	 * @since 1.0.14
	 */
	public function set_reader( ?Reader $reader ): void {
		$this->reader = $reader;
	}

	/**
	 * Check whether reader initialization has already been attempted.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_reader_initialized(): bool {
		return $this->reader_initialized;
	}

	/**
	 * Mark the reader as initialized or reset.
	 *
	 * @param bool $reader_initialized Reader initialization state.
	 * @return void
	 * @since 1.0.14
	 */
	public function set_reader_initialized( bool $reader_initialized ): void {
		$this->reader_initialized = $reader_initialized;
	}

	/**
	 * Clear the cached reader state.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function reset_reader(): void {
		$this->close_reader();
		$this->reader_initialized = false;
	}

	/**
	 * Close the active reader, if any.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function close_reader(): void {
		if ( null !== $this->reader ) {
			$this->reader->close();
		}

		$this->reader = null;
	}
}
