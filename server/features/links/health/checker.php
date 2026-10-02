<?php
/**
 * Destination health orchestration domain service.
 *
 * Coordinates destination reachability, multi-IP fallback, monotonic timeout accounting,
 * safe redirect traversal, and explicit health classification.
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
 * Checker — orchestrates bounded, observational health probes against link destinations.
 *
 * Delegates address resolution and SSRF policy to Resolver, and outbound HTTP
 * execution to Probe. Preserves strict observational isolation (never mutates core
 * link routing, redirects, analytics, or caching).
 *
 * @since 1.7.1
 */
class Checker {

	/**
	 * Default maximum request timeout in seconds.
	 *
	 * @since 1.7.1
	 */
	public const DEFAULT_TIMEOUT_SECONDS = 3.0;

	/**
	 * Default maximum redirects to follow safely.
	 *
	 * @since 1.7.1
	 */
	public const DEFAULT_MAX_REDIRECTS = 5;

	/**
	 * Slow response threshold in milliseconds (1500ms).
	 *
	 * @since 1.7.1
	 */
	public const DEFAULT_SLOW_THRESHOLD_MS = 1500;

	/**
	 * Explicit status values.
	 *
	 * @since 1.7.1
	 */
	public const STATUS_HEALTHY       = 'healthy';
	public const STATUS_SLOW          = 'slow';
	public const STATUS_UNREACHABLE   = 'unreachable';
	public const STATUS_DNS_ERROR     = 'dns_error';
	public const STATUS_TLS_ERROR     = 'tls_error';
	public const STATUS_TIMEOUT       = 'timeout';
	public const STATUS_HTTP_ERROR    = 'http_error';
	public const STATUS_REDIRECT_LOOP = 'redirect_loop';
	public const STATUS_SSRF_BLOCKED  = 'ssrf_blocked';

	/**
	 * Address and URL resolver.
	 *
	 * @var Resolver
	 * @since 1.7.1
	 */
	private Resolver $resolver;

	/**
	 * Outbound HTTP transport probe.
	 *
	 * @var Probe
	 * @since 1.7.1
	 */
	private Probe $probe;

	/**
	 * Request timeout in seconds.
	 *
	 * @var float
	 * @since 1.7.1
	 */
	private float $timeout_seconds;

	/**
	 * Maximum number of redirects to follow.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	private int $max_redirects;

	/**
	 * Slow threshold in milliseconds.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	private int $slow_threshold_ms;

	/**
	 * Optional custom monotonic clock callable for deterministic testing.
	 *
	 * @var callable|null
	 * @since 1.7.1
	 */
	private $clock;

	/**
	 * Create a new destination health checker.
	 *
	 * @param Resolver      $resolver          Address resolution service.
	 * @param Probe         $probe             HTTP probe transport.
	 * @param float         $timeout_seconds   Overall probe timeout budget in seconds (default 3.0).
	 * @param int           $max_redirects     Maximum redirects to follow (default 5).
	 * @param int           $slow_threshold_ms Slow classification threshold in ms (default 1500).
	 * @param callable|null $clock             Optional monotonic clock mock callback.
	 * @since 1.7.1
	 */
	public function __construct(
		Resolver $resolver,
		Probe $probe,
		float $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS,
		int $max_redirects = self::DEFAULT_MAX_REDIRECTS,
		int $slow_threshold_ms = self::DEFAULT_SLOW_THRESHOLD_MS,
		?callable $clock = null
	) {
		$this->resolver          = $resolver;
		$this->probe             = $probe;
		$this->timeout_seconds   = max( 1.0, min( 10.0, $timeout_seconds ) );
		$this->max_redirects     = max( 1, min( 20, $max_redirects ) );
		$this->slow_threshold_ms = max( 100, $slow_threshold_ms );
		$this->clock             = $clock;
	}

	/**
	 * Get current monotonic time in nanoseconds.
	 *
	 * @return int|float Nanoseconds.
	 * @since 1.7.1
	 */
	private function monotonic_time_ns(): int|float {
		if ( null !== $this->clock && is_callable( $this->clock ) ) {
			return call_user_func( $this->clock );
		}

		return hrtime( true );
	}

