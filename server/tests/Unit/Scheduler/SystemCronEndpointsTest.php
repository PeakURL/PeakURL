<?php
/**
 * Unit tests for SystemController and SystemService cron execution endpoints.
 *
 * @package PeakURL\Tests\Unit\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Scheduler\Scheduler;
use PeakURL\Core\Scheduler\JobRegistry;
use PeakURL\Core\Scheduler\JobDefinition;
use PeakURL\Core\Scheduler\JobHandlerInterface;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\ApiException;
use ReflectionClass;

class SystemCronEndpointsTest extends TestCase {

	public function test_system_controller_run_cron_dispatches_with_json_data(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'POST',
			'/api/v1/system/cron/run',
			array(),
			array( 'job_id' => 'peakurl_cache_cleanup' )
		);

		$system_service->expects( $this->once() )
			->method( 'run_cron_job' )
			->with( $request, 'peakurl_cache_cleanup' )
			->willReturn(
				array(
					'success' => true,
					'result'  => array( 'status' => 'success' ),
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->run_cron( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
	}

	public function test_system_controller_run_cron_dispatches_run_all_when_no_job_id(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'POST',
			'/api/v1/system/cron/run',
			array(),
			array()
		);

		$system_service->expects( $this->once() )
			->method( 'run_cron_job' )
			->with( $request, null )
			->willReturn(
				array(
					'run_all' => true,
					'success' => true,
					'results' => array(),
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->run_cron( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['run_all'] );
	}

	public function test_system_controller_cron_run_now_delegates_to_run_cron_job(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'POST',
			'/api/v1/system/cron/jobs/peakurl_geoip_update/run',
			array(),
			array()
		);
		$request->set_route_params( array( 'id' => 'peakurl_geoip_update' ) );

		$system_service->expects( $this->once() )
			->method( 'run_cron_job' )
			->with( $request, 'peakurl_geoip_update' )
			->willReturn(
				array(
					'success' => true,
					'result'  => array( 'status' => 'success' ),
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->cron_run_now( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
	}
	public function test_system_service_run_cron_job_reads_json_data_without_fatal_get_json(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );

		$handler  = $this->createMock( JobHandlerInterface::class );
		$registry = new JobRegistry();
		$registry->register( new JobDefinition( 'test_job', 'Test Job', 3600, $handler ) );

		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->method( 'get_registry' )
			->willReturn( $registry );
		$scheduler->expects( $this->once() )
			->method( 'run_job' )
			->with( 'test_job', true )
			->willReturn( ExecutionResult::success( 'Executed ok' ) );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/run',
			array(),
			array( 'job_id' => 'test_job' )
		);

		$result = $system_service->run_cron_job( $request );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'test_job', $result['job_id'] );
		$this->assertSame( 'success', $result['status'] );
	}

	public function test_system_service_run_cron_job_runs_all_due_jobs_when_body_is_empty(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );

		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->expects( $this->once() )
			->method( 'run_due_jobs' )
			->willReturn(
				array(
					'test_job' => array( 'status' => 'success' ),
				)
			);

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/run',
			array(),
			array()
		);

		$result = $system_service->run_cron_job( $request );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['run_all'] );
		$this->assertArrayHasKey( 'test_job', $result['results'] );
	}

	public function test_system_controller_cron_settings_update(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'POST',
			'/api/v1/system/cron/settings',
			array(),
			array( 'retention_days' => 14 )
		);

		$system_service->expects( $this->once() )
			->method( 'update_cron_settings' )
			->with( $request )
			->willReturn(
				array(
					'retention_days' => 14,
					'success'        => true,
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->cron_settings_update( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
		$this->assertSame( 14, $response['body']['data']['retention_days'] );
	}

	public function test_system_controller_cron_clear_history(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'DELETE',
			'/api/v1/system/cron/history',
			array( 'job_id' => 'peakurl_cache_cleanup' ),
			array()
		);

		$system_service->expects( $this->once() )
			->method( 'clear_cron_history' )
			->with( $request )
			->willReturn(
				array(
					'deleted_count' => 5,
					'job_id'        => 'peakurl_cache_cleanup',
					'success'       => true,
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->cron_clear_history( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
		$this->assertSame( 5, $response['body']['data']['deleted_count'] );
	}

	public function test_system_service_clear_cron_history_with_job_key(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );

		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->expects( $this->once() )
			->method( 'clear_history' )
			->with( 'job_via_key' )
			->willReturn( 7 );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/history/clear',
			array(),
			array( 'job_key' => 'job_via_key' )
		);

		$result = $system_service->clear_cron_history( $request );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 7, $result['deleted_count'] );
		$this->assertSame( 'job_via_key', $result['job_id'] );
		$this->assertSame( 'job_via_key', $result['job_key'] );
	}

	public function test_system_service_clear_cron_history_unauthorized_rejection(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '2',
					'role' => 'editor',
				)
			);

		$authorization = $this->createMock( Authorization::class );
		$authorization->method( 'validate_capability' )
			->willThrowException( new ApiException( 'Admin access is required.', 403 ) );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/history/clear',
			array(),
			array()
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$system_service->clear_cron_history( $request );
	}

	public function test_system_service_update_cron_settings_rejects_negative_and_invalid(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );
		$scheduler     = $this->createMock( Scheduler::class );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/settings',
			array(),
			array( 'retention_days' => -1 )
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$system_service->update_cron_settings( $request );
	}

	public function test_system_controller_cron_job_update_delegates_to_service(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'PATCH',
			'/api/v1/system/cron/jobs/peakurl_session_cleanup',
			array(),
			array(
				'schedule_interval' => 86400,
				'preferred_time'    => '02:00',
				'is_enabled'        => true,
			)
		);
		$request->set_route_params( array( 'id' => 'peakurl_session_cleanup' ) );

		$system_service->expects( $this->once() )
			->method( 'update_cron_job' )
			->with( $request )
			->willReturn(
				array(
					'success' => true,
					'message' => 'Job schedule updated successfully.',
					'job'     => array( 'id' => 'peakurl_session_cleanup' ),
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->cron_job_update( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
	}

	public function test_system_controller_cron_job_reset_delegates_to_service(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request(
			'POST',
			'/api/v1/system/cron/jobs/peakurl_session_cleanup/reset',
			array(),
			array()
		);
		$request->set_route_params( array( 'id' => 'peakurl_session_cleanup' ) );

		$system_service->expects( $this->once() )
			->method( 'reset_cron_job' )
			->with( $request )
			->willReturn(
				array(
					'success' => true,
					'message' => 'Job schedule reset to recommended defaults.',
					'job'     => array( 'id' => 'peakurl_session_cleanup' ),
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->cron_job_reset( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['data']['success'] );
	}

	public function test_system_service_update_cron_job_rejects_sub_300_interval(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );

		$handler  = $this->createMock( JobHandlerInterface::class );
		$registry = new JobRegistry();
		$registry->register( new JobDefinition( 'peakurl_cache_cleanup', 'Cache Cleanup', 3600, $handler ) );

		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->method( 'get_registry' )
			->willReturn( $registry );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'PATCH',
			'/api/v1/system/cron/jobs/peakurl_cache_cleanup',
			array(),
			array( 'schedule_interval' => 60 )
		);
		$request->set_route_params( array( 'id' => 'peakurl_cache_cleanup' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$system_service->update_cron_job( $request, 'peakurl_cache_cleanup' );
	}

	public function test_system_service_update_cron_job_rejects_invalid_time_format(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '1',
					'role' => 'admin',
				)
			);

		$authorization = $this->createMock( Authorization::class );

		$handler  = $this->createMock( JobHandlerInterface::class );
		$registry = new JobRegistry();
		$registry->register( new JobDefinition( 'peakurl_session_cleanup', 'Session Cleanup', 86400, $handler ) );

		$scheduler = $this->createMock( Scheduler::class );
		$scheduler->method( 'get_registry' )
			->willReturn( $registry );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$prop_scheduler = $ref->getProperty( 'scheduler' );
		$prop_scheduler->setValue( $system_service, $scheduler );

		$request = new Request(
			'PATCH',
			'/api/v1/system/cron/jobs/peakurl_session_cleanup',
			array(),
			array(
				'schedule_interval' => 86400,
				'preferred_time'    => '25:99',
			)
		);
		$request->set_route_params( array( 'id' => 'peakurl_session_cleanup' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$system_service->update_cron_job( $request, 'peakurl_session_cleanup' );
	}

	public function test_system_service_update_cron_job_unauthorized_rejection(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '2',
					'role' => 'editor',
				)
			);

		$authorization = $this->createMock( Authorization::class );
		$authorization->method( 'validate_capability' )
			->willThrowException( new ApiException( 'Admin access is required.', 403 ) );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$request = new Request(
			'PATCH',
			'/api/v1/system/cron/jobs/peakurl_session_cleanup',
			array(),
			array( 'schedule_interval' => 86400 )
		);
		$request->set_route_params( array( 'id' => 'peakurl_session_cleanup' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$system_service->update_cron_job( $request, 'peakurl_session_cleanup' );
	}

	public function test_system_service_reset_cron_job_unauthorized_rejection(): void {
		$ref            = new ReflectionClass( SystemService::class );
		$system_service = $ref->newInstanceWithoutConstructor();

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => '2',
					'role' => 'editor',
				)
			);

		$authorization = $this->createMock( Authorization::class );
		$authorization->method( 'validate_capability' )
			->willThrowException( new ApiException( 'Admin access is required.', 403 ) );

		$prop_auth = $ref->getProperty( 'auth_service' );
		$prop_auth->setValue( $system_service, $auth_service );

		$prop_authorization = $ref->getProperty( 'authorization' );
		$prop_authorization->setValue( $system_service, $authorization );

		$request = new Request(
			'POST',
			'/api/v1/system/cron/jobs/peakurl_session_cleanup/reset',
			array(),
			array()
		);
		$request->set_route_params( array( 'id' => 'peakurl_session_cleanup' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$system_service->reset_cron_job( $request, 'peakurl_session_cleanup' );
	}
}
