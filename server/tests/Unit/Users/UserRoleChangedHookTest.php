<?php
/**
 * Unit tests for user_role_changed lifecycle hook.
 *
 * Verifies that:
 * - user_role_changed fires strictly after successful role modification persistence
 * - listener receives accurate bounded payload, new_role, old_role (from locked row), and actor
 * - same-role updates do not dispatch user_role_changed even when other profile fields change
 * - profile updates omitting role do not dispatch user_role_changed
 * - self-service profile updates never dispatch user_role_changed
 * - unauthorized attempts and attempts to demote the last administrator are rejected without firing
 * - failed transaction attempts, zero affected rows, or concurrent deletions do not emit the action
 * - deadlock retry loop dispatches user_role_changed exactly once upon eventual commit
 * - observer exceptions are isolated and do not disrupt persistence or API response
 * - sensitive credentials, hashes, and tokens are strictly excluded from payload and actor
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

class UserRoleChangedHookTest extends TestCase {

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
				'id'                            => '101',
				'username'                      => 'john_doe',
				'email'                         => 'john@example.com',
				'first_name'                    => 'John',
				'last_name'                     => 'Doe',
				'display_name'                  => 'John Doe',
				'password_hash'                 => '$2y$10$encrypted_secret_hash_value_here',
				'two_factor_secret'             => 'BASE32SECRETVALUEDOESNOTLEAK',
				'two_factor_pending_secret'     => 'PENDINGSECRETVALUEDOESNOTLEAK',
				'backup_codes_json'             => '["code1","code2"]',
				'email_verification_token'      => 'token_verification_secret_hash',
				'email_verification_expires_at' => '2026-12-31 23:59:59',
				'password_reset_token'          => 'token_reset_secret_hash',
				'password_reset_expires_at'     => '2026-12-31 23:59:59',
				'role'                          => 'editor',
				'created_at'                    => '2026-01-01 00:00:00',
				'updated_at'                    => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	private function sample_admin_user( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                            => '1',
				'username'                      => 'admin_super',
				'email'                         => 'admin@example.com',
				'password_hash'                 => '$2y$10$admin_password_hash_value',
				'two_factor_secret'             => 'ADMINBASE32SECRET',
				'two_factor_pending_secret'     => null,
				'backup_codes_json'             => '["admincode"]',
				'email_verification_token'      => null,
				'email_verification_expires_at' => null,
				'password_reset_token'          => null,
				'password_reset_expires_at'     => null,
				'role'                          => 'admin',
			),
			$overrides
		);
	}

	private function create_request( string $method, string $path, array $body_params = array() ): Request {
		return new Request( $method, $path, array(), $body_params );
	}

	private function record_action( string $hook_name, int $accepted_args = 4 ): ArrayObject {
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

	public function test_role_transition_from_editor_to_admin_dispatches_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'admin',
				'updated_at' => '2026-10-24 14:00:00',
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
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertSame( 'admin', $result['role'] );

		// Confirm both user_updated and user_role_changed fired
		$this->assertCount( 1, $updated_calls );
		$this->assertCount( 1, $role_calls );

		list( $user_payload, $new_role, $old_role, $actor_payload ) = $role_calls[0];

		// Verify target user payload
		$this->assertSame( '101', $user_payload['id'] );
		$this->assertSame( 'john_doe', $user_payload['username'] );
		$this->assertSame( 'john@example.com', $user_payload['email'] );
		$this->assertSame( 'John Doe', $user_payload['displayName'] );
		$this->assertSame( 'admin', $user_payload['role'] );
		$this->assertSame( '2026-10-24 14:00:00', $user_payload['updatedAt'] );

		// Verify role transitions
		$this->assertSame( 'admin', $new_role );
		$this->assertSame( 'editor', $old_role );

		// Verify actor details
		$this->assertSame( '1', $actor_payload['id'] );
		$this->assertSame( 'admin_super', $actor_payload['username'] );
		$this->assertSame( 'admin', $actor_payload['role'] );
	}

	public function test_role_transition_from_admin_to_editor_dispatches_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row(
			array(
				'id'       => '102',
				'username' => 'second_admin',
				'role'     => 'admin',
			)
		);
		$locked_row  = $this->sample_user_row(
			array(
				'id'       => '102',
				'username' => 'second_admin',
				'role'     => 'admin',
			)
		);
		$updated_row = $this->sample_user_row(
			array(
				'id'         => '102',
				'username'   => 'second_admin',
				'role'       => 'editor',
				'updated_at' => '2026-10-24 14:05:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/second_admin' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'102' => $locked_row,
			)
		);
		// Two admins exist, so demoting second_admin is allowed
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 2 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$result = $this->service->update_user_by_username(
			$request,
			'second_admin',
			array( 'role' => 'editor' )
		);

		$this->assertSame( 'editor', $result['role'] );
		$this->assertCount( 1, $role_calls );

		list( $user_payload, $new_role, $old_role, $actor_payload ) = $role_calls[0];

		$this->assertSame( '102', $user_payload['id'] );
		$this->assertSame( 'second_admin', $user_payload['username'] );
		$this->assertSame( 'editor', $user_payload['role'] );
		$this->assertSame( 'editor', $new_role );
		$this->assertSame( 'admin', $old_role );
		$this->assertSame( 'admin_super', $actor_payload['username'] );
	}

	public function test_old_role_comes_from_locked_row_not_stale_pre_transaction_row(): void {
		$admin_actor = $this->sample_admin_user();
		// Initial row loaded before transaction shows editor role
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		// Concurrent process had changed target to admin right before row lock
		$locked_row = $this->sample_user_row( array( 'role' => 'admin' ) );
		// Requested transition is back to editor
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'editor',
				'updated_at' => '2026-10-24 14:10:00',
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

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'editor' )
		);

		$this->assertSame( 'editor', $result['role'] );
		$this->assertCount( 1, $role_calls );

		list( , $new_role, $old_role, ) = $role_calls[0];

		// old_role must reflect locked row ('admin'), NOT initial pre-transaction row ('editor')
		$this->assertSame( 'admin', $old_role );
		$this->assertSame( 'editor', $new_role );
	}

	public function test_same_role_update_does_not_fire_user_role_changed_even_if_other_fields_change(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row(
			array(
				'role'         => 'editor',
				'display_name' => 'Old Name',
			)
		);
		$locked_row  = $this->sample_user_row(
			array(
				'role'         => 'editor',
				'display_name' => 'Old Name',
			)
		);
		$updated_row = $this->sample_user_row(
			array(
				'role'         => 'editor',
				'display_name' => 'New Name',
				'updated_at'   => '2026-10-24 14:15:00',
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
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		// Admin submits both display_name change and same role ('editor')
		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array(
				'displayName' => 'New Name',
				'role'        => 'editor',
			)
		);

		$this->assertSame( 'editor', $result['role'] );

		// user_updated must fire because profile changed and update succeeded
		$this->assertCount( 1, $updated_calls );

		// user_role_changed must NOT fire because role did not change
		$this->assertCount( 0, $role_calls );
	}

	public function test_profile_update_without_role_parameter_does_not_fire_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row(
			array(
				'role'       => 'editor',
				'first_name' => 'John',
			)
		);
		$locked_row  = $this->sample_user_row(
			array(
				'role'       => 'editor',
				'first_name' => 'John',
			)
		);
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'editor',
				'first_name' => 'Jonathan',
				'updated_at' => '2026-10-24 14:20:00',
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
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'firstName' => 'Jonathan' )
		);

		$this->assertCount( 1, $updated_calls );
		$this->assertCount( 0, $role_calls );
	}

	public function test_self_service_update_never_fires_user_role_changed(): void {
		$user_row    = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'editor',
				'first_name' => 'Johnny',
				'updated_at' => '2026-10-24 14:25:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/me' );
		$this->auth_service->method( 'get_current_user' )->willReturn( $user_row );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		// Even if client attempts to pass role in self-service update, it is stripped
		$this->service->update_current_user(
			$request,
			array(
				'firstName' => 'Johnny',
				'role'      => 'admin',
			)
		);

		$this->assertCount( 1, $updated_calls );
		$this->assertCount( 0, $role_calls );
	}

	public function test_non_admin_actor_is_forbidden_and_does_not_fire_user_role_changed(): void {
		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willThrowException(
			new ApiException( 'Forbidden', 403 )
		);

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->service->update_user_by_username(
				$request,
				'john_doe',
				array( 'role' => 'admin' )
			);
		} finally {
			$this->assertCount( 0, $role_calls );
		}
	}

	public function test_demoting_last_admin_is_rejected_and_does_not_fire_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_admin_user(); // only 1 admin
		$locked_row  = $this->sample_admin_user();

		$request = $this->create_request( 'PUT', '/users/admin_super' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1' => $locked_row,
			)
		);
		// count_admin_users_for_update returns 1 -> last administrator guard triggers
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->update_user_by_username(
				$request,
				'admin_super',
				array( 'role' => 'editor' )
			);
		} finally {
			$this->assertCount( 0, $role_calls );
		}
	}

	public function test_zero_affected_rows_does_not_fire_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $locked_row,
			)
		);
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $initial_row );
		// Database update produces 0 affected rows
		$this->db->method( 'update' )->willReturn( 0 );

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		$this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertCount( 0, $updated_calls );
		$this->assertCount( 0, $role_calls );
	}

	public function test_concurrently_deleted_user_throws_404_and_does_not_fire_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		// When locking, user 101 has been deleted by concurrent request
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1' => $admin_actor,
			)
		);

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 404 );

		try {
			$this->service->update_user_by_username(
				$request,
				'john_doe',
				array( 'role' => 'admin' )
			);
		} finally {
			$this->assertCount( 0, $role_calls );
		}
	}

	public function test_database_update_exception_rolls_back_and_does_not_fire_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $locked_row,
			)
		);
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->db->method( 'update' )->willThrowException(
			new PDOException( 'Disk full or constraint violation' )
		);

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$this->expectException( PDOException::class );

		try {
			$this->service->update_user_by_username(
				$request,
				'john_doe',
				array( 'role' => 'admin' )
			);
		} finally {
			$this->assertCount( 0, $role_calls );
		}
	}

	public function test_deadlock_retry_dispatches_user_role_changed_exactly_once_on_commit(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row1 = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row2 = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'admin',
				'updated_at' => '2026-10-24 14:30:00',
			)
		);

		$request = $this->create_request( 'PUT', '/users/john_doe' );
		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->willReturn( $initial_row );

		$attempt = 0;
		$this->users_api->method( 'lock_users' )->willReturnCallback(
			function () use ( &$attempt, $admin_actor, $locked_row1, $locked_row2 ): array {
				++$attempt;
				return array(
					'1'   => $admin_actor,
					'101' => 1 === $attempt ? $locked_row1 : $locked_row2,
				);
			}
		);
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );

		$update_attempt = 0;
		$this->db->method( 'update' )->willReturnCallback(
			function () use ( &$update_attempt ): int {
				++$update_attempt;
				if ( 1 === $update_attempt ) {
					// Simulate MySQL deadlock error
					throw new PDOException( '1213 Deadlock found when trying to get lock', 1213 );
				}
				return 1;
			}
		);

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertSame( 'admin', $result['role'] );
		$this->assertSame( 2, $update_attempt );

		// Hook must dispatch exactly once on the successful commit
		$this->assertCount( 1, $role_calls );
		$this->assertSame( 'admin', $role_calls[0][1] );
		$this->assertSame( 'editor', $role_calls[0][2] );
	}

	public function test_notifications_and_webhooks_failure_does_not_prevent_user_role_changed(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'admin',
				'updated_at' => '2026-10-24 14:35:00',
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
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		// Simulate auxiliary side-effect failures
		$this->auth_service->method( 'send_password_changed' )->willThrowException(
			new RuntimeException( 'SMTP service down' )
		);
		$this->analytics_service->method( 'record_activity' )->willThrowException(
			new RuntimeException( 'Analytics pipeline offline' )
		);
		$this->webhooks_service->method( 'dispatch_user_event' )->willThrowException(
			new RuntimeException( 'Webhook connection timed out' )
		);

		$role_calls    = $this->record_action( 'user_role_changed', 4 );
		$updated_calls = $this->record_action( 'user_updated', 3 );

		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertSame( 'admin', $result['role'] );

		// Both hooks still fire successfully
		$this->assertCount( 1, $updated_calls );
		$this->assertCount( 1, $role_calls );
		$this->assertSame( 'admin', $role_calls[0][1] );
		$this->assertSame( 'editor', $role_calls[0][2] );
	}

	public function test_observer_exception_is_isolated_and_does_not_disrupt_update(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'admin',
				'updated_at' => '2026-10-24 14:40:00',
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
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		// Register an observer that throws
		\add_action(
			'user_role_changed',
			function (): void {
				throw new RuntimeException( 'Third-party extension crashed' );
			}
		);

		// Execution must succeed and return formatted user without throwing
		$result = $this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertSame( 'admin', $result['role'] );
	}

	public function test_user_role_changed_payload_and_actor_strictly_exclude_sensitive_credentials(): void {
		$admin_actor = $this->sample_admin_user();
		$initial_row = $this->sample_user_row( array( 'role' => 'editor' ) );
		$locked_row  = $this->sample_user_row( array( 'role' => 'editor' ) );
		$updated_row = $this->sample_user_row(
			array(
				'role'       => 'admin',
				'updated_at' => '2026-10-24 14:45:00',
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
		$this->users_api->method( 'count_admin_users_for_update' )->willReturn( 1 );
		$this->users_api->method( 'get_user' )->willReturn( $updated_row );
		$this->db->method( 'update' )->willReturn( 1 );

		$role_calls = $this->record_action( 'user_role_changed', 4 );

		$this->service->update_user_by_username(
			$request,
			'john_doe',
			array( 'role' => 'admin' )
		);

		$this->assertCount( 1, $role_calls );
		list( $user_payload, , , $actor_payload ) = $role_calls[0];

		// Check sensitive user fields exclusion
		$this->assertArrayNotHasKey( 'password_hash', $user_payload );
		$this->assertArrayNotHasKey( 'two_factor_secret', $user_payload );
		$this->assertArrayNotHasKey( 'two_factor_pending_secret', $user_payload );
		$this->assertArrayNotHasKey( 'backup_codes_json', $user_payload );
		$this->assertArrayNotHasKey( 'email_verification_token', $user_payload );
		$this->assertArrayNotHasKey( 'password_reset_token', $user_payload );

		// Check sensitive actor fields exclusion
		$this->assertArrayNotHasKey( 'password_hash', $actor_payload );
		$this->assertArrayNotHasKey( 'two_factor_secret', $actor_payload );
		$this->assertArrayNotHasKey( 'email', $actor_payload );
	}
}