	/**
	 * Perform a bounded, observational health check on a target destination URL.
	 *
	 * Enforces the total request timeout budget in milliseconds across all probe executions
	 * and redirect hops. Retries candidate public IPs within the remaining budget.
	 * Follows redirects manually up to max_redirects with DNS/IP pinning and SSRF validation on each hop.
	 *
	 * Note: The monotonic budget bounds HTTP probe execution and redirect/address retry processing.
	 * DNS resolution is a synchronous system operation and is not forcibly cancellable by the cURL timeout.
	 *
	 * @param string $url Destination URL to inspect.
	 * @return array{
	 *     status: string,
	 *     response_code: ?int,
	 *     response_time_ms: ?int,
	 *     error_message: ?string,
	 *     redirect_count: int
	 * } Health inspection result.
	 * @since 1.7.1
	 */
	public function check( string $url ): array {
		$url = trim( $url );

		if ( '' === $url ) {
			return array(
				'status'           => self::STATUS_UNREACHABLE,
				'response_code'    => null,
				'response_time_ms' => null,
				'error_message'    => __( 'Empty destination URL.', 'peakurl' ),
				'redirect_count'   => 0,
			);
		}

		$start_time           = $this->monotonic_time_ns();
		$total_budget_ms      = (int) round( $this->timeout_seconds * 1000 );
		$current_url          = $url;
		$redirect_count       = 0;
		$accumulated_probe_ms = 0;
		$visited              = array( Resolver::normalize_target( $current_url ) => true );
		$last_code            = null;

		while ( true ) {
			$elapsed_ms           = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );
			$remaining_timeout_ms = $total_budget_ms - $elapsed_ms;

			if ( $remaining_timeout_ms <= 0 ) {
				return array(
					'status'           => self::STATUS_TIMEOUT,
					'response_code'    => $last_code,
					'response_time_ms' => $elapsed_ms,
					'error_message'    => __( 'Request timed out.', 'peakurl' ),
					'redirect_count'   => $redirect_count,
				);
			}

			// Validate URL structure for current hop.
			$parts = parse_url( $current_url );
			if ( false === $parts || ! is_array( $parts ) ) {
				return array(
					'status'           => self::STATUS_UNREACHABLE,
					'response_code'    => $last_code,
					'response_time_ms' => $elapsed_ms,
					'error_message'    => __( 'Malformed destination URL.', 'peakurl' ),
					'redirect_count'   => $redirect_count,
				);
			}

			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return array(
					'status'           => self::STATUS_SSRF_BLOCKED,
					'response_code'    => $last_code,
					'response_time_ms' => $elapsed_ms,
					'error_message'    => __( 'Destination rejected by SSRF protection.', 'peakurl' ),
					'redirect_count'   => $redirect_count,
				);
			}

