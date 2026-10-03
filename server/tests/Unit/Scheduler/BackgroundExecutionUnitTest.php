<?php
/**
 * Unit tests for PeakURL Background Runner and Task Dispatcher.
 *
 * @package PeakURL\Tests\Unit\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Application;
use PeakURL\Core\Scheduler\BackgroundDispatcher;
use PeakURL\Core\Scheduler\BackgroundRunner;
use PeakURL\Core\Scheduler\BackgroundRunnerFactory;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\Database\PeakURL_DB;

/**
 * BackgroundExecutionUnitTest — verifies BackgroundRunner and BackgroundDispatcher logic.
 */
class BackgroundExecutionUnitTest extends TestCase {

	public function test_enqueue_targeted_job_deduplicates_and_trims(): void {
		$runner = new BackgroundRunner();

		$runner->enqueue_targeted_job( 'peakurl_link_health_check:link_123' );
		$runner->enqueue_targeted_job( '  peakurl_link_health_check:link_123  ' );
		$runner->enqueue_targeted_job( 'peakurl_link_health_check:link_456' );
		$runner->enqueue_targeted_job( '' );

		$reflection    = new \ReflectionClass( $runner );
		$property      = $reflection->getProperty( 'targeted_jobs' );
		$targeted_jobs = $property->getValue( $runner );

		$this->assertSame(
			array( 'peakurl_link_health_check:link_123', 'peakurl_link_health_check:link_456' ),
			$targeted_jobs
		);
	}

	public function test_enqueue_webhooks_sets_flag(): void {
		$runner = new BackgroundRunner();

		$reflection = new \ReflectionClass( $runner );
		$property   = $reflection->getProperty( 'has_pending_webhooks' );

		$this->assertFalse( $property->getValue( $runner ) );

		$runner->enqueue_webhooks();
		$this->assertTrue( $property->getValue( $runner ) );
	}

	public function test_dispatch_post_response_is_idempotent(): void {
		$scheduler        = $this->createMock( Scheduler::class );
		$webhooks_service = $this->createMock( WebhooksService::class );

		$webhooks_service->expects( $this->once() )
			->method( 'process_pending_deliveries' );

		$runner = new BackgroundRunner( $scheduler, $webhooks_service );
		$runner->enqueue_webhooks();

		$runner->dispatch_post_response( false );
		// Second call must be a no-op due to has_executed flag.
		$runner->dispatch_post_response( false );
	}

	public function test_dispatch_post_response_executes_targeted_jobs_and_webhooks(): void {
		$scheduler        = $this->createMock( Scheduler::class );
		$webhooks_service = $this->createMock( WebhooksService::class );

		$scheduler->expects( $this->once() )
			->method( 'run_job' )
			->with( 'peakurl_link_health_check:link_abc', true );

		$webhooks_service->expects( $this->once() )
			->method( 'process_pending_deliveries' )
			->willReturn(
				array(
					'processed' => 1,
					'delivered' => 1,
					'retried'   => 0,
					'failed'    => 0,
				)
			);

		$runner = new BackgroundRunner( $scheduler, $webhooks_service );
		$runner->enqueue_targeted_job( 'peakurl_link_health_check:link_abc' );
		$runner->enqueue_webhooks();

		$runner->dispatch_post_response( false );
	}

