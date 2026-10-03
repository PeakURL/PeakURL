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

	public function test_scheduler_retention_validation_and_forever(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$scheduler  = new Scheduler( $registry, $repository );

		$this->expectException( \InvalidArgumentException::class );
		$scheduler->set_retention_days( -5 );
	}

	public function test_scheduler_prune_history_delegates_when_global_retention_is_zero(): void {
		$registry   = new JobRegistry();
		$repository = $this->createMock( SchedulerRepository::class );
		$repository->expects( $this->exactly( 2 ) )
			->method( 'prune_history' )
			->with( 0 )
			->willReturn( 5 );

		$scheduler = new Scheduler( $registry, $repository, null, null, 0 );
		$this->assertSame( 0, $scheduler->get_retention_days() );
		$this->assertSame( 5, $scheduler->prune_history() );
		$this->assertSame( 5, $scheduler->prune_history( 0 ) );
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
			->with( 'test_job', 7200, '04:00', false, $this->isType( 'string' ), false, null )
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
			->with( 'running_job', 7200, null, null, null, false, null )
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

	public function test_effective_retention_resolution_matrix(): void {
		$registry     = new JobRegistry();
		$handler      = $this->createMock( JobHandlerInterface::class );
		$job_inherit  = new JobDefinition( 'job_inherit', 'Inherit Job', 3600, $handler );
		$job_override = new JobDefinition( 'job_override', 'Override Job', 3600, $handler );
		$job_indef    = new JobDefinition( 'job_indef', 'Indefinite Job', 3600, $handler );
		$job_targeted = new JobDefinition( 'peakurl_link_health_check', 'Link Health', 86400, $handler );

		$registry->register( $job_inherit );
		$registry->register( $job_override );
		$registry->register( $job_indef );
		$registry->register( $job_targeted );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturnCallback(
				function ( string $id ) {
					if ( 'job_inherit' === $id ) {
						return array(
							'id'             => 'job_inherit',
							'retention_days' => null,
						);
					}
					if ( 'job_override' === $id ) {
						return array(
							'id'             => 'job_override',
							'retention_days' => 90,
						);
					}
					if ( 'job_indef' === $id ) {
						return array(
							'id'             => 'job_indef',
							'retention_days' => 0,
						);
					}
					if ( 'peakurl_link_health_check' === $id ) {
						return array(
							'id'             => 'peakurl_link_health_check',
							'retention_days' => 14,
						);
					}
					return null;
				}
			);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_CRON_HISTORY_RETENTION_DAYS )
			->willReturn( '30' );

		$scheduler = new Scheduler( $registry, $repository, null, $settings_api );

		// Case 1: NULL inherits global (30)
		$this->assertSame( 30, $scheduler->get_effective_job_retention( 'job_inherit' ) );

		// Case 2: Explicit positive retention (90) preserved
		$this->assertSame( 90, $scheduler->get_effective_job_retention( 'job_override' ) );

		// Case 3: 0 means indefinite
		$this->assertSame( 0, $scheduler->get_effective_job_retention( 'job_indef' ) );

		// Case 4: Targeted job IDs inherit the retention policy of their base registered job
		$this->assertSame( 14, $scheduler->get_effective_job_retention( 'peakurl_link_health_check:link_xyz789' ) );
		$this->assertSame( 14, $scheduler->get_effective_job_retention( 'peakurl_link_health_check:link_xyz789:next' ) );
	}

	public function test_global_retention_changes_affect_inherited_jobs_only(): void {
		$registry     = new JobRegistry();
		$handler      = $this->createMock( JobHandlerInterface::class );
		$job_inherit  = new JobDefinition( 'job_inherit', 'Inherit Job', 3600, $handler );
		$job_override = new JobDefinition( 'job_override', 'Override Job', 3600, $handler );
		$job_indef    = new JobDefinition( 'job_indef', 'Indefinite Job', 3600, $handler );

		$registry->register( $job_inherit );
		$registry->register( $job_override );
		$registry->register( $job_indef );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturnCallback(
				function ( string $id ) {
					if ( 'job_inherit' === $id ) {
						return array(
							'id'             => 'job_inherit',
							'retention_days' => null,
						);
					}
					if ( 'job_override' === $id ) {
						return array(
							'id'             => 'job_override',
							'retention_days' => 90,
						);
					}
					if ( 'job_indef' === $id ) {
						return array(
							'id'             => 'job_indef',
							'retention_days' => 0,
						);
					}
					return null;
				}
			);

		$stored_global = '30';
		$settings_api  = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_CRON_HISTORY_RETENTION_DAYS )
			->willReturnCallback(
				function () use ( &$stored_global ) {
					return $stored_global;
				}
			);
		$settings_api->method( 'update_option' )
			->willReturnCallback(
				function ( $opt, $val ) use ( &$stored_global ) {
					$stored_global = (string) $val;
					return true;
				}
			);

		$scheduler = new Scheduler( $registry, $repository, null, $settings_api );

		// Initial global = 30
		$this->assertSame( 30, $scheduler->get_effective_job_retention( 'job_inherit' ) );
		$this->assertSame( 90, $scheduler->get_effective_job_retention( 'job_override' ) );
		$this->assertSame( 0, $scheduler->get_effective_job_retention( 'job_indef' ) );

		// Change global to 60
		$scheduler->set_retention_days( 60 );
		$this->assertSame( 60, $scheduler->get_retention_days() );

		// Job inheriting NULL immediately reflects new global 60
		$this->assertSame( 60, $scheduler->get_effective_job_retention( 'job_inherit' ) );

		// Jobs with explicit overrides remain completely unchanged
		$this->assertSame( 90, $scheduler->get_effective_job_retention( 'job_override' ) );
		$this->assertSame( 0, $scheduler->get_effective_job_retention( 'job_indef' ) );

		// Change global to 0 (indefinite)
		$scheduler->set_retention_days( 0 );
		$this->assertSame( 0, $scheduler->get_retention_days() );
		$this->assertSame( 0, $scheduler->get_effective_job_retention( 'job_inherit' ) );
		$this->assertSame( 90, $scheduler->get_effective_job_retention( 'job_override' ) );
	}

	public function test_update_job_retention_validation_and_delegation(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		$job      = new JobDefinition( 'test_job', 'Test Job', 3600, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'test_job',
					'schedule_interval'  => 3600,
					'preferred_run_time' => null,
					'is_enabled'         => 1,
					'retention_days'     => null,
					'status'             => 'idle',
				)
			);

		$scheduler = new Scheduler( $registry, $repository, null, null, 30 );

		// 1. Negative retention rejected
		try {
			$scheduler->update_job( 'test_job', array( 'retention_days' => -5 ) );
			$this->fail( 'Expected InvalidArgumentException for negative retention.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Retention days must be null or a non-negative integer', $e->getMessage() );
		}

		// 2. Non-numeric retention rejected
		try {
			$scheduler->update_job( 'test_job', array( 'retention_days' => 'abc' ) );
			$this->fail( 'Expected InvalidArgumentException for non-numeric retention.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Retention days must be null or a non-negative integer', $e->getMessage() );
		}

		// 3. Valid explicit retention (90 days)
		$repository->expects( $this->once() )
			->method( 'update_job_schedule' )
			->with( 'test_job', 3600, null, null, $this->anything(), true, 90 )
			->willReturn( true );

		$scheduler->update_job( 'test_job', array( 'retention_days' => 90 ) );
	}

	public function test_reset_job_restores_null_retention_inheritance(): void {
		$registry = new JobRegistry();
		$handler  = $this->createMock( JobHandlerInterface::class );
		// Registered job with default null retention
		$job = new JobDefinition( 'test_job', 'Test Job', 3600, $handler );
		$registry->register( $job );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturn(
				array(
					'id'                 => 'test_job',
					'schedule_interval'  => 7200,
					'preferred_run_time' => '03:00',
					'is_enabled'         => 1,
					'retention_days'     => 90, // currently customized
					'status'             => 'idle',
				)
			);

		// reset_job must call reset_job_schedule without hardcoding global retention
		$repository->expects( $this->once() )
			->method( 'reset_job_schedule' )
			->with( 'test_job', 3600, true, $this->anything() )
			->willReturn( true );

		$scheduler = new Scheduler( $registry, $repository, null, null, 30 );
		$status    = $scheduler->reset_job( 'test_job' );

		// Wire status confirms retention returns to null inheritance
		$this->assertSame( 'test_job', $status['id'] );
	}

	public function test_job_status_reports_wire_level_retention_fields(): void {
		$registry   = new JobRegistry();
		$handler    = $this->createMock( JobHandlerInterface::class );
		$job_global = new JobDefinition( 'job_global', 'Global Job', 3600, $handler );
		$job_custom = new JobDefinition( 'job_custom', 'Custom Job', 3600, $handler );
		$job_indef  = new JobDefinition( 'job_indef', 'Indefinite Job', 3600, $handler );

		$registry->register( $job_global );
		$registry->register( $job_custom );
		$registry->register( $job_indef );

		$repository = $this->createMock( SchedulerRepository::class );
		$repository->method( 'get_job' )
			->willReturnCallback(
				function ( string $id ) {
					if ( 'job_global' === $id ) {
						return array(
							'id'                => 'job_global',
							'schedule_interval' => 3600,
							'retention_days'    => null,
							'status'            => 'idle',
							'is_enabled'        => 1,
						);
					}
					if ( 'job_custom' === $id ) {
						return array(
							'id'                => 'job_custom',
							'schedule_interval' => 3600,
							'retention_days'    => 90,
							'status'            => 'idle',
							'is_enabled'        => 1,
						);
					}
					if ( 'job_indef' === $id ) {
						return array(
							'id'                => 'job_indef',
							'schedule_interval' => 3600,
							'retention_days'    => 0,
							'status'            => 'idle',
							'is_enabled'        => 1,
						);
					}
					return null;
				}
			);

		$scheduler = new Scheduler( $registry, $repository, null, null, 30 );

		// 1. Inherited global job
		$status_global = $scheduler->get_single_job_status( 'job_global' );
		$this->assertNull( $status_global['retention_days'] );
		$this->assertFalse( $status_global['retention_is_customized'] );
		$this->assertSame( 30, $status_global['effective_retention_days'] );
		$this->assertFalse( $status_global['is_customized'] );

		// 2. Customized explicit job
		$status_custom = $scheduler->get_single_job_status( 'job_custom' );
		$this->assertSame( 90, $status_custom['retention_days'] );
		$this->assertTrue( $status_custom['retention_is_customized'] );
		$this->assertSame( 90, $status_custom['effective_retention_days'] );
		$this->assertTrue( $status_custom['is_customized'] );

		// 3. Indefinite job
		$status_indef = $scheduler->get_single_job_status( 'job_indef' );
		$this->assertSame( 0, $status_indef['retention_days'] );
		$this->assertTrue( $status_indef['retention_is_customized'] );
		$this->assertSame( 0, $status_indef['effective_retention_days'] );
		$this->assertTrue( $status_indef['is_customized'] );
	}
}
