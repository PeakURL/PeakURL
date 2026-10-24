<?php
/**
 * Unit tests for user lifecycle hooks (user_updated, user_deleted).
 *
 * Verifies that:
 * - user_updated fires independently after confirmed persistence across self-service and admin updates
 * - notifications run independently despite failures in password emails, activity logs, or webhooks
 * - updates with zero affected rows or concurrent deletion do not emit user_updated
 * - locked user records provide the accurate previous state for role modifications
 * - failed transaction attempts do not leak stale data into successful retries
 * - user_deleted fires strictly after transaction commit with accurate transferred links count
 * - payloads and context strictly exclude sensitive credentials and internal hashes
 * - observer exceptions are isolated and do not disrupt persistence or responses
 *
 * @package PeakURL\Tests\Unit\Users
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Users;

use ArrayObject;
use PDOException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Api\LinksApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\SocialPreview;
use RuntimeException;

class UserLifecycleHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private MockObject&AuthService $auth_service;
	private MockObject&AnalyticsService $analytics_service;
	private UsersValidator $validator;
	private Roles $roles;
	private Authorization $authorization;
	private MockObject&SocialPreview $social_preview;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&LinksApi $links_api;
	private UsersService $service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db                = $this->createMock( PeakURL_DB::class );
		$this->users_api         = $this->createMock( UsersApi::class );
		$this->auth_service      = $this->createMock( AuthService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->validator         = new UsersValidator();
		$this->roles             = new Roles();
		$this->authorization     = new Authorization( $this->roles );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->webhooks_service->method( 'dispatch_user_event' )->willReturn( array() );
		$this->links_api = $this->createMock( LinksApi::class );

		$this->auth_service->method( 'format_user' )->willReturnCallback(
			function ( ?array $row ): array {
				if ( null === $row ) {
					return array();
				}
				return array(
					'id'          => (string) ( $row['id'] ?? '' ),
					'username'    => (string) ( $row['username'] ?? '' ),
					'email'       => (string) ( $row['email'] ?? '' ),
					'displayName' => (string) ( $row['display_name'] ?? '' ),
					'role'        => (string) ( $row['role'] ?? 'editor' ),
				);
			}
		);

		$this->service = new UsersService(
			$this->db,
			$this->users_api,
			$this->auth_service,
			$this->analytics_service,
			$this->validator,
			$this->roles,
			$this->authorization,
			$this->social_preview,
			$this->webhooks_service,
			$this->links_api
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	private function sample_user_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => '101',
				'username'      => 'john_doe',
				'email'         => 'john@example.com',
				'first_name'    => 'John',
				'last_name'     => 'Doe',
				'display_name'  => 'John Doe',
				'password_hash' => '$2y$10$encrypted_secret_hash_value_here',
				'role'          => 'editor',
				'created_at'    => '2026-01-01 00:00:00',
				'updated_at'    => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	private function sample_admin_user( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => '1',
				'username' => 'admin_super',
				'email'    => 'admin@example.com',
				'role'     => 'admin',
			),
			$overrides
		);
	}

	private function create_request( string $method, string $path, array $body_params = array(), array $query_params = array() ): Request {
		return new Request( $method, $path, $query_params, $body_params );
	}

	/**
	 * Register an action observer that records invocation arguments and counts.
	 *
	 * @param string $hook_name     Action name.
	 * @param int    $accepted_args Number of accepted arguments.
	 * @return ArrayObject Recorded calls.
	 */
	private function record_action( string $hook_name, int $accepted_args = 3 ): ArrayObject {
		$calls = new ArrayObject();
		\add_action(
			$hook_name,
			function ( ...$args ) use ( $calls ): void {
				$calls->append( $args );
			},
			10,
			$accepted_args
		);
		return $calls;
	}

	// =========================================================================
	// Self-Service Profile Update Tests
	// =========================================================================

	public function test_self_service_update_fires_user_updated_with_bounded_details_and_actor(): void {
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row(
			array(
				'display_name' => 'Johnathan Doe',
				'updated_at'   => '2026-10-24 12:00:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/me' );
		$this->auth_service->method( 'get_current_user' )
			->with( $request )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
					'email'    => 'john@example.com',
					'role'     => 'editor',
				)
			);

		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_row, $updated_row );

		$this->db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback( fn( array $d ) => 'Johnathan Doe' === $d['display_name'] ),
				array( 'id' => '101' )
			)
			->willReturn( 1 );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_current_user( $request, array( 'displayName' => 'Johnathan Doe' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertSame( 'Johnathan Doe', $result['displayName'] );

		$this->assertCount( 1, $calls );
		$this->assertSame(
			array(
				'id'          => '101',
				'username'    => 'john_doe',
				'email'       => 'john@example.com',
				'role'        => 'editor',
				'displayName' => 'Johnathan Doe',
				'updatedAt'   => '2026-10-24 12:00:00',
			),
			$calls[0][0]
		);
		$this->assertSame(
			array(
				'id'          => '101',
				'username'    => 'john_doe',
				'email'       => 'john@example.com',
				'role'        => 'editor',
				'displayName' => 'John Doe',
				'updatedAt'   => '2026-01-01 00:00:00',
			),
			$calls[0][1]
		);
		$this->assertSame(
			array(
				'id'       => '101',
				'username' => 'john_doe',
				'role'     => 'editor',
			),
			$calls[0][2]
		);
	}

	public function test_self_service_update_does_not_fire_when_payload_is_empty(): void {
		$row     = $this->sample_user_row();
		$request = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )->willReturn( $row );

		$this->db->expects( $this->never() )->method( 'update' );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_current_user( $request, array() );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 0, $calls );
	}

	public function test_self_service_update_does_not_fire_when_zero_rows_affected(): void {
		$initial_row = $this->sample_user_row();
		$request     = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturn( $initial_row );

		$this->db->method( 'update' )->willReturn( 0 );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_current_user( $request, array( 'displayName' => 'Same Name' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 0, $calls );
	}

	public function test_self_service_update_throws_not_found_when_user_deleted_concurrently(): void {
		$initial_row = $this->sample_user_row();
		$request     = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_row, null );

		$this->db->method( 'update' )->willReturn( 0 );

		$calls = $this->record_action( 'user_updated', 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );

		try {
			$this->service->update_current_user( $request, array( 'displayName' => 'Lost User' ) );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_self_service_update_does_not_fire_on_validation_failure(): void {
		$request = $this->create_request( 'PUT', '/users/me' );
		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);

		$calls = $this->record_action( 'user_updated', 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->update_current_user( $request, array( 'username' => 'new_username' ) );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_self_service_update_does_not_fire_when_database_update_throws(): void {
		$initial_row = $this->sample_user_row();
		$request     = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )->willReturn( $initial_row );

		$this->db->method( 'update' )
			->willThrowException( new PDOException( 'Database write failure' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$this->expectException( PDOException::class );

		try {
			$this->service->update_current_user( $request, array( 'displayName' => 'Failed Write' ) );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_self_service_update_fires_hook_when_password_changed_notification_fails(): void {
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row(
			array(
				'password_hash' => '$2y$10$new_encrypted_password_hash',
				'updated_at'    => '2026-10-24 12:01:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/me' );
		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->willReturnOnConsecutiveCalls( $initial_row, $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		// Password change mailer throws unexpectedly
		$this->auth_service->method( 'send_password_changed' )
			->willThrowException( new RuntimeException( 'SMTP connection timed out' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_current_user(
			$request,
			array(
				'password'        => 'SuperSecretPassword123!',
				'currentPassword' => 'CurrentPasswordValid!',
			)
		);

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 1, $calls );
	}

	public function test_self_service_update_fires_hook_when_webhook_dispatch_fails(): void {
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row( array( 'display_name' => 'Safe Webhook Name' ) );
		$request     = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->willReturnOnConsecutiveCalls( $initial_row, $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		// Outbound webhook dispatcher throws
		$this->webhooks_service->method( 'dispatch_user_event' )
			->willThrowException( new RuntimeException( 'Webhook endpoint unreachable' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_current_user( $request, array( 'displayName' => 'Safe Webhook Name' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertSame( 'Safe Webhook Name', $result['displayName'] );
		$this->assertCount( 1, $calls );
	}

	public function test_self_service_update_isolates_observer_exceptions(): void {
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row( array( 'display_name' => 'Safe Name' ) );
		$request     = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->willReturnOnConsecutiveCalls( $initial_row, $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		\add_action(
			'user_updated',
			function (): void {
				throw new RuntimeException( 'Crashing observer on user_updated' );
			}
		);

		$result = $this->service->update_current_user( $request, array( 'displayName' => 'Safe Name' ) );

		$this->assertSame( 'Safe Name', $result['displayName'] );
	}

	public function test_self_service_update_excludes_sensitive_credentials_from_payloads(): void {
		$initial_row = $this->sample_user_row(
			array(
				'password_hash'             => '$2y$10$super_secret_hash_must_never_leak',
				'two_factor_secret'         => 'BASE32SECRETVAL9',
				'two_factor_pending_secret' => 'PENDINGSECRETVAL',
				'backup_codes_json'         => '["12345678", "87654321"]',
				'email_verification_token'  => 'token_verification_123',
				'password_reset_token'      => 'token_password_reset_456',
			)
		);
		$updated_row = array_merge( $initial_row, array( 'display_name' => 'Clean User' ) );

		$request = $this->create_request( 'PUT', '/users/me' );
		$this->auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => '101',
					'username' => 'john_doe',
				)
			);
		$this->users_api->method( 'get_user' )
			->willReturnOnConsecutiveCalls( $initial_row, $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		$calls = $this->record_action( 'user_updated', 3 );

		$this->service->update_current_user( $request, array( 'displayName' => 'Clean User' ) );

		$this->assertCount( 1, $calls );
		$captured_user     = $calls[0][0];
		$captured_previous = $calls[0][1];

		$forbidden_keys = array(
			'password',
			'password_hash',
			'currentPassword',
			'two_factor_secret',
			'two_factor_pending_secret',
			'backup_codes_json',
			'email_verification_token',
			'password_reset_token',
		);

		foreach ( $forbidden_keys as $key ) {
			$this->assertArrayNotHasKey( $key, $captured_user );
			$this->assertArrayNotHasKey( $key, $captured_previous );
		}

		$this->assertSame(
			array( 'id', 'username', 'email', 'role', 'displayName', 'updatedAt' ),
			array_keys( $captured_user )
		);
		$this->assertSame(
			array( 'id', 'username', 'email', 'role', 'displayName', 'updatedAt' ),
			array_keys( $captured_previous )
		);
	}

	// =========================================================================
	// Administrative User Update Tests
	// =========================================================================

	public function test_admin_update_fires_user_updated_with_actor_and_records_activity_and_webhook(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row(
			array(
				'first_name' => 'Johnny',
				'updated_at' => '2026-10-24 12:05:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )
			->with( $request )
			->willReturn( $admin_actor );

		$this->users_api->method( 'get_user_by_username' )
			->with( 'john_doe' )
			->willReturn( $initial_row );

		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturn( $updated_row );

		$this->db->expects( $this->once() )
			->method( 'update' )
			->willReturn( 1 );

		$execution_order = new ArrayObject();

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->willReturnCallback(
				function () use ( $execution_order ): void {
					$execution_order->append( 'activity' );
				}
			);

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->willReturnCallback(
				function () use ( $execution_order ): array {
					$execution_order->append( 'webhook' );
					return array();
				}
			);

		\add_action(
			'user_updated',
			function () use ( $execution_order ): void {
				$execution_order->append( 'user_updated' );
			}
		);

		$result = $this->service->update_user_by_username( $request, 'john_doe', array( 'firstName' => 'Johnny' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertSame(
			array( 'activity', 'webhook', 'user_updated' ),
			(array) $execution_order
		);
	}

	public function test_admin_update_does_not_fire_when_payload_is_empty(): void {
		$row           = $this->sample_user_row();
		$admin_request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->sample_admin_user() );
		$this->users_api->method( 'get_user_by_username' )
			->willReturn( $row );
		$this->users_api->method( 'get_user' )
			->willReturn( $row );

		$this->db->expects( $this->never() )->method( 'update' );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $admin_request, 'john_doe', array() );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 0, $calls );
	}

	public function test_admin_update_does_not_fire_when_zero_rows_affected(): void {
		$row           = $this->sample_user_row();
		$admin_request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->sample_admin_user() );
		$this->users_api->method( 'get_user_by_username' )
			->willReturn( $row );
		$this->users_api->method( 'get_user' )
			->willReturn( $row );

		$this->db->method( 'update' )->willReturn( 0 );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $admin_request, 'john_doe', array( 'firstName' => 'John' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 0, $calls );
	}

	public function test_admin_update_throws_not_found_when_user_deleted_concurrently(): void {
		$initial_row   = $this->sample_user_row();
		$admin_request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->sample_admin_user() );
		$this->users_api->method( 'get_user_by_username' )
			->willReturn( $initial_row );
		$this->users_api->method( 'get_user' )
			->willReturn( null );

		$this->db->method( 'update' )->willReturn( 0 );

		$calls = $this->record_action( 'user_updated', 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );

		try {
			$this->service->update_user_by_username( $admin_request, 'john_doe', array( 'firstName' => 'Ghost' ) );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_admin_update_does_not_fire_on_authorization_failure(): void {
		$request = $this->create_request( 'PUT', '/users/target_user' );
		$this->auth_service->method( 'get_admin_user' )
			->willThrowException( new ApiException( 'Administrator access required.', 403 ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->service->update_user_by_username( $request, 'target_user', array( 'firstName' => 'Hacked' ) );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_admin_role_update_uses_locked_row_for_accurate_previous_state(): void {
		$admin_actor = $this->sample_admin_user();
		// Initial row loaded before transaction has old display_name and editor role
		$initial_row = $this->sample_user_row(
			array(
				'display_name' => 'Old Stale Name',
				'role'         => 'editor',
			)
		);
		// Locked row re-read under FOR UPDATE contains concurrent name change
		$locked_row = $this->sample_user_row(
			array(
				'display_name' => 'Concurrent Fresh Name',
				'role'         => 'editor',
			)
		);
		// Final updated row with new role
		$updated_row = $this->sample_user_row(
			array(
				'display_name' => 'Concurrent Fresh Name',
				'role'         => 'admin',
				'updated_at'   => '2026-10-24 12:10:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $locked_row,
			)
		);
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 2 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $request, 'john_doe', array( 'role' => 'admin' ) );

		$this->assertSame( 'admin', $result['role'] );
		$this->assertCount( 1, $calls );

		// Verify previous state captures the locked row's Concurrent Fresh Name, NOT the stale Old Stale Name
		$this->assertSame( 'Concurrent Fresh Name', $calls[0][1]['displayName'] );
		$this->assertSame( 'editor', $calls[0][1]['role'] );
		$this->assertSame( 'admin', $calls[0][0]['role'] );
	}

	public function test_admin_role_update_retries_on_lock_conflict_and_dispatches_exactly_once(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row1 = $this->sample_user_row(
			array(
				'role'         => 'editor',
				'display_name' => 'Attempt 1 Name',
			)
		);
		$locked_row2 = $this->sample_user_row(
			array(
				'role'         => 'editor',
				'display_name' => 'Attempt 2 Final Name',
			)
		);
		$updated_row = $this->sample_user_row(
			array(
				'role'         => 'admin',
				'display_name' => 'Attempt 2 Final Name',
			)
		);

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );

		$this->users_api->method( 'lock_users' )->willReturnOnConsecutiveCalls(
			array(
				'1'   => $admin_actor,
				'101' => $locked_row1,
			),
			array(
				'1'   => $admin_actor,
				'101' => $locked_row2,
			)
		);
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 2 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$in_tx = false;
		$this->db->method( 'begin_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = true;
			}
		);
		$this->db->method( 'roll_back' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'commit' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'in_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				return $in_tx;
			}
		);

		// Attempt 1 hits 1213 deadlock; attempt 2 succeeds with 1 affected row
		$attempt_count = 0;
		$this->db->method( 'update' )->willReturnCallback(
			function () use ( &$attempt_count ): int {
				++$attempt_count;
				if ( 1 === $attempt_count ) {
					throw new PDOException( '1213 Deadlock found when trying to get lock', 1213 );
				}
				return 1;
			}
		);

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $request, 'john_doe', array( 'role' => 'admin' ) );

		$this->assertSame( 'admin', $result['role'] );
		$this->assertSame( 2, $attempt_count );

		// Dispatched exactly ONCE after the successful retry
		$this->assertCount( 1, $calls );
		$this->assertSame( 'Attempt 2 Final Name', $calls[0][1]['displayName'] );
		$this->assertSame( 'editor', $calls[0][1]['role'] );
		$this->assertSame( 'admin', $calls[0][0]['role'] );
	}

	public function test_admin_update_fires_hook_when_password_changed_notification_fails(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row(
			array(
				'password_hash' => '$2y$10$new_admin_reset_hash',
				'updated_at'    => '2026-10-24 12:15:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		$this->auth_service->method( 'send_password_changed' )
			->willThrowException( new RuntimeException( 'SMTP service down' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'password' => 'NewAdminAssignedPassword123!' )
		);

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 1, $calls );
	}

	public function test_admin_update_fires_hook_when_activity_recording_fails(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row( array( 'first_name' => 'CrashActivity' ) );

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		$this->analytics_service->method( 'record_activity' )
			->willThrowException( new RuntimeException( 'Audit log database disk full' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $request, 'john_doe', array( 'firstName' => 'CrashActivity' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 1, $calls );
	}

	public function test_admin_update_fires_hook_when_webhook_dispatch_fails(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row();
		$updated_row = $this->sample_user_row( array( 'first_name' => 'CrashWebhook' ) );

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$this->db->method( 'update' )->willReturn( 1 );

		$this->webhooks_service->method( 'dispatch_user_event' )
			->willThrowException( new RuntimeException( 'Webhook connection timed out' ) );

		$calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username( $request, 'john_doe', array( 'firstName' => 'CrashWebhook' ) );

		$this->assertSame( '101', $result['id'] );
		$this->assertCount( 1, $calls );
	}

	// =========================================================================
	// User Deletion Tests
	// =========================================================================

	public function test_user_deleted_fires_on_successful_deletion_after_transaction_commit(): void {
		$admin_actor = $this->sample_admin_user();
		$target_user = $this->sample_user_row(
			array(
				'id'       => '202',
				'username' => 'charlie_target',
				'email'    => 'charlie@example.com',
				'role'     => 'editor',
			)
		);

		$request = $this->create_request( 'DELETE', '/users/charlie_target' );
		$this->auth_service->method( 'get_admin_user' )
			->with( $request )
			->willReturn( $admin_actor );

		$this->users_api->method( 'get_user_by_username' )
			->with( 'charlie_target' )
			->willReturn( $target_user );

		$this->users_api->method( 'lock_users' )
			->willReturn(
				array(
					'1'   => $admin_actor,
					'202' => $target_user,
				)
			);

		// User owns 2 links to transfer
		$this->db->method( 'get_results_by' )
			->with( 'urls', array( 'user_id' => '202' ) )
			->willReturn(
				array(
					array(
						'id'         => 'link_1',
						'short_code' => 'l1',
						'alias'      => 'l1',
					),
					array(
						'id'         => 'link_2',
						'short_code' => 'l2',
						'alias'      => 'l2',
					),
				)
			);

		$this->db->method( 'count' )
			->with( 'urls', array( 'user_id' => '202' ) )
			->willReturn( 0 );

		$this->db->method( 'delete' )
			->willReturnCallback(
				function ( string $table, array $where ): int {
					if ( 'users' === $table && '202' === $where['id'] ) {
						return 1;
					}
					return 0;
				}
			);

		$committed = false;
		$this->db->expects( $this->once() )
			->method( 'commit' )
			->willReturnCallback(
				function () use ( &$committed ): void {
					$committed = true;
				}
			);

		$calls = $this->record_action( 'user_deleted', 2 );

		$result = $this->service->delete_user_by_username( $request, 'charlie_target' );

		$this->assertTrue( $result );
		$this->assertTrue( $committed );

		$this->assertCount( 1, $calls );
		$this->assertSame(
			array(
				'id'       => '202',
				'username' => 'charlie_target',
				'email'    => 'charlie@example.com',
				'role'     => 'editor',
			),
			$calls[0][0]
		);
		$this->assertSame(
			array(
				'actor'                 => array(
					'id'       => '1',
					'username' => 'admin_super',
					'role'     => 'admin',
				),
				'transferredLinksCount' => 2,
			),
			$calls[0][1]
		);
	}

	public function test_user_deleted_does_not_fire_for_nonexistent_user(): void {
		$request = $this->create_request( 'DELETE', '/users/missing_user' );
		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $this->sample_admin_user() );

		$this->users_api->method( 'get_user_by_username' )
			->with( 'missing_user' )
			->willReturn( null );

		$calls = $this->record_action( 'user_deleted', 2 );

		$result = $this->service->delete_user_by_username( $request, 'missing_user' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $calls );
	}

	public function test_user_deleted_does_not_fire_for_rejected_self_deletion(): void {
		$admin_actor = $this->sample_admin_user();
		$request     = $this->create_request( 'DELETE', '/users/admin_super' );

		$this->auth_service->method( 'get_admin_user' )
			->willReturn( $admin_actor );

		$this->users_api->method( 'get_user_by_username' )
			->with( 'admin_super' )
			->willReturn(
				$this->sample_user_row(
					array(
						'id'       => '1',
						'username' => 'admin_super',
					)
				)
			);

		$calls = $this->record_action( 'user_deleted', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->delete_user_by_username( $request, 'admin_super' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_user_deleted_does_not_fire_on_authorization_failure(): void {
		$request = $this->create_request( 'DELETE', '/users/editor_target' );
		$this->auth_service->method( 'get_admin_user' )
			->willThrowException( new ApiException( 'Administrator access required.', 403 ) );

		$calls = $this->record_action( 'user_deleted', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->service->delete_user_by_username( $request, 'editor_target' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_user_deleted_does_not_fire_on_transaction_rollback(): void {
		$admin_actor = $this->sample_admin_user();
		$target_user = $this->sample_user_row(
			array(
				'id'       => '303',
				'username' => 'failed_del',
			)
		);
		$request     = $this->create_request( 'DELETE', '/users/failed_del' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $target_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'303' => $target_user,
			)
		);

		$this->db->method( 'get_results_by' )->willReturn( array() );
		$this->db->method( 'delete' )
			->willThrowException( new PDOException( 'Database failure on delete' ) );

		$in_tx = false;
		$this->db->method( 'begin_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				$in_tx = true;
				return true;
			}
		);
		$this->db->method( 'roll_back' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				$in_tx = false;
				return true;
			}
		);
		$this->db->method( 'in_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				return $in_tx;
			}
		);

		$calls = $this->record_action( 'user_deleted', 2 );

		$this->expectException( PDOException::class );

		try {
			$this->service->delete_user_by_username( $request, 'failed_del' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_user_deleted_isolates_observer_exceptions(): void {
		$admin_actor = $this->sample_admin_user();
		$target_user = $this->sample_user_row(
			array(
				'id'       => '404',
				'username' => 'observer_fail',
			)
		);
		$request     = $this->create_request( 'DELETE', '/users/observer_fail' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $target_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'404' => $target_user,
			)
		);
		$this->db->method( 'get_results_by' )->willReturn( array() );
		$this->db->method( 'delete' )->willReturn( 1 );

		\add_action(
			'user_deleted',
			function (): void {
				throw new RuntimeException( 'Observer crashed on user_deleted' );
			}
		);

		$result = $this->service->delete_user_by_username( $request, 'observer_fail' );

		$this->assertTrue( $result );
	}

	public function test_user_deleted_preserves_webhook_and_audit_activity_before_hook(): void {
		$admin_actor = $this->sample_admin_user();
		$target_user = $this->sample_user_row(
			array(
				'id'       => '505',
				'username' => 'audit_target',
			)
		);
		$request     = $this->create_request( 'DELETE', '/users/audit_target' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $target_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'505' => $target_user,
			)
		);
		$this->db->method( 'get_results_by' )->willReturn( array() );
		$this->db->method( 'delete' )->willReturn( 1 );

		$execution_order = new ArrayObject();

		$this->analytics_service->expects( $this->once() )
			->method( 'record_activity' )
			->with(
				'user_deleted',
				'Deleted user audit_target.',
				'1',
				null,
				$this->isType( 'array' )
			)
			->willReturnCallback(
				function () use ( $execution_order ): void {
					$execution_order->append( 'activity' );
				}
			);

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.deleted', $target_user )
			->willReturnCallback(
				function () use ( $execution_order ): array {
					$execution_order->append( 'webhook' );
					return array();
				}
			);

		\add_action(
			'user_deleted',
			function () use ( $execution_order ): void {
				$execution_order->append( 'user_deleted' );
			}
		);

		$result = $this->service->delete_user_by_username( $request, 'audit_target' );

		$this->assertTrue( $result );
		$this->assertSame(
			array( 'activity', 'webhook', 'user_deleted' ),
			(array) $execution_order
		);
	}
}
