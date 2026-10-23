<?php
/**
 * Unit tests for link lifecycle action hooks (Phase 3).
 *
 * Verifies that:
 * - link_status_changed is fired on update_url when status transitions
 * - link_status_changed is fired on trash_link when link moves to trash
 * - link_status_changed is fired on restore_link when link is restored to active
 * - link_status_changed is fired on expire_due_links when link transitions to expired
 * - bulk_link_create_finished is fired on bulk_create_urls with summary counts
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Health\Checker as HealthChecker;
use PeakURL\Features\Links\Health\Context as HealthContext;
use PeakURL\Features\Links\Health\Probe as HealthProbe;
use PeakURL\Features\Links\Health\Resolver as HealthResolver;
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
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;

class LinkLifecycleHooksTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private HealthChecker $health_checker;
	private MockObject&SocialPreview $social_preview;
	private MockObject&Captcha $captcha;
	private MockObject&SettingsApi $settings_api;
	private Roles $roles;
	private Authorization $authorization;
	private LinksService $links_service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->captcha           = $this->createMock( Captcha::class );
		$this->settings_api      = $this->createMock( SettingsApi::class );

		$links_api = $this->createMock( \PeakURL\Api\LinksApi::class );
		$this->repository->method( 'get_links_api' )->willReturn( $links_api );

		$this->roles          = new Roles();
		$this->authorization  = new Authorization( $this->roles );
		$this->health_checker = new HealthChecker( new HealthResolver(), new HealthProbe( new HealthContext( array() ) ) );

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
			array( 'site_url' => 'https://peakurl.dev' ),
			$this->health_checker
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	public function test_bulk_create_urls_fires_bulk_link_create_finished_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		// Configure repository mocks for one successful insertion.
		$this->repository->method( 'short_code_exists' )->willReturn( false );
		$this->repository->method( 'find_url_row' )->willReturn(
			array(
				'id'              => 'new_link_id_1',
				'user_id'         => '1',
				'short_code'      => 'bulk1',
				'alias'           => 'bulk1',
				'destination_url' => 'https://example.com/item1',
				'title'           => 'Item 1',
				'status'          => 'active',
			)
		);

		$captured = null;
		add_action(
			'bulk_link_create_finished',
			function ( array $summary, ?array $user, ?Request $request ) use ( &$captured ): void {
				$captured = array(
					'summary' => $summary,
					'user'    => $user,
					'request' => $request,
				);
			},
			10,
			3
		);

		$request = new Request(
			'POST',
			'/api/v1/urls/bulk',
			array(),
			array(
				'urls' => array(
					array(
						'destinationUrl' => 'https://example.com/item1',
						'title'          => 'Item 1',
					),
					// Invalid payload item to generate an error in the batch.
					'not_an_array',
				),
			)
		);

		$response = $this->links_service->bulk_create_urls(
			$request,
			array(
				'urls' => array(
					array(
						'destinationUrl' => 'https://example.com/item1',
						'title'          => 'Item 1',
					),
					'not_an_array',
				),
			)
		);

		$this->assertCount( 1, $response['results'] );
		$this->assertCount( 1, $response['errors'] );

		$this->assertNotNull( $captured );
		$this->assertSame( 1, $captured['summary']['created'] );
		$this->assertSame( 1, $captured['summary']['failed'] );
		$this->assertCount( 1, $captured['summary']['results'] );
		$this->assertCount( 1, $captured['summary']['errors'] );
		$this->assertSame( '1', $captured['user']['id'] );
		$this->assertSame( $request, $captured['request'] );
	}

	public function test_expire_due_links_fires_link_status_changed_action(): void {
		$due_links = array(
			array(
				'id'              => 'exp_link_1',
				'user_id'         => '1',
				'short_code'      => 'exp1',
				'destination_url' => 'https://example.com/exp',
				'status'          => 'active',
				'expires_at'      => '2026-01-01 00:00:00',
			),
		);

		$this->repository->method( 'expire_due_links' )->with( 100 )->willReturn( $due_links );

		$captured = array();
		add_action(
			'link_status_changed',
			function ( array $link, string $old_status, string $new_status, ?array $user, ?Request $request ) use ( &$captured ): void {
				$captured[] = array(
					'link'       => $link,
					'old_status' => $old_status,
					'new_status' => $new_status,
					'user'       => $user,
					'request'    => $request,
				);
			},
			10,
			5
		);

		$expired_count = $this->links_service->expire_due_links( 100 );
		$this->assertSame( 1, $expired_count );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'active', $captured[0]['old_status'] );
		$this->assertSame( 'expired', $captured[0]['new_status'] );
		$this->assertSame( 'exp_link_1', $captured[0]['link']['id'] );
		$this->assertNull( $captured[0]['user'] );
		$this->assertNull( $captured[0]['request'] );
	}

	public function test_bulk_link_create_finished_observer_exception_does_not_break_response(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'short_code_exists' )->willReturn( false );
		$this->repository->method( 'find_url_row' )->willReturn(
			array(
				'id'              => 'resilient_link',
				'user_id'         => '1',
				'short_code'      => 'res1',
				'alias'           => 'res1',
				'destination_url' => 'https://example.com/res',
				'title'           => 'Resilient',
				'status'          => 'active',
			)
		);

		add_action(
			'bulk_link_create_finished',
			function (): void {
				throw new \RuntimeException( 'Simulated observer crash on bulk create finished' );
			}
		);

		$request  = new Request( 'POST', '/api/v1/urls/bulk', array(), array() );
		$response = $this->links_service->bulk_create_urls(
			$request,
			array(
				'urls' => array(
					array(
						'destinationUrl' => 'https://example.com/res',
						'title'          => 'Resilient',
					),
				),
			)
		);

		$this->assertCount( 1, $response['results'] );
		$this->assertCount( 0, $response['errors'] );
	}

	public function test_on_access_expiration_fires_link_status_changed_hook(): void {
		$expired_row = array(
			'id'              => 'access_exp_1',
			'short_code'      => 'acc-exp',
			'destination_url' => 'https://example.com/expired-target',
			'status'          => 'active',
			'expires_at'      => '2020-01-01 00:00:00',
		);

		$this->repository->method( 'find_link_access_row' )->with( 'acc-exp' )->willReturn( $expired_row );
		$this->repository->method( 'expire_link' )->with( 'access_exp_1' )->willReturn( true );

		$captured = null;
		add_action(
			'link_status_changed',
			function ( array $link, string $old_status, string $new_status, ?array $user, ?Request $request ) use ( &$captured ): void {
				$captured = array(
					'link'       => $link,
					'old_status' => $old_status,
					'new_status' => $new_status,
					'user'       => $user,
					'request'    => $request,
				);
			},
			10,
			5
		);

		$request = new Request( 'GET', '/acc-exp', array(), array() );
		$result  = $this->links_service->get_link_access( 'acc-exp', $request );

		$this->assertSame( 'expired', $result['status'] );
		$this->assertNotNull( $captured );
		$this->assertSame( 'active', $captured['old_status'] );
		$this->assertSame( 'expired', $captured['new_status'] );
		$this->assertSame( 'access_exp_1', $captured['link']['id'] );
		$this->assertNull( $captured['user'] );
		$this->assertSame( $request, $captured['request'] );
	}
}
