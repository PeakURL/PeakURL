<?php
/**
 * Background job definition.
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
 * JobDefinition — metadata and execution wrapper for a registered background job.
 *
 * @since 1.7.0
 */
class JobDefinition {

	public const OVERLAP_PREVENT = 'prevent';
	public const OVERLAP_ALLOW   = 'allow';

	/**
	 * Unique alphanumeric job identifier.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $id;

	/**
	 * Human-readable job title.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $title;

	/**
	 * Recurring execution interval in seconds (e.g. 3600 for hourly).
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $interval_seconds;

	/**
	 * Job execution handler: instance of JobHandlerInterface or callable.
	 *
	 * @var JobHandlerInterface|callable
	 * @since 1.7.0
	 */
	private $handler;

	/**
	 * Retry and backoff configuration.
	 *
	 * @var RetryPolicy
	 * @since 1.7.0
	 */
	private RetryPolicy $retry_policy;

	/**
	 * Overlap policy (prevent or allow).
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $overlap_policy;

	/**
	 * Maximum lock lease duration in seconds before considered stale (default 300s).
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $lease_seconds;

	/**
	 * Whether the job is enabled.
	 *
	 * @var bool
	 * @since 1.7.0
	 */
	private bool $is_enabled;

	/**
	 * Create a new job definition.
	 *
	 * @param string                       $id               Job identifier (alphanumeric, underscores, hyphens).
	 * @param string                       $title            Human-readable job title.
	 * @param int                          $interval_seconds Cadence in seconds.
	 * @param JobHandlerInterface|callable $handler          Executable handler.
	 * @param RetryPolicy|null             $retry_policy     Retry policy (defaults to standard).
	 * @param string                       $overlap_policy   `prevent` (default) or `allow`.
	 * @param int                          $lease_seconds    Lock lease duration in seconds.
	 * @param bool                         $is_enabled       Initial enabled state.
	 *
	 * @throws \InvalidArgumentException When ID or interval is invalid.
	 * @since 1.7.0
	 */
	public function __construct(
		string $id,
		string $title,
		int $interval_seconds,
		$handler,
		?RetryPolicy $retry_policy = null,
		string $overlap_policy = self::OVERLAP_PREVENT,
		int $lease_seconds = 300,
		bool $is_enabled = true
	) {
		$clean_id = trim( $id );
		if ( '' === $clean_id || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $clean_id ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid job identifier: %s', $id )
			);
		}

		if ( ! ( $handler instanceof JobHandlerInterface ) && ! is_callable( $handler ) ) {
			throw new \InvalidArgumentException(
				'Job handler must implement JobHandlerInterface or be a valid callable.'
			);
		}

		$this->id               = $clean_id;
		$this->title            = trim( $title ) !== '' ? trim( $title ) : $clean_id;
		$this->interval_seconds = max( 1, $interval_seconds );
		$this->handler          = $handler;
		$this->retry_policy     = $retry_policy ?? RetryPolicy::standard();
		$this->overlap_policy   = self::OVERLAP_ALLOW === $overlap_policy ? self::OVERLAP_ALLOW : self::OVERLAP_PREVENT;
		$this->lease_seconds    = max( 30, $lease_seconds );
		$this->is_enabled       = $is_enabled;
	}

	/**
	 * Execute the registered handler.
	 *
	 * @param ExecutionContext $context Execution context.
	 * @return ExecutionResult Execution outcome.
	 * @since 1.7.0
	 */
	public function run( ExecutionContext $context ): ExecutionResult {
		if ( $this->handler instanceof JobHandlerInterface ) {
			return $this->handler->execute( $context );
		}

		$result = call_user_func( $this->handler, $context );

		if ( $result instanceof ExecutionResult ) {
			return $result;
		}

		return ExecutionResult::success( is_string( $result ) ? $result : null );
	}

	/**
	 * Get the job identifier.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the human-readable job title.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Get the recurrence interval in seconds.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_interval_seconds(): int {
		return $this->interval_seconds;
	}

	/**
	 * Get the retry policy.
	 *
	 * @return RetryPolicy
	 * @since 1.7.0
	 */
	public function get_retry_policy(): RetryPolicy {
		return $this->retry_policy;
	}

	/**
	 * Get the overlap policy.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_overlap_policy(): string {
		return $this->overlap_policy;
	}

	/**
	 * Determine whether overlap is prevented for this job.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function prevents_overlap(): bool {
		return self::OVERLAP_PREVENT === $this->overlap_policy;
	}

	/**
	 * Get the lease timeout in seconds.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function get_lease_seconds(): int {
		return $this->lease_seconds;
	}

	/**
	 * Determine whether the job is enabled.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_enabled(): bool {
		return $this->is_enabled;
	}
}
