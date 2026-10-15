<?php
/**
 * Shared HTTP transport for GeoIP downloads and manifest requests.
 *
 * Provides bounded stream-to-disk and stream-to-memory transfers using cURL
 * with PHP stream wrapper fallback, TLS enforcement, and timeout safety.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Http\UserAgent;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Transport — unified bounded HTTP client for GeoIP operations.
 *
 * @since 1.7.2
 */
class Transport {

	/**
	 * Stream chunk size in bytes (64 KB).
	 *
	 * @var int
	 */
	public const CHUNK_SIZE = 65536;

	/**
	 * Default HTTP connection timeout in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_CONNECT_TIMEOUT = 15;

	/**
	 * Default HTTP transfer timeout in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_TIMEOUT = 90;

	/**
	 * Maximum captured error body size in bytes.
	 *
	 * @var int
	 */
	public const MAX_ERROR_BODY_BYTES = 1024;

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Permitted loopback test origin for unit test fixtures.
	 *
	 * @var string|null
	 */
	private ?string $test_origin = null;

	/**
	 * Create a new GeoIP HTTP transport.
	 *
	 * @param Context $context Shared GeoIP context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
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
	 */
	public function set_test_origin( ?string $origin ): void {
		if ( null === $origin ) {
			$this->test_origin = null;
			return;
		}

		$scheme = strtolower( (string) parse_url( $origin, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $origin, PHP_URL_HOST ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
			throw new \InvalidArgumentException( 'Test origin must be a loopback HTTP or HTTPS endpoint.' );
		}

		$this->test_origin = rtrim( $origin, '/' );
	}

	/**
	 * Get the configured test origin, if any.
	 *
	 * @return string|null
	 */
	public function get_test_origin(): ?string {
		return $this->test_origin;
	}

	/**
	 * Stream an HTTP resource directly to a local destination file.
	 *
	 * @param string               $initial_url Download URL.
	 * @param string               $dest_path   Destination file path.
	 * @param array<string, mixed> $options     Download configuration options.
	 * @return int Total bytes written to disk.
	 *
	 * @throws \RuntimeException On network failure, HTTP error, timeout, or size boundary violation.
	 */
	public function download_to_file(
		string $initial_url,
		string $dest_path,
		array $options = array()
	): int {
		$driver = $options['driver'] ?? 'auto';

		if ( 'stream' === $driver ) {
			return $this->download_stream( $initial_url, $dest_path, $options );
		}

		if ( 'curl' === $driver || function_exists( 'curl_init' ) ) {
			return $this->download_curl( $initial_url, $dest_path, $options );
		}

		return $this->download_stream( $initial_url, $dest_path, $options );
	}

	/**
	 * Fetch a small HTTP resource directly into memory as a string.
	 *
	 * @param string               $url     Target URL.
	 * @param array<string, mixed> $options Request options.
	 * @return string Response body.
	 *
	 * @throws \RuntimeException On request failure or size limit violation.
	 */
	public function fetch_string( string $url, array $options = array() ): string {
		$max_bytes       = (int) ( $options['max_bytes'] ?? self::CHUNK_SIZE );
		$timeout         = (int) ( $options['timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT );
		$connect_timeout = (int) ( $options['connect_timeout'] ?? 10 );
		$validate_url    = isset( $options['validate_url'] ) && is_callable( $options['validate_url'] )
			? $options['validate_url']
			: null;

		$validated_url = null !== $validate_url
			? (string) call_user_func( $validate_url, $url, true )
			: $url;

		$headers = is_array( $options['headers'] ?? null )
			? $options['headers']
			: array(
				'Accept: application/json',
				'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
			);

		if ( function_exists( 'curl_init' ) ) {
			return $this->fetch_string_curl( $validated_url, $headers, $max_bytes, $timeout, $connect_timeout );
		}

		return $this->fetch_string_stream( $validated_url, $headers, $max_bytes, $timeout );
	}

	/**
	 * Persist a chunk of data completely to disk, handling partial writes.
	 *
	 * @param resource $fp    Open file handle.
	 * @param string   $chunk Data bytes.
	 * @return bool True if all bytes written, false on unrecoverable write error.
	 */
	public function write_chunk( $fp, string $chunk ): bool {
		$length  = strlen( $chunk );
		$written = 0;

		while ( $written < $length ) {
			$bytes = fwrite( $fp, substr( $chunk, $written ) );
			if ( false === $bytes || 0 === $bytes ) {
				return false;
			}
			$written += $bytes;
		}

		return true;
	}

	/**
	 * Stream an HTTP download to disk using cURL.
	 *
	 * @param string               $initial_url Initial URL.
	 * @param string               $dest_path   Destination file path.
	 * @param array<string, mixed> $options     Download options.
	 * @return int Total bytes written.
	 *
	 * @throws \RuntimeException On cURL error or validation failure.
	 */
	private function download_curl(
		string $initial_url,
		string $dest_path,
		array $options
	): int {
		$max_bytes       = (int) ( $options['max_bytes'] ?? 209715200 );
		$timeout         = (int) ( $options['timeout'] ?? self::DEFAULT_TIMEOUT );
		$connect_timeout = (int) ( $options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT );
		$max_redirects   = (int) ( $options['max_redirects'] ?? 0 );
		$validate_url    = isset( $options['validate_url'] ) && is_callable( $options['validate_url'] )
			? $options['validate_url']
			: null;
		$on_redirect     = isset( $options['on_redirect'] ) && is_callable( $options['on_redirect'] )
			? $options['on_redirect']
			: null;
		$format_error    = isset( $options['format_error_message'] ) && is_callable( $options['format_error_message'] )
			? $options['format_error_message']
			: null;
		$sanitize_error  = isset( $options['sanitize_error_message'] ) && is_callable( $options['sanitize_error_message'] )
			? $options['sanitize_error_message']
			: null;

		$current_url    = $initial_url;
		$redirect_count = 0;
		$part_path      = $dest_path . '.part-' . bin2hex( random_bytes( 4 ) );
		$download_done  = false;
		$total_written  = 0;

		try {
			while ( $redirect_count <= $max_redirects ) {
				$validated_url = null !== $validate_url
					? (string) call_user_func( $validate_url, $current_url, 0 === $redirect_count )
					: $current_url;

				$redirect_target = null;
				$content_length  = null;
				$status_code     = 0;
				$error_body      = '';
				$bytes_written   = 0;
				$size_exceeded   = false;
				$write_failed    = false;

				$dest_fp = null;
				$handle  = curl_init( $validated_url );

				if ( false === $handle ) {
					throw new \RuntimeException(
						__( 'PeakURL could not initialize cURL for the GeoIP download.', 'peakurl' ),
					);
				}

				$headers = is_array( $options['headers'] ?? null )
					? $options['headers']
					: array(
						'Accept: application/gzip, application/octet-stream',
						'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
					);

				if ( null !== $on_redirect && $redirect_count > 0 ) {
					$custom_headers = call_user_func( $on_redirect, $validated_url, $current_url, $status_code );
					if ( is_array( $custom_headers ) ) {
						$headers = $custom_headers;
					}
				}

				$curl_options = array(
					CURLOPT_HTTPGET        => true,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_CONNECTTIMEOUT => $connect_timeout,
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_HEADERFUNCTION => function ( $ch, string $line ) use ( &$redirect_target, &$content_length, $current_url ): int {
						if ( 0 === stripos( $line, 'Location:' ) ) {
							$redirect_target = $this->resolve_redirect_url( trim( substr( $line, 9 ) ), $current_url );
						} elseif ( 0 === stripos( $line, 'Content-Length:' ) ) {
							$content_length = (int) trim( substr( $line, 15 ) );
						}
						return strlen( $line );
					},
					CURLOPT_WRITEFUNCTION  => function (
						$ch,
						string $chunk
					) use (
						&$dest_fp,
						&$bytes_written,
						&$error_body,
						&$size_exceeded,
						&$write_failed,
						$part_path,
						$max_bytes
					): int {
						$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

						if ( $status >= 300 && $status < 400 ) {
							return strlen( $chunk );
						}

						if ( $status >= 400 ) {
							if ( strlen( $error_body ) < self::MAX_ERROR_BODY_BYTES ) {
								$error_body .= substr( $chunk, 0, self::MAX_ERROR_BODY_BYTES - strlen( $error_body ) );
							}
							return strlen( $chunk );
						}

						if ( null === $dest_fp ) {
							$dest_fp = @fopen( $part_path, 'wb' );
							if ( false === $dest_fp ) {
								$write_failed = true;
								return 0;
							}
						}

						$chunk_len = strlen( $chunk );
						if ( ( $bytes_written + $chunk_len ) > $max_bytes ) {
							$size_exceeded = true;
							return 0;
						}

						if ( ! $this->write_chunk( $dest_fp, $chunk ) ) {
							$write_failed = true;
							return 0;
						}

						$bytes_written += $chunk_len;
						return $chunk_len;
					},
				);

				curl_setopt_array( $handle, $curl_options );
				$success = curl_exec( $handle );
				$errno   = curl_errno( $handle );
				$error   = curl_error( $handle );
				if ( $handle ) {
					$status_code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
				}
				if ( PHP_VERSION_ID < 80500 ) {
					curl_close( $handle );
				}
				unset( $handle );

				if ( is_resource( $dest_fp ) ) {
					fclose( $dest_fp );
					$dest_fp = null;
				}

				if ( in_array( $status_code, array( 301, 302, 303, 307, 308 ), true ) ) {
					if ( empty( $redirect_target ) ) {
						throw new \RuntimeException(
							__( 'Could not follow redirect (missing Location header).', 'peakurl' ),
						);
					}
					$current_url = $redirect_target;
					++$redirect_count;
					if ( $redirect_count > $max_redirects ) {
						throw new \RuntimeException(
							__( 'Too many redirects encountered while downloading.', 'peakurl' ),
						);
					}
					continue;
				}

				if ( $size_exceeded ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %d: maximum megabytes */
							__( 'The downloaded GeoLite2 archive exceeded the maximum allowed size (%d MB).', 'peakurl' ),
							(int) ( $max_bytes / ( 1024 * 1024 ) ),
						),
					);
				}

				if ( $write_failed ) {
					throw new \RuntimeException(
						__( 'Could not write download chunk to disk.', 'peakurl' ),
					);
				}

				if ( false === $success || $errno > 0 ) {
					if ( CURLE_PARTIAL_FILE === $errno ) {
						throw new \RuntimeException(
							sprintf(
								/* translators: %s: error message */
								__( 'Incomplete GeoLite2 download: connection closed prematurely (%s).', 'peakurl' ),
								$error,
							),
						);
					}

					$msg = sprintf(
						/* translators: 1: error code, 2: error message */
						__( 'PeakURL could not download the GeoLite2 City database from MaxMind. cURL error (%1$d): %2$s', 'peakurl' ),
						$errno,
						$error,
					);
					if ( null !== $sanitize_error ) {
						$msg = (string) call_user_func( $sanitize_error, $msg );
					}
					throw new \RuntimeException( $msg );
				}

				if ( $status_code < 200 || $status_code >= 300 ) {
					$err_msg = null !== $format_error
						? (string) call_user_func( $format_error, $status_code )
						: sprintf(
							/* translators: %d: HTTP status code */
							__( 'Download failed with HTTP %d.', 'peakurl' ),
							$status_code
						);
					throw new \RuntimeException( $err_msg );
				}

				if ( $bytes_written <= 0 ) {
					throw new \RuntimeException(
						__( 'The downloaded GeoLite2 archive is empty.', 'peakurl' ),
					);
				}

				if ( null !== $content_length && $content_length > 0 && $bytes_written !== $content_length ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: 1: received bytes, 2: expected bytes */
							__( 'Incomplete GeoLite2 download: received %1$d bytes of expected %2$d bytes.', 'peakurl' ),
							$bytes_written,
							$content_length,
						),
					);
				}

				if ( ! @rename( $part_path, $dest_path ) ) {
					throw new \RuntimeException(
						__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
					);
				}

				$download_done = true;
				$total_written = $bytes_written;
				return $total_written;
			}

			throw new \RuntimeException(
				__( 'Too many redirects encountered while downloading the GeoLite2 archive.', 'peakurl' ),
			);
		} finally {
			if ( ! $download_done && file_exists( $part_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning temporary part file.
				@unlink( $part_path );
			}
		}
	}

