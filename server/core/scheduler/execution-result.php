<?php
/**
 * Background job execution result DTO.
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
 * ExecutionResult — data transfer object representing a job's execution outcome.
 *
 * @since 1.7.0
 */
class ExecutionResult {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILURE = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Execution status.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $status;

	/**
	 * Brief human-readable summary of work performed.
	 *
	 * @var string|null
	 * @since 1.7.0
	 */
	private ?string $summary;

	/**
	 * Sanitized error message if execution failed.
	 *
	 * @var string|null
	 * @since 1.7.0
	 */
	private ?string $error;

	/**
	 * Whether the failure is permanent/fatal and should not be retried.
	 *
	 * @var bool
	 * @since 1.7.0
	 */
	private bool $is_fatal;

	/**
	 * Additional structured metadata from the execution.
	 *
	 * @var array<string, mixed>
	 * @since 1.7.0
	 */
	private array $metadata;

	/**
	 * Private constructor; use static factory methods.
	 *
	 * @param string               $status   Outcome status.
	 * @param string|null          $summary  Summary of work.
	 * @param string|null          $error    Error message if failed.
	 * @param bool                 $is_fatal Whether the failure is terminal.
	 * @param array<string, mixed> $metadata Structured metadata.
	 * @since 1.7.0
	 */
	private function __construct(
		string $status,
		?string $summary = null,
		?string $error = null,
		bool $is_fatal = false,
		array $metadata = array()
	) {
		$this->status   = $status;
		$this->summary  = $summary;
		$this->error    = $error;
		$this->is_fatal = $is_fatal;
		$this->metadata = $metadata;
	}

	/**
	 * Create a successful execution result.
	 *
	 * @param string|null          $summary  Brief human-readable summary.
	 * @param array<string, mixed> $metadata Structured result details.
	 * @return self
	 * @since 1.7.0
	 */
	public static function success( ?string $summary = null, array $metadata = array() ): self {
		return new self( self::STATUS_SUCCESS, $summary, null, false, $metadata );
	}

	/**
	 * Create a failed execution result.
	 *
	 * @param string $error    Error message.
	 * @param bool   $is_fatal True if the error is terminal and shouldn't be retried.
	 * @return self
	 * @since 1.7.0
	 */
	public static function failure( string $error, bool $is_fatal = false ): self {
		return new self( self::STATUS_FAILURE, null, $error, $is_fatal );
	}

	/**
	 * Create a skipped execution result (e.g. overlap prevented or conditions not met).
	 *
	 * @param string $reason Brief explanation for skipping.
	 * @return self
	 * @since 1.7.0
	 */
	public static function skipped( string $reason ): self {
		return new self( self::STATUS_SKIPPED, $reason );
	}

	/**
	 * Determine whether the execution was successful.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_success(): bool {
		return self::STATUS_SUCCESS === $this->status;
	}

	/**
	 * Determine whether the execution failed.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_failure(): bool {
		return self::STATUS_FAILURE === $this->status;
	}

	/**
	 * Determine whether the execution was skipped.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_skipped(): bool {
		return self::STATUS_SKIPPED === $this->status;
	}

	/**
	 * Determine whether the failure is terminal.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_fatal(): bool {
		return $this->is_fatal;
	}

	/**
	 * Get the execution status string.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get the summary message.
	 *
	 * @return string|null
	 * @since 1.7.0
	 */
	public function get_summary(): ?string {
		return $this->summary;
	}

	/**
	 * Get the error message.
	 *
	 * @return string|null
	 * @since 1.7.0
	 */
	public function get_error(): ?string {
		return $this->error;
	}

	/**
	 * Get the structured metadata.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}
}
