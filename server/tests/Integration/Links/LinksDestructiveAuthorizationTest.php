<?php
/**
 * Regression tests for Administrator Delete All and Empty Trash permissions (QA-001).
 *
 * Verifies that:
 * 1. Administrators possessing 'delete_all_links' can successfully empty trash and clear URLs.
 * 2. Editors possessing 'delete_all_links' can successfully empty trash and clear URLs.
 * 3. Unauthorized users lacking 'delete_all_links' are rejected with 403 Forbidden.
 * 4. No destructive repository mutations occur when authorization fails.
 *
 * @package PeakURL\Tests\Integration\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Captcha;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\ApiException;

class LinksDestructiveAuthorizationTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&SocialPreview $social_preview;
	private MockObject&Captcha $captcha;
	private MockObject&SettingsApi $settings_api;
	private Roles $roles;
	private Authorization $authorization;
	private LinksService $links_service;
	private LinksController $links_controller;

	protected function setUp(): void {
		parent::setUp();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->captcha           = $this->createMock( Captcha::class );
		$this->settings_api      = $this->createMock( SettingsApi::class );

		// Real role and authorization instances to test the actual application boundary.
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );

		$this->links_service = new LinksService(
			$this->repository,
			new LinksValidator(),
			$this->settings_api,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->captcha,
			$this->roles,
			$this->authorization,
			array( 'site_url' => 'https://peakurl.dev' )
		);

		$this->links_controller = new LinksController( $this->links_service );
	}

	public function test_administrator_can_empty_trash(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'site_admin',
			'role'     => 'admin',
		);

		$request = new Request( 'DELETE', '/api/v1/urls/trash', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$mock_trashed = array(
			array(
				'id'                => 'link_1',
				'title'             => 'Test Link 1',
				'alias'             => 'test1',
				'social_image_path' => null,
			),
		);

		$this->repository->expects( $this->once() )
			->method( 'get_all_trashed_links' )
			->with( $admin_user )
			->willReturn( $mock_trashed );

		$this->repository->expects( $this->once() )
			->method( 'bulk_delete_permanent' )
			->with( array( 'link_1' ) )
			->willReturn( 1 );

		$response = $this->links_controller->empty_trash( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 1, $response['body']['data']['deletedCount'] );
	}

	public function test_administrator_can_delete_all_links(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'site_admin',
			'role'     => 'admin',
		);

		$request = new Request( 'DELETE', '/api/v1/urls', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$mock_links = array(
			array(
				'id'                => 'link_1',
				'title'             => 'Test Link 1',
				'alias'             => 'test1',
				'social_image_path' => null,
			),
			array(
				'id'                => 'link_2',
				'title'             => 'Test Link 2',
				'alias'             => 'test2',
				'social_image_path' => null,
			),
		);

		$this->repository->expects( $this->once() )
			->method( 'get_all_accessible_links' )
			->with( $admin_user )
			->willReturn( $mock_links );

		$this->repository->expects( $this->once() )
			->method( 'bulk_delete_permanent' )
			->with( array( 'link_1', 'link_2' ) )
			->willReturn( 2 );

		$response = $this->links_controller->clear( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 2, $response['body']['data']['deletedCount'] );
	}

	public function test_editor_can_empty_trash(): void {
		$editor_user = array(
			'id'       => '2',
			'username' => 'site_editor',
			'role'     => 'editor',
		);

		$request = new Request( 'DELETE', '/api/v1/urls/trash', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );

		$mock_trashed = array(
			array(
				'id'                => 'link_editor_1',
				'title'             => 'Editor Link',
				'alias'             => 'ed1',
				'social_image_path' => null,
			),
		);

		$this->repository->expects( $this->once() )
			->method( 'get_all_trashed_links' )
			->with( $editor_user )
			->willReturn( $mock_trashed );

		$this->repository->expects( $this->once() )
			->method( 'bulk_delete_permanent' )
			->with( array( 'link_editor_1' ) )
			->willReturn( 1 );

		$response = $this->links_controller->empty_trash( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 1, $response['body']['data']['deletedCount'] );
	}

	public function test_editor_can_delete_all_links(): void {
		$editor_user = array(
			'id'       => '2',
			'username' => 'site_editor',
			'role'     => 'editor',
		);

		$request = new Request( 'DELETE', '/api/v1/urls', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );

		$mock_links = array(
			array(
				'id'                => 'link_editor_1',
				'title'             => 'Editor Link',
				'alias'             => 'ed1',
				'social_image_path' => null,
			),
		);

		$this->repository->expects( $this->once() )
			->method( 'get_all_accessible_links' )
			->with( $editor_user )
			->willReturn( $mock_links );

		$this->repository->expects( $this->once() )
			->method( 'bulk_delete_permanent' )
			->with( array( 'link_editor_1' ) )
			->willReturn( 1 );

		$response = $this->links_controller->clear( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 1, $response['body']['data']['deletedCount'] );
	}

	public function test_unauthorized_user_is_denied_empty_trash_and_no_destruction_occurs(): void {
		$restricted_roles = new class() extends Roles {
			public function has_capability( array $user, string $capability ): bool {
				return false;
			}
		};

		$restricted_auth       = new Authorization( $restricted_roles );
		$restricted_service    = new LinksService(
			$this->repository,
			new LinksValidator(),
			$this->settings_api,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->captcha,
			$restricted_roles,
			$restricted_auth,
			array( 'site_url' => 'https://peakurl.dev' )
		);
		$restricted_controller = new LinksController( $restricted_service );

		$unauthorized_user = array(
			'id'       => '99',
			'username' => 'restricted_user',
			'role'     => 'restricted',
		);

		$request = new Request( 'DELETE', '/api/v1/urls/trash', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $unauthorized_user );

		// Critical invariant: Repository MUST NOT be queried or mutated when unauthorized.
		$this->repository->expects( $this->never() )->method( 'get_all_trashed_links' );
		$this->repository->expects( $this->never() )->method( 'bulk_delete_permanent' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$restricted_controller->empty_trash( $request );
	}

	public function test_unauthorized_user_is_denied_delete_all_links_and_no_destruction_occurs(): void {
		$restricted_roles = new class() extends Roles {
			public function has_capability( array $user, string $capability ): bool {
				return false;
			}
		};

		$restricted_auth       = new Authorization( $restricted_roles );
		$restricted_service    = new LinksService(
			$this->repository,
			new LinksValidator(),
			$this->settings_api,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->captcha,
			$restricted_roles,
			$restricted_auth,
			array( 'site_url' => 'https://peakurl.dev' )
		);
		$restricted_controller = new LinksController( $restricted_service );

		$unauthorized_user = array(
			'id'       => '99',
			'username' => 'restricted_user',
			'role'     => 'restricted',
		);

		$request = new Request( 'DELETE', '/api/v1/urls', array(), array() );
		$this->auth_service->method( 'get_current_user' )->willReturn( $unauthorized_user );

		// Critical invariant: Repository MUST NOT be queried or mutated when unauthorized.
		$this->repository->expects( $this->never() )->method( 'get_all_accessible_links' );
		$this->repository->expects( $this->never() )->method( 'bulk_delete_permanent' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$restricted_controller->clear( $request );
	}
}
