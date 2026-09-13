<?php
/**
 * Background job retry policy.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * RetryPolicy — computes bounded exponential backoff delays for failed jobs.
 *
 * @since 1.7.0
 */
class RetryPolicy {

	/**
	 * Maximum allowed execution attempts before marking as terminally failed.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $max_attempts;

	/**
	 * Initial retry delay in seconds.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $initial_delay_seconds;

	/**
	 * Exponential backoff multiplier.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $backoff_multiplier;

	/**
	 * Maximum retry delay ceiling in seconds (default 3600 = 1 hour).
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $max_delay_seconds;

	/**
	 * Create a new retry policy.
	 *
	 * @param int $max_attempts          Maximum allowed attempts (minimum 1).
	 * @param int $initial_delay_seconds Initial delay in seconds before first retry.
	 * @param int $backoff_multiplier    Exponential backoff factor (minimum 1).
	 * @param int $max_delay_seconds     Ceiling on retry delay in seconds.
	 * @since 1.7.0
	 */
	public function __construct(
		int $max_attempts = 3,
		int $initial_delay_seconds = 60,
		int $backoff_multiplier = 2,
		int $max_delay_seconds = 3600
	) {
		$this->max_attempts          = max( 1, $max_attempts );
		$this->initial_delay_seconds = max( 1, $initial_delay_seconds );
		$this->backoff_multiplier    = max( 1, $backoff_multiplier );
		$this->max_delay_seconds     = max( $this->initial_delay_seconds, $max_delay_seconds );
	}

	/**
	 * Default standard retry policy for background maintenance jobs.
	 *
	 * @return self
	 * @since 1.7.0
	 */
	public static function standard(): self {
		return new self( 3, 60, 2, 3600 );
	}

	/**
	 * Immediate single-retry policy without multi-step backoff.
	 *
	 * @return self
	 * @since 1.7.0
	 */
	public static function no_retry(): self {
		return new self( 1, 0, 1, 0 );
	}

	/**
	 * Determine whether another retry is permitted after the given attempt.
	 *
	 * @param int $attempt Attempt number that just failed.
	 * @return bool True if another attempt is allowed.
	 * @since 1.7.0
	 */
	public function is_retryable( int $attempt ): bool {
		return $attempt < $this->max_attempts;
	}

	/**
	 * Calculate the timestamp for the next retry attempt using bounded exponential backoff.
	 *
	 * @param int $attempt        The attempt number that just completed/failed (1-indexed).
	 * @param int $base_timestamp Unix timestamp from which to calculate delay.
	 * @return int Target Unix timestamp for the next run.
	 * @since 1.7.0
	 */
	public function calculate_next_retry( int $attempt, int $base_timestamp ): int {
		$exponent = max( 0, $attempt - 1 );
		$factor   = (int) ( $this->backoff_multiplier ** $exponent );
		$delay    = min( $this->max_delay_seconds, $this->initial_delay_seconds * $factor );

		return $base_timestamp + $delay;
	}

	/**
	 * Get the maximum attempts.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Get the initial delay in seconds.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_initial_delay(): int {
		return $this->initial_delay_seconds;
	}

	/**
	 * Get the backoff multiplier.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_backoff_multiplier(): int {
		return $this->backoff_multiplier;
	}

	/**
	 * Get the maximum delay ceiling.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_max_delay(): int {
		return $this->max_delay_seconds;
	}
}
