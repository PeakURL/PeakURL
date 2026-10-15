<?php
/**
 * MaxMind download client helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Http\UserAgent;
use PeakURL\Services\Geoip as GeoipService;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Client — stream the GeoLite2 archive from MaxMind directly to disk with bounded memory.
 *
 * @since 1.0.14
 */
class Client {

	/**
	 * Maximum allowed archive size in bytes (200 MB).
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const MAX_ARCHIVE_BYTES = 209715200;

	/**
	 * Stream chunk size in bytes (64 KB).
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const CHUNK_SIZE = 65536;

	/**
	 * HTTP connection timeout in seconds.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const CONNECT_TIMEOUT_SECONDS = 15;

	/**
	 * HTTP transfer timeout in seconds.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const TIMEOUT_SECONDS = 90;

	/**
	 * Maximum number of redirects to follow.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const MAX_REDIRECTS = 5;

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Underlying bounded HTTP transport.
	 *
	 * @var Transport
	 * @since 1.7.2
	 */
	private Transport $transport;

	/**
	 * Create a new download client.
	 *
	 * @param Context        $context   Shared GeoIP context.
	 * @param Transport|null $transport Optional HTTP transport dependency.
	 * @since 1.0.14
	 */
	public function __construct( Context $context, ?Transport $transport = null ) {
		$this->context   = $context;
		$this->transport = $transport ?? new Transport( $context );
	}

	/**
	 * Configure a loopback test origin for unit test fixtures.
	 *
	 * Only permits loopback origins (127.0.0.1 or localhost) to prevent
	 * arbitrary production origin manipulation.
	 *
	 * @param string|null $origin Loopback base URL (e.g. 'http://127.0.0.1:8080') or null to clear.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a non-loopback origin is provided.
	 * @since 1.7.2
	 */
	public function set_test_origin( ?string $origin ): void {
		$this->transport->set_test_origin( $origin );
	}

	/**
	 * Download the GeoLite2 archive to a local file using stream-to-disk transfers.
	 *
	 * @param string      $archive_path Local archive destination file path.
	 * @param string|null $url          Optional download URL override.
	 * @return void
	 *
	 * @throws \RuntimeException When the download fails, times out, or exceeds size limits.
	 * @since 1.0.14
	 */
	public function download_archive( string $archive_path, ?string $url = null ): void {
		$target_url = ( null !== $url && '' !== trim( $url ) )
			? trim( $url )
			: GeoipService::DOWNLOAD_URL;

		$this->validate_request_url( $target_url, true );

		$this->transport->download_to_file(
			$target_url,
			$archive_path,
			array(
				'driver'                 => 'auto',
				'max_bytes'              => self::MAX_ARCHIVE_BYTES,
				'timeout'                => self::TIMEOUT_SECONDS,
				'connect_timeout'        => self::CONNECT_TIMEOUT_SECONDS,
				'max_redirects'          => self::MAX_REDIRECTS,
				'headers'                => $this->build_headers( $target_url ),
				'validate_url'           => array( $this, 'validate_request_url' ),
				'on_redirect'            => function ( string $target_url ): array {
					return $this->build_headers( $target_url );
				},
				'format_error_message'   => array( $this, 'get_http_error_message' ),
				'sanitize_error_message' => array( $this, 'sanitize_error_message' ),
			)
		);
	}

	/**
	 * Build HTTP request headers for a given URL, applying Basic Auth only to trusted auth hosts.
	 *
	 * @param string $url Target URL.
	 * @return array<int, string>
	 * @since 1.7.2
	 */
	public function build_headers( string $url ): array {
		$headers = array(
			'Accept: application/gzip, application/octet-stream',
			'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
		);

		if ( $this->is_trusted_auth_host( $url ) ) {
			$account_id  = $this->context->get_account_id();
			$license_key = $this->context->get_license_key();
			if ( '' !== $account_id && '' !== $license_key ) {
				$headers[] = 'Authorization: Basic ' . base64_encode( $account_id . ':' . $license_key );
			}
		}

		return $headers;
	}

	/**
	 * Validate that a request URL is well-formed, secure, and targets an authorized origin.
	 *
	 * @param string $url        Target URL.
	 * @param bool   $is_initial True for initial download URL, false for redirect hop.
	 * @return string Validated URL.
	 *
	 * @throws \RuntimeException When the URL fails syntax, protocol, port, or host verification.
	 * @since 1.7.2
	 */
	public function validate_request_url( string $url, bool $is_initial ): string {
		$parts = parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new \RuntimeException(
				__( 'Invalid download URL encountered during GeoLite2 download.', 'peakurl' ),
			);
		}

