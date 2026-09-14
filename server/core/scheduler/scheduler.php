<?php
/**
 * Central background job scheduler.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Scheduler — central orchestrator for background jobs and cron execution.
 *
 * Coordinates job registry discovery, atomic database claiming, process-level
 * concurrency safety, execution lifecycle, bounded exponential backoff retries,
 * catch-up handling, and persistent execution history.
 *
 * @since 1.7.0
 */
class Scheduler {

	/**
	 * Job registry instance.
	 *
	 * @var JobRegistry
	 * @since 1.7.0
	 */
	private JobRegistry $registry;

	/**
	 * Persistence repository instance.
	 *
	 * @var SchedulerRepository
	 * @since 1.7.0
	 */
	private SchedulerRepository $repository;

	/**
	 * Optional logger callback for CLI/worker progress output.
	 *
	 * @var callable|null
	 * @since 1.7.0
	 */
	private $logger;

	/**
	 * Settings API instance.
	 *
	 * @var SettingsApi|null
	 * @since 1.7.0
	 */
	private ?SettingsApi $settings_api;

	/**
	 * Configured retention days fallback/override.
	 *
	 * @var int
	 * @since 1.7.0
	 */
	private int $retention_days;

	/**
	 * Create a new Scheduler instance.
	 *
	 * @param JobRegistry         $registry       Job registry.
	 * @param SchedulerRepository $repository     Persistence repository.
	 * @param callable|null       $logger         Optional logging callback.
	 * @param SettingsApi|null    $settings_api   Optional settings API for retention persistence.
	 * @param int|null            $retention_days Optional retention days override.
	 * @since 1.7.0
	 */
	public function __construct(
		JobRegistry $registry,
		SchedulerRepository $repository,
		?callable $logger = null,
		?SettingsApi $settings_api = null,
		?int $retention_days = null
	) {
		$this->registry     = $registry;
		$this->repository   = $repository;
		$this->logger       = $logger;
		$this->settings_api = $settings_api;

		if ( null !== $retention_days ) {
			$this->retention_days = max( 0, $retention_days );
		} elseif ( null !== $this->settings_api ) {
			$stored_retention = $this->settings_api->get_option( Constants::SETTING_CRON_HISTORY_RETENTION_DAYS );
			if ( null !== $stored_retention && '' !== trim( $stored_retention ) && is_numeric( $stored_retention ) ) {
				$this->retention_days = max( 0, (int) $stored_retention );
			} else {
				$this->retention_days = Constants::DEFAULT_CRON_HISTORY_RETENTION_DAYS;
			}
		} else {
			$this->retention_days = Constants::DEFAULT_CRON_HISTORY_RETENTION_DAYS;
		}
	}

	/**
	 * Synchronize registered job definitions with the database.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function sync(): void {
		$this->repository->sync_registered_jobs( $this->registry->all() );
	}

	/**
	 * Execute all background jobs that are currently due or have expired claims.
	 *
	 * Individual job failures are isolated and logged; one failing job will
	 * never prevent subsequent due jobs from executing.
	 *
	 * @param string|null $now Optional MySQL datetime timestamp override.
	 * @return array<string, array<string, mixed>> Execution summary keyed by job ID.
	 * @since 1.7.0
	 */
	public function run_due_jobs( ?string $now = null ): array {
		$this->sync();

		$due_rows = $this->repository->get_due_jobs( $now );
		$outcomes = array();

		foreach ( $due_rows as $due_job_row ) {
			$job_id = (string) ( $due_job_row['id'] ?? '' );
			if ( '' === $job_id ) {
				continue;
			}

			$definition = $this->registry->get( $job_id );
			if ( null === $definition ) {
				$this->log( sprintf( 'Skipping unregistered job row: %s', $job_id ) );
				continue;
			}

			$execution_result = $this->execute_job( $definition, $due_job_row, false, $now );

			$outcomes[ $job_id ] = array(
				'status'  => $execution_result->get_status(),
				'summary' => $execution_result->get_summary(),
				'error'   => $execution_result->get_error(),
			);
		}

		$this->prune_history();

		return $outcomes;
	}

