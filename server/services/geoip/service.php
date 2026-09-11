<?php
/**
 * MaxMind GeoLite2 location services.
 *
 * Resolves click locations from a local MaxMind database and manages
 * the optional GeoLite2 City download flow for self-hosted installs.
 *
 * @package PeakURL\Services
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Api\SettingsApi;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip\Client;
use PeakURL\Services\Geoip\Context;
use PeakURL\Services\Geoip\Credentials;
use PeakURL\Services\Geoip\Downloader;
use PeakURL\Services\Geoip\Filesystem;
use PeakURL\Services\Geoip\Lookup;
use PeakURL\Services\Geoip\Status;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Geoip — public GeoIP service facade for lookup, status, and downloads.
 *
 * @since 1.0.14
 */
class Geoip {

	/**
	 * MaxMind direct-download permalink for GeoLite2 City binary data.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	public const DOWNLOAD_URL =
		'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Credentials helper.
	 *
	 * @var Credentials
	 * @since 1.0.14
	 */
	private Credentials $credentials;

	/**
	 * Lookup helper.
	 *
	 * @var Lookup
	 * @since 1.0.14
	 */
	private Lookup $lookup;

	/**
	 * Status helper.
	 *
	 * @var Status
	 * @since 1.0.14
	 */
	private Status $status;

	/**
	 * Downloader helper.
	 *
	 * @var Downloader
	 * @since 1.0.14
	 */
	private Downloader $downloader;

	/**
	 * Create a new GeoIP service facade.
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
		$this->context     = new Context( $config, $settings_api, $crypto_service );
		$this->credentials = new Credentials( $this->context );
		$this->credentials->refresh();
		$this->lookup     = new Lookup( $this->context );
		$this->status     = new Status( $this->context, $this->credentials );
		$this->downloader = new Downloader(
			$this->context,
			$this->credentials,
			new Client( $this->context ),
			new Filesystem(),
		);
	}

	/**
	 * Resolve location data for a visitor IP address.
	 *
	 * @param string $ip_address Visitor IP address.
	 * @return array<string, string|null>
	 * @since 1.0.14
	 */
	public function lookup_location( string $ip_address = '' ): array {
		return $this->lookup->lookup_location( $ip_address );
	}

	/**
	 * Build the current GeoIP integration status.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_status(): array {
		return $this->status->get_status();
	}

	/**
	 * Validate and persist MaxMind credentials.
	 *
	 * @param string               $app_path Absolute app directory path.
	 * @param array<string, mixed> $input    Submitted settings payload.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the credentials are invalid.
	 * @since 1.0.14
	 */
	public function save_credentials( string $app_path, array $input ): array {
		$this->credentials->save( $app_path, $input );
		$this->credentials->refresh();

		return $this->get_status();
	}

	/**
	 * Download or refresh the GeoLite2 City database.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the download fails.
	 * @since 1.0.14
	 */
	public function download_database(): array {
		$this->downloader->download_database();

		return $this->get_status();
	}

	/**
	 * Close the active MaxMind reader when the service is destroyed.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function __destruct() {
		$this->context->close_reader();
	}
}
