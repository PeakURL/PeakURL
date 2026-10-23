<?php
/**
 * Unit tests for link lifecycle domain service.
 *
 * @package PeakURL\Tests\Unit\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\Lifecycle;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\SocialPreview;

class LifecycleTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private LinksValidator $validator;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&SocialPreview $social_preview;
	private Roles $roles;
	private Authorization $authorization;
	private array $scheduled_health_checks = array();
	private Lifecycle $lifecycle;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->repository              = $this->createMock( LinksRepository::class );
		$this->validator               = new LinksValidator();
		$this->auth_service            = $this->createMock( AuthService::class );
		$this->analytics_service       = $this->createMock( AnalyticsService::class );
		$this->webhooks_service        = $this->createMock( WebhooksService::class );
		$this->social_preview          = $this->createMock( SocialPreview::class );
		$this->roles                   = new Roles();
		$this->authorization           = new Authorization( $this->roles );
		$this->scheduled_health_checks = array();

		$links_api = $this->createMock( \PeakURL\Api\LinksApi::class );
		$this->repository->method( 'get_links_api' )->willReturn( $links_api );
		$this->repository->method( 'trash_url' )->willReturn( true );
		$this->repository->method( 'restore_url' )->willReturn( true );

		$this->lifecycle = new Lifecycle(
			$this->repository,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $row ): array => array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'shortCode'      => (string) ( $row['short_code'] ?? '' ),
				'alias'          => (string) ( $row['alias'] ?? '' ),
				'title'          => (string) ( $row['title'] ?? '' ),
				'destinationUrl' => (string) ( $row['destination_url'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? 'active' ),
			),
			fn( array $url, array $user, ?string $owner_id ): array => ( new Roles() )->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				),
			function ( string $link_id ): void {
				$this->scheduled_health_checks[] = $link_id;
			},
			fn( ?array $row ): ?array => null !== $row ? array( 'status' => (string) ( $row['status'] ?? 'healthy' ) ) : null
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	public function test_update_returns_null_when_link_not_found(): void {
		$this->auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 'user_1',
				'role' => 'admin',
			)
		);
		$this->repository->method( 'get_link_by_id' )->with( 'missing' )->willReturn( null );

		$request = new Request( 'PUT', '/missing', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'missing', array( 'title' => 'New Title' ) );

		$this->assertNull( $result );
	}

	public function test_update_denies_unauthorized_user_when_not_owner_or_admin(): void {
		$editor_user = array(
			'id'   => 'user_editor',
			'role' => 'editor',
		);
		$existing    = array(
			'id'      => 'link_1',
			'user_id' => 'user_other',
			'alias'   => 'test',
			'status'  => 'active',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_1' )->willReturn( $existing );
		$this->repository->method( 'get_user_role' )->with( 'user_other' )->willReturn( 'editor' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$request = new Request( 'PUT', '/link_1', array(), array() );
		$this->lifecycle->update_url( $request, 'link_1', array( 'title' => 'Updated' ) );
	}

	public function test_update_mutates_fields_and_dispatches_events(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_up_1',
			'user_id'         => 'user_admin',
			'alias'           => 'oldalias',
			'title'           => 'Old Title',
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
		);
		$updated    = array_merge( $existing, array( 'title' => 'New Title' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_up_1' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_up_1' )->willReturn( $updated );

		$this->repository->expects( $this->once() )
			->method( 'update_url_fields' )
			->with( 'link_up_1', $this->isType( 'array' ), $this->isType( 'array' ) );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_updated', $this->isType( 'string' ), 'user_admin', 'link_up_1' );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with( 'link.updated', $this->isType( 'array' ), $admin_user, $this->isType( 'array' ) );

		$request = new Request( 'PUT', '/link_up_1', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_up_1', array( 'title' => 'New Title' ) );

		$this->assertNotNull( $result );
		$this->assertSame( 'New Title', $result['title'] );
	}

	public function test_update_detects_destination_change_resets_health_and_schedules_check(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_up_2',
			'user_id'         => 'user_admin',
			'alias'           => 'alias2',
			'title'           => 'Title 2',
			'destination_url' => 'https://example.com/old',
			'status'          => 'active',
		);
		$updated    = array_merge( $existing, array( 'destination_url' => 'https://example.com/new' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_up_2' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_up_2' )->willReturn( $updated );

		$this->repository->expects( $this->once() )
			->method( 'delete_link_health' )
			->with( 'link_up_2' );

		$request = new Request( 'PUT', '/link_up_2', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_up_2', array( 'destinationUrl' => 'https://example.com/new' ) );

		$this->assertNotNull( $result );
		$this->assertNull( $result['health'] );
		$this->assertContains( 'link_up_2', $this->scheduled_health_checks );
	}

	public function test_delete_returns_false_when_link_not_found(): void {
		$this->auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 'user_1',
				'role' => 'admin',
			)
		);
		$this->repository->method( 'get_link_by_id' )->with( 'missing' )->willReturn( null );

		$request = new Request( 'DELETE', '/missing', array(), array() );
		$this->assertFalse( $this->lifecycle->delete_url( $request, 'missing' ) );
	}

	public function test_delete_trashes_active_link_and_records_activity(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_del_1',
			'user_id'         => 'user_admin',
			'alias'           => 'del1',
			'title'           => 'Delete Me',
			'status'          => 'active',
			'destination_url' => 'https://example.com',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_del_1' )->willReturn( $row );
		$this->repository->expects( $this->once() )->method( 'trash_url' )->with( 'link_del_1' )->willReturn( true );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_trashed', $this->stringContains( 'Delete Me' ), 'user_admin', 'link_del_1' );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with( 'link.deleted', $this->isType( 'array' ), $admin_user );

		$request = new Request( 'DELETE', '/link_del_1', array(), array() );
		$this->assertTrue( $this->lifecycle->delete_url( $request, 'link_del_1', false ) );
	}

	public function test_delete_permanently_deletes_trashed_link_and_removes_social_image(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'                => 'link_del_2',
			'user_id'           => 'user_admin',
			'alias'             => 'del2',
			'title'             => 'Trashed Link',
			'status'            => 'trashed',
			'social_image_path' => 'uploads/image.png',
			'destination_url'   => 'https://example.com',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_del_2' )->willReturn( $row );
		$this->repository->expects( $this->once() )->method( 'delete_url_permanently' )->with( 'link_del_2' )->willReturn( true );
		$this->social_preview->expects( $this->once() )->method( 'delete_link_image' )->with( 'uploads/image.png' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_deleted', $this->stringContains( 'Trashed Link' ), 'user_admin', null );

		$request = new Request( 'DELETE', '/link_del_2', array(), array() );
		$this->assertTrue( $this->lifecycle->delete_url( $request, 'link_del_2' ) );
	}

	public function test_restore_throws_when_link_not_found(): void {
		$this->auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 'user_1',
				'role' => 'admin',
			)
		);
		$this->repository->method( 'get_link_by_id' )->with( 'missing' )->willReturn( null );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );

		$request = new Request( 'POST', '/missing/restore', array(), array() );
		$this->lifecycle->restore_url( $request, 'missing' );
	}

	public function test_restore_throws_when_link_already_active(): void {
		$this->auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 'user_1',
				'role' => 'admin',
			)
		);
		$this->repository->method( 'get_link_by_id' )->with( 'link_active' )->willReturn( array( 'status' => 'active' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );

		$request = new Request( 'POST', '/link_active/restore', array(), array() );
		$this->lifecycle->restore_url( $request, 'link_active' );
	}

	public function test_restore_transitions_trashed_link_to_active(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_res_1',
			'user_id'         => 'user_admin',
			'alias'           => 'res1',
			'title'           => 'Restore Me',
			'status'          => 'trashed',
			'destination_url' => 'https://example.com',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_res_1' )->willReturn( $row );
		$this->repository->expects( $this->once() )->method( 'restore_url' )->with( 'link_res_1' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_restored', $this->stringContains( 'Restore Me' ), 'user_admin', 'link_res_1' );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with( 'link.restored', $this->isType( 'array' ), $admin_user );

		$request = new Request( 'POST', '/link_res_1/restore', array(), array() );
		$result  = $this->lifecycle->restore_url( $request, 'link_res_1' );

		$this->assertSame( 'active', $result['status'] );
	}

	public function test_bulk_delete_trashes_active_links(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'      => 'b1',
				'title'   => 'Link B1',
				'status'  => 'active',
				'user_id' => 'user_admin',
			),
			array(
				'id'      => 'b2',
				'title'   => 'Link B2',
				'status'  => 'active',
				'user_id' => 'user_admin',
			),
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_links_by_ids' )->with( array( 'b1', 'b2' ) )->willReturn( $rows );

		$this->repository->expects( $this->exactly( 2 ) )->method( 'trash_url' );
		$this->analytics_service->expects( $this->exactly( 2 ) )->method( 'record_activity' );
		$this->webhooks_service->expects( $this->exactly( 2 ) )->method( 'dispatch_link_event' );

		$request = new Request( 'POST', '/bulk-delete', array(), array( 'ids' => array( 'b1', 'b2' ) ) );
		$count   = $this->lifecycle->bulk_delete_urls( $request, array( 'b1', 'b2' ), false );

		$this->assertSame( 2, $count );
	}

	public function test_bulk_restore_restores_trashed_links(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'      => 'r1',
				'title'   => 'Link R1',
				'status'  => 'trashed',
				'user_id' => 'user_admin',
			),
			array(
				'id'      => 'r2',
				'title'   => 'Link R2',
				'status'  => 'trashed',
				'user_id' => 'user_admin',
			),
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_links_by_ids' )->with( array( 'r1', 'r2' ) )->willReturn( $rows );

		$this->repository->expects( $this->exactly( 2 ) )->method( 'restore_url' );
		$this->analytics_service->expects( $this->exactly( 2 ) )->method( 'record_activity' );
		$this->webhooks_service->expects( $this->exactly( 2 ) )->method( 'dispatch_link_event' );

		$request = new Request( 'POST', '/bulk-restore', array(), array( 'ids' => array( 'r1', 'r2' ) ) );
		$count   = $this->lifecycle->bulk_restore_urls( $request, array( 'r1', 'r2' ) );

		$this->assertSame( 2, $count );
	}

	public function test_clear_urls_rejects_invalid_mode(): void {
		$this->auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 'user_1',
				'role' => 'admin',
			)
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 400 );

		$request = new Request( 'POST', '/clear', array(), array( 'mode' => 'invalid_mode' ) );
		$this->lifecycle->clear_urls( $request, 'invalid_mode' );
	}

	public function test_empty_trash_permanently_deletes_all_trashed_links(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'                => 't1',
				'title'             => 'Trash 1',
				'status'            => 'trashed',
				'social_image_path' => null,
			),
			array(
				'id'                => 't2',
				'title'             => 'Trash 2',
				'status'            => 'trashed',
				'social_image_path' => 'img2.png',
			),
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_trashed_links' )->with( $admin_user )->willReturn( $rows );
		$this->repository->expects( $this->once() )->method( 'delete_links_permanently' )->with( array( 't1', 't2' ) )->willReturn( 2 );
		$this->social_preview->expects( $this->once() )->method( 'delete_link_images' )->with( array( null, 'img2.png' ) );

		$request = new Request( 'POST', '/trash/empty', array(), array() );
		$count   = $this->lifecycle->empty_trash( $request );

		$this->assertSame( 2, $count );
	}

	public function test_clear_urls_permanently_deletes_accessible_links(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'                => 'c1',
				'title'             => 'Link C1',
				'social_image_path' => null,
			),
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_accessible_links' )->willReturn( $rows );
		$this->repository->expects( $this->once() )->method( 'delete_links_permanently' )->with( array( 'c1' ) )->willReturn( 1 );

		$request = new Request( 'POST', '/clear', array(), array( 'mode' => 'permanent' ) );
		$count   = $this->lifecycle->clear_urls( $request, 'permanent' );

		$this->assertSame( 1, $count );
	}
	public function test_delete_denies_trashing_by_non_owner_editor(): void {
		$editor_user = array(
			'id'   => 'user_editor',
			'role' => 'editor',
		);
		$row         = array(
			'id'      => 'link_del_auth',
			'user_id' => 'user_other',
			'alias'   => 'no-trash',
			'status'  => 'active',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_del_auth' )->willReturn( $row );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$request = new Request( 'DELETE', '/link_del_auth', array(), array() );
		$this->lifecycle->delete_url( $request, 'link_del_auth', false );
	}

	public function test_restore_denies_non_owner_editor(): void {
		$editor_user = array(
			'id'   => 'user_editor',
			'role' => 'editor',
		);
		$row         = array(
			'id'      => 'link_res_auth',
			'user_id' => 'user_other',
			'alias'   => 'no-restore',
			'status'  => 'trashed',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_res_auth' )->willReturn( $row );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		$request = new Request( 'POST', '/link_res_auth/restore', array(), array() );
		$this->lifecycle->restore_url( $request, 'link_res_auth' );
	}

	public function test_clear_urls_trashes_active_links(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'     => 'ca1',
				'title'  => 'Link CA1',
				'status' => 'active',
			),
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_accessible_links' )->willReturn( $rows );
		$this->repository->expects( $this->once() )->method( 'trash_url' )->with( 'ca1' );

		$request = new Request( 'POST', '/clear', array(), array( 'mode' => 'trash' ) );
		$count   = $this->lifecycle->clear_urls( $request, 'trash' );

		$this->assertSame( 1, $count );
	}

	public function test_lifecycle_fails_closed_when_analytics_filter_is_omitted(): void {
		$this->expectException( \TypeError::class );
		/** @phpstan-ignore-next-line */
		new Lifecycle(
			$this->repository,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $row ): array => array()
		);
	}

	public function test_lifecycle_fails_closed_when_analytics_filter_is_null(): void {
		$this->expectException( \TypeError::class );
		/** @phpstan-ignore-next-line */
		new Lifecycle(
			$this->repository,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $row ): array => array(),
			null
		);
	}

	public function test_update_applies_injected_analytics_filter(): void {
		$filter_invoked = false;
		$custom_filter  = function ( array $url, array $user, ?string $owner_id ) use ( &$filter_invoked ): array {
			$filter_invoked      = true;
			$url['clicks']       = null;
			$url['uniqueClicks'] = null;
			return $url;
		};

		$lifecycle = new Lifecycle(
			$this->repository,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $row ): array => array(
				'id'           => 'link_filtered',
				'clicks'       => 10,
				'uniqueClicks' => 5,
				'title'        => (string) ( $row['title'] ?? '' ),
			),
			$custom_filter
		);

		$user = array(
			'id'   => 'user_owner',
			'role' => 'editor',
		);
		$row  = array(
			'id'      => 'link_filtered',
			'user_id' => 'user_owner',
			'title'   => 'Old Title',
			'status'  => 'active',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_filtered' )->willReturn( $row );
		$this->repository->method( 'find_url_row' )->with( 'link_filtered' )->willReturn( $row );
		$this->repository->method( 'update_url_fields' );

		$request = new Request( 'PUT', '/link_filtered', array(), array() );
		$result  = $lifecycle->update_url( $request, 'link_filtered', array( 'title' => 'New Title' ) );

		$this->assertTrue( $filter_invoked, 'Injected analytics filter must be invoked' );
		$this->assertNotNull( $result );
		$this->assertNull( $result['clicks'] );
		$this->assertNull( $result['uniqueClicks'] );
	}

	public function test_update_records_activity_with_explicit_alias(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_act_1',
			'user_id'         => 'user_admin',
			'alias'           => 'old-alias',
			'short_code'      => 'old-alias',
			'title'           => 'Existing Link',
			'destination_url' => 'https://example.com/original',
			'status'          => 'active',
		);
		$updated    = array_merge(
			$existing,
			array(
				'alias'      => 'new-alias',
				'short_code' => 'new-alias',
			)
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_act_1' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_act_1' )->willReturn( $updated );
		$this->repository->method( 'short_code_exists' )->willReturn( false );
		$this->repository->method( 'update_url_fields' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_updated', 'Updated link new-alias.', 'user_admin', 'link_act_1' );

		$request = new Request( 'PUT', '/link_act_1', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_act_1', array( 'alias' => 'new-alias' ) );

		$this->assertNotNull( $result );
	}

	public function test_update_records_activity_falling_back_to_existing_alias(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_act_2',
			'user_id'         => 'user_admin',
			'alias'           => 'existing-slug',
			'short_code'      => 'existing-slug',
			'title'           => 'Title 1',
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
		);
		$updated    = array_merge( $existing, array( 'title' => 'Title 2' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_act_2' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_act_2' )->willReturn( $updated );
		$this->repository->method( 'update_url_fields' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_updated', 'Updated link existing-slug.', 'user_admin', 'link_act_2' );

		$request = new Request( 'PUT', '/link_act_2', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_act_2', array( 'title' => 'Title 2' ) );

		$this->assertNotNull( $result );
	}

	public function test_update_records_activity_falling_back_to_existing_short_code(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_act_3',
			'user_id'         => 'user_admin',
			'alias'           => '',
			'short_code'      => 'sc789',
			'title'           => 'Title A',
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
		);
		$updated    = array_merge( $existing, array( 'title' => 'Title B' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_act_3' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_act_3' )->willReturn( $updated );
		$this->repository->method( 'update_url_fields' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_updated', 'Updated link sc789.', 'user_admin', 'link_act_3' );

		$request = new Request( 'PUT', '/link_act_3', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_act_3', array( 'title' => 'Title B' ) );

		$this->assertNotNull( $result );
	}

	public function test_update_records_activity_falling_back_to_id_never_dangling_period(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_act_4',
			'user_id'         => 'user_admin',
			'alias'           => '',
			'short_code'      => '',
			'title'           => 'Title X',
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
		);
		$updated    = array_merge( $existing, array( 'title' => 'Title Y' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_act_4' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_act_4' )->willReturn( $updated );
		$this->repository->method( 'update_url_fields' );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_updated', 'Updated link link_act_4.', 'user_admin', 'link_act_4' );

		$request = new Request( 'PUT', '/link_act_4', array(), array() );
		$result  = $this->lifecycle->update_url( $request, 'link_act_4', array( 'title' => 'Title Y' ) );

		$this->assertNotNull( $result );
	}

	public function test_update_fires_link_status_changed_hook_when_status_transitions(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_sc_1',
			'user_id'         => 'user_admin',
			'short_code'      => 'sc1',
			'destination_url' => 'https://example.com/dest',
			'title'           => 'Title 1',
			'status'          => 'active',
			'alias'           => 'sc1',
		);
		$updated    = array_merge( $existing, array( 'status' => 'inactive' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_sc_1' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_sc_1' )->willReturn( $updated );

		$captured = null;
		\add_action(
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

		$request = new Request( 'PUT', '/link_sc_1', array(), array() );
		$this->lifecycle->update_url( $request, 'link_sc_1', array( 'status' => 'inactive' ) );

		$this->assertNotNull( $captured );
		$this->assertSame( 'active', $captured['old_status'] );
		$this->assertSame( 'inactive', $captured['new_status'] );
		$this->assertSame( 'link_sc_1', $captured['link']['id'] );
		$this->assertSame( 'user_admin', $captured['user']['id'] );
		$this->assertSame( $request, $captured['request'] );

		\remove_all_actions( 'link_status_changed' );
	}

	public function test_trash_link_fires_link_status_changed_hook(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_trash_sc',
			'user_id'         => 'user_admin',
			'short_code'      => 'trashme',
			'destination_url' => 'https://example.com/trash',
			'title'           => 'Trash Me',
			'status'          => 'active',
			'alias'           => 'trashme',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_trash_sc' )->willReturn( $row );
		$this->repository->method( 'trash_url' )->willReturn( true );

		$captured = null;
		\add_action(
			'link_status_changed',
			function ( array $link, string $old_status, string $new_status, ?array $user, ?Request $request ) use ( &$captured ): void {
				$captured = array(
					'link'       => $link,
					'old_status' => $old_status,
					'new_status' => $new_status,
					'user'       => $user,
				);
			},
			10,
			5
		);

		$request = new Request( 'DELETE', '/link_trash_sc', array(), array() );
		$this->lifecycle->delete_url( $request, 'link_trash_sc', false );

		$this->assertNotNull( $captured );
		$this->assertSame( 'active', $captured['old_status'] );
		$this->assertSame( 'trashed', $captured['new_status'] );
		$this->assertSame( 'trashed', $captured['link']['status'] );

		\remove_all_actions( 'link_status_changed' );
	}

	public function test_restore_link_fires_link_status_changed_hook(): void {
		$admin_user   = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row          = array(
			'id'              => 'link_res_sc',
			'user_id'         => 'user_admin',
			'short_code'      => 'restoreme',
			'destination_url' => 'https://example.com/restore',
			'title'           => 'Restore Me',
			'status'          => 'trashed',
			'alias'           => 'restoreme',
		);
		$restored_row = array_merge( $row, array( 'status' => 'active' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_res_sc' )->willReturn( $row );
		$this->repository->method( 'find_url_row' )->with( 'link_res_sc' )->willReturn( $restored_row );
		$this->repository->method( 'restore_url' )->willReturn( true );

		$captured = null;
		\add_action(
			'link_status_changed',
			function ( array $link, string $old_status, string $new_status, ?array $user, ?Request $request ) use ( &$captured ): void {
				$captured = array(
					'link'       => $link,
					'old_status' => $old_status,
					'new_status' => $new_status,
					'user'       => $user,
				);
			},
			10,
			5
		);

		$request = new Request( 'POST', '/link_res_sc/restore', array(), array() );
		$this->lifecycle->restore_url( $request, 'link_res_sc' );

		$this->assertNotNull( $captured );
		$this->assertSame( 'trashed', $captured['old_status'] );
		$this->assertSame( 'active', $captured['new_status'] );
		$this->assertSame( 'active', $captured['link']['status'] );

		\remove_all_actions( 'link_status_changed' );
	}

	public function test_unchanged_status_does_not_fire_link_status_changed_hook(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_unchanged',
			'user_id'         => 'user_admin',
			'short_code'      => 'same1',
			'destination_url' => 'https://example.com/dest',
			'title'           => 'Title Same',
			'status'          => 'active',
			'alias'           => 'same1',
		);
		$updated    = array_merge( $existing, array( 'title' => 'Title Renamed' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_unchanged' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_unchanged' )->willReturn( $updated );

		$fired = false;
		\add_action(
			'link_status_changed',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$request = new Request( 'PUT', '/link_unchanged', array(), array() );
		$this->lifecycle->update_url( $request, 'link_unchanged', array( 'title' => 'Title Renamed' ) );

		$this->assertFalse( $fired, 'link_status_changed must not fire when status is unchanged' );
		\remove_all_actions( 'link_status_changed' );
	}

	public function test_computed_expiration_does_not_fire_link_status_changed_hook_when_persisted_status_unchanged(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$existing   = array(
			'id'              => 'link_past_exp',
			'user_id'         => 'user_admin',
			'short_code'      => 'pastexp',
			'destination_url' => 'https://example.com/target',
			'title'           => 'Past Expire Link',
			'status'          => 'active',
			'expires_at'      => '2020-01-01 00:00:00',
			'alias'           => 'pastexp',
		);
		$updated    = array_merge( $existing, array( 'title' => 'Renamed Past Expire Link' ) );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_past_exp' )->willReturn( $existing );
		$this->repository->method( 'find_url_row' )->with( 'link_past_exp' )->willReturn( $updated );

		$lifecycle = new Lifecycle(
			$this->repository,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			function ( ?array $r ): array {
				if ( ! $r ) {
					return array();
				}
				$out = $r;
				if ( ! empty( $r['expires_at'] ) && strtotime( $r['expires_at'] ) < time() ) {
					$out['status'] = 'expired';
				}
				return $out;
			},
			fn( array $url ): array => $url
		);

		$fired = false;
		\add_action(
			'link_status_changed',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$request = new Request( 'PUT', '/link_past_exp', array(), array() );
		$result  = $lifecycle->update_url( $request, 'link_past_exp', array( 'title' => 'Renamed Past Expire Link' ) );

		$this->assertNotNull( $result );
		$this->assertSame( 'expired', $result['status'], 'Presentation status can still be computed as expired' );
		$this->assertFalse( $fired, 'link_status_changed must NOT fire when stored status is unchanged active despite computed expiration' );

		\remove_all_actions( 'link_status_changed' );
	}

	public function test_failed_trash_persist_does_not_fire_link_status_changed_hook(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_fail_trash',
			'user_id'         => 'user_admin',
			'short_code'      => 'failtrash',
			'destination_url' => 'https://example.com/trash',
			'title'           => 'Fail Trash',
			'status'          => 'active',
			'alias'           => 'failtrash',
		);

		$repo = $this->createMock( LinksRepository::class );
		$repo->method( 'get_link_by_id' )->with( 'link_fail_trash' )->willReturn( $row );
		$repo->method( 'trash_url' )->willReturn( false );

		$lifecycle = new Lifecycle(
			$repo,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $r ): array => array(
				'id'     => (string) ( $r['id'] ?? '' ),
				'status' => (string) ( $r['status'] ?? 'active' ),
			),
			fn( array $url ): array => $url
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$fired = false;
		\add_action(
			'link_status_changed',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$request = new Request( 'DELETE', '/link_fail_trash', array(), array() );
		$result  = $lifecycle->delete_url( $request, 'link_fail_trash', false );

		$this->assertFalse( $result, 'delete_url must return false when trash persistence fails' );
		$this->assertFalse( $fired, 'link_status_changed must not fire when trash persistence affects zero rows' );
		\remove_all_actions( 'link_status_changed' );
	}

	public function test_failed_bulk_trash_persist_counts_only_successful_rows(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'     => 'b_ok',
				'title'  => 'OK Link',
				'status' => 'active',
			),
			array(
				'id'     => 'b_fail',
				'title'  => 'Fail Link',
				'status' => 'active',
			),
		);

		$repo = $this->createMock( LinksRepository::class );
		$repo->method( 'get_links_by_ids' )->willReturn( $rows );
		$repo->method( 'trash_url' )->willReturnCallback(
			fn( string $id ): bool => 'b_ok' === $id
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$lifecycle = $this->create_lifecycle_with_repository( $repo );

		$request = new Request( 'POST', '/api/v1/links/bulk-delete', array(), array( 'ids' => array( 'b_ok', 'b_fail' ) ) );
		$count   = $lifecycle->bulk_delete_urls( $request, array( 'b_ok', 'b_fail' ) );

		$this->assertSame( 1, $count, 'bulk_delete_urls must count only rows successfully persisted to trash' );
	}

	public function test_failed_restore_persist_throws_api_exception(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_fail_res',
			'user_id'         => 'user_admin',
			'short_code'      => 'failres',
			'destination_url' => 'https://example.com/res',
			'title'           => 'Fail Restore',
			'status'          => 'trashed',
		);

		$repo = $this->createMock( LinksRepository::class );
		$repo->method( 'get_link_by_id' )->with( 'link_fail_res' )->willReturn( $row );
		$repo->method( 'restore_url' )->willReturn( false );

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$lifecycle = $this->create_lifecycle_with_repository( $repo );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 500 );

		$request = new Request( 'POST', '/link_fail_res/restore', array(), array() );
		$lifecycle->restore_url( $request, 'link_fail_res' );
	}

	public function test_failed_bulk_restore_persist_counts_only_successful_rows(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$rows       = array(
			array(
				'id'     => 'r_ok',
				'title'  => 'OK Link',
				'status' => 'trashed',
			),
			array(
				'id'     => 'r_fail',
				'title'  => 'Fail Link',
				'status' => 'trashed',
			),
		);

		$repo = $this->createMock( LinksRepository::class );
		$repo->method( 'get_links_by_ids' )->willReturn( $rows );
		$repo->method( 'restore_url' )->willReturnCallback(
			fn( string $id ): bool => 'r_ok' === $id
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$lifecycle = $this->create_lifecycle_with_repository( $repo );

		$request = new Request( 'POST', '/api/v1/links/bulk-restore', array(), array( 'ids' => array( 'r_ok', 'r_fail' ) ) );
		$count   = $lifecycle->bulk_restore_urls( $request, array( 'r_ok', 'r_fail' ) );

		$this->assertSame( 1, $count, 'bulk_restore_urls must count only rows successfully persisted as restored' );
	}

	public function test_link_status_changed_observer_exception_does_not_break_trash_operation(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$row        = array(
			'id'              => 'link_obs_trash',
			'user_id'         => 'user_admin',
			'short_code'      => 'obstrash',
			'destination_url' => 'https://example.com/obs',
			'title'           => 'Obs Trash',
			'status'          => 'active',
			'alias'           => 'obstrash',
		);

		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_link_by_id' )->with( 'link_obs_trash' )->willReturn( $row );
		$this->repository->method( 'trash_url' )->willReturn( true );

		\add_action(
			'link_status_changed',
			function (): void {
				throw new \RuntimeException( 'Crashing plugin observer' );
			}
		);

		$request = new Request( 'DELETE', '/link_obs_trash', array(), array() );
		$result  = $this->lifecycle->delete_url( $request, 'link_obs_trash', false );

		$this->assertTrue( $result, 'delete_url must succeed despite observer exception' );
		\remove_all_actions( 'link_status_changed' );
	}

	private function create_lifecycle_with_repository( LinksRepository $repo ): Lifecycle {
		$links_api = $this->createMock( \PeakURL\Api\LinksApi::class );
		$repo->method( 'get_links_api' )->willReturn( $links_api );

		return new Lifecycle(
			$repo,
			$this->validator,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			$this->social_preview,
			$this->roles,
			$this->authorization,
			fn( ?array $row ): array => array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'shortCode'      => (string) ( $row['short_code'] ?? '' ),
				'alias'          => (string) ( $row['alias'] ?? '' ),
				'title'          => (string) ( $row['title'] ?? '' ),
				'destinationUrl' => (string) ( $row['destination_url'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? 'active' ),
			),
			fn( array $url, array $user, ?string $owner_id ): array => ( new Roles() )->can_view_link_analytics( $user, $owner_id )
				? $url
				: array_merge(
					$url,
					array(
						'clicks'       => null,
						'uniqueClicks' => null,
					)
				),
			function ( string $link_id ): void {
				$this->scheduled_health_checks[] = $link_id;
			},
			fn( ?array $row ): ?array => null !== $row ? array( 'status' => (string) ( $row['status'] ?? 'healthy' ) ) : null
		);
	}
}