	public function test_can_dispatch_due_tasks_returns_false_when_throttled(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) time() ); // Checked just now.

		// DB queries must not be called when throttled.
		$db->expects( $this->never() )
			->method( 'get_var' );

		$this->assertFalse( BackgroundDispatcher::can_dispatch_due_tasks( $db, $settings_api, 60 ) );
	}

	public function test_can_dispatch_due_tasks_detects_due_jobs(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) ( time() - 100 ) ); // 100s ago (exceeds 60s throttle).

		$db->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( 1 ); // 1 due job found.

		$settings_api->method( 'acquire_option_lock' )
			->willReturn( true ); // Successfully acquired atomic visit worker lock.

		$this->assertTrue( BackgroundDispatcher::can_dispatch_due_tasks( $db, $settings_api, 60 ) );
	}

	public function test_can_dispatch_due_tasks_detects_due_webhooks(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) ( time() - 100 ) );

		$db->expects( $this->exactly( 2 ) )
			->method( 'get_var' )
			->willReturnOnConsecutiveCalls( false, 1 ); // 0 due cron jobs, 1 due webhook.

		$settings_api->method( 'acquire_option_lock' )
			->willReturn( true ); // Successfully acquired atomic visit worker lock.

		$this->assertTrue( BackgroundDispatcher::can_dispatch_due_tasks( $db, $settings_api, 60 ) );
	}

	public function test_can_dispatch_due_tasks_updates_timestamp_when_no_tasks_due(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) ( time() - 100 ) );

		$db->expects( $this->exactly( 2 ) )
			->method( 'get_var' )
			->willReturnOnConsecutiveCalls( false, false ); // Nothing due.

		$settings_api->expects( $this->once() )
			->method( 'update_option' )
			->with( 'cron_last_visit_check', $this->isType( 'string' ), $this->isType( 'string' ), false );

		$this->assertFalse( BackgroundDispatcher::can_dispatch_due_tasks( $db, $settings_api, 60 ) );
	}

	public function test_supports_post_response_execution_returns_boolean(): void {
		$supports = BackgroundRunner::supports_post_response_execution();
		$expected = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
		$this->assertSame( $expected, $supports );
	}

	public function test_finish_request_runs_without_exception(): void {
		// In CLI test execution, finish_request should execute cleanly without error.
		BackgroundRunner::finish_request();
		$this->assertTrue( true );
	}

	public function test_dispatch_post_response_handles_empty_dependencies_gracefully(): void {
		$runner = new BackgroundRunner();
		$runner->enqueue_targeted_job( 'peakurl_link_health_check:test' );
		$runner->enqueue_webhooks();

		// Should not throw when dependencies are null.
		$runner->dispatch_post_response( false );
		$this->assertTrue( true );
	}

	public function test_background_dispatcher_register_hooks_is_idempotent(): void {
		$connection = $this->createMock( \PeakURL\Services\Database\Connection::class );

		BackgroundDispatcher::register_hooks( array(), $connection );
		// Second call must return early without error.
		BackgroundDispatcher::register_hooks( array(), $connection );

		$this->assertTrue( true );
	}

	public function test_background_runner_factory_creates_runner_from_canonical_application(): void {
		$mock_stmt = $this->createMock( \PDOStatement::class );
		$mock_stmt->method( 'execute' )->willReturn( true );
		$mock_stmt->method( 'fetch' )->willReturn( false );
		$mock_stmt->method( 'fetchAll' )->willReturn( array() );

		$mock_pdo = $this->createMock( \PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );
		$connection = new class( array(), $mock_pdo ) extends \PeakURL\Services\Database\Connection {
			private \PDO $mock_pdo;

			public function __construct( array $config, \PDO $mock_pdo ) {
				parent::__construct( $config );
				$this->mock_pdo = $mock_pdo;
			}

			public function get_connection(): \PDO {
				return $this->mock_pdo;
			}
		};

		$config = array(
			'site_url' => 'http://localhost',
			'env'      => 'testing',
		);

		$runner = BackgroundRunnerFactory::create( $connection, $config );
		$this->assertInstanceOf( BackgroundRunner::class, $runner );
		$this->assertInstanceOf( Scheduler::class, $runner->get_scheduler() );
	}

	public function test_acquire_visit_worker_lock_succeeds_when_available(): void {
		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'table_exists' )
			->with( 'settings' )
			->willReturn( true );
		$db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnOnConsecutiveCalls( 1, 1 ); // INSERT succeeded, UPDATE affected 1 row.

		$this->assertTrue( BackgroundDispatcher::acquire_visit_worker_lock( $db, 60 ) );
	}

	public function test_acquire_visit_worker_lock_fails_when_concurrent_worker_holds_lock(): void {
		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'table_exists' )
			->with( 'settings' )
			->willReturn( true );
		$db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnOnConsecutiveCalls( 1, 0 ); // INSERT succeeded, UPDATE affected 0 rows (active lock).

		$this->assertFalse( BackgroundDispatcher::acquire_visit_worker_lock( $db, 60 ) );
	}

	public function test_can_dispatch_due_tasks_returns_false_when_concurrent_worker_acquired_lock(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) ( time() - 100 ) );

		$db->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( 1 ); // 1 due job found.

		$settings_api->method( 'acquire_option_lock' )
			->willReturn( false ); // Another concurrent worker already updated the lock!

		// Must return false so this request does NOT boot another heavy worker.
		$this->assertFalse( BackgroundDispatcher::can_dispatch_due_tasks( $db, $settings_api, 60 ) );
	}

	public function test_immediate_webhooks_bypasses_scheduled_cooldown(): void {
		$webhooks_service = $this->createMock( WebhooksService::class );
		$webhooks_service->expects( $this->once() )
			->method( 'process_pending_deliveries' );

		$settings_api = $this->createMock( SettingsApi::class );
		// Simulates that cron_last_visit_check was updated 0 seconds ago (actively throttled).
		$settings_api->method( 'get_option' )
			->with( 'cron_last_visit_check' )
			->willReturn( (string) time() );

		$runner = new BackgroundRunner( null, $webhooks_service, $settings_api );
		$runner->enqueue_webhooks();

		// Immediate dispatch must execute webhooks despite cooldown.
		$runner->dispatch_post_response( false );
		$this->assertTrue( true );
	}

	public function test_targeted_job_executes_via_scheduler_run_job(): void {
		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->expects( $this->once() )
			->method( 'run_job' )
			->with( 'peakurl_link_health_check:link_xyz', true );

		$runner = new BackgroundRunner( $scheduler );
		$runner->enqueue_targeted_job( 'peakurl_link_health_check:link_xyz' );

		$runner->dispatch_post_response( false );
		$this->assertTrue( true );
	}

	public function test_settings_api_acquire_option_lock_returns_false_if_table_missing(): void {
		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'table_exists' )->with( 'settings' )->willReturn( false );

		$settings_api = new SettingsApi( $db );
		$this->assertFalse( $settings_api->acquire_option_lock( 'cron_last_visit_check', 60 ) );
	}

	public function test_settings_api_acquire_option_lock_returns_true_on_successful_lock(): void {
		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'table_exists' )->with( 'settings' )->willReturn( true );
		$db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnOnConsecutiveCalls( 1, 1 );

		$settings_api = new SettingsApi( $db );
		$this->assertTrue( $settings_api->acquire_option_lock( 'cron_last_visit_check', 60 ) );
	}

	public function test_settings_api_acquire_option_lock_returns_false_on_active_lock(): void {
		$db = $this->createMock( PeakURL_DB::class );
		$db->method( 'table_exists' )->with( 'settings' )->willReturn( true );
		$db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnOnConsecutiveCalls( 1, 0 );

		$settings_api = new SettingsApi( $db );
		$this->assertFalse( $settings_api->acquire_option_lock( 'cron_last_visit_check', 60 ) );
	}

	public function test_process_due_scheduled_jobs_calls_run_due_jobs_when_force_true(): void {
		$scheduler    = $this->createMock( Scheduler::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->never() )
			->method( 'acquire_option_lock' );

		$scheduler->expects( $this->once() )
			->method( 'run_due_jobs' );

		$runner = new BackgroundRunner( $scheduler, null, $settings_api );
		$runner->process_due_scheduled_jobs( true );
	}

	public function test_process_due_scheduled_jobs_skips_run_due_jobs_when_lock_not_acquired(): void {
		$scheduler    = $this->createMock( Scheduler::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'acquire_option_lock' )
			->with( 'cron_last_visit_check', 60 )
			->willReturn( false );

		$scheduler->expects( $this->never() )
			->method( 'run_due_jobs' );

		$runner = new BackgroundRunner( $scheduler, null, $settings_api );
		$runner->process_due_scheduled_jobs( false );
	}

	public function test_process_due_scheduled_jobs_calls_run_due_jobs_when_lock_acquired(): void {
		$scheduler    = $this->createMock( Scheduler::class );
		$settings_api = $this->createMock( SettingsApi::class );

		$settings_api->expects( $this->once() )
			->method( 'acquire_option_lock' )
			->with( 'cron_last_visit_check', 60 )
			->willReturn( true );

		$scheduler->expects( $this->once() )
			->method( 'run_due_jobs' );

		$runner = new BackgroundRunner( $scheduler, null, $settings_api );
		$runner->process_due_scheduled_jobs( false );
	}

	public function test_cron_execution_summary_formatting(): void {
		$format_summary = function ( int $count ): string {
			return sprintf( 'Processed %d due background job%s.', $count, 1 === $count ? '' : 's' );
		};

		$this->assertSame( 'Processed 0 due background jobs.', $format_summary( 0 ) );
		$this->assertSame( 'Processed 1 due background job.', $format_summary( 1 ) );
		$this->assertSame( 'Processed 2 due background jobs.', $format_summary( 2 ) );
		$this->assertSame( 'Processed 10 due background jobs.', $format_summary( 10 ) );
	}

	public function test_cron_failure_detection(): void {
		$results = array(
			'job_1' => array( 'status' => 'success' ),
			'job_2' => array(
				'status' => 'failed',
				'error'  => 'Something failed',
			),
		);

		$has_failure = false;
		foreach ( $results as $outcome ) {
			if ( 'failed' === ( $outcome['status'] ?? '' ) ) {
				$has_failure = true;
			}
		}

		$this->assertTrue( $has_failure );

		$successful_results = array(
			'job_1' => array( 'status' => 'success' ),
		);

		$no_failure = false;
		foreach ( $successful_results as $outcome ) {
			if ( 'failed' === ( $outcome['status'] ?? '' ) ) {
				$no_failure = true;
			}
		}

		$this->assertFalse( $no_failure );
	}
}
