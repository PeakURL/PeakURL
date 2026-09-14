<?php
/**
 * Unit tests for PeakURL Scheduler and Background Jobs.
 *
 * @package PeakURL\Tests\Unit\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Core\Scheduler\RetryPolicy;
use PeakURL\Core\Scheduler\JobRegistry;
use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Features\Links\Jobs\LinkHealthCheckJob;

class SchedulerUnitTest extends TestCase {

	public function test_retry_policy_exponential_backoff_calculation(): void {
		$policy = new RetryPolicy( 5, 60, 2, 1800 );

		$this->assertSame( 5, $policy->get_max_attempts() );
		$this->assertSame( 60, $policy->get_initial_delay() );
		$this->assertSame( 1800, $policy->get_max_delay() );
		$this->assertSame( 2, $policy->get_backoff_multiplier() );

		$base_time = 1000000;
		// Attempt 1: 60 * 2^0 = 60
		$this->assertSame( $base_time + 60, $policy->calculate_next_retry( 1, $base_time ) );
		// Attempt 2: 60 * 2^1 = 120
		$this->assertSame( $base_time + 120, $policy->calculate_next_retry( 2, $base_time ) );
		// Attempt 3: 60 * 2^2 = 240
		$this->assertSame( $base_time + 240, $policy->calculate_next_retry( 3, $base_time ) );
		// Attempt 4: 60 * 2^3 = 480
		$this->assertSame( $base_time + 480, $policy->calculate_next_retry( 4, $base_time ) );
		// Attempt 5: 60 * 2^4 = 960
		$this->assertSame( $base_time + 960, $policy->calculate_next_retry( 5, $base_time ) );
		// Attempt 6: 60 * 2^5 = 1920 -> capped at 1800
		$this->assertSame( $base_time + 1800, $policy->calculate_next_retry( 6, $base_time ) );
	}

	public function test_retry_policy_is_retryable(): void {
		$policy = new RetryPolicy( 3 );

		$this->assertTrue( $policy->is_retryable( 0 ) );
		$this->assertTrue( $policy->is_retryable( 1 ) );
		$this->assertTrue( $policy->is_retryable( 2 ) );
		$this->assertFalse( $policy->is_retryable( 3 ) );
		$this->assertFalse( $policy->is_retryable( 4 ) );
	}

	public function test_retry_policy_standard_and_no_retry_factories(): void {
		$standard = RetryPolicy::standard();
		$this->assertSame( 3, $standard->get_max_attempts() );
		$this->assertSame( 60, $standard->get_initial_delay() );

		$no_retry = RetryPolicy::no_retry();
		$this->assertSame( 1, $no_retry->get_max_attempts() );
		$this->assertFalse( $no_retry->is_retryable( 1 ) );
	}

	public function test_job_registry_registration_and_lookup(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition(
			'test_job',
			'Test Job',
			300,
			$handler
		);

		$registry->register( $job );

		$this->assertTrue( $registry->has( 'test_job' ) );
		$this->assertFalse( $registry->has( 'unknown_job' ) );
		$this->assertSame( $job, $registry->get( 'test_job' ) );
		$this->assertCount( 1, $registry->all() );
		$this->assertSame( array( 'test_job' ), $registry->get_registered_ids() );
	}

	public function test_job_registry_returns_null_for_unregistered(): void {
		$registry = new JobRegistry();
		$this->assertNull( $registry->get( 'non_existent' ) );
		$this->assertFalse( $registry->has( 'non_existent' ) );
	}

	public function test_job_definition_validation(): void {
		$handler = $this->createMock( JobHandlerInterface::class );

		$this->expectException( \InvalidArgumentException::class );
		new JobDefinition(
			'',
			'Invalid Job',
			300,
			$handler
		);
	}

	public function test_execution_context_and_result(): void {
		$context = new ExecutionContext(
			'sample_job',
			'run_12345',
			2,
			true,
			'2026-09-13 10:00:00'
		);

		$this->assertSame( 'sample_job', $context->get_job_id() );
		$this->assertSame( 'run_12345', $context->get_run_id() );
		$this->assertSame( 2, $context->get_attempt() );
		$this->assertTrue( $context->is_manual() );
		$this->assertTrue( $context->is_forced() );
		$this->assertSame( '2026-09-13 10:00:00', $context->get_started_at() );

		$success = ExecutionResult::success( 'Success msg', array( 'count' => 10 ) );
		$this->assertTrue( $success->is_success() );
		$this->assertFalse( $success->is_failure() );
		$this->assertFalse( $success->is_skipped() );
		$this->assertSame( 'Success msg', $success->get_summary() );
		$this->assertSame( array( 'count' => 10 ), $success->get_metadata() );

		$failure = ExecutionResult::failure( 'Fail msg' );
		$this->assertTrue( $failure->is_failure() );
		$this->assertFalse( $failure->is_success() );
		$this->assertSame( 'Fail msg', $failure->get_error() );

		$skipped = ExecutionResult::skipped( 'Skip msg' );
		$this->assertTrue( $skipped->is_skipped() );
		$this->assertSame( 'Skip msg', $skipped->get_summary() );
	}

	public function test_link_health_check_ssrf_safety_validation(): void {
		// Valid public IP URLs
		$this->assertTrue( LinkHealthCheckJob::is_safe_url( 'http://93.184.216.34/path' ) );
		$this->assertTrue( LinkHealthCheckJob::is_safe_url( 'https://8.8.8.8' ) );

		// Invalid schemes
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'file:///etc/passwd' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'gopher://localhost:70' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'ftp://ftp.example.com' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'javascript:alert(1)' ) );

		// Loopback addresses
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://127.0.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://127.0.0.1:8080/test' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://127.1.2.3' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://localhost' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://[::1]' ) );

		// Private network addresses (RFC 1918)
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://10.0.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://172.16.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://192.168.1.1' ) );

		// Cloud metadata addresses
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://169.254.169.254' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'http://169.254.169.254/latest/meta-data/' ) );

		// Malformed or empty
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( '' ) );
		$this->assertFalse( LinkHealthCheckJob::is_safe_url( 'not-a-valid-url' ) );
	}

	public function test_link_health_check_is_public_ip(): void {
		$this->assertTrue( LinkHealthCheckJob::is_public_ip( '8.8.8.8' ) );
		$this->assertTrue( LinkHealthCheckJob::is_public_ip( '1.1.1.1' ) );
		$this->assertTrue( LinkHealthCheckJob::is_public_ip( '93.184.216.34' ) );

		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '127.0.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '10.0.0.5' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '192.168.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '172.16.0.1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '169.254.169.254' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( '::1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( 'fe80::1' ) );
		$this->assertFalse( LinkHealthCheckJob::is_public_ip( 'invalid-ip' ) );
	}

	public function test_scheduler_retention_validation_and_forever(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		$this->expectException( \InvalidArgumentException::class );
		$scheduler->set_retention_days( -5 );
	}

	public function test_scheduler_retention_forever_disables_pruning(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$repository->expects( $this->never() )->method( 'prune_history' );

		$scheduler = new Scheduler( $registry, $repository, null, null, 0 );
		$this->assertSame( 0, $scheduler->get_retention_days() );
		$this->assertSame( 0, $scheduler->prune_history() );
		$this->assertSame( 0, $scheduler->prune_history( 0 ) );
	}

	public function test_scheduler_retention_fallback_on_invalid_stored_value(): void {
		$registry     = new JobRegistry();
		$repository   = $this->createMock( SchedulerRepository::class );
		$settings_api = $this->createMock( SettingsApi::class );

		// Test missing setting (null) -> 30
		$settings_api->method( 'get_option' )->willReturn( null );
		$scheduler = new Scheduler( $registry, $repository, null, $settings_api );
		$this->assertSame( 30, $scheduler->get_retention_days() );

		// Test empty string -> 30
		$settings_api2 = $this->createMock( SettingsApi::class );
		$settings_api2->method( 'get_option' )->willReturn( '' );
		$scheduler2 = new Scheduler( $registry, $repository, null, $settings_api2 );
		$this->assertSame( 30, $scheduler2->get_retention_days() );

		// Test invalid string -> 30
		$settings_api3 = $this->createMock( SettingsApi::class );
		$settings_api3->method( 'get_option' )->willReturn( 'invalid_data' );
		$scheduler3 = new Scheduler( $registry, $repository, null, $settings_api3 );
		$this->assertSame( 30, $scheduler3->get_retention_days() );

		// Test negative string -> 30
		$settings_api4 = $this->createMock( SettingsApi::class );
		$settings_api4->method( 'get_option' )->willReturn( '-10' );
		$scheduler4 = new Scheduler( $registry, $repository, null, $settings_api4 );
		$this->assertSame( 30, $scheduler4->get_retention_days() );

		// Test valid stored '0' (Forever) -> 0
		$settings_api5 = $this->createMock( SettingsApi::class );
		$settings_api5->method( 'get_option' )->willReturn( '0' );
		$scheduler5 = new Scheduler( $registry, $repository, null, $settings_api5 );
		$this->assertSame( 0, $scheduler5->get_retention_days() );

		// Test valid stored '14' -> 14
		$settings_api6 = $this->createMock( SettingsApi::class );
		$settings_api6->method( 'get_option' )->willReturn( '14' );
		$scheduler6 = new Scheduler( $registry, $repository, null, $settings_api6 );
		$this->assertSame( 14, $scheduler6->get_retention_days() );
	}

	public function test_scheduler_run_due_jobs_isolates_pruning_failure(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$handler->method( 'execute' )->willReturn( ExecutionResult::success( 'Processed 5 items' ) );

		$job = new JobDefinition( 'test_job_1', 'Test Job 1', 300, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_due_jobs' )->willReturn(
			array(
				array(
					'id'          => 'test_job_1',
					'status'      => 'idle',
					'is_enabled'  => 1,
					'next_run_at' => '2026-09-14 10:00:00',
					'attempts'    => 0,
				),
			)
		);
		$repository->method( 'claim_job' )->willReturn( true );
		$repository->method( 'record_run_start' )->willReturn( 'run_abc123' );
		$repository->method( 'record_success' );

		// Pruning throws an exception (e.g. database table lock or temporary glitch)
		$repository->method( 'prune_history' )->willThrowException(
			new \RuntimeException( 'History table locked during maintenance' )
		);

		$logged_messages = array();
		$logger          = function ( string $msg ) use ( &$logged_messages ): void {
			$logged_messages[] = $msg;
		};

		$scheduler = new Scheduler( $registry, $repository, $logger, null, 30 );
		$outcomes  = $scheduler->run_due_jobs();

		// Due job executed successfully and its outcome is preserved
		$this->assertArrayHasKey( 'test_job_1', $outcomes );
		$this->assertSame( 'success', $outcomes['test_job_1']['status'] );
		$this->assertSame( 'Processed 5 items', $outcomes['test_job_1']['summary'] );
		$this->assertNull( $outcomes['test_job_1']['error'] );

		// Pruning failure was logged and did not break job outcomes
		$this->assertTrue(
			array_reduce(
				$logged_messages,
				function ( bool $carry, string $msg ): bool {
					return $carry || false !== strpos( $msg, 'Execution history pruning failed' );
				},
				false
			),
			'Expected pruning failure to be logged'
		);
	}

	public function test_calculate_next_run_interval_only(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		// 3600s from 2026-09-14 10:00:00 UTC -> 2026-09-14 11:00:00
		$next = $scheduler->calculate_next_run( 3600, null, '2026-09-14 10:00:00' );
		$this->assertSame( '2026-09-14 11:00:00', $next );
	}

	public function test_calculate_next_run_preferred_run_time_daily(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		// Case A: preferred time 14:00, from_time 10:00 -> should run today at 14:00
		$next_today = $scheduler->calculate_next_run( 86400, '14:00', '2026-09-14 10:00:00' );
		$this->assertSame( '2026-09-14 14:00:00', $next_today );

		// Case B: preferred time 02:00, from_time 10:00 -> should run tomorrow at 02:00
		$next_tomorrow = $scheduler->calculate_next_run( 86400, '02:00', '2026-09-14 10:00:00' );
		$this->assertSame( '2026-09-15 02:00:00', $next_tomorrow );
	}

	public function test_calculate_next_run_preferred_run_time_weekly(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		// Weekly (604800) at 03:00 from 2026-09-14 10:00:00 -> 7 days later at 03:00
		$next_weekly = $scheduler->calculate_next_run( 604800, '03:00', '2026-09-14 10:00:00' );
		$this->assertSame( '2026-09-21 03:00:00', $next_weekly );
	}

	public function test_calculate_next_run_with_site_timezone(): void {
		$registry     = new JobRegistry();
		$repository   = $this->createMock( SchedulerRepository::class );
		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $option ) {
					if ( 'timezone' === $option || 'site_timezone' === $option ) {
						return 'America/New_York'; // UTC-4 in September (EDT)
					}
					if ( Constants::SETTING_CRON_HISTORY_RETENTION_DAYS === $option ) {
						return '30';
					}
					return null;
				}
			);

		$scheduler = new Scheduler( $registry, $repository, null, $settings_api );

		// 02:00 EDT is 06:00 UTC
		// If from_time is 2026-09-14 00:00:00 UTC (which is 20:00 EDT previous day)
		// Next 02:00 EDT occurrence is 2026-09-14 02:00 EDT = 2026-09-14 06:00:00 UTC
		$next = $scheduler->calculate_next_run( 86400, '02:00', '2026-09-14 00:00:00' );
		$this->assertSame( '2026-09-14 06:00:00', $next );
	}

	public function test_update_job_enforces_minimum_interval(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'test_job', 'Test Job', 3600, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Recurrence interval must be at least 300 seconds (5 minutes).' );
		$scheduler->update_job( 'test_job', array( 'interval_seconds' => 120 ) );
	}

	public function test_update_job_rejects_unknown_job(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown background job identifier: non_existent' );
		$scheduler->update_job( 'non_existent', array( 'interval_seconds' => 3600 ) );
	}

	public function test_update_job_delegates_to_repository(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'test_job', 'Test Job', 3600, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->expects( $this->once() )
			->method( 'update_job_schedule' )
			->with( 'test_job', 7200, '04:00', false, $this->isType( 'string' ) )
			->willReturn( true );

		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'test_job',
					'schedule_interval'  => 7200,
					'preferred_run_time' => '04:00',
					'is_enabled'         => 0,
					'status'             => 'idle',
					'next_run_at'        => '2026-09-14 12:00:00',
				)
			);

		$scheduler = new Scheduler( $registry, $repository );
		$result    = $scheduler->update_job(
			'test_job',
			array(
				'interval_seconds'   => 7200,
				'preferred_run_time' => '04:00',
				'is_enabled'         => false,
			)
		);

		$this->assertSame( 'test_job', $result['id'] );
		$this->assertSame( 7200, $result['interval_seconds'] );
		$this->assertSame( 3600, $result['recommended_interval_seconds'] );
		$this->assertTrue( $result['is_customized'] );
		$this->assertFalse( $result['is_enabled'] );
	}

	public function test_reset_job_restores_recommended_defaults(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'test_job', 'Test Job', 86400, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->expects( $this->once() )
			->method( 'reset_job_schedule' )
			->with( 'test_job', 86400, true, $this->isType( 'string' ) )
			->willReturn( true );

		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'test_job',
					'schedule_interval'  => 86400,
					'preferred_run_time' => null,
					'is_enabled'         => 1,
					'status'             => 'idle',
					'next_run_at'        => '2026-09-15 00:00:00',
				)
			);

		$scheduler = new Scheduler( $registry, $repository );
		$result    = $scheduler->reset_job( 'test_job' );

		$this->assertSame( 'test_job', $result['id'] );
		$this->assertSame( 86400, $result['interval_seconds'] );
		$this->assertFalse( $result['is_customized'] );
		$this->assertTrue( $result['is_enabled'] );
		$this->assertNull( $result['preferred_run_time'] );
	}

	public function test_calculate_next_run_dst_transitions_london(): void {
		$registry     = new JobRegistry();
		$repository   = $this->createMock( SchedulerRepository::class );
		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $option ) {
					if ( 'site_timezone' === $option || 'timezone' === $option ) {
						return 'Europe/London';
					}
					return null;
				}
			);

		$scheduler = new Scheduler( $registry, $repository, null, $settings_api );

		// Summer (BST, UTC+1): 02:00 BST = 01:00:00 UTC
		$next_summer = $scheduler->calculate_next_run( 86400, '02:00', '2026-07-01 00:00:00' );
		$this->assertSame( '2026-07-01 01:00:00', $next_summer );

		// Winter (GMT, UTC+0): 02:00 GMT = 02:00:00 UTC
		$next_winter = $scheduler->calculate_next_run( 86400, '02:00', '2026-01-01 00:00:00' );
		$this->assertSame( '2026-01-01 02:00:00', $next_winter );
	}

	public function test_update_job_running_job_safety(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'running_job', 'Running Job', 3600, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		// When job is currently running, next_run_at passed to repository MUST be null
		$repository->expects( $this->once() )
			->method( 'update_job_schedule' )
			->with( 'running_job', 7200, null, null, null )
			->willReturn( true );

		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'running_job',
					'schedule_interval'  => 7200,
					'preferred_run_time' => null,
					'is_enabled'         => 1,
					'status'             => 'running',
					'next_run_at'        => '2026-09-14 10:00:00',
				)
			);

		$scheduler = new Scheduler( $registry, $repository );
		$result    = $scheduler->update_job(
			'running_job',
			array(
				'interval_seconds' => 7200,
			)
		);

		$this->assertSame( 'running_job', $result['id'] );
		$this->assertSame( 7200, $result['interval_seconds'] );
		$this->assertSame( 'running', $result['status'] );
	}

	public function test_update_job_rejects_invalid_preferred_run_time(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'test_job', 'Test Job', 86400, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'test_job',
					'schedule_interval'  => 86400,
					'preferred_run_time' => null,
					'is_enabled'         => 1,
					'status'             => 'idle',
				)
			);

		$scheduler = new Scheduler( $registry, $repository );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Preferred time must be in 24-hour format (HH:MM).' );
		$scheduler->update_job( 'test_job', array( 'preferred_run_time' => '25:99' ) );
	}
}