	/**
	 * Stream an HTTP download to disk using PHP stream wrappers.
	 *
	 * @param string               $initial_url Initial URL.
	 * @param string               $dest_path   Destination file path.
	 * @param array<string, mixed> $options     Download options.
	 * @return int Total bytes written.
	 *
	 * @throws \RuntimeException On stream error, timeout, or validation failure.
	 */
	private function download_stream(
		string $initial_url,
		string $dest_path,
		array $options
	): int {
		$max_bytes     = (int) ( $options['max_bytes'] ?? 209715200 );
		$timeout       = (int) ( $options['timeout'] ?? self::DEFAULT_TIMEOUT );
		$max_redirects = (int) ( $options['max_redirects'] ?? 0 );
		$validate_url  = isset( $options['validate_url'] ) && is_callable( $options['validate_url'] )
			? $options['validate_url']
			: null;
		$on_redirect   = isset( $options['on_redirect'] ) && is_callable( $options['on_redirect'] )
			? $options['on_redirect']
			: null;
		$format_error  = isset( $options['format_error_message'] ) && is_callable( $options['format_error_message'] )
			? $options['format_error_message']
			: null;

		$current_url    = $initial_url;
		$redirect_count = 0;
		$part_path      = $dest_path . '.part-' . bin2hex( random_bytes( 4 ) );
		$download_done  = false;
		$total_written  = 0;

		try {
			while ( $redirect_count <= $max_redirects ) {
				$validated_url = null !== $validate_url
					? (string) call_user_func( $validate_url, $current_url, 0 === $redirect_count )
					: $current_url;

				$headers = is_array( $options['headers'] ?? null )
					? $options['headers']
					: array(
						'Accept: application/gzip, application/octet-stream',
						'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
					);

				if ( null !== $on_redirect && $redirect_count > 0 ) {
					$custom_headers = call_user_func( $on_redirect, $validated_url, $current_url, 0 );
					if ( is_array( $custom_headers ) ) {
						$headers = $custom_headers;
					}
				}

				$context = stream_context_create(
					array(
						'ssl'  => array(
							'verify_peer'      => true,
							'verify_peer_name' => true,
						),
						'http' => array(
							'method'          => 'GET',
							'header'          => implode( "\r\n", $headers ) . "\r\n",
							'ignore_errors'   => true,
							'follow_location' => 0,
							'timeout'         => $timeout,
						),
					),
				);

				$src = @fopen( $validated_url, 'rb', false, $context );

				if ( false === $src ) {
					throw new \RuntimeException(
						__( 'PeakURL could not connect to download server.', 'peakurl' ),
					);
				}

				stream_set_timeout( $src, $timeout );

				$response_headers = $http_response_header ?? array();
				$status_code      = $this->parse_http_status( $response_headers );
				$content_length   = $this->parse_content_length( $response_headers );

				if ( $status_code >= 300 && $status_code < 400 ) {
					fclose( $src );
					$redirect_target = $this->get_redirect_url( $response_headers );
					if ( null === $redirect_target ) {
						throw new \RuntimeException(
							__( 'Could not follow redirect (missing Location header).', 'peakurl' ),
						);
					}
					$current_url = $this->resolve_redirect_url( $redirect_target, $current_url );
					++$redirect_count;
					if ( $redirect_count > $max_redirects ) {
						throw new \RuntimeException(
							__( 'Too many redirects encountered while downloading.', 'peakurl' ),
						);
					}
					continue;
				}

				if ( $status_code < 200 || $status_code >= 300 ) {
					fclose( $src );
					$err_msg = null !== $format_error
						? (string) call_user_func( $format_error, $status_code )
						: sprintf(
							/* translators: %d: HTTP status code */
							__( 'Download failed with HTTP %d.', 'peakurl' ),
							$status_code
						);
					throw new \RuntimeException( $err_msg );
				}

				$dest_fp = @fopen( $part_path, 'wb' );
				if ( false === $dest_fp ) {
					fclose( $src );
					throw new \RuntimeException(
						__( 'PeakURL could not initialize local storage for download.', 'peakurl' ),
					);
				}

				$bytes_written  = 0;
				$stream_failed  = false;
				$transfer_start = time();
				$deadline       = $transfer_start + $timeout;

				while ( ! feof( $src ) ) {
					if ( time() > $deadline ) {
						fclose( $src );
						fclose( $dest_fp );
						throw new \RuntimeException( __( 'GeoLite2 download transfer timed out.', 'peakurl' ) );
					}

					$meta = stream_get_meta_data( $src );
					if ( ! empty( $meta['timed_out'] ) ) {
						fclose( $src );
						fclose( $dest_fp );
						throw new \RuntimeException( __( 'GeoLite2 download stream timed out.', 'peakurl' ) );
					}

					$chunk = fread( $src, self::CHUNK_SIZE );

					if ( false === $chunk ) {
						$meta = stream_get_meta_data( $src );
						if ( ! empty( $meta['timed_out'] ) || time() > $deadline ) {
							fclose( $src );
							fclose( $dest_fp );
							throw new \RuntimeException( __( 'GeoLite2 download stream timed out.', 'peakurl' ) );
						}
						$stream_failed = true;
						break;
					}

					if ( '' === $chunk ) {
						if ( ! feof( $src ) ) {
							usleep( 10000 );
							continue;
						}
						break;
					}

					$chunk_len = strlen( $chunk );
					if ( ( $bytes_written + $chunk_len ) > $max_bytes ) {
						fclose( $src );
						fclose( $dest_fp );
						throw new \RuntimeException(
							sprintf(
								/* translators: %d: maximum megabytes */
								__( 'The downloaded GeoLite2 archive exceeded the maximum allowed size (%d MB).', 'peakurl' ),
								(int) ( $max_bytes / ( 1024 * 1024 ) ),
							),
						);
					}

					if ( ! $this->write_chunk( $dest_fp, $chunk ) ) {
						fclose( $src );
						fclose( $dest_fp );
						throw new \RuntimeException(
							__( 'PeakURL could not write download chunk to disk.', 'peakurl' ),
						);
					}

					$bytes_written += $chunk_len;
				}

				fclose( $src );
				fclose( $dest_fp );

				if ( $stream_failed ) {
					throw new \RuntimeException(
						__( 'Stream read failure occurred during download.', 'peakurl' ),
					);
				}

				if ( $bytes_written <= 0 ) {
					throw new \RuntimeException(
						__( 'The downloaded GeoLite2 archive is empty.', 'peakurl' ),
					);
				}

				if ( null !== $content_length && $content_length > 0 && $bytes_written !== $content_length ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: 1: received bytes, 2: expected bytes */
							__( 'Incomplete GeoLite2 download: received %1$d bytes of expected %2$d bytes.', 'peakurl' ),
							$bytes_written,
							$content_length,
						),
					);
				}

				if ( ! @rename( $part_path, $dest_path ) ) {
					throw new \RuntimeException(
						__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
					);
				}

				$download_done = true;
				$total_written = $bytes_written;
				return $total_written;
			}

