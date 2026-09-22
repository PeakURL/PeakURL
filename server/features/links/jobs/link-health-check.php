<?php
/**
 * Link destination health check background job.
 *
 * @package PeakURL\Features\Links\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Jobs;

use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * LinkHealthCheckJob — samples active link destinations and tests reachability.
 *
 * Enforces strict SSRF protections: blocks internal network targets, private IP
 * ranges (RFC 1918), loopback (127.0.0.1/::1), and cloud metadata services
 * (169.254.169.254).
 *
 * @since 1.7.0
 */
class LinkHealthCheckJob implements JobHandlerInterface {

	/**
	 * Database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Maximum number of links to check per execution run.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $batch_limit;

	/**
	 * Request timeout in seconds.
	 *
	 * @var float
	 * @since 1.7.0
	 */
	private float $timeout_seconds;

	/**
	 * Create a new link health check job.
	 *
	 * @param PeakURL_DB $db              Database wrapper.
	 * @param int        $batch_limit     Number of links to sample per run (default 25).
	 * @param float      $timeout_seconds HTTP request timeout in seconds (default 3.0).
	 * @since 1.7.0
	 */
	public function __construct(
		PeakURL_DB $db,
		int $batch_limit = 25,
		float $timeout_seconds = 3.0
	) {
		$this->db              = $db;
		$this->batch_limit     = max( 1, min( 100, $batch_limit ) );
		$this->timeout_seconds = max( 1.0, min( 10.0, $timeout_seconds ) );
	}

	/**
	 * Validate a URL against SSRF vulnerabilities.
	 *
	 * Blocks private IP ranges, loopback, cloud metadata endpoints, and non-HTTP schemes.
	 *
	 * @param string $url URL to inspect.
	 * @return bool True if destination is safe to request.
	 * @since 1.7.0
	 */
	public static function is_safe_url( string $url ): bool {
		$parts = parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
			return false;
		}

		// Direct IP check or DNS resolution.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing DNS resolution safely.
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : @gethostbynamel( $host );

		if ( empty( $ips ) || ! is_array( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an IP address is a publicly routable Internet address.
	 *
	 * Rejects private, reserved, loopback, and link-local ranges.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if public and routable.
	 * @since 1.7.0
	 */
	public static function is_public_ip( string $ip ): bool {
		$valid = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( false === $valid ) {
			return false;
		}

		// Explicit check for cloud metadata (169.254.x.x) and loopback.
		if ( str_starts_with( $ip, '127.' ) || str_starts_with( $ip, '169.254.' ) || '0.0.0.0' === $ip || '::1' === $ip ) {
			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$links = $this->db->get_results(
			'SELECT id, destination_url FROM urls
			WHERE status = :active_status
			ORDER BY updated_at ASC
			LIMIT ' . $this->batch_limit,
			array(
				'active_status' => 'active',
			)
		);

		if ( empty( $links ) || ! is_array( $links ) ) {
			return ExecutionResult::success( 'No active links available for health check.' );
		}

		$healthy_count = 0;
		$blocked_count = 0;
		$failed_count  = 0;

		foreach ( $links as $link ) {
			$dest_url = trim( (string) ( $link['destination_url'] ?? '' ) );

			if ( ! self::is_safe_url( $dest_url ) ) {
				++$blocked_count;
				continue;
			}

			if ( $this->check_destination_url( $dest_url ) ) {
				++$healthy_count;
			} else {
				++$failed_count;
			}
		}

		$total_links = count( $links );
		$message     = 1 === $total_links
			? sprintf(
				'Health check completed for %1$d link: %2$d healthy, %3$d unreachable, %4$d blocked by SSRF filter.',
				$total_links,
				$healthy_count,
				$failed_count,
				$blocked_count
			)
			: sprintf(
				'Health check completed for %1$d links: %2$d healthy, %3$d unreachable, %4$d blocked by SSRF filter.',
				$total_links,
				$healthy_count,
				$failed_count,
				$blocked_count
			);

		return ExecutionResult::success(
			$message,
			array(
				'checked'     => $total_links,
				'healthy'     => $healthy_count,
				'unreachable' => $failed_count,
				'blockedSsrf' => $blocked_count,
			)
		);
	}

	/**
	 * Perform a bounded HTTP probe on the target URL.
	 *
	 * @param string $url Target URL.
	 * @return bool True if destination responded with 2xx/3xx.
	 * @since 1.7.0
	 */
	private function check_destination_url( string $url ): bool {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_NOBODY         => true,
					CURLOPT_TIMEOUT        => (int) ceil( $this->timeout_seconds ),
					CURLOPT_CONNECTTIMEOUT => (int) ceil( $this->timeout_seconds ),
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_USERAGENT      => 'PeakURL-HealthCheck/1.0',
				)
			);

			$exec = curl_exec( $ch );
			$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			return false !== $exec && $code >= 200 && $code < 400;
		}

		// Stream context fallback.
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'HEAD',
					'timeout'       => $this->timeout_seconds,
					'ignore_errors' => true,
					'user_agent'    => 'PeakURL-HealthCheck/1.0',
				),
			)
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing remote headers safely.
		$headers = @get_headers( $url, false, $context );
		if ( false === $headers || empty( $headers[0] ) ) {
			return false;
		}

		return (bool) preg_match( '/^HTTP\/\S+\s+(2\d\d|3\d\d)/i', $headers[0] );
	}
}
