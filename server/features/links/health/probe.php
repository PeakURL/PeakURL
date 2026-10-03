<?php
/**
 * Destination health HTTP probe transport.
 *
 * Owns outbound HTTP transport via cURL with CURLOPT_RESOLVE address pinning
 * for the link health domain.
 *
 * @package PeakURL\Features\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Health;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Probe — executes single outbound HTTP probe requests via cURL.
 *
 * Configured with HEAD/NOBODY requests, TLS verification, millisecond timeouts,
 * and CURLOPT_RESOLVE IP pinning. Never automatically follows redirects.
 *
 * @since 1.7.1
 */
class Probe {

	/**
	 * Health service context.
	 *
	 * @var Context
	 * @since 1.7.1
	 */
	private Context $context;

	/**
	 * Optional custom HTTP prober callable for deterministic testing.
	 *
	 * @var callable|null
	 * @since 1.7.1
	 */
	private $http_prober;

	/**
	 * Create a new destination health probe transport.
	 *
	 * @param Context       $context     Link health context.
	 * @param callable|null $http_prober Optional HTTP prober mock callback for testing.
	 * @since 1.7.1
	 */
	public function __construct( Context $context, ?callable $http_prober = null ) {
		$this->context     = $context;
		$this->http_prober = $http_prober;
	}

