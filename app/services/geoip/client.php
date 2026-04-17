<?php
/**
 * MaxMind download client helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Services\Geoip as GeoipService;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Client — download the GeoLite2 archive from MaxMind.
 *
 * @since 1.0.14
 */
class Client {

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new download client.
	 *
	 * @param Context $context Shared GeoIP context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Download the GeoLite2 archive to a local file.
	 *
	 * @param string $archive_path Local archive file path.
	 * @return void
	 *
	 * @throws \RuntimeException When the request fails.
	 * @since 1.0.14
	 */
	public function download_archive( string $archive_path ): void {
		if ( function_exists( 'curl_init' ) ) {
			$this->download_with_curl( $archive_path );
			return;
		}

		$this->download_with_streams( $archive_path );
	}

	/**
	 * Download the GeoLite2 archive through cURL.
	 *
	 * @param string $archive_path Local archive file path.
	 * @return void
	 *
	 * @throws \RuntimeException When the request fails.
	 * @since 1.0.14
	 */
	private function download_with_curl( string $archive_path ): void {
		$handle = curl_init( GeoipService::DOWNLOAD_URL );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'PeakURL could not initialize cURL for the GeoLite2 download.', 'peakurl' ) );
		}

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 10,
				CURLOPT_TIMEOUT        => 90,
				CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
				CURLOPT_USERPWD        => $this->context->get_account_id() . ':' . $this->context->get_license_key(),
				CURLOPT_HTTPHEADER     => array(
					'Accept: application/gzip, application/octet-stream',
					'User-Agent: PeakURL/' . $this->context->get_version(),
				),
			),
		);

		$body       = curl_exec( $handle );
		$status     = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$curl_error = curl_error( $handle );
		unset( $handle );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not download the GeoLite2 City database from MaxMind. ', 'peakurl' ) . $curl_error,
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( $this->build_http_error_message( $status ) );
		}

		if ( false === file_put_contents( $archive_path, $body, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
			);
		}
	}

	/**
	 * Download the GeoLite2 archive through PHP streams.
	 *
	 * @param string $archive_path Local archive file path.
	 * @return void
	 *
	 * @throws \RuntimeException When the request fails.
	 * @since 1.0.14
	 */
	private function download_with_streams( string $archive_path ): void {
		$headers = array(
			'Accept: application/gzip, application/octet-stream',
			'Authorization: Basic ' . base64_encode(
				$this->context->get_account_id() . ':' . $this->context->get_license_key(),
			),
			'User-Agent: PeakURL/' . $this->context->get_version(),
		);
		$result  = $this->send_stream_request(
			GeoipService::DOWNLOAD_URL,
			$headers,
			false,
		);

		if ( $result['status'] >= 300 && $result['status'] < 400 ) {
			$redirect_url = $this->get_redirect_url( $result['headers'] );

			if ( null === $redirect_url ) {
				throw new \RuntimeException(
					__( 'PeakURL could not follow the GeoLite2 download redirect from MaxMind.', 'peakurl' ),
				);
			}

			$result = $this->send_stream_request(
				$redirect_url,
				array(
					'Accept: application/gzip, application/octet-stream',
					'User-Agent: PeakURL/' . $this->context->get_version(),
				),
				false,
			);
		}

		if ( false === $result['body'] || $result['status'] < 200 || $result['status'] >= 300 ) {
			throw new \RuntimeException( $this->build_http_error_message( $result['status'] ) );
		}

		if ( false === file_put_contents( $archive_path, $result['body'], LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
			);
		}
	}

	/**
	 * Execute a single stream-wrapper request.
	 *
	 * @param string             $url             Remote URL.
	 * @param array<int, string> $headers         Request headers.
	 * @param bool               $follow_location Whether redirects should be followed.
	 * @return array{body: string|false, headers: array<int, string>, status: int}
	 * @since 1.0.14
	 */
	private function send_stream_request(
		string $url,
		array $headers,
		bool $follow_location
	): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => implode( "\r\n", $headers ) . "\r\n",
					'ignore_errors'   => true,
					'follow_location' => $follow_location ? 1 : 0,
					'max_redirects'   => $follow_location ? 10 : 0,
					'timeout'         => 90,
				),
			),
		);
		$body    = @file_get_contents( $url, false, $context );
		$headers = $http_response_header ?? array();

		return array(
			'body'    => $body,
			'headers' => $headers,
			'status'  => $this->parse_http_status( $headers ),
		);
	}

	/**
	 * Parse the final HTTP status code from response headers.
	 *
	 * @param array<int, string> $headers Wrapper response headers.
	 * @return int
	 * @since 1.0.14
	 */
	private function parse_http_status( array $headers ): int {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( preg_match( '/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return 0;
	}

	/**
	 * Extract the redirect URL from response headers.
	 *
	 * @param array<int, string> $headers Wrapper response headers.
	 * @return string|null
	 * @since 1.0.14
	 */
	private function get_redirect_url( array $headers ): ?string {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( 0 !== stripos( $header, 'Location:' ) ) {
				continue;
			}

			return trim( substr( $header, 9 ) );
		}

		return null;
	}

	/**
	 * Build a human-readable download HTTP error message.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 * @since 1.0.14
	 */
	private function build_http_error_message( int $status ): string {
		if ( 401 === $status || 403 === $status ) {
			return __( 'MaxMind rejected the download request. Check the account ID, license key, and GeoLite download permissions.', 'peakurl' );
		}

		if ( 0 === $status ) {
			return __( 'PeakURL could not download the GeoLite2 City database from MaxMind.', 'peakurl' );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'PeakURL could not download the GeoLite2 City database from MaxMind. HTTP %d.', 'peakurl' ),
			$status,
		);
	}
}
