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
						'retention_days'    => null,
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
						'title'        => $def->get_title(),
						'max_attempts' => $retry->get_max_attempts(),
						'retry_delay'  => $retry->get_initial_delay(),
						'updated_at'   => $now,
					),
					array( 'id' => $def->get_id() )
				);
			}
		}
	}

	/**
	 * Enqueue or update a targeted one-off or scheduled background job.
	 *
	 * Idempotently creates or re-enables the job record without duplicating rows.
	 *
	 * @param string      $job_id           Unique job identifier.
	 * @param string      $title            Human-readable job title.
	 * @param int         $interval_seconds Recurring interval (0 for one-off).
	 * @param string|null $run_at           Target execution timestamp (UTC).
	 * @param int         $max_attempts     Max retry attempts.
	 * @param int         $retry_delay      Initial retry delay in seconds.
	 * @return string The effective enqueued job identifier.
	 * @since 1.7.1
	 */
	public function enqueue_job(
		string $job_id,
		string $title,
		int $interval_seconds = 0,
		?string $run_at = null,
		int $max_attempts = 3,
		int $retry_delay = 60
	): string {
		$now      = Date::now();
		$next_run = $run_at ?? $now;

		$is_next_target = str_ends_with( $job_id, ':next' );
		$primary_id     = $is_next_target ? substr( $job_id, 0, -5 ) : $job_id;
		$next_id        = $primary_id . ':next';

		$did_begin = false;
		if ( ! $this->db->in_transaction() ) {
			$this->db->begin_transaction();
			$did_begin = true;
		}

		try {
			// 1. Atomically lock and inspect primary job state.
			$primary_row = $this->db->get_row(
				'SELECT * FROM cron_jobs WHERE id = :id FOR UPDATE',
				array( 'id' => $primary_id )
			);

			$primary_is_running = ! empty( $primary_row )
				&& 'running' === (string) ( $primary_row['status'] ?? '' )
				&& ( empty( $primary_row['lock_expires_at'] ) || strtotime( (string) $primary_row['lock_expires_at'] ) >= strtotime( $now . ' UTC' ) );

			// 2. If primary is not running, also lock and inspect :next to prevent concurrent overlap.
			$next_row = null;
			if ( ! $primary_is_running ) {
				$next_row = $this->db->get_row(
					'SELECT * FROM cron_jobs WHERE id = :id FOR UPDATE',
					array( 'id' => $next_id )
				);
			}

			$next_is_running = ! empty( $next_row )
				&& 'running' === (string) ( $next_row['status'] ?? '' )
				&& ( empty( $next_row['lock_expires_at'] ) || strtotime( (string) $next_row['lock_expires_at'] ) >= strtotime( $now . ' UTC' ) );

			if ( $primary_is_running ) {
				// Primary is executing: enqueue into :next slot, held in non-runnable 'waiting' state until primary finishes.
				$target_id = $next_id;
				$sql       = 'INSERT INTO cron_jobs (
					id, title, schedule_interval, status, next_run_at, attempts, max_attempts, retry_delay, is_enabled, created_at, updated_at
				) VALUES (
					:id, :title, :schedule_interval, :waiting_status, :next_run, 0, :max_attempts, :retry_delay, 0, :created_at, :updated_at
				) ON DUPLICATE KEY UPDATE
					title = VALUES(title),
					next_run_at = VALUES(next_run_at),
					status = IF(status = :running_status, status, :waiting_status2),
					attempts = 0,
					is_enabled = IF(status = :running_status2, is_enabled, 0),
					updated_at = VALUES(updated_at)';

				$this->db->query(
					$sql,
					array(
						'id'                => $target_id,
						'title'             => $title,
						'schedule_interval' => $interval_seconds,
						'waiting_status'    => 'waiting',
						'waiting_status2'   => 'waiting',
						'next_run'          => $next_run,
						'max_attempts'      => $max_attempts,
						'retry_delay'       => $retry_delay,
						'created_at'        => $now,
						'updated_at'        => $now,
						'running_status'    => 'running',
						'running_status2'   => 'running',
					)
				);
			} elseif ( $next_is_running ) {
				// :next is executing: mark :next for follow-up upon completion so primary does not run concurrently.
				$target_id = $next_id;
				$this->db->query(
					'UPDATE cron_jobs
					SET title = :title,
						next_run_at = :next_run,
						attempts = 0,
						updated_at = :now
					WHERE id = :id',
					array(
						'id'       => $target_id,
						'title'    => $title,
						'next_run' => $next_run,
						'now'      => $now,
					)
				);
			} else {
				// Neither is running: activate primary slot.
				$target_id = $primary_id;
				$sql       = 'INSERT INTO cron_jobs (
					id, title, schedule_interval, status, next_run_at, attempts, max_attempts, retry_delay, is_enabled, created_at, updated_at
				) VALUES (
					:id, :title, :schedule_interval, :idle_status, :next_run, 0, :max_attempts, :retry_delay, 1, :created_at, :updated_at
				) ON DUPLICATE KEY UPDATE
					title = VALUES(title),
					next_run_at = VALUES(next_run_at),
					status = :idle_status2,
					attempts = 0,
					is_enabled = 1,
					updated_at = VALUES(updated_at)';

				$this->db->query(
					$sql,
					array(
						'id'                => $target_id,
						'title'             => $title,
						'schedule_interval' => $interval_seconds,
						'idle_status'       => 'idle',
						'idle_status2'      => 'idle',
						'next_run'          => $next_run,
						'max_attempts'      => $max_attempts,
						'retry_delay'       => $retry_delay,
						'created_at'        => $now,
						'updated_at'        => $now,
					)
				);
			}

			if ( $did_begin ) {
				$this->db->commit();
			}

			return $target_id;
		} catch ( \Throwable $e ) {
			if ( $did_begin ) {
				$this->db->roll_back();
			}
			throw $e;
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
			AND status != :waiting_status
			AND (
				( status != :running_status AND next_run_at <= :now_time_due )
				OR
				( status = :running_status_lock AND lock_expires_at IS NOT NULL AND lock_expires_at < :now_time_stale )
			)
			ORDER BY next_run_at ASC';

		$results = $this->db->get_results(
			$sql,
			array(
				'waiting_status'      => 'waiting',
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

		$is_next_target = str_ends_with( $job_id, ':next' );
		$primary_id     = $is_next_target ? substr( $job_id, 0, -5 ) : $job_id;
		$next_id        = $primary_id . ':next';

		$did_begin = false;
		if ( ! $this->db->in_transaction() ) {
			$this->db->begin_transaction();
			$did_begin = true;
		}

		try {
			// Deterministically lock primary and :next rows in alphabetical order.
			$locked_rows = $this->db->get_results(
				'SELECT * FROM cron_jobs WHERE id IN (:id1, :id2) ORDER BY id ASC FOR UPDATE',
				array(
					'id1' => $primary_id,
					'id2' => $next_id,
				)
			);

			$other_id  = $is_next_target ? $primary_id : $next_id;
			$other_row = null;
			foreach ( $locked_rows as $row ) {
				if ( (string) $row['id'] === $other_id ) {
					$other_row = $row;
					break;
				}
			}

			// Mutual exclusivity guard: if the related slot is actively running with an unexpired lease, reject claim.
			if ( ! empty( $other_row ) && 'running' === (string) ( $other_row['status'] ?? '' ) ) {
				$expires          = (string) ( $other_row['lock_expires_at'] ?? '' );
				$is_other_running = '' === $expires || strtotime( $expires . ' UTC' ) >= $base_epoch;
				if ( $is_other_running ) {
					if ( $did_begin ) {
						$this->db->commit();
					}
					return false;
				}
			}

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
					AND status != :waiting_status
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
						'waiting_status'     => 'waiting',
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
					AND status != :waiting_status
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
						'waiting_status'     => 'waiting',
						'status_check'       => 'running',
						'now_due'            => $now_time,
						'status_check_stale' => 'running',
						'now_stale'          => $now_time,
					)
				);
			}

			if ( $did_begin ) {
				$this->db->commit();
			}

			return 1 === $affected;
		} catch ( \Throwable $e ) {
			if ( $did_begin ) {
				$this->db->roll_back();
			}
			throw $e;
		}
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
		$now_time    = $now ?? Date::now();
		$job_row     = $this->get_job( $job_id );
		$is_one_off  = empty( $job_row ) || (int) ( $job_row['schedule_interval'] ?? 0 ) <= 0;
		$re_enqueued = $is_one_off && (
			0 === (int) ( $job_row['attempts'] ?? -1 )
			|| ( ! empty( $job_row['locked_at'] ) && ! empty( $job_row['updated_at'] ) && strtotime( (string) $job_row['updated_at'] ) > strtotime( (string) $job_row['locked_at'] ) )
		);

		$status     = $re_enqueued ? 'idle' : ( $is_one_off ? 'success' : 'idle' );
		$is_enabled = ( $re_enqueued || ! $is_one_off ) ? 1 : 0;
		$next_run   = $re_enqueued ? $now_time : $next_run_at;

		$affected = $this->db->query(
			'UPDATE cron_jobs
			SET status = :status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				attempts = 0,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = NULL,
				next_run_at = :next_run,
				is_enabled = :is_enabled,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'status'        => $status,
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'next_run'      => $next_run,
				'is_enabled'    => $is_enabled,
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

		if ( $affected > 0 ) {
			$this->promote_next_job_if_waiting( $job_id, $now_time );
		}
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
		$now_time    = $now ?? Date::now();
		$job_row     = $this->get_job( $job_id );
		$is_one_off  = empty( $job_row ) || (int) ( $job_row['schedule_interval'] ?? 0 ) <= 0;
		$re_enqueued = $is_one_off && (
			0 === (int) ( $job_row['attempts'] ?? -1 )
			|| ( ! empty( $job_row['locked_at'] ) && ! empty( $job_row['updated_at'] ) && strtotime( (string) $job_row['updated_at'] ) > strtotime( (string) $job_row['locked_at'] ) )
		);

		$status     = $re_enqueued ? 'idle' : ( $is_one_off ? 'skipped' : 'idle' );
		$is_enabled = ( $re_enqueued || ! $is_one_off ) ? 1 : 0;
		$next_run   = $re_enqueued ? $now_time : $next_run_at;

		$affected = $this->db->query(
			'UPDATE cron_jobs
			SET status = :status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				attempts = 0,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = NULL,
				next_run_at = :next_run,
				is_enabled = :is_enabled,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'status'        => $status,
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'next_run'      => $next_run,
				'is_enabled'    => $is_enabled,
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
				'status'         => 'skipped',
				'finished_at'    => $now_time,
				'duration_ms'    => max( 0, $duration_ms ),
				'output_summary' => $summary,
			),
			array( 'id' => $run_id )
		);

		if ( $affected > 0 ) {
			$this->promote_next_job_if_waiting( $job_id, $now_time );
		}
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
		$job_row     = $this->get_job( $job_id );
		$is_one_off  = empty( $job_row ) || (int) ( $job_row['schedule_interval'] ?? 0 ) <= 0;
		$re_enqueued = $is_one_off && (
			0 === (int) ( $job_row['attempts'] ?? -1 )
			|| ( ! empty( $job_row['locked_at'] ) && ! empty( $job_row['updated_at'] ) && strtotime( (string) $job_row['updated_at'] ) > strtotime( (string) $job_row['locked_at'] ) )
		);

		$next_status = $re_enqueued ? 'idle' : ( $is_terminal ? 'failed' : 'idle' );
		$run_status  = $is_terminal ? 'failed' : 'retrying';
		$is_enabled  = ( $re_enqueued || ! ( $is_terminal && $is_one_off ) ) ? 1 : 0;
		$next_run    = $re_enqueued ? $now_time : $next_run_at;
		$attempts    = $re_enqueued ? 0 : (int) ( $job_row['attempts'] ?? 0 );

		$affected = $this->db->query(
			'UPDATE cron_jobs
			SET status = :next_status,
				locked_at = NULL,
				lock_token = NULL,
				lock_expires_at = NULL,
				attempts = :attempts,
				last_run_at = :last_run,
				last_finished_at = :last_finished,
				last_error = :error_message,
				next_run_at = :next_run,
				is_enabled = :is_enabled,
				updated_at = :updated_at
			WHERE id = :job_id
			AND lock_token = :lock_token',
			array(
				'next_status'   => $next_status,
				'attempts'      => $attempts,
				'last_run'      => $started_at,
				'last_finished' => $now_time,
				'error_message' => $error_message,
				'next_run'      => $next_run,
				'is_enabled'    => $is_enabled,
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

		if ( $affected > 0 ) {
			$this->promote_next_job_if_waiting( $job_id, $now_time );
		}
	}

	/**
	 * Promote a waiting :next follow-up job to runnable after primary finishes.
	 *
	 * @param string $job_id   Completed job identifier.
	 * @param string $now_time Current UTC timestamp.
	 * @return void
	 * @since 1.7.1
	 */
	private function promote_next_job_if_waiting( string $job_id, string $now_time ): void {
		if ( str_ends_with( $job_id, ':next' ) ) {
			return;
		}

		$next_id = $job_id . ':next';

		$this->db->query(
			'UPDATE cron_jobs
			SET status = :idle_status,
				is_enabled = 1,
				attempts = 0,
				next_run_at = IF(next_run_at > :now_time, next_run_at, :now_time2),
				updated_at = :now_time3
			WHERE id = :next_id
			AND status = :waiting_status',
			array(
				'idle_status'    => 'idle',
				'now_time'       => $now_time,
				'now_time2'      => $now_time,
				'now_time3'      => $now_time,
				'next_id'        => $next_id,
				'waiting_status' => 'waiting',
			)
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
	 * Prune old terminal execution history records according to per-job effective retention.
	 *
	 * Preserves 'running' and 'retrying' runs under all circumstances.
	 * Bounded batch deletion with safety limit iterations.
	 *
	 * @param int         $global_retention_days Fallback global retention in days (0 disables automatic pruning for inherited jobs).
	 * @param int         $batch_size            Maximum number of records to delete per batch.
	 * @param string|null $now                   Optional UTC timestamp override for testing.
	 * @return int Total number of pruned history rows.
	 * @since 1.7.0
	 */
	public function prune_history( int $global_retention_days, int $batch_size = 500, ?string $now = null ): int {
		$global_days = max( 0, $global_retention_days );
		$now_time    = $now ?? Date::now();
		$cutoff      = gmdate( 'Y-m-d H:i:s', strtotime( $now_time . ' UTC' ) - ( $global_days * 86400 ) );
		$limit       = max( 1, min( 5000, (int) $batch_size ) );

		$total_deleted  = 0;
		$max_iterations = 10;
		$iterations     = 0;

		$sql = 'DELETE FROM cron_runs
		WHERE id IN (
			SELECT id FROM (
				SELECT cr.id
				FROM cron_runs AS cr
				LEFT JOIN cron_jobs AS cj ON cj.id = SUBSTRING_INDEX(cr.job_id, \':\', 1)
				WHERE cr.status != :running_status
				AND cr.status != :retrying_status
				AND cj.id IS NOT NULL
				AND (
					(cj.retention_days IS NOT NULL AND cj.retention_days > 0 AND cr.created_at < DATE_SUB(:now_time, INTERVAL cj.retention_days DAY))
					OR
					(cj.retention_days IS NULL AND :global_retention > 0 AND cr.created_at < :global_cutoff)
				)
				LIMIT ' . $limit . '
			) AS batch_to_prune
		)';

		do {
			$deleted = $this->db->query(
				$sql,
				array(
					'running_status'   => 'running',
					'retrying_status'  => 'retrying',
					'now_time'         => $now_time,
					'global_retention' => $global_days,
					'global_cutoff'    => $cutoff,
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

	/**
	 * Update schedule configuration for a registered background job.
	 *
	 * @param string      $job_id           Unique job identifier.
	 * @param int         $interval_seconds Cadence in seconds.
	 * @param string|null $preferred_run_time   Optional preferred time of day (HH:MM) or null.
	 * @param bool|null   $is_enabled       Optional enabled state or null to preserve.
	 * @param string|null $next_run_at      Optional recalculated next run timestamp.
	 * @param bool        $update_retention Whether retention_days should be updated.
	 * @param int|null    $retention_days   Retention override in days, 0 for indefinite, or null to inherit.
	 * @return bool True if updated successfully.
	 * @since 1.7.0
	 */
	public function update_job_schedule(
		string $job_id,
		int $interval_seconds,
		?string $preferred_run_time = null,
		?bool $is_enabled = null,
		?string $next_run_at = null,
		bool $update_retention = false,
		?int $retention_days = null
	): bool {
		$fields = array(
			'schedule_interval'  => max( 1, $interval_seconds ),
			'preferred_run_time' => ( null !== $preferred_run_time && '' !== trim( $preferred_run_time ) ) ? trim( $preferred_run_time ) : null,
			'updated_at'         => Date::now(),
		);

		if ( null !== $is_enabled ) {
			$fields['is_enabled'] = $is_enabled ? 1 : 0;
		}

		if ( null !== $next_run_at ) {
			$fields['next_run_at'] = $next_run_at;
		}

		if ( $update_retention ) {
			$fields['retention_days'] = ( null !== $retention_days ) ? max( 0, $retention_days ) : null;
		}

		return (bool) $this->db->update(
			'cron_jobs',
			$fields,
			array( 'id' => $job_id )
		);
	}

	/**
	 * Reset a background job to its recommended default schedule.
	 *
	 * Restores default recurrence interval and enabled state, and resets retention
	 * to NULL (inheriting global retention default).
	 *
	 * @param string      $job_id           Unique job identifier.
	 * @param int         $default_interval Built-in recommended interval in seconds.
	 * @param bool        $default_enabled  Built-in recommended enabled state.
	 * @param string|null $next_run_at      Recalculated next run timestamp.
	 * @return bool True if reset successfully.
	 * @since 1.7.0
	 */
	public function reset_job_schedule(
		string $job_id,
		int $default_interval,
		bool $default_enabled,
		?string $next_run_at = null
	): bool {
		$fields = array(
			'schedule_interval'  => max( 1, $default_interval ),
			'preferred_run_time' => null,
			'retention_days'     => null,
			'is_enabled'         => $default_enabled ? 1 : 0,
			'updated_at'         => Date::now(),
		);

		if ( null !== $next_run_at ) {
			$fields['next_run_at'] = $next_run_at;
		}

		return (bool) $this->db->update(
			'cron_jobs',
			$fields,
			array( 'id' => $job_id )
		);
	}
}