	/**
	 * Format a CURLOPT_RESOLVE entry for host:port:address pinning.
	 *
	 * Strips authority brackets from host and ensures IPv6 addresses are bracketed as [addr].
	 *
	 * @param string $host Target hostname or host authority.
	 * @param int    $port Target port.
	 * @param string $ip   Validated IP address.
	 * @return string Formatted entry string (e.g. host:port:ip or host:port:[ip]).
	 * @since 1.7.1
	 */
	public static function host_mapping( string $host, int $port, string $ip ): string {
		$clean_host = ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) )
			? substr( $host, 1, -1 )
			: $host;
		$clean_ip   = trim( $ip, '[]' );
		$ip_entry   = str_contains( $clean_ip, ':' ) ? "[{$clean_ip}]" : $clean_ip;

		return "{$clean_host}:{$port}:{$ip_entry}";
	}

	/**
	 * Extract the redirect Location header value from raw HTTP response headers.
	 *
	 * Parses headers directly as the authoritative redirect target source.
	 *
	 * @param string $headers Raw response headers string.
	 * @return string|null Redirect target string or null if not found.
	 * @since 1.7.1
	 */
	public static function extract_location_header( string $headers ): ?string {
		$matches = array();
		if ( preg_match( '/^Location:\s*([^\r\n]+)/im', $headers, $matches ) ) {
			$location = trim( $matches[1] );
			return '' !== $location ? $location : null;
		}

		return null;
	}

	/**
	 * Return cURL option array for a pinned probe request.
	 *
	 * @param string $url        Request URL.
	 * @param string $host       Host authority.
	 * @param int    $port       Target port.
	 * @param string $pinned_ip  Validated IP address to pin.
	 * @param int    $timeout_ms Remaining timeout budget in milliseconds.
	 * @param string $method     HTTP method: 'HEAD' (default) or 'GET'.
	 * @return array<int, mixed> cURL options.
	 * @since 1.7.1
	 */
	public function curl_options(
		string $url,
		string $host,
		int $port,
		string $pinned_ip,
		int $timeout_ms,
		string $method = 'HEAD'
	): array {
		$is_get  = 'GET' === strtoupper( $method );
		$options = array(
			CURLOPT_URL               => $url,
			CURLOPT_HEADER            => ! $is_get,
			CURLOPT_NOBODY            => ! $is_get,
			CURLOPT_TIMEOUT_MS        => max( 1, $timeout_ms ),
			CURLOPT_CONNECTTIMEOUT_MS => max( 1, $timeout_ms ),
			CURLOPT_NOSIGNAL          => 1,
			CURLOPT_FOLLOWLOCATION    => false,
			CURLOPT_RETURNTRANSFER    => ! $is_get,
			CURLOPT_SSL_VERIFYPEER    => true,
			CURLOPT_SSL_VERIFYHOST    => 2,
			CURLOPT_USERAGENT         => 'PeakURL/' . $this->context->get_version(),
			CURLOPT_PROXY             => '',
		);

		if ( $is_get ) {
			$options[ CURLOPT_HTTPGET ] = true;
		}

		$unbracketed_host = ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) )
			? substr( $host, 1, -1 )
			: $host;

		if ( ! filter_var( $unbracketed_host, FILTER_VALIDATE_IP ) ) {
			$options[ CURLOPT_RESOLVE ] = array( self::host_mapping( $host, $port, $pinned_ip ) );
		}

		return $options;
	}

	/**
	 * Execute one HTTP probe request to a pre-validated IP address.
	 *
	 * @param string $url        Request URL.
	 * @param string $host       Target hostname.
	 * @param int    $port       Target port.
	 * @param string $pinned_ip  Pre-validated IP address.
	 * @param int    $timeout_ms Remaining timeout budget in milliseconds.
	 * @param string $method     HTTP method: 'HEAD' (default) or 'GET'.
	 * @return array{
	 *     response_code: ?int,
	 *     duration_ms: int,
	 *     error_code: int,
	 *     error_message: string,
	 *     redirect_url: ?string
	 * } Probe result.
	 * @since 1.7.1
	 */
	public function probe(
		string $url,
		string $host,
		int $port,
		string $pinned_ip,
		int $timeout_ms,
		string $method = 'HEAD'
	): array {
		if ( null !== $this->http_prober && is_callable( $this->http_prober ) ) {
			return call_user_func(
				$this->http_prober,
				$url,
				$timeout_ms,
				$pinned_ip,
				$host,
				$port,
				$method
			);
		}

		return $this->curl_probe(
			$url,
			$host,
			$port,
			$pinned_ip,
			$timeout_ms,
			$method
		);
	}

	/**
	 * Probe target using cURL with NOBODY (HEAD) or bounded streaming GET request.
	 *
	 * Surfaces a runtime configuration exception if the cURL extension is missing.
	 *
	 * @param string $url        Target URL.
	 * @param string $host       Target host.
	 * @param int    $port       Target port.
	 * @param string $pinned_ip  Validated public IP.
	 * @param int    $timeout_ms Remaining timeout budget in ms.
	 * @param string $method     HTTP method: 'HEAD' (default) or 'GET'.
	 * @return array{
	 *     response_code: ?int,
	 *     duration_ms: int,
	 *     error_code: int,
	 *     error_message: string,
	 *     redirect_url: ?string
	 * } Probe result.
	 *
	 * @throws \RuntimeException If the PHP cURL extension is not loaded.
	 * @since 1.7.1
	 */
	public function curl_probe(
		string $url,
		string $host,
		int $port,
		string $pinned_ip,
		int $timeout_ms,
		string $method = 'HEAD'
	): array {
		if ( ! extension_loaded( 'curl' ) || ! function_exists( 'curl_init' ) ) {
			throw new \RuntimeException( 'The PHP cURL extension is required for destination health checks.' );
		}

		$curl_handle = curl_init();
		if ( false === $curl_handle ) {
			return array(
				'response_code' => null,
				'duration_ms'   => 0,
				'error_code'    => 1,
				'error_message' => __( 'Failed to initialize cURL.', 'peakurl' ),
				'redirect_url'  => null,
			);
		}

		$is_get  = 'GET' === strtoupper( $method );
		$options = $this->curl_options(
			$url,
			$host,
			$port,
			$pinned_ip,
			$timeout_ms,
			$method
		);

		$raw_headers = '';
		$body_bytes  = 0;
		$max_bytes   = 65536; // 64KB body ceiling for GET fallback.

		if ( $is_get ) {
			$options[ CURLOPT_HEADERFUNCTION ] = static function ( $ch, string $header_line ) use ( &$raw_headers ): int {
				$raw_headers .= $header_line;
				return strlen( $header_line );
			};
			$options[ CURLOPT_WRITEFUNCTION ]  = static function ( $ch, string $data ) use ( &$body_bytes, $max_bytes ): int {
				$body_bytes += strlen( $data );
				if ( $body_bytes > $max_bytes ) {
					return 0; // Abort body stream once ceiling is exceeded.
				}
				return strlen( $data );
			};
		}

		try {
			$configured = curl_setopt_array( $curl_handle, $options );
		} catch ( \Throwable $e ) {
			$configured = false;
		}

		if ( false === $configured ) {
			unset( $curl_handle );

			return array(
				'response_code' => null,
				'duration_ms'   => 0,
				'error_code'    => 1,
				'error_message' => __( 'Failed to configure transport options.', 'peakurl' ),
				'redirect_url'  => null,
			);
		}

		$res         = curl_exec( $curl_handle );
		$curl_errno  = curl_errno( $curl_handle );
		$curl_error  = curl_error( $curl_handle );
		$http_code   = curl_getinfo( $curl_handle, CURLINFO_HTTP_CODE );
		$duration_ms = (int) round( (float) curl_getinfo( $curl_handle, CURLINFO_TOTAL_TIME ) * 1000 );
		unset( $curl_handle );

		if ( ! $is_get && is_string( $res ) ) {
			$raw_headers = $res;
		}

		// When body ceiling was reached on GET, cURL throws error 23, but headers and HTTP status were already captured.
		if ( $is_get && 23 === $curl_errno && $http_code > 0 ) {
			$curl_errno = 0;
			$curl_error = '';
		}

		$redirect_url = '' !== $raw_headers ? self::extract_location_header( $raw_headers ) : null;

		return array(
			'response_code' => $http_code > 0 ? (int) $http_code : null,
			'duration_ms'   => $duration_ms,
			'error_code'    => (int) $curl_errno,
			'error_message' => (string) $curl_error,
			'redirect_url'  => $redirect_url,
		);
	}
}