			throw new \RuntimeException(
				__( 'Too many redirects encountered while downloading the GeoLite2 archive.', 'peakurl' ),
			);
		} finally {
			if ( ! $download_done && file_exists( $part_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning temporary part file.
				@unlink( $part_path );
			}
		}
	}

	/**
	 * Fetch small response body into string using cURL.
	 *
	 * @param string             $url             URL to fetch.
	 * @param array<int, string> $headers         Request headers.
	 * @param int                $max_bytes       Max response bytes.
	 * @param int                $timeout         Total timeout.
	 * @param int                $connect_timeout Connect timeout.
	 * @return string Response string.
	 *
	 * @throws \RuntimeException On error or limit violation.
	 */
	private function fetch_string_curl(
		string $url,
		array $headers,
		int $max_bytes,
		int $timeout,
		int $connect_timeout
	): string {
		$body          = '';
		$size_exceeded = false;
		$handle        = curl_init( $url );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'Could not initialize cURL.', 'peakurl' ) );
		}

		$curl_options = array(
			CURLOPT_HTTPGET        => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => $connect_timeout,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_WRITEFUNCTION  => function ( $ch, string $chunk ) use ( &$body, &$size_exceeded, $max_bytes ): int {
				$chunk_len = strlen( $chunk );
				if ( ( strlen( $body ) + $chunk_len ) > $max_bytes ) {
					$size_exceeded = true;
					return 0;
				}
				$body .= $chunk;
				return $chunk_len;
			},
		);

		curl_setopt_array( $handle, $curl_options );
		$success     = curl_exec( $handle );
		$errno       = curl_errno( $handle );
		$error       = curl_error( $handle );
		$status_code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

		if ( PHP_VERSION_ID < 80500 ) {
			curl_close( $handle );
		}
		unset( $handle );

		if ( $size_exceeded ) {
			throw new \RuntimeException( __( 'Response exceeded maximum allowed size.', 'peakurl' ) );
		}

		if ( false === $success || $errno > 0 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: error code, 2: error message */
					__( 'HTTP fetch failed (%1$d): %2$s', 'peakurl' ),
					$errno,
					$error
				)
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP request returned status %d.', 'peakurl' ),
					$status_code
				)
			);
		}

		return $body;
	}

	/**
	 * Fetch small response body into string using PHP stream wrapper.
	 *
	 * @param string             $url       URL to fetch.
	 * @param array<int, string> $headers   Request headers.
	 * @param int                $max_bytes Max response bytes.
	 * @param int                $timeout   Total timeout.
	 * @return string Response string.
	 *
	 * @throws \RuntimeException On error or limit violation.
	 */
	private function fetch_string_stream(
		string $url,
		array $headers,
		int $max_bytes,
		int $timeout
	): string {
		$context = stream_context_create(
			array(
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
				'http' => array(
					'method'          => 'GET',
					'header'          => implode( "\r\n", $headers ) . "\r\n",
					'ignore_errors'   => true,
					'follow_location' => 0,
					'timeout'         => $timeout,
				),
			),
		);

		$src = @fopen( $url, 'rb', false, $context );
		if ( false === $src ) {
			throw new \RuntimeException( __( 'Could not open stream to URL.', 'peakurl' ) );
		}

		$response_headers = $http_response_header ?? array();
		$status_code      = $this->parse_http_status( $response_headers );

		if ( $status_code < 200 || $status_code >= 300 ) {
			fclose( $src );
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP request returned status %d.', 'peakurl' ),
					$status_code
				)
			);
		}

		$body     = '';
		$deadline = time() + $timeout;

		while ( ! feof( $src ) ) {
			if ( time() > $deadline ) {
				fclose( $src );
				throw new \RuntimeException( __( 'HTTP stream transfer timed out.', 'peakurl' ) );
			}

			$chunk = fread( $src, 8192 );
			if ( false === $chunk ) {
				fclose( $src );
				throw new \RuntimeException( __( 'Stream read failure.', 'peakurl' ) );
			}
			if ( '' === $chunk ) {
				break;
			}

			if ( ( strlen( $body ) + strlen( $chunk ) ) > $max_bytes ) {
				fclose( $src );
				throw new \RuntimeException( __( 'Response exceeded maximum allowed size.', 'peakurl' ) );
			}

			$body .= $chunk;
		}

		fclose( $src );
		return $body;
	}

	/**
	 * Resolve a redirect target against the current URL.
	 *
	 * @param string $target Redirect Location header value.
	 * @param string $base   Current URL.
	 * @return string Absolute redirect URL.
	 */
	public function resolve_redirect_url( string $target, string $base ): string {
		if ( preg_match( '/^https?:\/\//i', $target ) ) {
			return $target;
		}

		$base_parts = parse_url( $base );
		$scheme     = $base_parts['scheme'] ?? 'https';
		$host       = $base_parts['host'] ?? '';
		$port_str   = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';

		if ( str_starts_with( $target, '/' ) ) {
			return $scheme . '://' . $host . $port_str . $target;
		}

		$base_path = $base_parts['path'] ?? '/';
		$dir       = rtrim( dirname( $base_path ), '/\\' );

		return $scheme . '://' . $host . $port_str . ( '' !== $dir ? $dir : '' ) . '/' . $target;
	}

	/**
	 * Parse the Content-Length header value from response headers.
	 *
	 * @param array<int, string> $headers Response headers.
	 * @return int|null
	 */
	public function parse_content_length( array $headers ): ?int {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( 0 === stripos( $header, 'Content-Length:' ) ) {
				$length = (int) trim( substr( $header, 15 ) );
				return $length >= 0 ? $length : null;
			}
		}

		return null;
	}

	/**
	 * Parse the final HTTP status code from response headers.
	 *
	 * @param array<int, string> $headers Wrapper response headers.
	 * @return int
	 */
	public function parse_http_status( array $headers ): int {
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
	 */
	public function get_redirect_url( array $headers ): ?string {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( 0 !== stripos( $header, 'Location:' ) ) {
				continue;
			}

			$redirect_url = trim( substr( $header, 9 ) );
			if ( '' === $redirect_url ) {
				return null;
			}

			$scheme = parse_url( $redirect_url, PHP_URL_SCHEME );

			if ( null !== $scheme && 'https' !== $scheme && 'http' !== $scheme ) {
				return null;
			}

			return $redirect_url;
		}

		return null;
	}
}
