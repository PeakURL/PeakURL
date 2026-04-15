<?php
/**
 * Dashboard updater manifest helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Manifest — remote update manifest loading and normalization.
 *
 * @since 1.0.14
 */
class Manifest {

	/** @var int Cache time-to-live for the update manifest (12 hours). */
	private const CACHE_TTL = 43200;

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Shared updater HTTP client.
	 *
	 * @var Client
	 * @since 1.0.14
	 */
	private Client $client;

	/**
	 * Create a new update manifest helper.
	 *
	 * @param Context $context Shared updater context helper.
	 * @param Client  $client  Shared updater HTTP client.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Client $client
	) {
		$this->context = $context;
		$this->client  = $client;
	}

	/**
	 * Get the resolved update manifest URL from config.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the URL is not a valid HTTPS endpoint.
	 * @since 1.0.14
	 */
	public function get_manifest_url(): string {
		$manifest_url = trim(
			(string) $this->context->get_config_value(
				Constants::CONFIG_UPDATE_MANIFEST_URL,
				'',
			),
		);

		if ( '' === $manifest_url ) {
			return $this->client->assert_https_url(
				Constants::DEFAULT_UPDATE_MANIFEST_URL,
				__( 'update manifest URL', 'peakurl' ),
			);
		}

		return $this->client->assert_https_url(
			$manifest_url,
			__( 'update manifest URL', 'peakurl' ),
		);
	}

	/**
	 * Check whether the cached manifest is still fresh.
	 *
	 * @param string|null $last_checked_at ISO/MySQL datetime of last check.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_cache_fresh( ?string $last_checked_at ): bool {
		if ( empty( $last_checked_at ) ) {
			return false;
		}

		$timestamp = strtotime( $last_checked_at . ' UTC' );

		if ( false === $timestamp ) {
			return false;
		}

		return $timestamp >= ( time() - self::CACHE_TTL );
	}

	/**
	 * Fetch and normalise the remote update manifest.
	 *
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException On HTTP failure or invalid JSON.
	 * @since 1.0.14
	 */
	public function fetch_manifest(): array {
		$body    = $this->client->get(
			$this->get_manifest_url(),
			'application/json',
		);
		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			throw new \RuntimeException(
				__( 'The update manifest returned invalid JSON.', 'peakurl' ),
			);
		}

		return $this->normalize_manifest( $payload );
	}

	/**
	 * Normalise a raw manifest payload into a consistent structure.
	 *
	 * Supports both flat manifests and nested `offers[0]` formats.
	 *
	 * @param array<string, mixed> $payload Raw manifest JSON.
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException When version or package URL is missing.
	 * @since 1.0.14
	 */
	private function normalize_manifest( array $payload ): array {
		$source = $payload;

		if (
			isset( $payload['offers'] ) &&
			is_array( $payload['offers'] ) &&
			isset( $payload['offers'][0] ) &&
			is_array( $payload['offers'][0] )
		) {
			$source = $payload['offers'][0];
		}

		$version           = trim(
			(string) ( $source['version'] ?? $source['current'] ?? '' ),
		);
		$package_url       = trim(
			(string) ( $source['packageUrl'] ?? $source['package'] ?? '' ),
		);
		$download_url      = trim(
			(string) ( $source['downloadUrl'] ?? $source['download'] ?? '' ),
		);
		$checksum          = strtolower(
			trim(
				(string) ( $source['checksumSha256'] ?? $source['checksum_sha256'] ?? '' ),
			),
		);
		$release_notes_url = trim(
			(string) ( $source['releaseNotesUrl'] ?? '' ),
		);

		if ( '' === $package_url ) {
			$package_url = $download_url;
		}

		if ( '' === $download_url ) {
			$download_url = $package_url;
		}

		if ( '' === $version ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a version.', 'peakurl' ),
			);
		}

		if ( '' === $package_url ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a package URL.', 'peakurl' ),
			);
		}

		if ( '' === $checksum ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a SHA-256 checksum.', 'peakurl' ),
			);
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			throw new \RuntimeException(
				__( 'The update manifest included an invalid SHA-256 checksum.', 'peakurl' ),
			);
		}

		$package_url  = $this->client->assert_https_url(
			$package_url,
			__( 'release package URL', 'peakurl' ),
		);
		$download_url = $this->client->assert_https_url(
			$download_url,
			__( 'release download URL', 'peakurl' ),
		);

		return array(
			'product'         => trim(
				(string) ( $payload['product'] ?? $source['product'] ?? 'peakurl' ),
			),
			'channel'         => trim(
				(string) ( $payload['channel'] ?? $source['channel'] ?? 'latest' ),
			),
			'version'         => $version,
			'downloadUrl'     => $download_url,
			'packageUrl'      => $package_url,
			'checksumSha256'  => $checksum,
			'releaseNotesUrl' => $release_notes_url,
			'releasedAt'      => trim(
				(string) ( $source['releasedAt'] ?? $source['date'] ?? '' ),
			),
			'minimumPhp'      => trim(
				(string) ( $source['minimumPhp'] ?? $source['minimum_php'] ?? '' ),
			),
			'minimumMysql'    => trim(
				(string) ( $source['minimumMysql'] ?? $source['minimum_mysql'] ?? '' ),
			),
			'minimumMariaDb'  => trim(
				(string) ( $source['minimumMariaDb'] ?? $source['minimum_mariadb'] ?? '' ),
			),
		);
	}
}
