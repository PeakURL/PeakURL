<?php
/**
 * Integration tests for Users Controller and Service contracts and business rules.
 *
 * @package PeakURL\Tests\Integration\Users
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Users;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Users\Controller as UsersController;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Api\UsersApi;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Services\SocialPreview;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\ApiException;
use ReflectionClass;

class UsersContractsTest extends TestCase {

	public function test_controller_declares_standard_rest_actions(): void {
		$ref = new ReflectionClass( UsersController::class );

		$expected_methods = array(
			'index',
			'create',
			'me',
			'update_me',
			'update',
			'delete',
		);

		foreach ( $expected_methods as $method_name ) {
			$this->assertTrue(
				$ref->hasMethod( $method_name ),
				"UsersController must implement method: {$method_name}"
			);
			$method = $ref->getMethod( $method_name );
			$params = $method->getParameters();
			$this->assertGreaterThanOrEqual( 1, count( $params ) );
			$this->assertSame( Request::class, $params[0]->getType()->getName() );
		}
	}

	public function test_update_current_user_strictly_rejects_username_modification(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'       => '1',
				'username' => 'original_username',
				'role'     => 'admin',
			)
		);

		$service = new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'PUT', '/api/v1/users/me', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Username cannot be changed.' );

		$service->update_current_user(
			$request,
			array(
				'username' => 'attempted_new_username',
			)
		);
	}

	public function test_delete_user_prevents_self_deletion(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$users_api->method( 'get_user_by_username' )->with( 'admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$service = new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'DELETE', '/api/v1/users/admin_user', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'You cannot delete the current user.' );

		$service->delete_user_by_username( $request, 'admin_user' );
	}

	public function test_delete_user_returns_false_when_user_not_found(): void {
		$db                = $this->createMock( PeakURL_DB::class );
		$users_api         = $this->createMock( UsersApi::class );
		$auth_service      = $this->createMock( AuthService::class );
		$analytics_service = $this->createMock( AnalyticsService::class );
		$validator         = $this->createMock( UsersValidator::class );
		$roles             = new Roles();
		$authorization     = new Authorization( $roles );
		$social_preview    = $this->createMock( SocialPreview::class );

		$auth_service->method( 'get_admin_user' )->willReturn(
			array(
				'id'       => 'user-1',
				'username' => 'admin_user',
				'role'     => 'admin',
			)
		);

		$users_api->method( 'get_user_by_username' )->with( 'non_existent' )->willReturn( null );

		$service = new UsersService(
			$db,
			$users_api,
			$auth_service,
			$analytics_service,
			$validator,
			$roles,
			$authorization,
			$social_preview
		);

		$request = new Request( 'DELETE', '/api/v1/users/non_existent', array(), array() );
		$result  = $service->delete_user_by_username( $request, 'non_existent' );

		$this->assertFalse( $result );
	}
}
