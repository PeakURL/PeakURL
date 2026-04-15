<?php
/**
 * Dashboard updater HTTP client helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Client — remote updater HTTP and URL validation helpers.
 *
 * Keeps manifest and package fetch logic behind one focused transport layer.
 *
 * @since 1.0.14
 */
class Client {

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new updater client helper.
	 *
	 * @param Context $context Shared updater context helper.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Perform an HTTP GET request using the best available transport.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 * @since 1.0.14
	 */
	public function get( string $url, string $accept ): string {
		$this->assert_https_url(
			$url,
			__( 'remote update URL', 'peakurl' ),
		);

		if ( function_exists( 'curl_init' ) ) {
			return $this->get_with_curl( $url, $accept );
		}

		return $this->get_with_stream( $url, $accept );
	}

	/**
	 * Validate that an updater URL is a well-formed HTTPS endpoint.
	 *
	 * @param string $url   Candidate absolute URL.
	 * @param string $label Human-readable field label.
	 * @return string
	 *
	 * @throws \RuntimeException When the URL is invalid or not HTTPS.
	 * @since 1.0.14
	 */
	public function assert_https_url( string $url, string $label ): string {
		$url   = trim( $url );
		$parts = parse_url( $url );

		if (
			'' === $url ||
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] )
		) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: manifest field label. */
					__( 'The %s is not a valid absolute URL.', 'peakurl' ),
					$label,
				),
			);
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: manifest field label. */
					__( 'The %s must use HTTPS.', 'peakurl' ),
					$label,
				),
			);
		}

		return $url;
	}

	/**
	 * HTTP GET via cURL.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 *
	 * @throws \RuntimeException On cURL failure or non-2xx status.
	 * @since 1.0.14
	 */
	private function get_with_curl( string $url, string $accept ): string {
		$curl = curl_init( $url );

		if ( false === $curl ) {
			throw new \RuntimeException(
				__( 'PeakURL could not start the update request.', 'peakurl' ),
			);
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => array(
					'Accept: ' . $accept,
					'User-Agent: ' . $this->build_user_agent(),
				),
			),
		);

		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
		}

		if ( defined( 'CURLOPT_REDIR_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS );
		}

		$body       = curl_exec( $curl );
		$status     = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$curl_error = curl_error( $curl );
		unset( $curl );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not contact the update service. ', 'peakurl' ) . $curl_error,
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The update service returned HTTP %d.', 'peakurl' ),
					$status,
				),
			);
		}

		return (string) $body;
	}

	/**
	 * HTTP GET via PHP streams.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 *
	 * @throws \RuntimeException On stream failure or non-2xx status.
	 * @since 1.0.14
	 */
	private function get_with_stream( string $url, string $accept ): string {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 30,
					'ignore_errors' => true,
					'header'        => implode(
						"\r\n",
						array(
							'Accept: ' . $accept,
							'User-Agent: ' . $this->build_user_agent(),
						),
					),
				),
				'ssl'  => array(
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'allow_self_signed' => false,
				),
			),
		);
		$stream  = fopen( $url, 'rb', false, $context );

		if ( false === $stream ) {
			throw new \RuntimeException(
				__( 'PeakURL could not contact the update service.', 'peakurl' ),
			);
		}

		$body            = stream_get_contents( $stream );
		$stream_metadata = stream_get_meta_data( $stream );
		fclose( $stream );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the update service response.', 'peakurl' ),
			);
		}

		$status_line      = '';
		$response_headers = array();

		if (
			isset( $stream_metadata['wrapper_data'] ) &&
			is_array( $stream_metadata['wrapper_data'] )
		) {
			$response_headers = $stream_metadata['wrapper_data'];
		}

		if ( isset( $response_headers[0] ) ) {
			$status_line = (string) $response_headers[0];
		}

		if ( ! preg_match( '/\s(\d{3})\s/', $status_line, $matches ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the update service response.', 'peakurl' ),
			);
		}

		$status = (int) $matches[1];

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The update service returned HTTP %d.', 'peakurl' ),
					$status,
				),
			);
		}

		return (string) $body;
	}

	/**
	 * Build the User-Agent header for update requests.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function build_user_agent(): string {
		return sprintf(
			'PeakURL/%s; %s',
			$this->context->get_current_version(),
			$this->context->get_site_url(),
		);
	}
}
