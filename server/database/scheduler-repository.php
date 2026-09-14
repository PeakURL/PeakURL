<?php
/**
 * Database repository for scheduler jobs and execution history.
 *
 * @package PeakURL\Database
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Database;

use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SchedulerRepository — database persistence for cron jobs and execution runs.
 *
 * Encapsulates all SQL queries for due discovery, atomic process-safe claiming,
 * execution history, and state transitions.
 *
 * @since 1.7.0
 */
class SchedulerRepository {

	/**
	 * Database wrapper instance.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Create a new scheduler repository.
	 *
	 * @param PeakURL_DB $db Database wrapper instance.
	 * @since 1.7.0
	 */
	public function __construct( PeakURL_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Synchronize declared JobDefinitions with the database table.
	 *
	 * Inserts missing jobs and updates titles/cadences without overwriting
	 * dynamic run state (such as last_run_at or lock status).
	 *
	 * @param array<string, JobDefinition> $definitions Registered definitions.
	 * @return void
	 * @since 1.7.0
	 */
	public function sync_registered_jobs( array $definitions ): void {
		$now = Date::now();

		foreach ( $definitions as $def ) {
			$existing = $this->db->get_row_by( 'cron_jobs', array( 'id' => $def->get_id() ) );
			$retry    = $def->get_retry_policy();

			if ( empty( $existing ) ) {
				$this->db->insert(
					'cron_jobs',
					array(
						'id'                => $def->get_id(),
						'title'             => $def->get_title(),
						'schedule_interval' => $def->get_interval_seconds(),
						'status'            => 'idle',
						'next_run_at'       => $now,
						'attempts'          => 0,
						'max_attempts'      => $retry->get_max_attempts(),
						'retry_delay'       => $retry->get_initial_delay(),
						'is_enabled'        => $def->is_enabled() ? 1 : 0,
						'created_at'        => $now,
						'updated_at'        => $now,
					)
				);
			} else {
				$this->db->update(
					'cron_jobs',
					array(
						'title'             => $def->get_title(),
						'schedule_interval' => $def->get_interval_seconds(),
						'max_attempts'      => $retry->get_max_attempts(),
						'retry_delay'       => $retry->get_initial_delay(),
						'is_enabled'        => $def->is_enabled() ? 1 : 0,
						'updated_at'        => $now,
					),
					array( 'id' => $def->get_id() )
				);
			}
		}
	}

	/**
	 * Retrieve all due or stale-claimed background jobs.
	 *
	 * @param string|null $now Optional MySQL datetime timestamp for testing.
	 * @return array<int, array<string, mixed>> Due job rows.
	 * @since 1.7.0
	 */
	public function get_due_jobs( ?string $now = null ): array {
		$now_time = $now ?? Date::now();

		$sql = 'SELECT * FROM cron_jobs
			WHERE is_enabled = 1
			AND (
				( status != :running_status AND next_run_at <= :now_time_due )
				OR
				( status = :running_status_lock AND lock_expires_at IS NOT NULL AND lock_expires_at < :now_time_stale )
			)
			ORDER BY next_run_at ASC';

		$results = $this->db->get_results(
			$sql,
			array(
				'running_status'      => 'running',
				'now_time_due'        => $now_time,
				'running_status_lock' => 'running',
				'now_time_stale'      => $now_time,
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Atomically claim a job for exclusive execution.
	 *
	 * Ensures that between separate concurrent PHP processes, exactly one
	 * process acquires the lock. Also allows recovering stale locks whose
	 * lease expired.
	 *
	 * @param string      $job_id        Job identifier.
	 * @param string      $lock_token    Unique token generated for this attempt.
	 * @param int         $lease_seconds Lock lease duration in seconds.
	 * @param bool        $force         True to bypass due timestamp (for manual execution).
	 * @param string|null $now           Optional MySQL datetime timestamp for testing.
	 * @return bool True if the claim was acquired, false if claimed by another process.
	 * @since 1.7.0
	 */
	public function claim_job(
		string $job_id,
		string $lock_token,
		int $lease_seconds = 300,
		bool $force = false,
		?string $now = null
	): bool {
		$now_time   = $now ?? Date::now();
		$base_epoch = strtotime( $now_time . ' UTC' );
		if ( false === $base_epoch ) {
			$base_epoch = time();
		}
		$lock_expires = gmdate( 'Y-m-d H:i:s', $base_epoch + max( 30, $lease_seconds ) );

		if ( $force ) {
			$sql = 'UPDATE cron_jobs
				SET status = :status_running,
					locked_at = :locked_at,
					lock_token = :lock_token,
					lock_expires_at = :lock_expires,
					attempts = attempts + 1,
					updated_at = :updated_at
				WHERE id = :job_id
				AND is_enabled = 1
				AND (
					status != :status_check
					OR ( status = :status_check_stale AND lock_expires_at IS NOT NULL AND lock_expires_at < :now_stale )
				)';

			$affected = $this->db->query(
				$sql,
				array(
					'status_running'     => 'running',
					'locked_at'          => $now_time,
					'lock_token'         => $lock_token,
					'lock_expires'       => $lock_expires,
					'updated_at'         => $now_time,
					'job_id'             => $job_id,
					'status_check'       => 'running',
					'status_check_stale' => 'running',
					'now_stale'          => $now_time,
				)
			);
		} else {
			$sql = 'UPDATE cron_jobs
				SET status = :status_running,
					locked_at = :locked_at,
					lock_token = :lock_token,
					lock_expires_at = :lock_expires,
					attempts = attempts + 1,
					updated_at = :updated_at
				WHERE id = :job_id
				AND is_enabled = 1
				AND (
					( status != :status_check AND next_run_at <= :now_due )
					OR ( status = :status_check_stale AND lock_expires_at IS NOT NULL AND lock_expires_at < :now_stale )
				)';

			$affected = $this->db->query(
				$sql,
				array(
					'status_running'     => 'running',
					'locked_at'          => $now_time,
					'lock_token'         => $lock_token,
					'lock_expires'       => $lock_expires,
					'updated_at'         => $now_time,
					'job_id'             => $job_id,
					'status_check'       => 'running',
					'now_due'            => $now_time,
					'status_check_stale' => 'running',
					'now_stale'          => $now_time,
				)
			);
		}

		return 1 === $affected;
	}

	/**
	 * Insert a running record into cron_runs.
	 *
	 * @param string      $job_id     Job identifier.
	 * @param int         $attempt    Attempt number.
	 * @param string|null $started_at Start timestamp.
	 * @return string Generated run ID.
	 * @since 1.7.0
	 */
	public function record_run_start( string $job_id, int $attempt, ?string $started_at = null ): string {
		$run_id     = Str::random_id();
		$start_time = $started_at ?? Date::now();

		$this->db->insert(
			'cron_runs',
			array(
				'id'         => $run_id,
				'job_id'     => $job_id,
				'status'     => 'running',
				'attempt'    => max( 1, $attempt ),
				'started_at' => $start_time,
				'created_at' => $start_time,
			)
		);

		return $run_id;
	}

	/**
	 * Record a successful job execution, release the lock, and advance schedule.
	 *
	 * @param string      $job_id         Job identifier.
	 * @param string      $run_id         Run execution identifier.
	 * @param string      $lock_token     Lock token held by the current runner.
	 * @param string      $started_at     Timestamp when execution began.
	 * @param string      $next_run_at    Calculated timestamp for next run.
	 * @param int         $duration_ms    Duration in milliseconds.
	 * @param string|null $output_summary Summary message.
	 * @param string|null $now            Current timestamp override.
	 * @return void
	 * @since 1.7.0
	 */
	public function record_success(
		string $job_id,
		string $run_id,
		string $lock_token,
		string $started_at,
		string $next_run_at,
		int $duration_ms,
		?string $output_summary = null,
		?string $now = null
	): void {
		$now_time = $now ?? Date::now();

		$this->db->query(
			'UPDATE cron_jobs
			SET status = :idle_status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				attempts = 0,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = NULL,
				next_run_at = :next_run,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'idle_status'   => 'idle',
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'next_run'      => $next_run_at,
				'updated_at'    => $now_time,
				'job_id'        => $job_id,
				'lock_token'    => $lock_token,
			)
		);

		$summary = null !== $output_summary ? substr( trim( $output_summary ), 0, 255 ) : null;

		$this->db->update(
			'cron_runs',
			array(
				'status'         => 'success',
				'finished_at'    => $now_time,
				'duration_ms'    => max( 0, $duration_ms ),
				'output_summary' => $summary,
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Record a skipped job execution, release the lock, and advance schedule.
	 *
	 * @param string      $job_id         Job identifier.
	 * @param string      $run_id         Run execution identifier.
	 * @param string      $lock_token     Lock token held by current runner.
	 * @param string      $started_at     Timestamp when execution began.
	 * @param string      $next_run_at    Calculated timestamp for next run.
	 * @param int         $duration_ms    Duration in milliseconds.
	 * @param string|null $output_summary Summary or skip reason.
	 * @param string|null $now            Current timestamp override.
	 * @return void
	 * @since 1.7.0
	 */
	public function record_skipped(
		string $job_id,
		string $run_id,
		string $lock_token,
		string $started_at,
		string $next_run_at,
		int $duration_ms,
		?string $output_summary = null,
		?string $now = null
	): void {
		$now_time = $now ?? Date::now();

		$this->db->query(
			'UPDATE cron_jobs
			SET status = :idle_status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				attempts = 0,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = NULL,
				next_run_at = :next_run,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'idle_status'   => 'idle',
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'next_run'      => $next_run_at,
				'updated_at'    => $now_time,
				'job_id'        => $job_id,
				'lock_token'    => $lock_token,
			)
		);

		$summary = null !== $output_summary ? substr( trim( $output_summary ), 0, 255 ) : null;
		if ( null !== $summary && 0 !== strpos( $summary, 'Skipped: ' ) ) {
			$summary = 'Skipped: ' . $summary;
		}

		$this->db->update(
			'cron_runs',
			array(
				'status'         => 'success',
				'finished_at'    => $now_time,
				'duration_ms'    => max( 0, $duration_ms ),
				'output_summary' => $summary,
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Record a failed execution, handle retry or terminal status, and release lock.
	 *
	 * @param string      $job_id        Job identifier.
	 * @param string      $run_id        Run execution identifier.
	 * @param string      $lock_token    Lock token held by current runner.
	 * @param string      $started_at    Timestamp when execution started.
	 * @param string      $next_run_at   Calculated timestamp for next retry or regular run.
	 * @param bool        $is_terminal   True if max attempts reached or fatal error.
	 * @param int         $duration_ms   Duration in milliseconds.
	 * @param string      $error_message Sanitized error message.
	 * @param string|null $now           Current timestamp override.
	 * @return void
	 * @since 1.7.0
	 */
	public function record_failure(
		string $job_id,
		string $run_id,
		string $lock_token,
		string $started_at,
		string $next_run_at,
		bool $is_terminal,
		int $duration_ms,
		string $error_message,
		?string $now = null
	): void {
		$now_time    = $now ?? Date::now();
		$next_status = $is_terminal ? 'failed' : 'idle';
		$run_status  = $is_terminal ? 'failed' : 'retrying';

		$this->db->query(
			'UPDATE cron_jobs
			SET status = :next_status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = :error_message,
				next_run_at = :next_run,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'next_status'   => $next_status,
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'error_message' => $error_message,
				'next_run'      => $next_run_at,
				'updated_at'    => $now_time,
				'job_id'        => $job_id,
				'lock_token'    => $lock_token,
			)
		);

		$this->db->update(
			'cron_runs',
			array(
				'status'        => $run_status,
				'finished_at'   => $now_time,
				'duration_ms'   => max( 0, $duration_ms ),
				'error_message' => $error_message,
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Retrieve a single job row by ID.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null Job row or null.
	 * @since 1.7.0
	 */
	public function get_job( string $job_id ): ?array {
		$row = $this->db->get_row_by( 'cron_jobs', array( 'id' => $job_id ) );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Retrieve all persistent job rows.
	 *
	 * @return array<int, array<string, mixed>> All registered jobs.
	 * @since 1.7.0
	 */
	public function get_all_jobs(): array {
		$results = $this->db->get_results(
			'SELECT * FROM cron_jobs ORDER BY id ASC'
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Retrieve recent run history for a specific job.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $limit  Maximum number of history items to return.
	 * @return array<int, array<string, mixed>> Execution run rows.
	 * @since 1.7.0
	 */
	public function get_job_runs( string $job_id, int $limit = 20 ): array {
		$results = $this->db->get_results(
			'SELECT * FROM cron_runs
			WHERE job_id = :job_id
			ORDER BY created_at DESC
			LIMIT ' . max( 1, (int) $limit ),
			array(
				'job_id' => $job_id,
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Retrieve recent run history across all jobs.
	 *
	 * @param int $limit Maximum number of history items to return.
	 * @return array<int, array<string, mixed>> Execution run rows.
	 * @since 1.7.0
	 */
	public function get_latest_runs( int $limit = 50 ): array {
		$results = $this->db->get_results(
			'SELECT * FROM cron_runs
			ORDER BY created_at DESC
			LIMIT ' . max( 1, (int) $limit )
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Prune completed execution history runs older than the specified retention days.
	 *
	 * Runs with 'running' or 'retrying' status are strictly excluded to avoid
	 * corrupting active executions.
	 *
	 * @param int $retention_days Number of days of history to retain (<= 0 disables pruning).
	 * @param int $batch_size     Maximum number of records to delete per batch.
	 * @return int Total number of pruned history rows.
	 * @since 1.7.0
	 */
	public function prune_history( int $retention_days, int $batch_size = 500 ): int {
		if ( $retention_days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * 86400 ) );
		$limit  = max( 1, (int) $batch_size );

		$total_deleted  = 0;
		$max_iterations = 10;
		$iterations     = 0;

		do {
			$deleted = $this->db->query(
				'DELETE FROM cron_runs
				WHERE created_at < :cutoff
				AND status != :running_status
				AND status != :retrying_status
				LIMIT ' . $limit,
				array(
					'cutoff'          => $cutoff,
					'running_status'  => 'running',
					'retrying_status' => 'retrying',
				)
			);

			$total_deleted += $deleted;
			++$iterations;
		} while ( $deleted === $limit && $iterations < $max_iterations );

		return $total_deleted;
	}

	/**
	 * Clear finished execution run history across all jobs or for a specific job.
	 *
	 * Runs with 'running' or 'retrying' status are strictly excluded to protect
	 * active executions.
	 *
	 * @param string|null $job_id Optional job identifier to scope deletion.
	 * @return int Total number of deleted history rows.
	 * @since 1.7.0
	 */
	public function clear_history( ?string $job_id = null ): int {
		$clean_job_id = ( null !== $job_id && '' !== trim( $job_id ) ) ? trim( $job_id ) : null;

		if ( null !== $clean_job_id ) {
			return $this->db->query(
				'DELETE FROM cron_runs
				WHERE job_id = :job_id
				AND status != :running_status
				AND status != :retrying_status',
				array(
					'job_id'          => $clean_job_id,
					'running_status'  => 'running',
					'retrying_status' => 'retrying',
				)
			);
		}

		return $this->db->query(
			'DELETE FROM cron_runs
			WHERE status != :running_status
			AND status != :retrying_status',
			array(
				'running_status'  => 'running',
				'retrying_status' => 'retrying',
			)
		);
	}
}
