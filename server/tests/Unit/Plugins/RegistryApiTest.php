<?php
/**
 * Unit tests for Plugins API endpoint.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\System\Controller as SystemController;
use PeakURL\Features\System\Service as SystemService;
use PeakURL\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests read-only installed plugins API routing, authentication, and response safety.
 */
class RegistryApiTest extends TestCase {

	public function test_system_controller_plugins_returns_safe_envelope(): void {
		$system_service = $this->createMock( SystemService::class );
		$request        = new Request( 'GET', '/api/v1/system/plugins', array(), array() );

		$system_service->expects( $this->once() )
			->method( 'get_installed_plugins' )
			->with( $request )
			->willReturn(
				array(
					'items'   => array(
						array(
							'id'         => 'example-plugin',
							'type'       => 'regular',
							'name'       => 'Example Plugin',
							'version'    => '1.0.0',
							'valid'      => true,
							'compatible' => true,
						),
					),
					'regular' => array(),
					'mustUse' => array(),
					'total'   => 1,
				)
			);

		$controller = new SystemController( $system_service );
		$response   = $controller->plugins( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['success'] );
		$this->assertSame( 1, $response['body']['data']['total'] );
		$this->assertSame( 'example-plugin', $response['body']['data']['items'][0]['id'] );
	}

	public function test_system_service_requires_admin_capability(): void {
		$auth_service  = $this->createMock( AuthService::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		// Simulate non-admin user (viewer role without manage_updates or manage_site_settings).
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => 42,
					'role' => 'viewer',
				)
			);

		$ref_class    = new \ReflectionClass( SystemService::class );
		$service_inst = $ref_class->newInstanceWithoutConstructor();

		// Inject mock properties.
		$auth_prop = $ref_class->getProperty( 'auth_service' );
		$auth_prop->setValue( $service_inst, $auth_service );

		$authz_prop = $ref_class->getProperty( 'authorization' );
		$authz_prop->setValue( $service_inst, $authorization );

		$request = new Request( 'GET', '/api/v1/system/plugins', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$service_inst->get_installed_plugins( $request );
	}

	public function test_system_service_rejects_unauthenticated_request(): void {
		$auth_service  = $this->createMock( AuthService::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$auth_service->method( 'get_current_user' )
			->willThrowException( new ApiException( 'Authentication required.', 401 ) );

		$ref_class    = new \ReflectionClass( SystemService::class );
		$service_inst = $ref_class->newInstanceWithoutConstructor();

		$auth_prop = $ref_class->getProperty( 'auth_service' );
		$auth_prop->setValue( $service_inst, $auth_service );

		$authz_prop = $ref_class->getProperty( 'authorization' );
		$authz_prop->setValue( $service_inst, $authorization );

		$request = new Request( 'GET', '/api/v1/system/plugins', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 401 );

		$service_inst->get_installed_plugins( $request );
	}

	public function test_system_service_allows_user_with_admin_capability(): void {
		$auth_service  = $this->createMock( AuthService::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		// Simulate admin user who has manage_updates and manage_site_settings in catalog.
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'   => 1,
					'role' => 'admin',
				)
			);

		$ref_class    = new \ReflectionClass( SystemService::class );
		$service_inst = $ref_class->newInstanceWithoutConstructor();

		$auth_prop = $ref_class->getProperty( 'auth_service' );
		$auth_prop->setValue( $service_inst, $auth_service );

		$authz_prop = $ref_class->getProperty( 'authorization' );
		$authz_prop->setValue( $service_inst, $authorization );

		$request  = new Request( 'GET', '/api/v1/system/plugins', array(), array() );
		$response = $service_inst->get_installed_plugins( $request );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'items', $response );
		$this->assertArrayHasKey( 'regular', $response );
		$this->assertArrayHasKey( 'mustUse', $response );
		$this->assertArrayHasKey( 'total', $response );
		$this->assertArrayHasKey( 'uploadLimit', $response );
		$this->assertIsArray( $response['uploadLimit'] );
		$this->assertArrayHasKey( 'bytes', $response['uploadLimit'] );
		$this->assertArrayHasKey( 'formatted', $response['uploadLimit'] );
		$this->assertGreaterThan( 0, $response['uploadLimit']['bytes'] );
		$this->assertNotEmpty( $response['uploadLimit']['formatted'] );
	}
}
