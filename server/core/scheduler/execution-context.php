<?php
/**
 * Background job execution context.
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
 * ExecutionContext — metadata passed to a job handler during execution.
 *
 * @since 1.7.0
 */
class ExecutionContext {

	/**
	 * Unique job identifier.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $job_id;

	/**
	 * Unique run/attempt execution identifier.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $run_id;

	/**
	 * Current attempt number (1-indexed).
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $attempt;

	/**
	 * Whether this execution was triggered manually (run-now).
	 *
	 * @var bool
	 * @since 1.7.0
	 */
	private bool $is_manual;

	/**
	 * Execution start time in MySQL datetime format (UTC).
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $started_at;

	/**
	 * Create a new execution context.
	 *
	 * @param string $job_id     Unique job identifier.
	 * @param string $run_id     Unique run identifier.
	 * @param int    $attempt    Current attempt number.
	 * @param bool   $is_manual  True if triggered manually.
	 * @param string $started_at MySQL datetime start time.
	 * @since 1.7.0
	 */
	public function __construct(
		string $job_id,
		string $run_id,
		int $attempt,
		bool $is_manual,
		string $started_at
	) {
		$this->job_id     = $job_id;
		$this->run_id     = $run_id;
		$this->attempt    = $attempt;
		$this->is_manual  = $is_manual;
		$this->started_at = $started_at;
	}

	/**
	 * Get the job identifier.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_job_id(): string {
		return $this->job_id;
	}

	/**
	 * Get the execution run identifier.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_run_id(): string {
		return $this->run_id;
	}

	/**
	 * Get the current attempt number.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_attempt(): int {
		return $this->attempt;
	}

	/**
	 * Determine whether this run was manually triggered.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_manual(): bool {
		return $this->is_manual;
	}

	/**
	 * Determine whether this run was manually triggered or forced.
	 *
	 * Alias of is_manual().
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_forced(): bool {
		return $this->is_manual;
	}

	/**
	 * Get the start time.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_started_at(): string {
		return $this->started_at;
	}
}