			$host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
			if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
				return array(
					'status'           => self::STATUS_SSRF_BLOCKED,
					'response_code'    => $last_code,
					'response_time_ms' => $elapsed_ms,
					'error_message'    => __( 'Destination rejected by SSRF protection.', 'peakurl' ),
					'redirect_count'   => $redirect_count,
				);
			}

			$port = ! empty( $parts['port'] )
				? (int) $parts['port']
				: ( 'https' === $scheme ? 443 : 80 );

			// Resolve validated public IP candidates.
			$candidate_ips = $this->resolver->public_addresses( $host );

			if ( empty( $candidate_ips ) ) {
				$unbracketed_host = ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) )
					? substr( $host, 1, -1 )
					: $host;

				if ( filter_var( $unbracketed_host, FILTER_VALIDATE_IP ) ) {
					return array(
						'status'           => self::STATUS_SSRF_BLOCKED,
						'response_code'    => $last_code,
						'response_time_ms' => (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 ),
						'error_message'    => __( 'Destination rejected by SSRF protection.', 'peakurl' ),
						'redirect_count'   => $redirect_count,
					);
				}

				$all_addresses = $this->resolver->addresses_for_host( $host );
				if ( ! empty( $all_addresses ) ) {
					return array(
						'status'           => self::STATUS_SSRF_BLOCKED,
						'response_code'    => $last_code,
						'response_time_ms' => (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 ),
						'error_message'    => __( 'Destination rejected by SSRF protection.', 'peakurl' ),
						'redirect_count'   => $redirect_count,
					);
				}

				return array(
					'status'           => self::STATUS_DNS_ERROR,
					'response_code'    => $last_code,
					'response_time_ms' => (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 ),
					'error_message'    => __( 'Could not resolve host.', 'peakurl' ),
					'redirect_count'   => $redirect_count,
				);
			}

			// Try all validated public addresses within the remaining timeout budget.
			$probe_result     = null;
			$candidate_errors = array();
			$pinned_ip        = null;

			foreach ( $candidate_ips as $candidate_ip ) {
				$elapsed_ms           = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );
				$remaining_timeout_ms = $total_budget_ms - $elapsed_ms;

				if ( $remaining_timeout_ms <= 0 ) {
					return array(
						'status'           => self::STATUS_TIMEOUT,
						'response_code'    => $last_code,
						'response_time_ms' => $elapsed_ms,
						'error_message'    => __( 'Request timed out.', 'peakurl' ),
						'redirect_count'   => $redirect_count,
					);
				}

				$probe = $this->probe->probe(
					$current_url,
					$host,
					$port,
					$candidate_ip,
					$remaining_timeout_ms
				);

				$accumulated_probe_ms += (int) ( $probe['duration_ms'] ?? 0 );

				// Stop on first successful probe (no connection error code and has HTTP status code).
				if ( 0 === (int) ( $probe['error_code'] ?? 0 ) && null !== $probe['response_code'] ) {
					$probe_result = $probe;
					$pinned_ip    = $candidate_ip;
					break;
				}

				$candidate_errors[] = $probe;
			}

			// Controlled GET fallback when HEAD returns 404 or 405.
			if ( null !== $probe_result && null !== $pinned_ip && 0 === (int) ( $probe_result['error_code'] ?? 0 ) ) {
				$head_code = (int) $probe_result['response_code'];
				if ( 404 === $head_code || 405 === $head_code ) {
					$elapsed_ms           = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );
					$remaining_timeout_ms = $total_budget_ms - $elapsed_ms;

					if ( $remaining_timeout_ms <= 0 ) {
						return array(
							'status'           => self::STATUS_TIMEOUT,
							'response_code'    => $head_code,
							'response_time_ms' => $elapsed_ms,
							'error_message'    => __( 'Request timed out.', 'peakurl' ),
							'redirect_count'   => $redirect_count,
						);
					}

					$get_probe = $this->probe->probe(
						$current_url,
						$host,
						$port,
						$pinned_ip,
						$remaining_timeout_ms,
						'GET'
					);

					$accumulated_probe_ms += (int) ( $get_probe['duration_ms'] ?? 0 );
					$probe_result          = $get_probe;
				}
			}

			if ( null === $probe_result ) {
				// Select best error representation according to explicit retry matrix precedence:
				// 1. TLS error (specific cryptographic failure takes precedence over generic connection failure)
				// 2. Timeout (if any candidate probe timed out or overall budget expired)
				// 3. Unreachable (generic connection failure)
				$chosen_error = null;
				foreach ( $candidate_errors as $candidate_err ) {
					$candidate_status = $this->network_status(
						(int) ( $candidate_err['error_code'] ?? 0 ),
						(string) ( $candidate_err['error_message'] ?? '' )
					);
					if ( self::STATUS_TLS_ERROR === $candidate_status ) {
						$chosen_error = $candidate_err;
						break;
					}
				}

				if ( null === $chosen_error ) {
					foreach ( $candidate_errors as $candidate_err ) {
						$candidate_status = $this->network_status(
							(int) ( $candidate_err['error_code'] ?? 0 ),
							(string) ( $candidate_err['error_message'] ?? '' )
						);
						if ( self::STATUS_TIMEOUT === $candidate_status ) {
							$chosen_error = $candidate_err;
							break;
						}
					}
				}

				$probe_result = $chosen_error ?? ( ! empty( $candidate_errors ) ? end( $candidate_errors ) : array(
					'response_code' => null,
					'duration_ms'   => 0,
					'error_code'    => 1,
					'error_message' => __( 'Destination is unreachable.', 'peakurl' ),
					'redirect_url'  => null,
				) );
			}

			$response_code = $probe_result['response_code'];
			$last_code     = $response_code;
			$error_code    = $probe_result['error_code'];
			$error_message = $probe_result['error_message'];
			$redirect_url  = $probe_result['redirect_url'];

			// Handle probe failures / connection errors.
			if ( $error_code > 0 || ( null === $response_code && '' !== $error_message ) ) {
				$elapsed_ms = max( (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 ), $accumulated_probe_ms );
				$status     = $this->network_status( $error_code, $error_message );

				return array(
					'status'           => $status,
					'response_code'    => null,
					'response_time_ms' => $elapsed_ms,
					'error_message'    => '' !== $error_message ? $error_message : $this->status_error_message( $status ),
					'redirect_count'   => $redirect_count,
				);
			}

			// Check for 3xx redirect.
			if ( null !== $response_code && $response_code >= 300 && $response_code < 400 && null !== $redirect_url && '' !== $redirect_url ) {
				if ( $redirect_count >= $this->max_redirects ) {
					$elapsed_ms = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );

					return array(
						'status'           => self::STATUS_REDIRECT_LOOP,
						'response_code'    => $response_code,
						'response_time_ms' => $elapsed_ms,
						'error_message'    => sprintf(
							/* translators: %d is max redirect limit. */
							__( 'Exceeded maximum redirect limit (%d hops).', 'peakurl' ),
							$this->max_redirects
						),
						'redirect_count'   => $redirect_count,
					);
				}

				$resolved_target = Resolver::redirect_target( $current_url, $redirect_url );

				if ( null === $resolved_target ) {
					$elapsed_ms = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );

					return array(
						'status'           => self::STATUS_UNREACHABLE,
						'response_code'    => $response_code,
						'response_time_ms' => $elapsed_ms,
						'error_message'    => __( 'Unsupported redirect scheme or invalid redirect location.', 'peakurl' ),
						'redirect_count'   => $redirect_count,
					);
				}

				// Loop detection compares normalized HTTP request targets (fragment does not bypass loop detection).
				$normalized_target = Resolver::normalize_target( $resolved_target );
				if ( isset( $visited[ $normalized_target ] ) ) {
					$elapsed_ms = (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 );

					return array(
						'status'           => self::STATUS_REDIRECT_LOOP,
						'response_code'    => $response_code,
						'response_time_ms' => $elapsed_ms,
						'error_message'    => __( 'Redirect loop detected.', 'peakurl' ),
						'redirect_count'   => $redirect_count,
					);
				}

				++$redirect_count;
				$visited[ $normalized_target ] = true;
				$current_url                   = $resolved_target;
				continue;
			}

			// Final response reached.
			$total_elapsed_ms = max( (int) round( ( $this->monotonic_time_ns() - $start_time ) / 1e6 ), $accumulated_probe_ms );

			if ( null !== $response_code && $response_code >= 200 && $response_code < 400 ) {
				$is_slow = $total_elapsed_ms > $this->slow_threshold_ms;

				return array(
					'status'           => $is_slow ? self::STATUS_SLOW : self::STATUS_HEALTHY,
					'response_code'    => $response_code,
					'response_time_ms' => $total_elapsed_ms,
					'error_message'    => null,
					'redirect_count'   => $redirect_count,
				);
			}

			// 4xx or 5xx response.
			return array(
				'status'           => self::STATUS_HTTP_ERROR,
				'response_code'    => $response_code,
				'response_time_ms' => $total_elapsed_ms,
				'error_message'    => sprintf(
					/* translators: %d is HTTP status code. */
					__( 'HTTP status %d', 'peakurl' ),
					(int) $response_code
				),
				'redirect_count'   => $redirect_count,
			);
		}
	}

	/**
	 * Map a network or cURL error to an explicit status classification.
	 *
	 * @param int    $error_code    cURL error number.
	 * @param string $error_message Error description.
	 * @return string Explicit status.
	 * @since 1.7.1
	 */
	public function network_status( int $error_code, string $error_message ): string {
		// DNS resolution errors: CURLE_COULDNT_RESOLVE_HOST = 6, CURLE_COULDNT_RESOLVE_PROXY = 5.
		if ( 6 === $error_code || 5 === $error_code ) {
			return self::STATUS_DNS_ERROR;
		}

		// TLS/SSL errors: CURLE_SSL_CONNECT_ERROR = 35, CURLE_PEER_FAILED_VERIFICATION = 51 / 60,
		// CURLE_SSL_CERTPROBLEM = 58, CURLE_SSL_CIPHER = 59, CURLE_SSL_CACERT = 60, etc.
		if (
			in_array( $error_code, array( 35, 51, 58, 59, 60, 77, 80, 82, 83 ), true ) ||
			str_contains( strtolower( $error_message ), 'ssl' ) ||
			str_contains( strtolower( $error_message ), 'certificate' )
		) {
			return self::STATUS_TLS_ERROR;
		}

		// Timeout: CURLE_OPERATION_TIMEDOUT = 28.
		if ( 28 === $error_code || str_contains( strtolower( $error_message ), 'timed out' ) ) {
			return self::STATUS_TIMEOUT;
		}

		return self::STATUS_UNREACHABLE;
	}

	/**
	 * Return a default translated error message for an explicit status.
	 *
	 * @param string $status Health status.
	 * @return string Error message.
	 * @since 1.7.1
	 */
	public function status_error_message( string $status ): string {
		switch ( $status ) {
			case self::STATUS_DNS_ERROR:
				return __( 'DNS resolution failed.', 'peakurl' );
			case self::STATUS_TLS_ERROR:
				return __( 'TLS/SSL certificate verification failed.', 'peakurl' );
			case self::STATUS_TIMEOUT:
				return __( 'Connection timed out.', 'peakurl' );
			case self::STATUS_REDIRECT_LOOP:
				return __( 'Redirect loop detected.', 'peakurl' );
			case self::STATUS_SSRF_BLOCKED:
				return __( 'Blocked by SSRF filter.', 'peakurl' );
			case self::STATUS_UNREACHABLE:
			default:
				return __( 'Destination is unreachable.', 'peakurl' );
		}
	}
}