	/**
	 * Run a specific job immediately by ID (for manual run-now and targeted CLI execution).
	 *
	 * Validates the job ID against the registry, rejects unknown IDs safely,
	 * respects exclusive process locking, and writes execution history.
	 *
	 * @param string      $job_id Unique job identifier.
	 * @param bool        $force  True to bypass due timestamp check.
	 * @param string|null $now    Optional MySQL datetime timestamp override.
	 * @return ExecutionResult Execution result outcome.
	 *
	 * @throws \InvalidArgumentException When the job ID is not registered.
	 * @since 1.7.0
	 */
	public function run_job( string $job_id, bool $force = true, ?string $now = null ): ExecutionResult {
		$definition = $this->registry->get( $job_id );

		if ( null === $definition ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown background job identifier: %s', $job_id )
			);
		}

		$this->sync();
		$job_row = $this->repository->get_job( $job_id );

		return $this->execute_job( $definition, $job_row, $force, $now );
	}

	/**
	 * Return aggregated status and recent history for all registered jobs.
	 *
	 * @return array<string, mixed> Scheduler status payload.
	 * @since 1.7.0
	 */
	public function get_status(): array {
		$this->sync();

		$db_jobs = $this->repository->get_all_jobs();
		$jobs    = array();

		foreach ( $this->registry->all() as $job_id => $definition ) {
			$matching_row = null;
			foreach ( $db_jobs as $job_row ) {
				if ( ( $job_row['id'] ?? '' ) === $job_id ) {
					$matching_row = $job_row;
					break;
				}
			}

			$recent_runs = $this->repository->get_job_runs( $job_id, 5 );

			$jobs[] = array(
				'id'               => $job_id,
				'title'            => $definition->get_title(),
				'interval_seconds' => $definition->get_interval_seconds(),
				'status'           => (string) ( $matching_row['status'] ?? 'idle' ),
				'is_enabled'       => ! empty( $matching_row['is_enabled'] ),
				'next_run_at'      => $matching_row['next_run_at'] ? Date::to_iso( (string) $matching_row['next_run_at'] ) : null,
				'last_run_at'      => ! empty( $matching_row['last_run_at'] ) ? Date::to_iso( (string) $matching_row['last_run_at'] ) : null,
				'last_finished_at' => ! empty( $matching_row['last_finished_at'] ) ? Date::to_iso( (string) $matching_row['last_finished_at'] ) : null,
				'attempts'         => (int) ( $matching_row['attempts'] ?? 0 ),
				'max_attempts'     => (int) ( $matching_row['max_attempts'] ?? $definition->get_retry_policy()->get_max_attempts() ),
				'last_error'       => $matching_row['last_error'] ?? null,
				'recent_runs'      => array_map(
					function ( array $run_record ): array {
						return array(
							'id'             => (string) ( $run_record['id'] ?? '' ),
							'status'         => (string) ( $run_record['status'] ?? '' ),
							'attempt'        => (int) ( $run_record['attempt'] ?? 1 ),
							'started_at'     => Date::to_iso( (string) ( $run_record['started_at'] ?? '' ) ),
							'finished_at'    => ! empty( $run_record['finished_at'] ) ? Date::to_iso( (string) $run_record['finished_at'] ) : null,
							'duration_ms'    => isset( $run_record['duration_ms'] ) ? (int) $run_record['duration_ms'] : null,
							'output_summary' => $run_record['output_summary'] ?? null,
							'error_message'  => $run_record['error_message'] ?? null,
						);
					},
					$recent_runs
				),
			);
		}

		return array(
			'jobs'           => $jobs,
			'jobs_count'     => count( $jobs ),
			'retention_days' => $this->get_retention_days(),
		);
	}

	/**
	 * Execute a single claimed job definition with locking and result handling.
	 *
	 * @param JobDefinition             $definition Job definition.
	 * @param array<string, mixed>|null $job_row    Database row state if available.
	 * @param bool                      $force      Whether this is an unconstrained manual run.
	 * @param string|null               $now        Optional MySQL datetime timestamp override.
	 * @return ExecutionResult Outcome.
	 * @since 1.7.0
	 */
	private function execute_job(
		JobDefinition $definition,
		?array $job_row,
		bool $force,
		?string $now
	): ExecutionResult {
		$job_id     = $definition->get_id();
		$lock_token = Str::random_id();

		if ( $definition->prevents_overlap() ) {
			$claimed = $this->repository->claim_job(
				$job_id,
				$lock_token,
				$definition->get_lease_seconds(),
				$force,
				$now
			);

			if ( ! $claimed ) {
				$reason = sprintf( 'Job [%s] is already locked/running in another process.', $job_id );
				$this->log( $reason );
				return ExecutionResult::skipped( $reason );
			}
		}

		$now_time        = $now ?? Date::now();
		$current_attempt = (int) ( ( $job_row['attempts'] ?? 0 ) + 1 );
		$run_id          = $this->repository->record_run_start( $job_id, $current_attempt, $now_time );
		$context         = new ExecutionContext( $job_id, $run_id, $current_attempt, $force, $now_time );

		$this->log( sprintf( 'Executing job [%s] (Attempt %d, Run ID: %s)...', $job_id, $current_attempt, $run_id ) );

		$start_ts = microtime( true );

		try {
			$result = $definition->run( $context );
		} catch ( \Throwable $exception ) {
			$result = ExecutionResult::failure( $exception->getMessage() );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_ts ) * 1000 );

		if ( $result->is_success() ) {
			// Catch-up policy: advances to future timestamp based on current time
			// so downtime never replays every missed interval infinitely.
			$base_epoch  = max( time(), (int) strtotime( $now_time . ' UTC' ) );
			$next_epoch  = $base_epoch + $definition->get_interval_seconds();
			$next_run_at = gmdate( 'Y-m-d H:i:s', $next_epoch );

			$this->repository->record_success(
				$job_id,
				$run_id,
				$lock_token,
				$now_time,
				$next_run_at,
				$duration_ms,
				$result->get_summary(),
				$now
			);

			$this->log(
				sprintf(
					'Job [%s] succeeded in %dms. Next run: %s. %s',
					$job_id,
					$duration_ms,
					$next_run_at,
					(string) $result->get_summary()
				)
			);
		} elseif ( $result->is_skipped() ) {
			$base_epoch  = max( time(), (int) strtotime( $now_time . ' UTC' ) );
			$next_epoch  = $base_epoch + $definition->get_interval_seconds();
			$next_run_at = gmdate( 'Y-m-d H:i:s', $next_epoch );

			$this->repository->record_skipped(
				$job_id,
				$run_id,
				$lock_token,
				$now_time,
				$next_run_at,
				$duration_ms,
				$result->get_summary(),
				$now
			);

			$this->log(
				sprintf(
					'Job [%s] skipped in %dms: %s. Next run: %s.',
					$job_id,
					$duration_ms,
					(string) $result->get_summary(),
					$next_run_at
				)
			);
		} else {
			// Failed execution: apply bounded retry policy.
			$retry_policy = $definition->get_retry_policy();
			$is_retryable = ( ! $result->is_fatal() ) && $retry_policy->is_retryable( $current_attempt );
			$base_epoch   = (int) strtotime( $now_time . ' UTC' );

			if ( $is_retryable ) {
				$next_epoch  = $retry_policy->calculate_next_retry( $current_attempt, $base_epoch );
				$next_run_at = gmdate( 'Y-m-d H:i:s', $next_epoch );
				$is_terminal = false;

				$this->log(
					sprintf(
						'Job [%s] failed (Attempt %d/%d): %s. Retrying at: %s.',
						$job_id,
						$current_attempt,
						$retry_policy->get_max_attempts(),
						(string) $result->get_error(),
						$next_run_at
					)
				);
			} else {
				// Terminal failure: schedule next regular occurrence so it doesn't loop infinitely.
				$next_epoch  = max( time(), $base_epoch ) + $definition->get_interval_seconds();
				$next_run_at = gmdate( 'Y-m-d H:i:s', $next_epoch );
				$is_terminal = true;

				$this->log(
					sprintf(
						'Job [%s] failed terminally after %d attempts: %s. Next scheduled attempt: %s.',
						$job_id,
						$current_attempt,
						(string) $result->get_error(),
						$next_run_at
					)
				);
			}

			$this->repository->record_failure(
				$job_id,
				$run_id,
				$lock_token,
				$now_time,
				$next_run_at,
				$is_terminal,
				$duration_ms,
				(string) $result->get_error(),
				$now
			);
		}

		return $result;
	}

	/**
	 * Log a message to the registered logger or error_log.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.7.0
	 */
	private function log( string $message ): void {
		if ( is_callable( $this->logger ) ) {
			call_user_func( $this->logger, $message );
		}
	}

	/**
	 * Get the internal JobRegistry.
	 *
	 * @return JobRegistry
	 * @since 1.7.0
	 */
	public function get_registry(): JobRegistry {
		return $this->registry;
	}

	/**
	 * Get the internal persistence repository.
	 *
	 * @return SchedulerRepository
	 * @since 1.7.0
	 */
	public function get_repository(): SchedulerRepository {
		return $this->repository;
	}

	/**
	 * Get the configured execution history retention period in days.
	 *
	 * @return int Number of days (0 indicates indefinite retention / disabled pruning).
	 * @since 1.7.0
	 */
	public function get_retention_days(): int {
		if ( null !== $this->settings_api ) {
			$stored_retention = $this->settings_api->get_option( Constants::SETTING_CRON_HISTORY_RETENTION_DAYS );
			if ( null !== $stored_retention && '' !== trim( $stored_retention ) && is_numeric( $stored_retention ) ) {
				return max( 0, (int) $stored_retention );
			}
		}

		return $this->retention_days;
	}

	/**
	 * Update the execution history retention period in days.
	 *
	 * @param int $retention_days Number of days (0 disables automatic pruning).
	 * @return void
	 * @since 1.7.0
	 */
	public function set_retention_days( int $retention_days ): void {
		$days                 = max( 0, $retention_days );
		$this->retention_days = $days;

		if ( null !== $this->settings_api ) {
			$this->settings_api->update_option(
				Constants::SETTING_CRON_HISTORY_RETENTION_DAYS,
				(string) $days,
				false
			);
		}
	}

	/**
	 * Prune old terminal execution runs according to the retention policy.
	 *
	 * @param int|null $retention_days Optional override for retention days.
	 * @return int Total number of deleted history rows.
	 * @since 1.7.0
	 */
	public function prune_history( ?int $retention_days = null ): int {
		$days = ( null !== $retention_days ) ? max( 0, $retention_days ) : $this->get_retention_days();

		if ( $days <= 0 ) {
			return 0;
		}

		$pruned = $this->repository->prune_history( $days );
		if ( $pruned > 0 ) {
			$this->log( sprintf( 'Pruned %d stale cron execution history rows (retention: %d days).', $pruned, $days ) );
		}

		return $pruned;
	}

	/**
	 * Clear terminal execution run history across all jobs or for a specific job.
	 *
	 * @param string|null $job_id Optional job identifier to scope deletion.
	 * @return int Total number of deleted history rows.
	 * @since 1.7.0
	 */
	public function clear_history( ?string $job_id = null ): int {
		return $this->repository->clear_history( $job_id );
	}
}