		// Disallow userinfo in URL syntax.
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			throw new \RuntimeException(
				__( 'Download URLs containing embedded credentials are forbidden.', 'peakurl' ),
			);
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		// Check if this matches an explicitly configured loopback test origin.
		$test_origin = $this->transport->get_test_origin();
		if ( null !== $test_origin ) {
			$test_parts = parse_url( $test_origin );
			$test_host  = strtolower( (string) ( $test_parts['host'] ?? '' ) );
			$test_port  = isset( $test_parts['port'] ) ? (int) $test_parts['port'] : null;

			if ( $host === $test_host && $port === $test_port ) {
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					throw new \RuntimeException(
						__( 'GeoLite2 test downloads require HTTP or HTTPS.', 'peakurl' ),
					);
				}
				return $url;
			}
		}

		// Production enforcement: TLS only, standard port 443 only.
		if ( 'https' !== $scheme ) {
			throw new \RuntimeException(
				__( 'GeoLite2 database downloads require an encrypted HTTPS connection.', 'peakurl' ),
			);
		}

		if ( null !== $port && 443 !== $port ) {
			throw new \RuntimeException(
				__( 'GeoLite2 database downloads require standard HTTPS port 443.', 'peakurl' ),
			);
		}

		if ( $is_initial ) {
			if ( 'download.maxmind.com' !== $host && 'updates.maxmind.com' !== $host ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: unauthorized host */
						__( 'Untrusted download origin rejected: %s.', 'peakurl' ),
						$host,
					),
				);
			}
			return $url;
		}

		// Redirect hop host validation: MaxMind direct hosts or verified MaxMind S3 distribution buckets.
		if ( $this->is_allowlisted_redirect_host( $host ) ) {
			return $url;
		}

		throw new \RuntimeException(
			sprintf(
				/* translators: %s: untrusted host */
				__( 'Untrusted redirect destination rejected: %s.', 'peakurl' ),
				$host,
			),
		);
	}

	/**
	 * Check whether a redirect host belongs to MaxMind or its verified distribution endpoints.
	 *
	 * @param string $host Lowercase hostname.
	 * @return bool
	 * @since 1.7.2
	 */
	public function is_allowlisted_redirect_host( string $host ): bool {
		if ( 'download.maxmind.com' === $host || 'updates.maxmind.com' === $host || str_ends_with( $host, '.maxmind.com' ) ) {
			return true;
		}

		// MaxMind AWS S3 distribution buckets: geoip.maxmind.com.s3.amazonaws.com or geoip.maxmind.com.s3.<region>.amazonaws.com
		if ( preg_match( '/^geoip\.maxmind\.com\.s3(\.[a-z0-9-]+)?\.amazonaws\.com$/', $host ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether a destination URL is authorized to receive MaxMind Basic Auth credentials.
	 *
	 * @param string $url Destination URL.
	 * @return bool
	 * @since 1.7.2
	 */
	public function is_trusted_auth_host( string $url ): bool {
		$parts = parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		$test_origin = $this->transport->get_test_origin();
		if ( null !== $test_origin ) {
			$test_parts = parse_url( $test_origin );
			$test_host  = strtolower( (string) ( $test_parts['host'] ?? '' ) );
			$test_port  = isset( $test_parts['port'] ) ? (int) $test_parts['port'] : null;

			if ( $host === $test_host && $port === $test_port ) {
				$path = (string) ( $parts['path'] ?? '' );
				return 0 !== strpos( $path, '/cdn' ) && 0 !== strpos( $path, '/redirect' );
			}
		}

		return 'download.maxmind.com' === $host || 'updates.maxmind.com' === $host;
	}

	/**
	 * Build a human-readable download HTTP error message.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_http_error_message( int $status ): string {
		if ( 401 === $status || 403 === $status ) {
			return __( 'MaxMind rejected the download request. Check the account ID, license key, and GeoLite download permissions.', 'peakurl' );
		}

		if ( 429 === $status ) {
			return __( 'MaxMind download rate limit exceeded. Please wait before retrying.', 'peakurl' );
		}

		if ( $status >= 500 && $status < 600 ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'MaxMind download service temporarily unavailable (HTTP %d). Please try again later.', 'peakurl' ),
				$status,
			);
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

	/**
	 * Strip any sensitive credential material from an error message.
	 *
	 * @param string $message Raw error message.
	 * @return string Sanitized error message.
	 * @since 1.7.2
	 */
	public function sanitize_error_message( string $message ): string {
		$account_id  = $this->context->get_account_id();
		$license_key = $this->context->get_license_key();

		$sanitized = $message;

		if ( '' !== $license_key ) {
			$sanitized = str_replace( $license_key, '••••••••', $sanitized );
		}

		if ( '' !== $account_id ) {
			$sanitized = str_replace( $account_id, '••••••••', $sanitized );
		}

		return $sanitized;
	}
}
