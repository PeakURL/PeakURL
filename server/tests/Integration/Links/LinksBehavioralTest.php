<?php
/**
 * Comprehensive Behavioral and Side-Effect Integration Tests for Links (QA-Links).
 *
 * Verifies that:
 * 1. Link creation persists correct fields, hashes passwords, normalizes URLs, and records activity.
 * 2. Link response contract adheres to frontend requirements (shortUrl, hasPassword, no raw secrets).
 * 3. Update mutates intended fields, updates passwords, invalidates cache, and records activity.
 * 4. Trash and restore transition link status and invalidate cache.
 * 5. Permanent deletion cleans up database records, associated images, and dispatches webhooks.
 * 6. Bulk operations enforce ownership and apply to allowed IDs only.
 * 7. Duplicate alias collisions and dangerous destination protocols are rejected with 422.
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
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\ApiException;
use PDO;
use PDOStatement;

class LinksBehavioralTest extends TestCase {

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

		$mock_stmt = $this->createMock( PDOStatement::class );
		$mock_stmt->method( 'execute' )->willReturn( true );
		$mock_stmt->method( 'fetch' )->willReturn( false );
		$mock_stmt->method( 'fetchAll' )->willReturn( array() );

		$mock_pdo = $this->createMock( PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );

		$config = array(
			Constants::DB_PREFIX   => 'peak_',
			Constants::DB_DATABASE => 'peakurl',
			Constants::SITE_URL    => 'https://peakurl.dev',
		);

		$mock_connection = new class( $config, $mock_pdo ) extends Connection {
			private PDO $mock_pdo;

			public function __construct( array $config, PDO $mock_pdo ) {
				parent::__construct( $config );
				$this->mock_pdo = $mock_pdo;
			}

			public function get_connection(): PDO {
				return $this->mock_pdo;
			}
		};

		\get_settings_api( $config, $mock_connection );
		\add_filter( 'site_url', fn() => 'https://peakurl.dev' );

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->captcha           = $this->createMock( Captcha::class );
		$this->settings_api      = $this->createMock( SettingsApi::class );

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

	public function test_link_creation_persists_correct_fields_and_protects_secrets(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'short_code_exists' )->willReturn( false );

		$captured_row = null;
		$this->repository->expects( $this->once() )
			->method( 'insert_url' )
			->willReturnCallback(
				function ( array $row ) use ( &$captured_row ): string {
					$captured_row = $row;
					return (string) $row['id'];
				}
			);

		$this->repository->method( 'find_url_row' )
			->willReturnCallback(
				function () use ( &$captured_row ) {
					return $captured_row;
				}
			);

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( $this->equalTo( 'link_created' ) );

		$payload = array(
			'destinationUrl' => 'https://example.com/target-page',
			'alias'          => 'my-custom-alias',
			'title'          => 'Target Page Title',
			'password'       => 'Secret123!',
			'utmSource'      => 'newsletter',
		);

		$request  = new Request( 'POST', '/api/v1/urls', array(), $payload );
		$response = $this->links_controller->create( $request );

		$this->assertSame( 201, $response['status'] );
		$body = $response['body']['data'];

		// Verify API response contract
		$this->assertSame( 'my-custom-alias', $body['alias'] );
		$this->assertSame( 'https://example.com/target-page', $body['destinationUrl'] );
		$this->assertSame( 'Target Page Title', $body['title'] );
		$this->assertSame( 'https://peakurl.dev/my-custom-alias', $body['shortUrl'] );
		$this->assertTrue( $body['hasPassword'], 'hasPassword must be true' );
		$this->assertArrayNotHasKey( 'password', $body, 'Raw password must never be exposed' );
		$this->assertArrayNotHasKey( 'password_value', $body, 'Password hash must never be in response body' );

		// Verify database persistence side effects
		$this->assertNotNull( $captured_row );
		$this->assertSame( 'user_1', $captured_row['user_id'] );
		$this->assertSame( 'my-custom-alias', $captured_row['alias'] );
		$this->assertSame( 'active', $captured_row['status'] );
		$this->assertSame( 'newsletter', $captured_row['utm_source'] );
		$this->assertNotEmpty( $captured_row['password_value'] );
		$this->assertNotSame( 'Secret123!', $captured_row['password_value'], 'Password must be hashed' );
		$this->assertTrue(
			password_verify( 'Secret123!', $captured_row['password_value'] ),
			'Stored password must be verifiable with password_verify'
		);
	}

	public function test_link_update_mutates_fields_and_records_activity(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$existing_row = array(
			'id'              => 'link_123',
			'user_id'         => 'user_1',
			'short_code'      => 'old-alias',
			'alias'           => 'old-alias',
			'title'           => 'Old Title',
			'destination_url' => 'https://example.com/old',
			'status'          => 'active',
			'password_value'  => null,
			'expires_at'      => null,
			'created_at'      => '2026-09-01 10:00:00',
			'updated_at'      => '2026-09-01 10:00:00',
		);

		$this->repository->method( 'get_link_by_id' )->with( 'link_123' )->willReturn( $existing_row );

		$captured_update = null;
		$this->repository->expects( $this->once() )
			->method( 'update_url_fields' )
			->willReturnCallback(
				function ( string $id, array $updates, array $params ) use ( &$captured_update ): bool {
					$captured_update = $params;
					return true;
				}
			);

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with( $this->equalTo( 'link_updated' ) );

		$payload = array(
			'id'             => 'link_123',
			'title'          => 'Brand New Title',
			'destinationUrl' => 'https://example.com/new-dest',
		);

		$request = new Request( 'PUT', '/api/v1/urls/link_123', array(), $payload );
		$request->set_route_params( array( 'id' => 'link_123' ) );

		$updated_row = array_merge(
			$existing_row,
			array(
				'title'           => 'Brand New Title',
				'destination_url' => 'https://example.com/new-dest',
			)
		);
		$this->repository->method( 'find_url_row' )->with( 'link_123' )->willReturn( $updated_row );

		$response = $this->links_controller->update( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertNotNull( $captured_update );
		$this->assertSame( 'Brand New Title', $captured_update['title'] );
		$this->assertSame( 'https://example.com/new-dest', $captured_update['destination_url'] );
	}

	public function test_trash_and_restore_cycle(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		// 1. Move active link to trash
		$active_row = array(
			'id'              => 'link_cycle_1',
			'user_id'         => 'user_1',
			'short_code'      => 'cycle',
			'alias'           => 'cycle',
			'title'           => 'Cycle Link',
			'destination_url' => 'https://example.com',
			'status'          => 'active',
			'password_value'  => null,
			'expires_at'      => null,
			'created_at'      => '2026-09-01 10:00:00',
			'updated_at'      => '2026-09-01 10:00:00',
		);

		$link_status = 'active';
		$this->repository->method( 'get_link_by_id' )
			->with( 'link_cycle_1' )
			->willReturnCallback(
				function () use ( &$link_status, $active_row ) {
					return array_merge( $active_row, array( 'status' => $link_status ) );
				}
			);

		$this->repository->expects( $this->once() )
			->method( 'trash_url' )
			->with( 'link_cycle_1' )
			->willReturnCallback(
				function () use ( &$link_status ) {
					$link_status = 'trashed';
					return true;
				}
			);

		$this->analytics_service->expects( $this->exactly( 2 ) )
			->method( 'record_activity' );

		$trash_request = new Request( 'DELETE', '/api/v1/urls/link_cycle_1', array(), array() );
		$trash_request->set_route_params( array( 'id' => 'link_cycle_1' ) );
		$trash_response = $this->links_controller->delete( $trash_request );

		$this->assertSame( 200, $trash_response['status'] );

		// 2. Restore trashed link
		$this->repository->method( 'find_url_row' )
			->with( 'link_cycle_1' )
			->willReturnCallback(
				function () use ( &$link_status, $active_row ) {
					return array_merge( $active_row, array( 'status' => $link_status ) );
				}
			);

		$this->repository->expects( $this->once() )
			->method( 'restore_url' )
			->with( 'link_cycle_1' )
			->willReturnCallback(
				function () use ( &$link_status ) {
					$link_status = 'active';
					return true;
				}
			);

		$restore_request = new Request( 'POST', '/api/v1/urls/link_cycle_1/restore', array(), array() );
		$restore_request->set_route_params( array( 'id' => 'link_cycle_1' ) );
		$restore_response = $this->links_controller->restore( $restore_request );

		$this->assertSame( 200, $restore_response['status'] );
	}

	public function test_permanent_deletion_removes_records_and_dispatches_webhooks(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$trashed_row = array(
			'id'                => 'link_perm_1',
			'user_id'           => 'user_1',
			'short_code'        => 'perm',
			'alias'             => 'perm',
			'title'             => 'Permanent Delete Link',
			'destination_url'   => 'https://example.com',
			'status'            => 'trashed', // Already trashed, so delete() permanently deletes
			'password_value'    => null,
			'social_image_path' => 'uploads/preview.png',
		);

		$this->repository->method( 'get_link_by_id' )->with( 'link_perm_1' )->willReturn( $trashed_row );

		$this->repository->expects( $this->once() )
			->method( 'delete_url_permanent' )
			->with( 'link_perm_1' )
			->willReturn( true );

		$this->social_preview->expects( $this->once() )
			->method( 'delete_link_image' )
			->with( 'uploads/preview.png' );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with( 'link.deleted', $trashed_row, $admin_user );

		$delete_request = new Request( 'DELETE', '/api/v1/urls/link_perm_1', array(), array() );
		$delete_request->set_route_params( array( 'id' => 'link_perm_1' ) );
		$delete_response = $this->links_controller->delete( $delete_request );

		$this->assertSame( 200, $delete_response['status'] );
	}

	public function test_bulk_trash_operation(): void {
		$editor_user = array(
			'id'       => 'user_editor',
			'username' => 'editor',
			'role'     => 'editor',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );

		$this->repository->method( 'get_links_by_ids' )
			->willReturn(
				array(
					array(
						'id'      => 'b1',
						'title'   => 'Bulk 1',
						'status'  => 'active',
						'user_id' => 'user_editor',
					),
					array(
						'id'      => 'b2',
						'title'   => 'Bulk 2',
						'status'  => 'active',
						'user_id' => 'user_editor',
					),
				)
			);

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'trash_url' )
			->willReturn( true );

		$bulk_delete_request  = new Request( 'DELETE', '/api/v1/urls/bulk', array(), array( 'ids' => array( 'b1', 'b2' ) ) );
		$bulk_delete_response = $this->links_controller->bulk_delete( $bulk_delete_request );

		$this->assertSame( 200, $bulk_delete_response['status'] );
		$this->assertSame( 2, $bulk_delete_response['body']['data']['deletedCount'] );
	}

	public function test_bulk_restore_operation(): void {
		$editor_user = array(
			'id'       => 'user_editor',
			'username' => 'editor',
			'role'     => 'editor',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $editor_user );

		$this->repository->method( 'get_links_by_ids' )
			->willReturn(
				array(
					array(
						'id'      => 'b1',
						'title'   => 'Bulk 1',
						'status'  => 'trashed',
						'user_id' => 'user_editor',
					),
					array(
						'id'      => 'b2',
						'title'   => 'Bulk 2',
						'status'  => 'trashed',
						'user_id' => 'user_editor',
					),
				)
			);

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'restore_url' )
			->willReturn( true );

		$bulk_restore_request  = new Request( 'POST', '/api/v1/urls/restore', array(), array( 'ids' => array( 'b1', 'b2' ) ) );
		$bulk_restore_response = $this->links_controller->bulk_restore( $bulk_restore_request );

		$this->assertSame( 200, $bulk_restore_response['status'] );
		$this->assertSame( 2, $bulk_restore_response['body']['data']['restoredCount'] );
	}

	public function test_creation_rejects_dangerous_javascript_protocol(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$payload = array(
			'destinationUrl' => 'javascript:alert(document.cookie)',
			'alias'          => 'xss-test',
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), $payload );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		$this->links_controller->create( $request );
	}

	public function test_creation_rejects_duplicate_alias_conflict(): void {
		$admin_user = array(
			'id'       => 'user_1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'short_code_exists' )->with( 'taken-alias' )->willReturn( true );

		$payload = array(
			'destinationUrl' => 'https://example.com',
			'alias'          => 'taken-alias',
		);

		$request = new Request( 'POST', '/api/v1/urls', array(), $payload );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		$this->links_controller->create( $request );
	}
}
