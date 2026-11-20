<?php
/**
 * Downloads verified releases from the PeakURL plugin directory.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

use PeakURL\Core\Errors\ApiException;
use PeakURL\Http\UserAgent;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Client for the public plugin directory release API.
 */
class Directory {

	private const string API_URL         = 'https://api.peakurl.org/v1/plugins';
	private const int MAX_METADATA_BYTES = 16 * 1024;
	private const int MAX_PACKAGE_BYTES  = 25 * 1024 * 1024;

	/**
	 * Validate a slug assigned by the public directory.
	 *
	 * @param string $slug Directory slug.
	 * @return bool Whether the slug is valid.
	 */
	public static function is_valid_slug( string $slug ): bool {
		return 1 === preg_match( '/\A[a-z0-9][a-z0-9-]{0,79}\z/', $slug );
	}

	/**
	 * Fetch a published release and verify its archive before installation.
	 *
	 * @param string   $slug         Directory slug.
	 * @param string   $version      Requested release version.
	 * @param resource $archive      Temporary archive stream.
	 * @param string   $host_version PeakURL version for the user agent.
	 * @throws ApiException When the release cannot be downloaded or verified.
	 */
	public function download_release( string $slug, string $version, $archive, string $host_version ): void {
		if ( ! self::is_valid_slug( $slug ) || strlen( $version ) > 100 || ! preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version ) ) {
			throw new ApiException( __( 'A valid plugin slug and release version are required.', 'peakurl' ), 400 );
		}

		$release_url = self::API_URL . "/{$slug}/releases/{$version}";
		$metadata    = fopen( 'php://temp', 'w+b' );
		try {
			$this->download( $release_url, 'application/json', $metadata, self::MAX_METADATA_BYTES, $host_version );
			rewind( $metadata );
			$response = json_decode( (string) stream_get_contents( $metadata ), true );
		} finally {
			fclose( $metadata );
		}

		$release  = $response['data'] ?? null;
		$checksum = $release['package']['sha256'] ?? null;
		$size     = $release['package']['packageSizeBytes'] ?? null;
		if (
			( $release['version'] ?? null ) !== $version ||
			! is_string( $checksum ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $checksum ) ||
			! is_int( $size ) || $size < 1 || $size > self::MAX_PACKAGE_BYTES
		) {
			throw new ApiException( __( 'The directory did not provide a verified package for this release.', 'peakurl' ), 502 );
		}

		// The versioned download endpoint is fixed to the official API origin.
		$this->download( $release_url . '/download', 'application/zip', $archive, $size, $host_version );
		rewind( $archive );
		$hash       = hash_init( 'sha256' );
		$bytes_read = hash_update_stream( $hash, $archive );
		if ( $size !== $bytes_read || ! hash_equals( $checksum, hash_final( $hash ) ) ) {
			throw new ApiException( __( 'The downloaded plugin package failed verification.', 'peakurl' ), 502 );
		}
	}

	/**
	 * Stream a directory response to disk or memory within a fixed size bound.
	 *
	 * @param string   $url          Directory API URL.
	 * @param string   $accept       Expected response type.
	 * @param resource $destination Destination stream.
	 * @param int      $max_bytes   Maximum response size.
	 * @param string   $host_version PeakURL version for the user agent.
	 * @throws ApiException When the response is unavailable.
	 */
	protected function download( string $url, string $accept, $destination, int $max_bytes, string $host_version ): void {
		$curl = curl_init( $url );
		if ( false === $curl ) {
			throw new ApiException( __( 'Could not start the plugin directory request.', 'peakurl' ), 502 );
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_HTTPHEADER     => array( 'Accept: ' . $accept ),
				CURLOPT_USERAGENT      => UserAgent::format( $host_version ),
				CURLOPT_WRITEFUNCTION  => static function ( \CurlHandle $handle, string $chunk ) use ( $destination, &$max_bytes ): int {
					$length = strlen( $chunk );
					if ( $length > $max_bytes ) {
						return 0;
					}
					$max_bytes -= $length;
					return (int) fwrite( $destination, $chunk );
				},
			)
		);

		$result = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		if ( 404 === $status ) {
			throw new ApiException( __( 'This plugin release is no longer available in the directory.', 'peakurl' ), 404 );
		}
		if ( false === $result || 200 !== $status ) {
			throw new ApiException( __( 'The plugin directory could not be reached or returned an invalid response.', 'peakurl' ), 502 );
		}
	}
}
