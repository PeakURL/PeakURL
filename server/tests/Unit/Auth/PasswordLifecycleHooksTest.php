<?php
/**
 * Unit tests for Password lifecycle hooks (password_changed, password_reset).
 *
 * Verifies that:
 * - password_changed fires strictly after confirmed database persistence and transaction commit
 * - password_changed observer receives bounded metadata without passwords or credentials
 * - password_changed context correctly reflects 'reset', 'profile', or 'admin' source
 * - password reset enforces atomic token consumption with exact-match token and expiry checks
 * - password reset and session invalidation are bound within an atomic transaction with rollback on failure
 * - legacy password_reset action is preserved alongside password_changed
 * - observers are isolated via try/catch and error logging without altering API outcomes
 * - email notification failures do not suppress password_changed action dispatch
 * - profile-only updates without password changes do not emit false password_changed actions
 * - all operations execute safely when no listeners are registered
 *
 * @package PeakURL\Tests\Unit\Auth
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use ArrayObject;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use RuntimeException;

class PasswordLifecycleHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&SocialPreview $social_preview;
	private MockObject&LinksApi $links_api;
	private AuthValidator $auth_validator;
	private UsersValidator $users_validator;
	private MockObject&Totp $totp;
	private Crypto $crypto;
	private Roles $roles;
	private Authorization $authorization;
	private MockObject&AuthService $auth_service;
	private UsersService $users_service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db                = $this->createMock( PeakURL_DB::class );
		$this->users_api         = $this->createMock( UsersApi::class );
		$this->credentials       = new Credentials( $this->db );
		$this->notifications     = $this->createMock( Notifications::class );
		$this->geoip             = $this->createMock( Geoip::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->social_preview    = $this->createMock( SocialPreview::class );
		$this->links_api         = $this->createMock( LinksApi::class );
		$this->auth_validator    = new AuthValidator();
		$this->users_validator   = new UsersValidator();
		$this->totp              = $this->createMock( Totp::class );
		$this->roles             = new Roles();
		$this->authorization     = new Authorization( $this->roles );

		$config = array(
			Constants::SESSION_COOKIE_NAME => 'peakurl_session',
			Constants::SESSION_LIFETIME    => 2592000,
			Constants::AUTH_KEY            => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
			Constants::AUTH_SALT           => 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
		);

		$this->crypto = new Crypto( $config );

		$this->auth_service = $this->getMockBuilder( AuthService::class )
			->setConstructorArgs(
				array(
					$this->db,
					$this->users_api,
					$this->credentials,
					$this->auth_validator,
					$this->totp,
					$this->notifications,
					$this->crypto,
					$this->roles,
					$this->authorization,
					$this->geoip,
					$config,
					$this->webhooks_service,
				)
			)
			->onlyMethods( array( 'get_current_user', 'get_admin_user' ) )
			->getMock();

		$this->users_service = new UsersService(
			$this->db,
			$this->users_api,
			$this->auth_service,
			$this->analytics_service,
			$this->users_validator,
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

	/**
	 * Create sample user database row.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return array<string, mixed>
	 */
	private function sample_user( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                        => '101',
				'username'                  => 'editor_jane',
				'email'                     => 'jane@example.com',
				'role'                      => 'editor',
				'password_hash'             => password_hash( 'InitialSecurePassword123!', PASSWORD_DEFAULT ),
				'two_factor_enabled'        => 0,
				'two_factor_secret'         => null,
				'two_factor_pending_secret' => null,
				'backup_codes_json'         => null,
				'password_reset_token'      => Credentials::hash_lookup_token( 'valid-reset-token-12345' ),
				'password_reset_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'created_at'                => '2026-01-01 00:00:00',
				'updated_at'                => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * Create sample admin user database row.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return array<string, mixed>
	 */
	private function sample_admin( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => '1',
				'username'   => 'admin_super',
				'email'      => 'admin@example.com',
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * Create dummy incoming request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 * @return Request
	 */
	private function create_request( string $method = 'POST', string $path = '/auth/reset-password' ): Request {
		return new Request( $method, $path, array(), array() );
	}

	/**
	 * Record dispatched action arguments.
	 *
	 * @param string $hook_name     Action hook name.
	 * @param int    $accepted_args Number of accepted arguments.
	 * @return ArrayObject<int, array<int, mixed>>
	 */
	private function record_action( string $hook_name, int $accepted_args = 2 ): ArrayObject {
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
	// Section A: Password Reset Tests (AuthService::reset_password)
	// =========================================================================

	public function test_reset_password_dispatches_password_changed_with_bounded_user_and_reset_context(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )
			->with(
				$this->stringContains( 'password_reset_token' ),
				$this->isType( 'array' )
			)
			->willReturn( $user );

		$execution_order = array();

		$this->db->expects( $this->once() )
			->method( 'begin_transaction' )
			->willReturnCallback(
				function () use ( &$execution_order ): void {
					$execution_order[] = 'begin_transaction';
				}
			);

		$token_hash = Credentials::hash_lookup_token( 'valid-reset-token-12345' );

		$this->db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnCallback(
				function ( string $sql, array $params ) use ( &$execution_order, $token_hash ): int {
					if ( str_contains( $sql, 'UPDATE users' ) ) {
						$execution_order[] = 'update_users';
						$this->assertStringContainsString( 'AND password_reset_token = :token_hash', $sql );
						$this->assertStringContainsString( 'AND password_reset_expires_at > :now', $sql );
						$this->assertSame( '101', $params['id'] );
						$this->assertSame( $token_hash, $params['token_hash'] );
						return 1;
					}
					if ( str_contains( $sql, 'UPDATE sessions' ) ) {
						$execution_order[] = 'revoke_sessions';
						$this->assertSame( '101', $params['user_id'] );
						return 1;
					}
					return 0;
				}
			);

		$this->db->expects( $this->once() )
			->method( 'commit' )
			->willReturnCallback(
				function () use ( &$execution_order ): void {
					$execution_order[] = 'commit';
				}
			);

		$calls = $this->record_action( 'password_changed', 2 );
		\add_action(
			'password_changed',
			function () use ( &$execution_order ): void {
				$execution_order[] = 'password_changed_action';
			},
			9
		);

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );

		// Confirm execution ordering: action fired strictly AFTER transaction commit
		$this->assertSame(
			array( 'begin_transaction', 'update_users', 'revoke_sessions', 'commit', 'password_changed_action' ),
			$execution_order
		);

		// Confirm observer called exactly once
		$this->assertCount( 1, $calls );
		list( $payload, $context ) = $calls[0];

		// Check payload properties and bounds
		$this->assertSame( '101', $payload['id'] );
		$this->assertSame( 'editor_jane', $payload['username'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['occurredAt'] );
		$this->assertSame(
			array( 'id', 'username', 'role', 'occurredAt' ),
			array_keys( $payload )
		);

		// Check context properties and bounds
		$this->assertSame( array( 'source' => 'reset' ), $context );
		$this->assertSame( array( 'source' ), array_keys( $context ) );

		// Verify confidentiality: credentials strictly excluded
		$this->assertArrayNotHasKey( 'password', $payload );
		$this->assertArrayNotHasKey( 'password_hash', $payload );
		$this->assertArrayNotHasKey( 'token', $payload );
		$this->assertArrayNotHasKey( 'password_reset_token', $payload );
		$this->assertArrayNotHasKey( 'token_hash', $payload );
		$this->assertArrayNotHasKey( 'secret', $payload );
		$this->assertArrayNotHasKey( 'session', $payload );
		$this->assertArrayNotHasKey( 'cookie', $payload );
	}

	public function test_reset_password_preserves_existing_password_reset_action(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		$legacy_calls = $this->record_action( 'password_reset', 1 );
		$new_calls    = $this->record_action( 'password_changed', 2 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $legacy_calls );
		$this->assertCount( 1, $new_calls );

		// Legacy hook receives formatted user
		$legacy_user = $legacy_calls[0][0];
		$this->assertSame( '101', $legacy_user['id'] );
		$this->assertSame( 'editor_jane', $legacy_user['username'] );
	}

	public function test_reset_password_fails_and_suppresses_actions_when_token_is_invalid(): void {
		$request = $this->create_request( 'POST', '/auth/reset-password/invalid-token' );

		$this->db->method( 'get_row' )->willReturn( null );
		$this->db->expects( $this->never() )->method( 'begin_transaction' );

		$calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Password reset token is invalid or has expired.' );

		try {
			$this->auth_service->reset_password( $request, 'invalid-token', 'NewSecurePassword456!' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_reset_password_fails_and_suppresses_actions_when_token_is_expired_or_already_consumed_concurrently(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		// Update matches 0 rows because token was consumed or expired concurrently
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 0 );
		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Password reset token is invalid or has expired.' );

		try {
			$this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_reset_password_rolls_back_and_suppresses_actions_when_database_update_throws(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )
			->method( 'query' )
			->willThrowException( new PDOException( 'Lock wait timeout exceeded' ) );
		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( PDOException::class );

		try {
			$this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_reset_password_rolls_back_and_suppresses_actions_when_session_invalidation_fails(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'begin_transaction' );

		$this->db->expects( $this->exactly( 2 ) )
			->method( 'query' )
			->willReturnCallback(
				function ( string $sql ): int {
					if ( str_contains( $sql, 'UPDATE users' ) ) {
						return 1;
					}
					throw new PDOException( 'Session table constraint failure' );
				}
			);

		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( PDOException::class );

		try {
			$this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_reset_password_dispatches_actions_when_password_email_fails(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		// Notifications mailer throws unexpectedly
		$this->notifications->method( 'send_password_changed' )
			->willThrowException( new RuntimeException( 'SMTP service unavailable' ) );

		$calls = $this->record_action( 'password_changed', 2 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'reset', $calls[0][1]['source'] );
	}

	public function test_reset_password_dispatches_actions_when_password_notification_throws_non_runtime_error(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		// Notifications mailer throws a non-RuntimeException \Error
		$this->notifications->method( 'send_password_changed' )
			->willThrowException( new \Error( 'Fatal engine failure in mail transport' ) );

		$legacy_calls   = $this->record_action( 'password_reset', 1 );
		$password_calls = $this->record_action( 'password_changed', 2 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		// Password reset remains successful
		$this->assertTrue( $result );

		// Both relevant lifecycle actions execute
		$this->assertCount( 1, $legacy_calls );
		$this->assertSame( '101', $legacy_calls[0][0]['id'] );

		$this->assertCount( 1, $password_calls );
		$this->assertSame( '101', $password_calls[0][0]['id'] );
		$this->assertSame( 'reset', $password_calls[0][1]['source'] );
	}

	public function test_reset_password_isolates_observer_exceptions_on_password_changed(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		$observer_invoked = false;
		\add_action(
			'password_changed',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer threw unexpected error' );
			}
		);

		$legacy_calls = $this->record_action( 'password_reset', 1 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );
		$this->assertTrue( $observer_invoked );
		// Legacy hook still executed
		$this->assertCount( 1, $legacy_calls );
	}

	public function test_reset_password_isolates_observer_exceptions_on_legacy_password_reset(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		$legacy_observer_invoked = false;
		\add_action(
			'password_reset',
			function () use ( &$legacy_observer_invoked ): void {
				$legacy_observer_invoked = true;
				throw new RuntimeException( 'Legacy observer threw unexpected error' );
			}
		);

		$new_calls = $this->record_action( 'password_changed', 2 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );
		$this->assertTrue( $legacy_observer_invoked );
		// New hook still executed
		$this->assertCount( 1, $new_calls );
	}

	public function test_reset_password_executes_safely_without_listeners(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/auth/reset-password/valid-reset-token-12345' );

		$this->db->method( 'get_row' )->willReturn( $user );
		$this->db->method( 'query' )->willReturn( 1 );

		$result = $this->auth_service->reset_password( $request, 'valid-reset-token-12345', 'NewSecurePassword456!' );

		$this->assertTrue( $result );
		$this->assertSame( 0, \did_action( 'password_changed' ) );
	}

	// =========================================================================
	// Section B: Self-Service Profile Password Change Tests (UsersService)
	// =========================================================================

	public function test_update_current_user_dispatches_password_changed_with_profile_context(): void {
		$initial_user = $this->sample_user(
			array(
				'password_hash' => password_hash( 'CurrentCorrectPassword1!', PASSWORD_DEFAULT ),
			)
		);
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-06-15 12:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$password_calls = $this->record_action( 'password_changed', 2 );
		$user_calls     = $this->record_action( 'user_updated', 3 );

		$result = $this->users_service->update_current_user(
			$request,
			array(
				'password'        => 'FreshNewPassword789!',
				'currentPassword' => 'CurrentCorrectPassword1!',
			)
		);

		// password_changed action assertions
		$this->assertCount( 1, $password_calls );
		list( $payload, $context ) = $password_calls[0];

		$this->assertSame( '101', $payload['id'] );
		$this->assertSame( 'editor_jane', $payload['username'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertNotEmpty( $payload['occurredAt'] );
		$this->assertSame( array( 'id', 'username', 'role', 'occurredAt' ), array_keys( $payload ) );

		$this->assertSame( array( 'source' => 'profile' ), $context );
		$this->assertSame( array( 'source' ), array_keys( $context ) );

		// user_updated action also dispatched once
		$this->assertCount( 1, $user_calls );
		$this->assertSame( '101', $result['id'] );
	}

	public function test_update_current_user_suppresses_password_changed_on_profile_only_update(): void {
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'first_name' => 'JaneUpdated' ) );
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$password_calls = $this->record_action( 'password_changed', 2 );
		$user_calls     = $this->record_action( 'user_updated', 3 );

		$this->users_service->update_current_user(
			$request,
			array(
				'firstName' => 'JaneUpdated',
			)
		);

		// password_changed MUST NOT fire for profile-only updates
		$this->assertCount( 0, $password_calls );
		// user_updated fires normally
		$this->assertCount( 1, $user_calls );
	}

	public function test_update_current_user_suppresses_password_changed_when_current_password_is_incorrect(): void {
		$initial_user = $this->sample_user(
			array(
				'password_hash' => password_hash( 'RealSecretPassword1!', PASSWORD_DEFAULT ),
			)
		);
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $initial_user );
		$this->db->expects( $this->never() )->method( 'update' );

		$password_calls = $this->record_action( 'password_changed', 2 );
		$user_calls     = $this->record_action( 'user_updated', 3 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Current password is incorrect.' );

		try {
			$this->users_service->update_current_user(
				$request,
				array(
					'password'        => 'FreshNewPassword789!',
					'currentPassword' => 'WrongPassword!',
				)
			);
		} finally {
			$this->assertCount( 0, $password_calls );
			$this->assertCount( 0, $user_calls );
		}
	}

	public function test_update_current_user_suppresses_password_changed_when_database_update_affects_zero_rows(): void {
		$initial_user = $this->sample_user(
			array(
				'password_hash' => password_hash( 'CurrentCorrectPassword1!', PASSWORD_DEFAULT ),
			)
		);
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $initial_user );
		// DB update affects 0 rows
		$this->db->method( 'update' )->willReturn( 0 );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->users_service->update_current_user(
			$request,
			array(
				'password'        => 'FreshNewPassword789!',
				'currentPassword' => 'CurrentCorrectPassword1!',
			)
		);

		$this->assertCount( 0, $password_calls );
	}

	public function test_update_current_user_dispatches_password_changed_when_email_fails(): void {
		$initial_user = $this->sample_user(
			array(
				'password_hash' => password_hash( 'CurrentCorrectPassword1!', PASSWORD_DEFAULT ),
			)
		);
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-06-15 12:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		// Notifications mailer throws
		$this->notifications->method( 'send_password_changed' )
			->willThrowException( new RuntimeException( 'SMTP failure' ) );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->users_service->update_current_user(
			$request,
			array(
				'password'        => 'FreshNewPassword789!',
				'currentPassword' => 'CurrentCorrectPassword1!',
			)
		);

		$this->assertCount( 1, $password_calls );
		$this->assertSame( 'profile', $password_calls[0][1]['source'] );
	}

	public function test_update_current_user_isolates_observer_exceptions_on_password_changed(): void {
		$initial_user = $this->sample_user(
			array(
				'password_hash' => password_hash( 'CurrentCorrectPassword1!', PASSWORD_DEFAULT ),
			)
		);
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-06-15 12:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/me' );

		$this->auth_service->method( 'get_current_user' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$observer_invoked = false;
		\add_action(
			'password_changed',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer failure' );
			}
		);

		$result = $this->users_service->update_current_user(
			$request,
			array(
				'password'        => 'FreshNewPassword789!',
				'currentPassword' => 'CurrentCorrectPassword1!',
			)
		);

		$this->assertTrue( $observer_invoked );
		$this->assertSame( '101', $result['id'] );
	}

	// =========================================================================
	// Section C: Administrator Password Change Tests (UsersService)
	// =========================================================================

	public function test_update_user_by_username_dispatches_password_changed_with_admin_context(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-07-20 10:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$password_calls = $this->record_action( 'password_changed', 2 );
		$user_calls     = $this->record_action( 'user_updated', 3 );

		$result = $this->users_service->update_user_by_username(
			$request,
			'editor_jane',
			array(
				'password' => 'AdminResetPassword123!',
			)
		);

		// password_changed action assertions
		$this->assertCount( 1, $password_calls );
		list( $payload, $context ) = $password_calls[0];

		$this->assertSame( '101', $payload['id'] );
		$this->assertSame( 'editor_jane', $payload['username'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertNotEmpty( $payload['occurredAt'] );
		$this->assertSame( array( 'id', 'username', 'role', 'occurredAt' ), array_keys( $payload ) );

		$this->assertSame( array( 'source' => 'admin' ), $context );
		$this->assertSame( array( 'source' ), array_keys( $context ) );

		// user_updated action also dispatched once
		$this->assertCount( 1, $user_calls );
		$this->assertSame( '101', $result['id'] );
	}

	public function test_update_user_by_username_suppresses_password_changed_on_profile_only_update(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'first_name' => 'AdminEditedName' ) );
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$password_calls = $this->record_action( 'password_changed', 2 );
		$user_calls     = $this->record_action( 'user_updated', 3 );

		$this->users_service->update_user_by_username(
			$request,
			'editor_jane',
			array(
				'firstName' => 'AdminEditedName',
			)
		);

		$this->assertCount( 0, $password_calls );
		$this->assertCount( 1, $user_calls );
	}

	public function test_update_user_by_username_suppresses_password_changed_when_not_authorized(): void {
		$request = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )
			->willThrowException( new ApiException( 'Administrator access required.', 403 ) );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->users_service->update_user_by_username(
				$request,
				'editor_jane',
				array(
					'password' => 'AdminResetPassword123!',
				)
			);
		} finally {
			$this->assertCount( 0, $password_calls );
		}
	}

	public function test_update_user_by_username_suppresses_password_changed_on_transaction_rollback_and_fires_once_on_successful_retry(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-07-20 10:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $initial_user,
			)
		);

		$lock_conflict = new PDOException( 'Deadlock found when trying to get lock; try restarting transaction' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$lock_conflict->errorInfo = array( '40001', 1213, 'Deadlock found' );

		$in_tx = false;
		$this->db->method( 'begin_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = true;
			}
		);
		$this->db->method( 'commit' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'roll_back' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'in_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				return $in_tx;
			}
		);

		$update_attempts = 0;
		$this->db->expects( $this->exactly( 2 ) )
			->method( 'update' )
			->willReturnCallback(
				function () use ( &$update_attempts, $lock_conflict ): int {
					$update_attempts++;
					if ( 1 === $update_attempts ) {
						// Lock conflict on attempt 1
						throw $lock_conflict;
					}
					return 1;
				}
			);

		$password_calls = $this->record_action( 'password_changed', 2 );

		$result = $this->users_service->update_user_by_username(
			$request,
			'editor_jane',
			array(
				'role'     => 'editor',
				'password' => 'AdminResetPassword123!',
			)
		);

		// Exactly one notification fired after successful retry
		$this->assertCount( 1, $password_calls );
		$this->assertSame( 'admin', $password_calls[0][1]['source'] );
		$this->assertSame( '101', $result['id'] );
	}

	public function test_update_user_by_username_suppresses_password_changed_when_all_attempts_fail(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $initial_user );

		$this->db->method( 'update' )
			->willThrowException( new PDOException( 'General error: table crashed' ) );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( PDOException::class );

		try {
			$this->users_service->update_user_by_username(
				$request,
				'editor_jane',
				array(
					'password' => 'AdminResetPassword123!',
				)
			);
		} finally {
			$this->assertCount( 0, $password_calls );
		}
	}

	public function test_update_user_by_username_produces_http_409_on_exhausted_lock_conflicts(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $initial_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $initial_user,
			)
		);

		$lock_conflict = new PDOException( 'Deadlock found when trying to get lock; try restarting transaction' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$lock_conflict->errorInfo = array( '40001', 1213, 'Deadlock found' );

		$in_tx = false;
		$this->db->method( 'begin_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = true;
			}
		);
		$this->db->method( 'commit' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'roll_back' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'in_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				return $in_tx;
			}
		);

		$this->db->expects( $this->exactly( 3 ) )
			->method( 'update' )
			->willThrowException( $lock_conflict );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( 'Database lock conflict occurred. Please retry the operation.' );

		try {
			$this->users_service->update_user_by_username(
				$request,
				'editor_jane',
				array(
					'role'     => 'editor',
					'password' => 'AdminResetPassword123!',
				)
			);
		} finally {
			$this->assertCount( 0, $password_calls );
		}
	}

	public function test_delete_user_by_username_produces_http_409_on_exhausted_lock_conflicts(): void {
		$admin_actor = $this->sample_admin();
		$target_user = $this->sample_user();
		$request     = $this->create_request( 'DELETE', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $target_user );
		$this->users_api->method( 'lock_users' )->willReturn(
			array(
				'1'   => $admin_actor,
				'101' => $target_user,
			)
		);

		$lock_conflict = new PDOException( 'Deadlock found when trying to get lock; try restarting transaction' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$lock_conflict->errorInfo = array( '40001', 1213, 'Deadlock found' );

		$in_tx = false;
		$this->db->method( 'begin_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = true;
			}
		);
		$this->db->method( 'commit' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'roll_back' )->willReturnCallback(
			function () use ( &$in_tx ): void {
				$in_tx = false;
			}
		);
		$this->db->method( 'in_transaction' )->willReturnCallback(
			function () use ( &$in_tx ): bool {
				return $in_tx;
			}
		);

		$this->db->expects( $this->exactly( 3 ) )
			->method( 'delete' )
			->willThrowException( $lock_conflict );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( 'Database lock conflict occurred. Please retry the operation.' );

		$this->users_service->delete_user_by_username( $request, 'editor_jane' );
	}

	public function test_update_user_by_username_dispatches_password_changed_when_email_fails(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-07-20 10:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		// Notifications mailer throws
		$this->notifications->method( 'send_password_changed' )
			->willThrowException( new RuntimeException( 'Mailer offline' ) );

		$password_calls = $this->record_action( 'password_changed', 2 );

		$this->users_service->update_user_by_username(
			$request,
			'editor_jane',
			array(
				'password' => 'AdminResetPassword123!',
			)
		);

		$this->assertCount( 1, $password_calls );
		$this->assertSame( 'admin', $password_calls[0][1]['source'] );
	}

	public function test_update_user_by_username_isolates_observer_exceptions_on_password_changed(): void {
		$admin_actor  = $this->sample_admin();
		$initial_user = $this->sample_user();
		$updated_user = array_merge( $initial_user, array( 'updated_at' => '2026-07-20 10:00:00' ) );
		$request      = $this->create_request( 'PUT', '/users/editor_jane' );

		$this->auth_service->method( 'get_admin_user' )->willReturn( $admin_actor );
		$this->users_api->method( 'get_user_by_username' )->with( 'editor_jane' )->willReturn( $initial_user );
		$this->users_api->method( 'get_user' )
			->with( '101' )
			->willReturnOnConsecutiveCalls( $initial_user, $updated_user );
		$this->db->method( 'update' )->willReturn( 1 );

		$observer_invoked = false;
		\add_action(
			'password_changed',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer failure' );
			}
		);

		$result = $this->users_service->update_user_by_username(
			$request,
			'editor_jane',
			array(
				'password' => 'AdminResetPassword123!',
			)
		);

		$this->assertTrue( $observer_invoked );
		$this->assertSame( '101', $result['id'] );
	}

	// =========================================================================
	// Section D: Direct notify_password_changed & Privacy Tests
	// =========================================================================

	public function test_notify_password_changed_strictly_excludes_sensitive_credentials(): void {
		$user_with_secrets = array(
			'id'                        => '999',
			'username'                  => 'leaky_user',
			'role'                      => 'admin',
			'password'                  => 'TopSecretPasswordPlaintext!',
			'password_hash'             => '$2y$10$supersecrethashvaluehere',
			'password_reset_token'      => 'reset_token_secret_123',
			'two_factor_secret'         => 'TOTPSECRETVALUEDOESNOTLEAK',
			'two_factor_pending_secret' => 'PENDINGSECRETVALUEDOESNOTLEAK',
			'backup_codes_json'         => '["code1","code2"]',
			'auth_token'                => 'auth_token_secret_value',
			'session_id'                => 'session_cookie_token_here',
		);

		$calls = $this->record_action( 'password_changed', 2 );

		$this->auth_service->notify_password_changed( $user_with_secrets, 'profile', '2026-08-01 15:30:00' );

		$this->assertCount( 1, $calls );
		list( $payload, $context ) = $calls[0];

		// Allowed keys only
		$this->assertSame(
			array( 'id', 'username', 'role', 'occurredAt' ),
			array_keys( $payload )
		);
		$this->assertSame( '999', $payload['id'] );
		$this->assertSame( 'leaky_user', $payload['username'] );
		$this->assertSame( 'admin', $payload['role'] );
		$this->assertSame( Date::to_iso( '2026-08-01 15:30:00' ), $payload['occurredAt'] );

		$this->assertSame( array( 'source' => 'profile' ), $context );

		// Strictly no credentials
		$this->assertArrayNotHasKey( 'password', $payload );
		$this->assertArrayNotHasKey( 'password_hash', $payload );
		$this->assertArrayNotHasKey( 'password_reset_token', $payload );
		$this->assertArrayNotHasKey( 'two_factor_secret', $payload );
		$this->assertArrayNotHasKey( 'two_factor_pending_secret', $payload );
		$this->assertArrayNotHasKey( 'backup_codes_json', $payload );
		$this->assertArrayNotHasKey( 'auth_token', $payload );
		$this->assertArrayNotHasKey( 'session_id', $payload );
	}

	public function test_notify_password_changed_executes_safely_without_listeners(): void {
		$user = $this->sample_user();

		$this->auth_service->notify_password_changed( $user, 'profile' );

		$this->assertSame( 0, \did_action( 'password_changed' ) );
	}
}
