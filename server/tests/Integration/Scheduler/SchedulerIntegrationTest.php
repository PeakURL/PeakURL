<?php
/**
 * Real Database Persistence and Execution Integration Tests for Cron and Scheduler.
 *
 * @package PeakURL\Tests\Integration\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Core\Scheduler\JobRegistry;
use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\RetryPolicy;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PDO;

class SchedulerIntegrationTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private SchedulerRepository $repository;
	private JobRegistry $registry;
	private Scheduler $scheduler;
	private string $test_prefix;

	protected function setUp(): void {
		parent::setUp();

		$config           = Configuration::get_current();
		$this->connection = Connection::get_instance( $config );
		$this->pdo        = $this->connection->get_connection();
		$this->db         = new PeakURL_DB( $this->connection );

		$this->test_prefix = 'test_cron_' . bin2hex( random_bytes( 4 ) ) . '_';

		// Ensure database schema tables exist.
		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		if ( file_exists( $schema_file ) ) {
			$schema_sql = (string) file_get_contents( $schema_file );
			$this->pdo->exec( $this->connection->prefix_schema( $schema_sql ) );
		}

		$this->repository = new SchedulerRepository( $this->db );
		$this->registry   = new JobRegistry();
		$this->scheduler  = new Scheduler( $this->registry, $this->repository );
	}

	protected function tearDown(): void {
		// Clean up test jobs created during tests.
		$prefix = $this->connection->get_table_prefix();
		$this->pdo->exec( "DELETE FROM {$prefix}cron_runs WHERE job_id LIKE '{$this->test_prefix}%'" );
		$this->pdo->exec( "DELETE FROM {$prefix}cron_jobs WHERE id LIKE '{$this->test_prefix}%'" );

		parent::tearDown();
	}

	public function test_sync_definitions_persists_jobs_in_database(): void {
		$job_id  = $this->test_prefix . 'sync_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$job     = new JobDefinition(
			$job_id,
			'Sync Test Job',
			600,
			$handler,
			null,
			JobDefinition::OVERLAP_PREVENT,
			120,
			true
		);

		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		$record = $this->repository->get_job( $job_id );
		$this->assertNotNull( $record );
		$this->assertSame( $job_id, $record['id'] );
		$this->assertSame( 'Sync Test Job', $record['title'] );
		$this->assertSame( 600, (int) $record['schedule_interval'] );
		$this->assertSame( 'idle', $record['status'] );
		$this->assertNotNull( $record['next_run_at'] );
	}

	public function test_atomic_job_claiming_prevents_concurrent_runs(): void {
		$job_id  = $this->test_prefix . 'claim_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$job     = new JobDefinition( $job_id, 'Claim Test Job', 60, $handler );

		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Set next_run_at to past so it is due.
		$prefix = $this->connection->get_table_prefix();
		$this->pdo->exec(
			"UPDATE {$prefix}cron_jobs SET next_run_at = '2020-01-01 00:00:00' WHERE id = '{$job_id}'"
		);

		// Process 1 claims the job.
		$claimed1 = $this->repository->claim_job( $job_id, 'token_alpha', 300 );
		$this->assertTrue( $claimed1, 'Process 1 should successfully claim the due job.' );

		$record1 = $this->repository->get_job( $job_id );
		$this->assertSame( 'running', $record1['status'] );
		$this->assertSame( 'token_alpha', $record1['lock_token'] );

		// Process 2 attempts to claim the same job concurrently while it is running.
		$claimed2 = $this->repository->claim_job( $job_id, 'token_beta', 300 );
		$this->assertFalse( $claimed2, 'Process 2 must not claim a currently running job.' );
	}

	public function test_stale_lock_lease_recovery(): void {
		$job_id  = $this->test_prefix . 'stale_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$job     = new JobDefinition( $job_id, 'Stale Test Job', 60, $handler );

		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Simulate a process that died: status='running', lock_expires_at in past.
		$prefix = $this->connection->get_table_prefix();
		$this->pdo->exec(
			"UPDATE {$prefix}cron_jobs 
			 SET status = 'running', lock_token = 'dead_worker', lock_expires_at = '2020-01-01 00:00:00' 
			 WHERE id = '{$job_id}'"
		);

		// Fresh process should be able to claim the expired lock.
		$claimed = $this->repository->claim_job( $job_id, 'fresh_worker', 300 );
		$this->assertTrue( $claimed, 'A stale running job with expired lease should be claimable.' );

		$record = $this->repository->get_job( $job_id );
		$this->assertSame( 'fresh_worker', $record['lock_token'] );
		$this->assertSame( 'running', $record['status'] );
	}

	public function test_scheduler_executes_due_job_and_logs_run_history(): void {
		$job_id    = $this->test_prefix . 'exec_test';
		$executed  = false;
		$test_data = array( 'items' => 42 );

		$handler = new class( $test_data, $executed ) implements JobHandlerInterface {
			private array $data;
			private bool $executed;

			public function __construct( array $data, bool &$executed ) {
				$this->data     = $data;
				$this->executed = &$executed;
			}

			public function execute( ExecutionContext $context ): ExecutionResult {
				$this->executed = true;
				return ExecutionResult::success( 'Finished successfully', $this->data );
			}
		};

		$job = new JobDefinition( $job_id, 'Execution Test Job', 120, $handler );
		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Force job to be due.
		$prefix = $this->connection->get_table_prefix();
		$this->pdo->exec(
			"UPDATE {$prefix}cron_jobs SET next_run_at = '2020-01-01 00:00:00' WHERE id = '{$job_id}'"
		);

		$results = $this->scheduler->run_due_jobs();

		$this->assertTrue( $executed, 'Job handler should have been invoked.' );
		$this->assertArrayHasKey( $job_id, $results );
		$this->assertSame( 'success', $results[ $job_id ]['status'] );

		// Verify database state.
		$record = $this->repository->get_job( $job_id );
		$this->assertSame( 'idle', $record['status'] );
		$this->assertNotNull( $record['last_run_at'] );

		// Verify run history log in cron_runs.
		$runs = $this->repository->get_job_runs( $job_id, 5 );
		$this->assertCount( 1, $runs );
		$this->assertSame( 'success', $runs[0]['status'] );
		$this->assertSame( 'Finished successfully', $runs[0]['output_summary'] );
	}

	public function test_scheduler_manual_run_now(): void {
		$job_id   = $this->test_prefix . 'manual_test';
		$executed = false;

		$handler = new class( $executed ) implements JobHandlerInterface {
			private bool $executed;

			public function __construct( bool &$executed ) {
				$this->executed = &$executed;
			}

			public function execute( ExecutionContext $context ): ExecutionResult {
				$this->executed = true;
				return ExecutionResult::success( 'Manual run completed' );
			}
		};

		$job = new JobDefinition( $job_id, 'Manual Run Test Job', 3600, $handler );
		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Next run is in the future, but we force manual run-now.
		$result = $this->scheduler->run_job( $job_id, true );

		$this->assertNotNull( $result );
		$this->assertTrue( $executed );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Manual run completed', $result->get_summary() );

		$record = $this->repository->get_job( $job_id );
		$this->assertSame( 'idle', $record['status'] );
		$this->assertNotNull( $record['last_run_at'] );
	}

	public function test_scheduler_failed_job_applies_retry_backoff(): void {
		$job_id  = $this->test_prefix . 'fail_test';
		$handler = new class() implements JobHandlerInterface {
			public function execute( ExecutionContext $context ): ExecutionResult {
				return ExecutionResult::failure( 'Simulated failure', false );
			}
		};

		$policy = new RetryPolicy( 3, 300, 2, 3600 );
		$job    = new JobDefinition( $job_id, 'Fail Test Job', 3600, $handler, $policy );

		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		$result = $this->scheduler->run_job( $job_id, true );

		$this->assertNotNull( $result );
		$this->assertTrue( $result->is_failure() );

		$record = $this->repository->get_job( $job_id );
		$this->assertSame( 1, (int) $record['attempts'] );
		$this->assertSame( 'Simulated failure', $record['last_error'] );
	}

	public function test_scheduler_skipped_job_releases_lock_and_advances_next_run(): void {
		$job_id  = $this->test_prefix . 'skipped_test';
		$handler = new class() implements JobHandlerInterface {
			public function execute( ExecutionContext $context ): ExecutionResult {
				return ExecutionResult::skipped( 'Skipped reason test' );
			}
		};

		$job = new JobDefinition( $job_id, 'Skipped Test Job', 3600, $handler );
		$this->registry->register( $job );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		$result = $this->scheduler->run_job( $job_id, true );

		$this->assertNotNull( $result );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'Skipped reason test', $result->get_summary() );

		$record = $this->repository->get_job( $job_id );
		$this->assertSame( 'idle', $record['status'] );
		$this->assertNull( $record['lock_token'] );
		$this->assertNull( $record['lock_expires_at'] );
		$this->assertNotNull( $record['last_run_at'] );
		$this->assertNotNull( $record['next_run_at'] );

		// Verify run history entry
		$prefix = $this->connection->get_table_prefix();
		$stmt   = $this->pdo->prepare( "SELECT * FROM {$prefix}cron_runs WHERE job_id = ? ORDER BY id DESC LIMIT 1" );
		$stmt->execute( array( $job_id ) );
		$run = $stmt->fetch();

		$this->assertNotEmpty( $run );
		$this->assertSame( 'skipped', $run['status'] );
		$this->assertSame( 'Skipped: Skipped reason test', $run['output_summary'] );
	}

	public function test_prune_history_removes_old_terminal_runs_and_preserves_active(): void {
		$prefix  = $this->connection->get_table_prefix();
		$job_id  = $this->test_prefix . 'prune_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$this->registry->register( new JobDefinition( $job_id, 'Prune Test Job', 3600, $handler ) );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Insert an old terminal success run (40 days ago).
		$old_time = gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) );
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, duration_ms, output_summary, created_at)
			VALUES ('{$this->test_prefix}run_old_succ', '{$job_id}', 'success', 1, '{$old_time}', '{$old_time}', 100, 'done', '{$old_time}')"
		);

		// Insert an old terminal failed run (35 days ago).
		$old_fail_time = gmdate( 'Y-m-d H:i:s', strtotime( '-35 days' ) );
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, duration_ms, error_message, created_at)
			VALUES ('{$this->test_prefix}run_old_fail', '{$job_id}', 'failed', 1, '{$old_fail_time}', '{$old_fail_time}', 150, 'err', '{$old_fail_time}')"
		);

		// Insert an old active 'running' run (35 days ago) - MUST NOT be pruned.
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, created_at)
			VALUES ('{$this->test_prefix}run_old_active', '{$job_id}', 'running', 1, '{$old_fail_time}', '{$old_fail_time}')"
		);

		// Insert a recent terminal success run (2 days ago) - MUST NOT be pruned.
		$recent_time = gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) );
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, duration_ms, output_summary, created_at)
			VALUES ('{$this->test_prefix}run_recent', '{$job_id}', 'success', 1, '{$recent_time}', '{$recent_time}', 80, 'ok', '{$recent_time}')"
		);

		$pruned = $this->repository->prune_history( 30 );
		$this->assertSame( 2, $pruned );

		// Verify surviving runs
		$stmt          = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE job_id = '{$job_id}' ORDER BY id ASC" );
		$remaining_ids = $stmt->fetchAll( PDO::FETCH_COLUMN );

		$this->assertContains( "{$this->test_prefix}run_old_active", $remaining_ids );
		$this->assertContains( "{$this->test_prefix}run_recent", $remaining_ids );
		$this->assertNotContains( "{$this->test_prefix}run_old_succ", $remaining_ids );
		$this->assertNotContains( "{$this->test_prefix}run_old_fail", $remaining_ids );
	}

	public function test_clear_history_removes_runs_for_specific_job_and_all(): void {
		$prefix  = $this->connection->get_table_prefix();
		$job_a   = $this->test_prefix . 'clear_a';
		$job_b   = $this->test_prefix . 'clear_b';
		$handler = $this->createMock( JobHandlerInterface::class );
		$this->registry->register( new JobDefinition( $job_a, 'Clear Job A', 3600, $handler ) );
		$this->registry->register( new JobDefinition( $job_b, 'Clear Job B', 3600, $handler ) );
		$this->repository->sync_registered_jobs( $this->registry->all() );
		$now = gmdate( 'Y-m-d H:i:s' );

		// Job A runs: 2 finished, 1 running
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at) VALUES
			('{$this->test_prefix}a_1', '{$job_a}', 'success', 1, '{$now}', '{$now}', '{$now}'),
			('{$this->test_prefix}a_2', '{$job_a}', 'failed', 1, '{$now}', '{$now}', '{$now}'),
			('{$this->test_prefix}a_active', '{$job_a}', 'running', 1, '{$now}', NULL, '{$now}')"
		);

		// Job B runs: 2 finished
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at) VALUES
			('{$this->test_prefix}b_1', '{$job_b}', 'success', 1, '{$now}', '{$now}', '{$now}'),
			('{$this->test_prefix}b_2', '{$job_b}', 'success', 1, '{$now}', '{$now}', '{$now}')"
		);

		// Clear only Job A
		$deleted_a = $this->repository->clear_history( $job_a );
		$this->assertSame( 2, $deleted_a );

		// Check Job A running run still exists
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE job_id = '{$job_a}'" );
		$this->assertSame( array( "{$this->test_prefix}a_active" ), $stmt->fetchAll( PDO::FETCH_COLUMN ) );

		// Check Job B runs still exist
		$stmt = $this->pdo->query( "SELECT COUNT(*) FROM {$prefix}cron_runs WHERE job_id = '{$job_b}'" );
		$this->assertSame( 2, (int) $stmt->fetchColumn() );

		// Clear all jobs
		$deleted_all = $this->repository->clear_history();
		$this->assertGreaterThanOrEqual( 2, $deleted_all );

		// Job A's active run should STILL exist
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}a_active'" );
		$this->assertNotEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );
	}

	public function test_prune_history_cutoff_boundary_and_retrying_and_skipped_protection(): void {
		$prefix  = $this->connection->get_table_prefix();
		$job_id  = $this->test_prefix . 'boundary_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$this->registry->register( new JobDefinition( $job_id, 'Boundary Test Job', 3600, $handler ) );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		// Cutoff for 30 days is time() - (30 * 86400).
		// 1. Older than cutoff: 31 days ago (success, failed, skipped) -> pruned
		$older_time = gmdate( 'Y-m-d H:i:s', time() - ( 31 * 86400 ) );
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at) VALUES
			('{$this->test_prefix}run_old_skipped', '{$job_id}', 'skipped', 1, '{$older_time}', '{$older_time}', '{$older_time}'),
			('{$this->test_prefix}run_old_retrying', '{$job_id}', 'retrying', 1, '{$older_time}', NULL, '{$older_time}')"
		);

		// 2. Newer than cutoff: 29 days ago -> preserved
		$newer_time = gmdate( 'Y-m-d H:i:s', time() - ( 29 * 86400 ) );
		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at) VALUES
			('{$this->test_prefix}run_new_succ', '{$job_id}', 'success', 1, '{$newer_time}', '{$newer_time}', '{$newer_time}')"
		);

		$pruned = $this->repository->prune_history( 30 );
		$this->assertGreaterThanOrEqual( 1, $pruned );

		// Verify that old skipped was pruned
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}run_old_skipped'" );
		$this->assertEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );

		// Verify that old retrying was preserved
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}run_old_retrying'" );
		$this->assertNotEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );

		// Verify that new success was preserved
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}run_new_succ'" );
		$this->assertNotEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );
	}

	public function test_prune_history_multi_batch_draining(): void {
		$prefix  = $this->connection->get_table_prefix();
		$job_id  = $this->test_prefix . 'batch_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$this->registry->register( new JobDefinition( $job_id, 'Batch Test Job', 3600, $handler ) );
		$this->repository->sync_registered_jobs( $this->registry->all() );

		$old_time = gmdate( 'Y-m-d H:i:s', time() - ( 35 * 86400 ) );
		for ( $i = 1; $i <= 15; $i++ ) {
			$this->pdo->exec(
				"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at)
				VALUES ('{$this->test_prefix}batch_run_{$i}', '{$job_id}', 'success', 1, '{$old_time}', '{$old_time}', '{$old_time}')"
			);
		}

		// Prune in batches of 5
		$pruned = $this->repository->prune_history( 30, 5 );
		$this->assertGreaterThanOrEqual( 15, $pruned );

		$stmt = $this->pdo->query( "SELECT COUNT(*) FROM {$prefix}cron_runs WHERE job_id = '{$job_id}'" );
		$this->assertSame( 0, (int) $stmt->fetchColumn() );
	}

	public function test_clear_history_protects_retrying_runs(): void {
		$prefix  = $this->connection->get_table_prefix();
		$job_id  = $this->test_prefix . 'clear_retry_test';
		$handler = $this->createMock( JobHandlerInterface::class );
		$this->registry->register( new JobDefinition( $job_id, 'Clear Retry Job', 3600, $handler ) );
		$this->repository->sync_registered_jobs( $this->registry->all() );
		$now = gmdate( 'Y-m-d H:i:s' );

		$this->pdo->exec(
			"INSERT INTO {$prefix}cron_runs (id, job_id, status, attempt, started_at, finished_at, created_at) VALUES
			('{$this->test_prefix}retrying_run', '{$job_id}', 'retrying', 2, '{$now}', NULL, '{$now}'),
			('{$this->test_prefix}failed_run', '{$job_id}', 'failed', 3, '{$now}', '{$now}', '{$now}')"
		);

		$deleted = $this->repository->clear_history( $job_id );
		$this->assertSame( 1, $deleted );

		// Retrying run must still exist
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}retrying_run'" );
		$this->assertNotEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );

		// Failed run must be gone
		$stmt = $this->pdo->query( "SELECT id FROM {$prefix}cron_runs WHERE id = '{$this->test_prefix}failed_run'" );
		$this->assertEmpty( $stmt->fetchAll( PDO::FETCH_COLUMN ) );
	}
}
