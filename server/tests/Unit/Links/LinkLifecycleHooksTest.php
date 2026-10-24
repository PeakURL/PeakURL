<?php
/**
 * Unit tests for link lifecycle action hooks (Phase 2 & Phase 3).
 *
 * Verifies that:
 * - link_status_changed is fired on update_url when status transitions
 * - link_status_changed is fired on trash_link when link moves to trash
 * - link_status_changed is fired on restore_link when link is restored to active
 * - link_status_changed is fired on expire_due_links when link transitions to expired
 * - bulk_link_create_finished is fired on bulk_create_urls with summary counts
 * - bulk_link_delete_finished is fired on bulk_delete_urls for trash and permanent modes
 * - bulk_link_restore_finished is fired on bulk_restore_urls with confirmed restored counts
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
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Links\Controller as LinksController;

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

	public function test_bulk_delete_urls_fires_bulk_link_delete_finished_action_for_trash(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'del_1',
				'user_id'         => '1',
				'short_code'      => 'd1',
				'destination_url' => 'https://example.com/d1',
				'status'          => 'active',
			),
			array(
				'id'              => 'del_2',
				'user_id'         => '1',
				'short_code'      => 'd2',
				'destination_url' => 'https://example.com/d2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'del_1', 'del_2' ) )->willReturn( $rows );
		$this->repository->method( 'trash_url' )->willReturn( true );

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary, array $actor ) use ( &$captured ): void {
				$captured = array(
					'summary' => $summary,
					'actor'   => $actor,
				);
			},
			10,
			2
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'del_1', 'del_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'del_1', 'del_2' ), false );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'trash', $captured['summary']['mode'] );
		$this->assertSame( 2, $captured['summary']['requested'] );
		$this->assertSame( 2, $captured['summary']['affected'] );
		$this->assertSame( '1', $captured['actor']['id'] );
		$this->assertSame( 'admin', $captured['actor']['username'] );
		$this->assertSame( 'admin', $captured['actor']['role'] );
	}

	public function test_bulk_delete_urls_fires_bulk_link_delete_finished_action_for_permanent_force(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'perm_1',
				'user_id'         => '1',
				'short_code'      => 'p1',
				'destination_url' => 'https://example.com/p1',
				'status'          => 'active',
			),
			array(
				'id'              => 'perm_2',
				'user_id'         => '1',
				'short_code'      => 'p2',
				'destination_url' => 'https://example.com/p2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'perm_1', 'perm_2' ) )->willReturn( $rows );
		$this->repository->method( 'delete_links_permanently' )->with( array( 'perm_1', 'perm_2' ) )->willReturn( array( 'perm_1', 'perm_2' ) );

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary, array $actor ) use ( &$captured ): void {
				$captured = array(
					'summary' => $summary,
					'actor'   => $actor,
				);
			},
			10,
			2
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'perm_1', 'perm_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'perm_1', 'perm_2' ), true );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'permanent', $captured['summary']['mode'] );
		$this->assertSame( 2, $captured['summary']['requested'] );
		$this->assertSame( 2, $captured['summary']['affected'] );
		$this->assertSame( '1', $captured['actor']['id'] );
	}

	public function test_bulk_delete_urls_resolves_mode_to_permanent_for_already_trashed_links(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'trashed_1',
				'user_id'         => '1',
				'short_code'      => 't1',
				'destination_url' => 'https://example.com/t1',
				'status'          => 'trashed',
			),
			array(
				'id'              => 'trashed_2',
				'user_id'         => '1',
				'short_code'      => 't2',
				'destination_url' => 'https://example.com/t2',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'trashed_1', 'trashed_2' ) )->willReturn( $rows );
		$this->repository->method( 'delete_links_permanently' )->with( array( 'trashed_1', 'trashed_2' ) )->willReturn( array( 'trashed_1', 'trashed_2' ) );

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary, array $actor ) use ( &$captured ): void {
				$captured = array(
					'summary' => $summary,
					'actor'   => $actor,
				);
			},
			10,
			2
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'trashed_1', 'trashed_2' ) ) );
		// Note force is false, but links are already trashed:
		$count = $this->links_service->bulk_delete_urls( $request, array( 'trashed_1', 'trashed_2' ), false );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'permanent', $captured['summary']['mode'] );
		$this->assertSame( 2, $captured['summary']['requested'] );
		$this->assertSame( 2, $captured['summary']['affected'] );
	}

	public function test_bulk_restore_urls_fires_bulk_link_restore_finished_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'res_1',
				'user_id'         => '1',
				'short_code'      => 'r1',
				'destination_url' => 'https://example.com/r1',
				'status'          => 'trashed',
			),
			array(
				'id'              => 'res_2',
				'user_id'         => '1',
				'short_code'      => 'r2',
				'destination_url' => 'https://example.com/r2',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'res_1', 'res_2' ) )->willReturn( $rows );
		$this->repository->method( 'restore_url' )->willReturn( true );

		$captured = null;
		add_action(
			'bulk_link_restore_finished',
			function ( array $summary, array $actor ) use ( &$captured ): void {
				$captured = array(
					'summary' => $summary,
					'actor'   => $actor,
				);
			},
			10,
			2
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'res_1', 'res_2' ) ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array( 'res_1', 'res_2' ) );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertArrayNotHasKey( 'mode', $captured['summary'] );
		$this->assertSame( 2, $captured['summary']['requested'] );
		$this->assertSame( 2, $captured['summary']['affected'] );
		$this->assertSame( '1', $captured['actor']['id'] );
		$this->assertSame( 'admin', $captured['actor']['username'] );
		$this->assertSame( 'admin', $captured['actor']['role'] );
	}

	public function test_bulk_delete_urls_partial_success_reports_actual_affected_count(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'part_1',
				'user_id'         => '1',
				'short_code'      => 'part1',
				'destination_url' => 'https://example.com/part1',
				'status'          => 'active',
			),
			array(
				'id'              => 'part_2',
				'user_id'         => '1',
				'short_code'      => 'part2',
				'destination_url' => 'https://example.com/part2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'part_1', 'part_2' ) )->willReturn( $rows );
		$this->repository->method( 'trash_url' )->willReturnCallback(
			fn( string $id ): bool => 'part_1' === $id
		);

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'part_1', 'part_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'part_1', 'part_2' ), false );

		$this->assertSame( 1, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'trash', $captured['mode'] );
		$this->assertSame( 2, $captured['requested'] );
		$this->assertSame( 1, $captured['affected'] );
	}

	public function test_bulk_delete_permanent_partial_success_only_fires_side_effects_for_confirmed_links(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'                => 'perm_part_1',
				'user_id'           => '1',
				'title'             => 'Partial Link 1',
				'short_code'        => 'pp1',
				'destination_url'   => 'https://example.com/pp1',
				'status'            => 'active',
				'social_image_path' => 'pp1.png',
			),
			array(
				'id'                => 'perm_part_2',
				'user_id'           => '1',
				'title'             => 'Partial Link 2',
				'short_code'        => 'pp2',
				'destination_url'   => 'https://example.com/pp2',
				'status'            => 'active',
				'social_image_path' => 'pp2.png',
			),
			array(
				'id'                => 'perm_part_3',
				'user_id'           => '1',
				'title'             => 'Partial Link 3',
				'short_code'        => 'pp3',
				'destination_url'   => 'https://example.com/pp3',
				'status'            => 'active',
				'social_image_path' => 'pp3.png',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'perm_part_1', 'perm_part_2', 'perm_part_3' ) )->willReturn( $rows );
		$this->repository->method( 'delete_links_permanently' )
			->with( array( 'perm_part_1', 'perm_part_2', 'perm_part_3' ) )
			->willReturn( array( 'perm_part_1' ) );

		$deleted_action_ids = array();
		add_action(
			'link_deleted',
			function ( array $deleted_row ) use ( &$deleted_action_ids ): void {
				$deleted_action_ids[] = (string) ( $deleted_row['id'] ?? '' );
			}
		);

		$webhook_dispatched_ids = array();
		$this->webhooks_service->method( 'dispatch_link_event' )
			->willReturnCallback(
				function ( string $event, array $link_payload ) use ( &$webhook_dispatched_ids ): array {
					if ( 'link.deleted' === $event ) {
						$webhook_dispatched_ids[] = (string) ( $link_payload['id'] ?? '' );
					}

					return array();
				}
			);

		$this->social_preview->expects( $this->once() )
			->method( 'delete_link_images' )
			->with( array( 'pp1.png' ) );

		$activity_records = array();
		$this->analytics_service->method( 'record_activity' )
			->willReturnCallback(
				function ( string $action, string $description, ?string $user_id = null, ?string $link_id = null, ?array $meta = null ) use ( &$activity_records ): void {
					$activity_records[] = array(
						'action' => $action,
						'meta'   => $meta,
					);
				}
			);

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'perm_part_1', 'perm_part_2', 'perm_part_3' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'perm_part_1', 'perm_part_2', 'perm_part_3' ), true );

		$this->assertSame( 1, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'permanent', $captured['mode'] );
		$this->assertSame( 3, $captured['requested'] );
		$this->assertSame( 1, $captured['affected'] );

		// Verify that ONLY the confirmed deleted link received lifecycle notifications
		$this->assertSame( array( 'perm_part_1' ), $deleted_action_ids );
		$this->assertSame( array( 'perm_part_1' ), $webhook_dispatched_ids );
		$this->assertCount( 1, $activity_records );
		$this->assertSame( 'link_deleted', $activity_records[0]['action'] );
		$this->assertSame( 'perm_part_1', $activity_records[0]['meta']['link']['id'] ?? '' );
	}

	public function test_bulk_restore_urls_zero_affected_when_rows_already_active(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'already_act_1',
				'user_id'         => '1',
				'short_code'      => 'act1',
				'destination_url' => 'https://example.com/act1',
				'status'          => 'active',
			),
			array(
				'id'              => 'already_act_2',
				'user_id'         => '1',
				'short_code'      => 'act2',
				'destination_url' => 'https://example.com/act2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'already_act_1', 'already_act_2' ) )->willReturn( $rows );
		$this->repository->expects( $this->never() )->method( 'restore_url' );

		$captured = null;
		add_action(
			'bulk_link_restore_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'already_act_1', 'already_act_2' ) ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array( 'already_act_1', 'already_act_2' ) );

		$this->assertSame( 0, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 2, $captured['requested'] );
		$this->assertSame( 0, $captured['affected'] );
	}

	public function test_bulk_delete_urls_empty_selection_does_not_fire_action(): void {
		$invoked = false;
		add_action(
			'bulk_link_delete_finished',
			function () use ( &$invoked ): void {
				$invoked = true;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array() ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array() );

		$this->assertSame( 0, $count );
		$this->assertFalse( $invoked );
	}

	public function test_bulk_restore_urls_empty_selection_does_not_fire_action(): void {
		$invoked = false;
		add_action(
			'bulk_link_restore_finished',
			function () use ( &$invoked ): void {
				$invoked = true;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array() ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array() );

		$this->assertSame( 0, $count );
		$this->assertFalse( $invoked );
	}

	public function test_bulk_delete_urls_missing_database_rows_does_not_fire_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_links_by_ids' )->with( array( 'ghost_1' ) )->willReturn( array() );

		$invoked = false;
		add_action(
			'bulk_link_delete_finished',
			function () use ( &$invoked ): void {
				$invoked = true;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'ghost_1' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'ghost_1' ) );

		$this->assertSame( 0, $count );
		$this->assertFalse( $invoked );
	}

	public function test_bulk_delete_urls_mixed_selection_without_force_throws_and_does_not_fire_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'mix_active',
				'user_id'         => '1',
				'short_code'      => 'act',
				'destination_url' => 'https://example.com/act',
				'status'          => 'active',
			),
			array(
				'id'              => 'mix_trashed',
				'user_id'         => '1',
				'short_code'      => 'tsh',
				'destination_url' => 'https://example.com/tsh',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->willReturn( $rows );

		$invoked = false;
		add_action(
			'bulk_link_delete_finished',
			function () use ( &$invoked ): void {
				$invoked = true;
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'mix_active', 'mix_trashed' ) ) );
		try {
			$this->links_service->bulk_delete_urls( $request, array( 'mix_active', 'mix_trashed' ), false );
		} finally {
			$this->assertFalse( $invoked );
		}
	}

	public function test_bulk_delete_urls_authorization_failure_throws_and_does_not_fire_action(): void {
		$subscriber_user = array(
			'id'       => 'sub_1',
			'username' => 'subscriber',
			'role'     => 'subscriber',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $subscriber_user );

		$invoked = false;
		add_action(
			'bulk_link_delete_finished',
			function () use ( &$invoked ): void {
				$invoked = true;
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'any_id' ) ) );
		try {
			$this->links_service->bulk_delete_urls( $request, array( 'any_id' ), false );
		} finally {
			$this->assertFalse( $invoked );
		}
	}

	public function test_bulk_link_delete_finished_observer_exception_does_not_break_operation(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'obs_del_1',
				'user_id'         => '1',
				'short_code'      => 'od1',
				'destination_url' => 'https://example.com/od1',
				'status'          => 'active',
			),
			array(
				'id'              => 'obs_del_2',
				'user_id'         => '1',
				'short_code'      => 'od2',
				'destination_url' => 'https://example.com/od2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->willReturn( $rows );
		$this->repository->method( 'trash_url' )->willReturn( true );

		add_action(
			'bulk_link_delete_finished',
			function (): void {
				throw new \RuntimeException( 'Simulated crash in bulk_link_delete_finished listener' );
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'obs_del_1', 'obs_del_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'obs_del_1', 'obs_del_2' ), false );

		$this->assertSame( 2, $count );
	}

	public function test_bulk_link_restore_finished_observer_exception_does_not_break_operation(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'obs_res_1',
				'user_id'         => '1',
				'short_code'      => 'or1',
				'destination_url' => 'https://example.com/or1',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->willReturn( $rows );
		$this->repository->method( 'restore_url' )->willReturn( true );

		add_action(
			'bulk_link_restore_finished',
			function (): void {
				throw new \RuntimeException( 'Simulated crash in bulk_link_restore_finished listener' );
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'obs_res_1' ) ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array( 'obs_res_1' ) );

		$this->assertSame( 1, $count );
	}

	public function test_delete_links_permanently_zero_affected_does_not_fire_side_effects(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'                => 'zero_1',
				'user_id'           => '1',
				'short_code'        => 'z1',
				'destination_url'   => 'https://example.com/z1',
				'status'            => 'trashed',
				'social_image_path' => 'zero1.png',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->willReturn( $rows );
		$this->repository->method( 'delete_links_permanently' )->willReturn( array() );

		$this->social_preview->expects( $this->never() )->method( 'delete_link_images' );
		$this->analytics_service->expects( $this->never() )->method( 'record_activity' );
		$this->webhooks_service->expects( $this->never() )->method( 'dispatch_link_event' );

		$link_deleted_fired = false;
		add_action(
			'link_deleted',
			function () use ( &$link_deleted_fired ): void {
				$link_deleted_fired = true;
			}
		);

		$bulk_finished_summary = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary ) use ( &$bulk_finished_summary ): void {
				$bulk_finished_summary = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'zero_1' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'zero_1' ), true );

		$this->assertSame( 0, $count );
		$this->assertFalse( $link_deleted_fired );
		$this->assertNotNull( $bulk_finished_summary );
		$this->assertSame( 'permanent', $bulk_finished_summary['mode'] );
		$this->assertSame( 1, $bulk_finished_summary['requested'] );
		$this->assertSame( 0, $bulk_finished_summary['affected'] );
	}

	public function test_bulk_restore_urls_partial_success_only_fires_side_effects_for_trashed_links(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'res_part_1',
				'user_id'         => '1',
				'title'           => 'Trashed Link',
				'short_code'      => 'rp1',
				'destination_url' => 'https://example.com/rp1',
				'status'          => 'trashed',
			),
			array(
				'id'              => 'res_part_2',
				'user_id'         => '1',
				'title'           => 'Active Link',
				'short_code'      => 'rp2',
				'destination_url' => 'https://example.com/rp2',
				'status'          => 'active',
			),
			array(
				'id'              => 'res_part_3',
				'user_id'         => '1',
				'title'           => 'Expired Link',
				'short_code'      => 'rp3',
				'destination_url' => 'https://example.com/rp3',
				'status'          => 'expired',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'res_part_1', 'res_part_2', 'res_part_3' ) )->willReturn( $rows );
		$this->repository->method( 'restore_url' )->with( 'res_part_1' )->willReturn( true );
		$this->repository->method( 'find_url_row' )->with( 'res_part_1' )->willReturn(
			array(
				'id'              => 'res_part_1',
				'user_id'         => '1',
				'title'           => 'Trashed Link',
				'short_code'      => 'rp1',
				'destination_url' => 'https://example.com/rp1',
				'status'          => 'active',
			)
		);

		$restored_hook_ids = array();
		add_action(
			'link_restored',
			function ( array $row ) use ( &$restored_hook_ids ): void {
				$restored_hook_ids[] = (string) ( $row['id'] ?? '' );
			}
		);

		$webhook_dispatched_ids = array();
		$this->webhooks_service->method( 'dispatch_link_event' )
			->willReturnCallback(
				function ( string $event, array $link_payload ) use ( &$webhook_dispatched_ids ): array {
					if ( 'link.restored' === $event ) {
						$webhook_dispatched_ids[] = (string) ( $link_payload['id'] ?? '' );
					}

					return array();
				}
			);

		$captured = null;
		add_action(
			'bulk_link_restore_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'res_part_1', 'res_part_2', 'res_part_3' ) ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array( 'res_part_1', 'res_part_2', 'res_part_3' ) );

		$this->assertSame( 1, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 3, $captured['requested'] );
		$this->assertSame( 1, $captured['affected'] );
		$this->assertSame( array( 'res_part_1' ), $restored_hook_ids );
		$this->assertSame( array( 'res_part_1' ), $webhook_dispatched_ids );
	}

	public function test_link_trashed_observer_exception_does_not_break_bulk_delete_or_completion_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'obs_trash_1',
				'user_id'         => '1',
				'short_code'      => 'ot1',
				'destination_url' => 'https://example.com/ot1',
				'status'          => 'active',
			),
			array(
				'id'              => 'obs_trash_2',
				'user_id'         => '1',
				'short_code'      => 'ot2',
				'destination_url' => 'https://example.com/ot2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'obs_trash_1', 'obs_trash_2' ) )->willReturn( $rows );
		$this->repository->method( 'trash_url' )->willReturn( true );

		add_action(
			'link_trashed',
			function ( array $row ): void {
				if ( 'obs_trash_1' === ( $row['id'] ?? '' ) ) {
					throw new \RuntimeException( 'Simulated crash in link_trashed observer' );
				}
			}
		);

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'obs_trash_1', 'obs_trash_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'obs_trash_1', 'obs_trash_2' ), false );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'trash', $captured['mode'] );
		$this->assertSame( 2, $captured['requested'] );
		$this->assertSame( 2, $captured['affected'] );
	}

	public function test_link_restored_observer_exception_does_not_break_bulk_restore_or_completion_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'obs_res_hook_1',
				'user_id'         => '1',
				'short_code'      => 'orh1',
				'destination_url' => 'https://example.com/orh1',
				'status'          => 'trashed',
			),
			array(
				'id'              => 'obs_res_hook_2',
				'user_id'         => '1',
				'short_code'      => 'orh2',
				'destination_url' => 'https://example.com/orh2',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'obs_res_hook_1', 'obs_res_hook_2' ) )->willReturn( $rows );
		$this->repository->method( 'restore_url' )->willReturn( true );
		$this->repository->method( 'find_url_row' )->willReturnCallback(
			function ( string $id ) use ( $rows ) {
				foreach ( $rows as $r ) {
					if ( $r['id'] === $id ) {
						$r['status'] = 'active';
						return $r;
					}
				}
				return null;
			}
		);

		add_action(
			'link_restored',
			function ( array $row ): void {
				if ( 'obs_res_hook_1' === ( $row['id'] ?? '' ) ) {
					throw new \RuntimeException( 'Simulated crash in link_restored observer' );
				}
			}
		);

		$captured = null;
		add_action(
			'bulk_link_restore_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'obs_res_hook_1', 'obs_res_hook_2' ) ) );
		$count   = $this->links_service->bulk_restore_urls( $request, array( 'obs_res_hook_1', 'obs_res_hook_2' ) );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 2, $captured['requested'] );
		$this->assertSame( 2, $captured['affected'] );
	}

	public function test_link_deleted_observer_exception_does_not_break_permanent_delete_or_completion_action(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'obs_del_1',
				'user_id'         => '1',
				'short_code'      => 'od1',
				'destination_url' => 'https://example.com/od1',
				'status'          => 'active',
			),
			array(
				'id'              => 'obs_del_2',
				'user_id'         => '1',
				'short_code'      => 'od2',
				'destination_url' => 'https://example.com/od2',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'obs_del_1', 'obs_del_2' ) )->willReturn( $rows );
		$this->repository->method( 'delete_links_permanently' )
			->with( array( 'obs_del_1', 'obs_del_2' ) )
			->willReturn( array( 'obs_del_1', 'obs_del_2' ) );

		add_action(
			'link_deleted',
			function ( array $row ): void {
				if ( 'obs_del_1' === ( $row['id'] ?? '' ) ) {
					throw new \RuntimeException( 'Simulated crash in link_deleted observer' );
				}
			}
		);

		$captured = null;
		add_action(
			'bulk_link_delete_finished',
			function ( array $summary ) use ( &$captured ): void {
				$captured = $summary;
			}
		);

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'obs_del_1', 'obs_del_2' ) ) );
		$count   = $this->links_service->bulk_delete_urls( $request, array( 'obs_del_1', 'obs_del_2' ), true );

		$this->assertSame( 2, $count );
		$this->assertNotNull( $captured );
		$this->assertSame( 'permanent', $captured['mode'] );
		$this->assertSame( 2, $captured['requested'] );
		$this->assertSame( 2, $captured['affected'] );
	}

	public function test_controller_bulk_delete_returns_exact_deleted_count_response(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'ctrl_del_1',
				'user_id'         => '1',
				'short_code'      => 'cd1',
				'destination_url' => 'https://example.com/cd1',
				'status'          => 'active',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'ctrl_del_1' ) )->willReturn( $rows );
		$this->repository->method( 'trash_url' )->willReturn( true );

		$controller = new LinksController(
			$this->links_service,
			$this->repository,
			$this->auth_service,
			$this->settings_api,
			$this->roles,
			$this->authorization,
			$this->social_preview,
			$this->health_checker,
			array()
		);

		$request  = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'ctrl_del_1' ) ) );
		$response = $controller->bulk_delete( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 1, $response['body']['data']['deletedCount'] );
	}

	public function test_controller_bulk_restore_returns_exact_restored_count_response(): void {
		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$rows = array(
			array(
				'id'              => 'ctrl_res_1',
				'user_id'         => '1',
				'short_code'      => 'cr1',
				'destination_url' => 'https://example.com/cr1',
				'status'          => 'trashed',
			),
		);
		$this->repository->method( 'get_links_by_ids' )->with( array( 'ctrl_res_1' ) )->willReturn( $rows );
		$this->repository->method( 'restore_url' )->willReturn( true );
		$this->repository->method( 'find_url_row' )->willReturn(
			array(
				'id'              => 'ctrl_res_1',
				'user_id'         => '1',
				'short_code'      => 'cr1',
				'destination_url' => 'https://example.com/cr1',
				'status'          => 'active',
			)
		);

		$controller = new LinksController(
			$this->links_service,
			$this->repository,
			$this->auth_service,
			$this->settings_api,
			$this->roles,
			$this->authorization,
			$this->social_preview,
			$this->health_checker,
			array()
		);

		$request  = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'ctrl_res_1' ) ) );
		$response = $controller->bulk_restore( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 1, $response['body']['data']['restoredCount'] );
	}

	public function test_repository_delete_links_permanently_locks_verifies_and_returns_deleted_ids(): void {
		$db = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$db->method( 'in_placeholders' )
			->with( array( 'repo_1', 'repo_2', 'repo_3' ), 'del_id' )
			->willReturn(
				array(
					'sql'    => ':del_id_0, :del_id_1, :del_id_2',
					'params' => array(
						':del_id_0' => 'repo_1',
						':del_id_1' => 'repo_2',
						':del_id_2' => 'repo_3',
					),
				)
			);
		$db->expects( $this->once() )->method( 'begin_transaction' );
		$db->expects( $this->once() )->method( 'commit' );
		$db->method( 'get_col' )
			->with(
				'SELECT id FROM urls WHERE id IN (:del_id_0, :del_id_1, :del_id_2) FOR UPDATE',
				array(
					':del_id_0' => 'repo_1',
					':del_id_1' => 'repo_2',
					':del_id_2' => 'repo_3',
				)
			)
			->willReturn( array( 'repo_1' ) );

		$db->expects( $this->once() )
			->method( 'update_where_in' )
			->with( 'audit_logs', array( 'link_id' => null ), 'link_id', array( 'repo_1' ) );

		$deleted_tables = array();
		$db->method( 'delete_where_in' )
			->willReturnCallback(
				function ( string $table, string $column, array $values ) use ( &$deleted_tables ): int {
					$deleted_tables[] = array(
						'table'  => $table,
						'column' => $column,
						'values' => $values,
					);

					return count( $values );
				}
			);

		$repository = new LinksRepository( $db, $this->createMock( \PeakURL\Api\LinksApi::class ) );

		$confirmed_ids = $repository->delete_links_permanently( array( 'repo_1', 'repo_2', 'repo_3' ) );
		$this->assertSame( array( 'repo_1' ), $confirmed_ids );

		$this->assertCount( 2, $deleted_tables );
		$this->assertSame( 'clicks', $deleted_tables[0]['table'] );
		$this->assertSame( 'url_id', $deleted_tables[0]['column'] );
		$this->assertSame( array( 'repo_1' ), $deleted_tables[0]['values'] );
		$this->assertSame( 'urls', $deleted_tables[1]['table'] );
		$this->assertSame( 'id', $deleted_tables[1]['column'] );
		$this->assertSame( array( 'repo_1' ), $deleted_tables[1]['values'] );
	}

	public function test_repository_delete_links_permanently_mismatch_throws_and_rolls_back(): void {
		$db = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$db->method( 'in_placeholders' )
			->with( array( 'repo_1', 'repo_2' ), 'del_id' )
			->willReturn(
				array(
					'sql'    => ':del_id_0, :del_id_1',
					'params' => array(
						':del_id_0' => 'repo_1',
						':del_id_1' => 'repo_2',
					),
				)
			);
		$db->expects( $this->once() )->method( 'begin_transaction' );
		$db->expects( $this->never() )->method( 'commit' );
		$db->expects( $this->once() )->method( 'roll_back' );
		$db->method( 'in_transaction' )->willReturn( true );

		$db->method( 'get_col' )
			->with(
				'SELECT id FROM urls WHERE id IN (:del_id_0, :del_id_1) FOR UPDATE',
				array(
					':del_id_0' => 'repo_1',
					':del_id_1' => 'repo_2',
				)
			)
			->willReturn( array( 'repo_1', 'repo_2' ) );

		$db->method( 'delete_where_in' )
			->willReturnCallback(
				function ( string $table, string $column, array $values ): int {
					if ( 'urls' === $table ) {
						// Simulate mismatch: only 1 row deleted instead of 2 expected
						return 1;
					}

					return count( $values );
				}
			);

		$repository = new LinksRepository( $db, $this->createMock( \PeakURL\Api\LinksApi::class ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Permanent deletion row count mismatch.' );

		$repository->delete_links_permanently( array( 'repo_1', 'repo_2' ) );
	}

	public function test_purge_stale_trashed_links_partial_success_only_fires_side_effects_for_confirmed_links(): void {
		$stale_rows = array(
			array(
				'id'                => 'stale_1',
				'title'             => 'Stale 1',
				'social_image_path' => 'stale_1.png',
			),
			array(
				'id'                => 'stale_2',
				'title'             => 'Stale 2',
				'social_image_path' => 'stale_2.png',
			),
			array(
				'id'                => 'stale_3',
				'title'             => 'Stale 3',
				'social_image_path' => 'stale_3.png',
			),
		);

		$this->repository->method( 'get_stale_trashed_links' )->willReturn( $stale_rows );
		$this->repository->method( 'delete_links_permanently' )
			->with( array( 'stale_1', 'stale_2', 'stale_3' ) )
			->willReturn( array( 'stale_1' ) );

		$this->social_preview->expects( $this->once() )
			->method( 'delete_link_images' )
			->with( array( 'stale_1.png' ) );

		$deleted_action_ids = array();
		add_action(
			'link_deleted',
			function ( array $deleted_row ) use ( &$deleted_action_ids ): void {
				$deleted_action_ids[] = (string) ( $deleted_row['id'] ?? '' );
			}
		);

		$purged_count = $this->links_service->purge_stale_trashed_links( 30, 100 );

		$this->assertSame( 1, $purged_count );
		$this->assertSame( array( 'stale_1' ), $deleted_action_ids );
	}

	public function test_purge_stale_trashed_links_zero_affected_skips_side_effects(): void {
		$stale_rows = array(
			array(
				'id'                => 'stale_zero_1',
				'title'             => 'Stale Zero',
				'social_image_path' => 'stale_zero.png',
			),
		);

		$this->repository->method( 'get_stale_trashed_links' )->willReturn( $stale_rows );
		$this->repository->method( 'delete_links_permanently' )
			->with( array( 'stale_zero_1' ) )
			->willReturn( array() );

		$this->social_preview->expects( $this->never() )->method( 'delete_link_images' );

		$link_deleted_fired = false;
		add_action(
			'link_deleted',
			function () use ( &$link_deleted_fired ): void {
				$link_deleted_fired = true;
			}
		);

		$purged_count = $this->links_service->purge_stale_trashed_links( 30, 100 );

		$this->assertSame( 0, $purged_count );
		$this->assertFalse( $link_deleted_fired );
	}

	public function test_purge_stale_trashed_links_observer_exception_does_not_abort_cleanup(): void {
		$stale_rows = array(
			array(
				'id'                => 'stale_obs_1',
				'title'             => 'Stale Obs 1',
				'social_image_path' => 'stale_obs_1.png',
			),
			array(
				'id'                => 'stale_obs_2',
				'title'             => 'Stale Obs 2',
				'social_image_path' => 'stale_obs_2.png',
			),
		);

		$this->repository->method( 'get_stale_trashed_links' )->willReturn( $stale_rows );
		$this->repository->method( 'delete_links_permanently' )
			->with( array( 'stale_obs_1', 'stale_obs_2' ) )
			->willReturn( array( 'stale_obs_1', 'stale_obs_2' ) );

		add_action(
			'link_deleted',
			function ( array $deleted_row ): void {
				if ( 'stale_obs_1' === ( $deleted_row['id'] ?? '' ) ) {
					throw new \RuntimeException( 'Simulated observer crash during scheduled purge' );
				}
			}
		);

		$purged_count = $this->links_service->purge_stale_trashed_links( 30, 100 );

		$this->assertSame( 2, $purged_count );
	}
}
