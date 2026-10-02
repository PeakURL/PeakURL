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
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * LinkHealthCheckJob — samples active link destinations and tests reachability with rotating coverage.
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
	 * Destination health checker service.
	 *
	 * @var Checker
	 * @since 1.7.1
	 */
	private Checker $checker;

	/**
	 * Create a new link health check job.
	 *
	 * @param PeakURL_DB $db          Database wrapper.
	 * @param Checker    $checker     Shared destination health checker.
	 * @param int        $batch_limit Number of links to sample per run (default 25).
	 * @since 1.7.0
	 */
	public function __construct(
		PeakURL_DB $db,
		Checker $checker,
		int $batch_limit = 25
	) {
		$this->db          = $db;
		$this->checker     = $checker;
		$this->batch_limit = max( 1, min( 100, $batch_limit ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		// Rotating sweep: never-checked links first (checked_at IS NULL), then oldest checked_at, with deterministic tie-breaker.
		$links = $this->db->get_results(
			'SELECT u.id, u.destination_url
			FROM urls u
			LEFT JOIN link_health lh ON lh.link_id = u.id
			WHERE u.status = :active_status
			ORDER BY
				CASE WHEN lh.checked_at IS NULL THEN 0 ELSE 1 END ASC,
				lh.checked_at ASC,
				u.updated_at ASC,
				u.id ASC
			LIMIT ' . $this->batch_limit,
			array(
				'active_status' => 'active',
			)
		);

		if ( empty( $links ) || ! is_array( $links ) ) {
			return ExecutionResult::success( 'No active links available for health check.' );
		}

		$category_counts = array(
			'healthy'       => 0,
			'slow'          => 0,
			'http_error'    => 0,
			'dns_error'     => 0,
			'tls_error'     => 0,
			'timeout'       => 0,
			'unreachable'   => 0,
			'redirect_loop' => 0,
			'ssrf_blocked'  => 0,
		);

		$persistence_failures = 0;

		foreach ( $links as $link ) {
			$link_id  = (string) ( $link['id'] ?? '' );
			$dest_url = trim( (string) ( $link['destination_url'] ?? '' ) );

			if ( '' === $link_id ) {
				continue;
			}

			$result = $this->checker->check( $dest_url );
			$status = (string) $result['status'];

			if ( isset( $category_counts[ $status ] ) ) {
				++$category_counts[ $status ];
			} else {
				++$category_counts['unreachable'];
			}

			// Persist single snapshot per link in link_health (never touches urls.status or urls.destination_url).
			$now = Date::now();
			try {
				$upserted = $this->db->upsert(
					'link_health',
					array(
						'link_id'          => $link_id,
						'status'           => $status,
						'checked_at'       => $now,
						'response_code'    => $result['response_code'],
						'response_time_ms' => $result['response_time_ms'],
						'error_message'    => $result['error_message'],
						'redirect_count'   => (int) ( $result['redirect_count'] ?? 0 ),
						'created_at'       => $now,
						'updated_at'       => $now,
					),
					array(
						'status',
						'checked_at',
						'response_code',
						'response_time_ms',
						'error_message',
						'redirect_count',
						'updated_at',
					)
				);
				if ( false === $upserted || ! is_int( $upserted ) || $upserted < 0 ) {
					++$persistence_failures;
				}
			} catch ( \Throwable $e ) {
				++$persistence_failures;
			}
		}

		$total_links = count( $links );
		$breakdown   = $this->format_category_breakdown( $category_counts );

		if ( $persistence_failures === $total_links && $total_links > 0 ) {
			return ExecutionResult::failure(
				sprintf(
					/* translators: %d: count of links */
					__( 'Failed to record health snapshots for all %d checked links.', 'peakurl' ),
					$total_links
				)
			);
		}

		$message = 1 === $total_links
			? sprintf(
				/* translators: 1: total links, 2: category breakdown string. */
				__( 'Health check completed for %1$d link: %2$s.', 'peakurl' ),
				$total_links,
				$breakdown
			)
			: sprintf(
				/* translators: 1: total links, 2: category breakdown string. */
				__( 'Health check completed for %1$d links: %2$s.', 'peakurl' ),
				$total_links,
				$breakdown
			);

		if ( $persistence_failures > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: count of persistence failures */
				__( '(%d persistence failures)', 'peakurl' ),
				$persistence_failures
			);
		}

		return ExecutionResult::success(
			$message,
			array(
				'checked'             => $total_links,
				'persistenceFailures' => $persistence_failures,
				'healthy'             => $category_counts['healthy'],
				'slow'                => $category_counts['slow'],
				'httpError'           => $category_counts['http_error'],
				'dnsError'            => $category_counts['dns_error'],
				'tlsError'            => $category_counts['tls_error'],
				'timeout'             => $category_counts['timeout'],
				'unreachable'         => $category_counts['unreachable'],
				'redirectLoop'        => $category_counts['redirect_loop'],
				'blockedSsrf'         => $category_counts['ssrf_blocked'],
			)
		);
	}

	/**
	 * Format human-readable category count breakdown string.
	 *
	 * @param array<string, int> $counts Category counts.
	 * @return string Formatted breakdown string.
	 * @since 1.7.1
	 */
	private function format_category_breakdown( array $counts ): string {
		$parts = array();

		if ( $counts['healthy'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				__( '%d healthy', 'peakurl' ),
				$counts['healthy']
			);
		}

		if ( $counts['slow'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				__( '%d slow', 'peakurl' ),
				$counts['slow']
			);
		}

		if ( $counts['http_error'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				_n( '%d HTTP error', '%d HTTP errors', $counts['http_error'], 'peakurl' ),
				$counts['http_error']
			);
		}

		if ( $counts['dns_error'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				_n( '%d DNS error', '%d DNS errors', $counts['dns_error'], 'peakurl' ),
				$counts['dns_error']
			);
		}

		if ( $counts['tls_error'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				_n( '%d TLS error', '%d TLS errors', $counts['tls_error'], 'peakurl' ),
				$counts['tls_error']
			);
		}

		if ( $counts['timeout'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				_n( '%d timeout', '%d timeouts', $counts['timeout'], 'peakurl' ),
				$counts['timeout']
			);
		}

		if ( $counts['unreachable'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				__( '%d unreachable', 'peakurl' ),
				$counts['unreachable']
			);
		}

		if ( $counts['redirect_loop'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				_n( '%d redirect loop', '%d redirect loops', $counts['redirect_loop'], 'peakurl' ),
				$counts['redirect_loop']
			);
		}

		if ( $counts['ssrf_blocked'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d is count. */
				__( '%d blocked', 'peakurl' ),
				$counts['ssrf_blocked']
			);
		}

		if ( empty( $parts ) ) {
			return __( '0 checked', 'peakurl' );
		}

		return implode( ', ', $parts );
	}
}
