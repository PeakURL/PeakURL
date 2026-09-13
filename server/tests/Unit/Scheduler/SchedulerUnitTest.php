<?php
/**
 * Unit tests for PeakURL Scheduler and Background Jobs.
 *
 * @package PeakURL\Tests\Unit\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Scheduler\RetryPolicy;
use PeakURL\Core\Scheduler\JobRegistry;
use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
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
}
