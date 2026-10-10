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
			function ( string $link_id ): void {
				$this->scheduled_health_checks[] = $link_id;
			},
			fn( ?array $row ): ?array => null !== $row ? array( 'status' => (string) ( $row['status'] ?? 'healthy' ) ) : null
		);
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
}
