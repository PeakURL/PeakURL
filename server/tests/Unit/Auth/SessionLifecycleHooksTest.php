<?php
/**
 * Unit tests for Session lifecycle hooks.
 *
 * Verifies that:
 * - session_created fires strictly after confirmed database persistence of a newly generated session
 * - raw session tokens, signed cookies, token hashes, IP addresses, and user agents are excluded from session_created
 * - session_created reflects persistent (remember=true) vs transient (remember=false) session state
 * - session_created is suppressed and throws when database persistence fails
 * - session_revoked fires strictly after confirmed deletion of a specific session via revoke_session or logout
 * - session_revoked receives bounded session identification and distinct reason context (explicit_revocation vs logout)
 * - session_revoked is suppressed when session deletion affects zero rows or when logging out without a session
 * - sessions_revoked fires strictly after confirmed bulk deletion of other active sessions with accurate affected count
 * - sessions_revoked is suppressed when zero other sessions exist or when current session is unidentified
 * - session replacement during login does not emit false revocation actions
 * - sessions_pruned fires when expired or revoked sessions are pruned, reporting accurate deletion count and cutoff
 * - sessions_pruned includes batch limit only when limit is specified
 * - sessions_pruned is suppressed when zero rows are pruned or on database failure
 * - SessionCleanupJob delegates to AuthService and dispatches sessions_pruned exactly once
 * - SessionCleanupJob direct database fallback dispatches sessions_pruned with batch limit
 * - all observers are isolated via try/catch and sanitized logging without altering API behavior
 * - all operations execute safely when no listeners are registered
 *
 * @package PeakURL\Tests\Unit\Auth
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use ArrayObject;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Jobs\SessionCleanupJob;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use RuntimeException;

class SessionLifecycleHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks_service;
	private AuthValidator $validator;
	private Totp $totp;
	private Crypto $crypto;
	private Roles $roles;
	private Authorization $authorization;
	private AuthService $service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db               = $this->createMock( PeakURL_DB::class );
		$this->users_api        = $this->createMock( UsersApi::class );
		$this->credentials      = new Credentials( $this->db );
		$this->notifications    = $this->createMock( Notifications::class );
		$this->geoip            = $this->createMock( Geoip::class );
		$this->webhooks_service = $this->createMock( WebhooksService::class );
		$this->validator        = new AuthValidator();
		$this->totp             = new Totp();
		$this->roles            = new Roles();
		$this->authorization    = new Authorization( $this->roles );

		$config = array(
			Constants::SESSION_COOKIE_NAME => 'peakurl_session',
			Constants::SESSION_LIFETIME    => 2592000,
			Constants::AUTH_KEY            => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
			Constants::AUTH_SALT           => 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
		);

		$this->crypto = new Crypto( $config );

		$this->service = new AuthService(
			$this->db,
			$this->users_api,
			$this->credentials,
			$this->validator,
			$this->totp,
			$this->notifications,
			$this->crypto,
			$this->roles,
			$this->authorization,
			$this->geoip,
			$config,
			$this->webhooks_service
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();

		parent::tearDown();
	}

	/**
	 * Helper to create a standard incoming Request.
	 *
	 * @param array<string, string> $cookies Cookie parameters.
	 * @param array<string, string> $server  Server variables.
	 * @return Request
	 */
	private function make_request( array $cookies = array(), array $server = array() ): Request {
		$default_server = array(
			'HTTP_USER_AGENT' => 'TestBrowser/2.0 (Macintosh; Intel Mac OS X 14_0)',
			'REMOTE_ADDR'     => '198.51.100.42',
			'REQUEST_METHOD'  => 'POST',
			'REQUEST_URI'     => '/api/v1/auth/session',
		);

		return new Request(
			'POST',
			'/api/v1/auth/session',
			array(),
			array(),
			$cookies,
			array(),
			array_merge( $default_server, $server )
		);
	}

	/**
	 * Helper to create a standard user profile row.
	 *
	 * @param string $id       User ID.
	 * @param string $role     User role.
	 * @param string $username Username.
	 * @return array<string, mixed>
	 */
	private function make_user_row(
		string $id = 'user-1',
		string $role = 'admin',
		string $username = 'admin_user'
	): array {
		return array(
			'id'                => $id,
			'username'          => $username,
			'email'             => "{$username}@example.com",
			'role'              => $role,
			'first_name'        => 'Admin',
			'last_name'         => 'Test',
			'display_name'      => 'Admin Test',
			'phone_number'      => '',
			'company'           => '',
			'job_title'         => '',
			'bio'               => '',
			'is_email_verified' => 1,
			'email_verified_at' => '2026-10-24 10:00:00',
			'created_at'        => '2026-10-24 09:00:00',
			'updated_at'        => '2026-10-24 09:00:00',
		);
	}

	// =========================================================================
	// 1. session_created Hook Tests
	// =========================================================================

	public function test_session_created_fires_with_bounded_payload_and_queues_cookie_on_successful_persistent_session(): void {
		$request = $this->make_request();

		$captured_row = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'sessions',
				$this->callback(
					function ( array $row ) use ( &$captured_row ): bool {
						$captured_row = $row;
						return 'user-123' === $row['user_id'];
					}
				)
			)
			->willReturn( 1 );

		$fired_payload = null;
		$fire_count    = 0;

		\add_action(
			'session_created',
			function ( array $payload ) use ( &$fired_payload, &$fire_count ): void {
				$fired_payload = $payload;
				++$fire_count;
			}
		);

		$this->service->create_session_for_user( $request, 'user-123', true );

		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_payload );
		$this->assertSame( $captured_row['id'], $fired_payload['id'] );
		$this->assertSame( 'user-123', $fired_payload['userId'] );
		$this->assertSame( Date::to_iso( (string) $captured_row['created_at'] ), $fired_payload['createdAt'] );
		$this->assertTrue( $fired_payload['remember'] );

		// Strict credential, network, and request exclusion checks.
		$this->assertArrayNotHasKey( 'token', $fired_payload );
		$this->assertArrayNotHasKey( 'rawToken', $fired_payload );
		$this->assertArrayNotHasKey( 'token_hash', $fired_payload );
		$this->assertArrayNotHasKey( 'tokenHash', $fired_payload );
		$this->assertArrayNotHasKey( 'ip_address', $fired_payload );
		$this->assertArrayNotHasKey( 'ipAddress', $fired_payload );
		$this->assertArrayNotHasKey( 'user_agent', $fired_payload );
		$this->assertArrayNotHasKey( 'userAgent', $fired_payload );
		$this->assertArrayNotHasKey( 'browser', $fired_payload );
		$this->assertArrayNotHasKey( 'device', $fired_payload );
		$this->assertArrayNotHasKey( 'operating_system', $fired_payload );

		// Cookie verification: persistent cookie queued with Max-Age.
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'peakurl_session=', $cookies[0] );
		$this->assertStringContainsString( 'Max-Age=2592000', $cookies[0] );
		$this->assertStringContainsString( 'HttpOnly', $cookies[0] );
	}

	public function test_session_created_fires_with_transient_flag_when_remember_is_false(): void {
		$request = $this->make_request();

		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'sessions', $this->isType( 'array' ) )
			->willReturn( 1 );

		$fired_payload = null;
		$fire_count    = 0;

		\add_action(
			'session_created',
			function ( array $payload ) use ( &$fired_payload, &$fire_count ): void {
				$fired_payload = $payload;
				++$fire_count;
			}
		);

		$this->service->create_session_for_user( $request, 'user-456', false );

		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_payload );
		$this->assertSame( 'user-456', $fired_payload['userId'] );
		$this->assertFalse( $fired_payload['remember'] );

		// Transient cookie queued without Max-Age.
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringNotContainsString( 'Max-Age=', $cookies[0] );
	}

	public function test_session_created_suppressed_and_throws_when_database_insertion_fails(): void {
		$request = $this->make_request();

		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'sessions', $this->isType( 'array' ) )
			->willReturn( 0 );

		$fired = false;
		\add_action(
			'session_created',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to persist user session.' );

		try {
			$this->service->create_session_for_user( $request, 'user-123', true );
		} finally {
			$this->assertFalse( $fired );
			$this->assertEmpty( $request->get_response_cookies() );
		}
	}

	public function test_session_created_observer_exception_is_isolated_and_logged(): void {
		$request = $this->make_request();

		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'sessions', $this->isType( 'array' ) )
			->willReturn( 1 );

		$executed = false;
		\add_action(
			'session_created',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'session_created observer explosion' );
			}
		);

		$this->service->create_session_for_user( $request, 'user-123', true );

		$this->assertTrue( $executed );
		$this->assertNotEmpty( $request->get_response_cookies() );
	}

	// =========================================================================
	// 2. session_revoked Hook Tests (Explicit Revocation & Logout)
	// =========================================================================

	public function test_session_revoked_fires_on_explicit_revocation_with_reason_and_user_context(): void {
		$user_row = $this->make_user_row( 'user-789' );
		$token    = 'raw_token_789';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-active',
					'user_id'    => 'user-789',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-789' )
			->willReturn( $user_row );

		// Simulate DELETE affecting 1 row.
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'DELETE FROM sessions' ),
				$this->callback(
					fn( array $params ): bool => 'sess-to-revoke' === $params['id'] && 'user-789' === $params['user_id']
				)
			)
			->willReturn( 1 );

		$fired_session = null;
		$fired_context = null;
		$fire_count    = 0;

		\add_action(
			'session_revoked',
			function ( array $session, array $context ) use ( &$fired_session, &$fired_context, &$fire_count ): void {
				$fired_session = $session;
				$fired_context = $context;
				++$fire_count;
			},
			10,
			2
		);

		$result = $this->service->revoke_session( $request, 'sess-to-revoke' );

		$this->assertTrue( $result );
		$this->assertSame( 1, $fire_count );
		$this->assertSame(
			array(
				'id'     => 'sess-to-revoke',
				'userId' => 'user-789',
			),
			$fired_session
		);
		$this->assertSame(
			array(
				'reason' => 'explicit_revocation',
			),
			$fired_context
		);
	}

	public function test_session_revoked_not_fired_when_explicit_revocation_affects_zero_rows(): void {
		$user_row = $this->make_user_row( 'user-789' );
		$token    = 'raw_token_789';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-active',
					'user_id'    => 'user-789',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-789' )
			->willReturn( $user_row );

		// Simulate DELETE affecting 0 rows (non-existent, expired, or other user's session).
		$this->db->method( 'query' )->willReturn( 0 );

		$fired = false;
		\add_action(
			'session_revoked',
			function () use ( &$fired ): void {
				$fired = true;
			},
			10,
			2
		);

		$result = $this->service->revoke_session( $request, 'sess-missing' );

		$this->assertFalse( $result );
		$this->assertFalse( $fired );
	}

	public function test_session_revoked_observer_exception_is_isolated_on_explicit_revocation(): void {
		$user_row = $this->make_user_row( 'user-789' );
		$token    = 'raw_token_789';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-active',
					'user_id'    => 'user-789',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-789' )
			->willReturn( $user_row );
		$this->db->method( 'query' )->willReturn( 1 );

		$executed = false;
		\add_action(
			'session_revoked',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'session_revoked explicit observer crash' );
			},
			10,
			2
		);

		$result = $this->service->revoke_session( $request, 'sess-1' );

		$this->assertTrue( $result );
		$this->assertTrue( $executed );
	}

	public function test_session_revoked_fires_on_logout_with_valid_session_and_reason_logout(): void {
		$token   = 'token_for_logout';
		$signed  = $this->crypto->sign_session_token( $token );
		$request = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-logout-1',
					'user_id'    => 'user-999',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-999' )
			->willReturn( $this->make_user_row( 'user-999' ) );

		$this->db->expects( $this->once() )
			->method( 'delete' )
			->with( 'sessions', array( 'id' => 'sess-logout-1' ) )
			->willReturn( 1 );

		$fired_session = null;
		$fired_context = null;
		$fire_count    = 0;

		\add_action(
			'session_revoked',
			function ( array $session, array $context ) use ( &$fired_session, &$fired_context, &$fire_count ): void {
				$fired_session = $session;
				$fired_context = $context;
				++$fire_count;
			},
			10,
			2
		);

		$legacy_logout_fired = false;
		\add_action(
			'logout',
			function () use ( &$legacy_logout_fired ): void {
				$legacy_logout_fired = true;
			}
		);

		$this->service->logout( $request );

		$this->assertSame( 1, $fire_count );
		$this->assertSame(
			array(
				'id'     => 'sess-logout-1',
				'userId' => 'user-999',
			),
			$fired_session
		);
		$this->assertSame(
			array(
				'reason' => 'logout',
			),
			$fired_context
		);

		$this->assertTrue( $legacy_logout_fired );

		// Cookie expiration verified.
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'Max-Age=0', $cookies[0] );
	}

	public function test_session_revoked_not_fired_on_logout_when_no_active_session_exists(): void {
		$request = $this->make_request(); // No session cookie.

		$this->db->method( 'get_row' )->willReturn( null );
		$this->db->expects( $this->never() )->method( 'delete' );

		$session_revoked_fired = false;
		\add_action(
			'session_revoked',
			function () use ( &$session_revoked_fired ): void {
				$session_revoked_fired = true;
			},
			10,
			2
		);

		$legacy_logout_fired = false;
		\add_action(
			'logout',
			function () use ( &$legacy_logout_fired ): void {
				$legacy_logout_fired = true;
			}
		);

		$this->service->logout( $request );

		$this->assertFalse( $session_revoked_fired );
		$this->assertTrue( $legacy_logout_fired );

		// Expire cookie is still called.
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'Max-Age=0', $cookies[0] );
	}

	public function test_logout_observer_exception_on_session_revoked_is_isolated_and_preserves_cookie_expiration(): void {
		$token   = 'token_logout_isolated';
		$signed  = $this->crypto->sign_session_token( $token );
		$request = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-isolated',
					'user_id'    => 'user-iso',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-iso' )
			->willReturn( $this->make_user_row( 'user-iso' ) );
		$this->db->method( 'delete' )->willReturn( 1 );

		$executed = false;
		\add_action(
			'session_revoked',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'session_revoked observer crash on logout' );
			},
			10,
			2
		);

		$this->service->logout( $request );

		$this->assertTrue( $executed );
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'Max-Age=0', $cookies[0] );
	}

	public function test_logout_observer_exception_on_legacy_logout_action_is_isolated(): void {
		$request = $this->make_request();

		$executed = false;
		\add_action(
			'logout',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'legacy logout observer crash' );
			}
		);

		$this->service->logout( $request );

		$this->assertTrue( $executed );
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'Max-Age=0', $cookies[0] );
	}

	// =========================================================================
	// 3. sessions_revoked Hook Tests (Bulk Revocation)
	// =========================================================================

	public function test_sessions_revoked_fires_on_revoke_other_sessions_with_affected_count(): void {
		$user_row = $this->make_user_row( 'user-bulk' );
		$token    = 'token_bulk';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'current-sess-id',
					'user_id'    => 'user-bulk',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-bulk' )
			->willReturn( $user_row );

		// Simulate DELETE query removing 4 other sessions.
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'id <> :current_session_id' ),
				$this->callback(
					fn( array $params ): bool => 'current-sess-id' === $params['current_session_id'] && 'user-bulk' === $params['user_id']
				)
			)
			->willReturn( 4 );

		$fired_summary = null;
		$fire_count    = 0;

		\add_action(
			'sessions_revoked',
			function ( array $summary ) use ( &$fired_summary, &$fire_count ): void {
				$fired_summary = $summary;
				++$fire_count;
			}
		);

		$revoked_count = $this->service->revoke_other_sessions( $request );

		$this->assertSame( 4, $revoked_count );
		$this->assertSame( 1, $fire_count );
		$this->assertSame(
			array(
				'userId'  => 'user-bulk',
				'revoked' => 4,
				'reason'  => 'other_sessions',
			),
			$fired_summary
		);
	}

	public function test_sessions_revoked_not_fired_when_zero_other_sessions_deleted(): void {
		$user_row = $this->make_user_row( 'user-bulk' );
		$token    = 'token_bulk_zero';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'current-sess-id',
					'user_id'    => 'user-bulk',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-bulk' )
			->willReturn( $user_row );

		// Simulate DELETE query returning 0.
		$this->db->method( 'query' )->willReturn( 0 );

		$fired = false;
		\add_action(
			'sessions_revoked',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$revoked_count = $this->service->revoke_other_sessions( $request );

		$this->assertSame( 0, $revoked_count );
		$this->assertFalse( $fired );
	}

	public function test_sessions_revoked_suppressed_when_current_session_cannot_be_identified(): void {
		$user_row = $this->make_user_row( 'user-bulk' );

		// Create request with Bearer token for API user auth without a session cookie.
		$auth_request = new Request(
			'POST',
			'/api/v1/auth/sessions/revoke-others',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_AUTHORIZATION' => 'Bearer valid_api_key_test',
				'REMOTE_ADDR'        => '127.0.0.1',
			)
		);

		$this->db->method( 'get_row' )
			->willReturnCallback(
				function ( string $sql ) use ( $user_row ): ?array {
					if ( str_contains( $sql, 'FROM api_keys' ) ) {
						return $user_row;
					}
					return null;
				}
			);

		$fired = false;
		\add_action(
			'sessions_revoked',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->revoke_other_sessions( $auth_request );
		} finally {
			$this->assertFalse( $fired );
		}
	}

	public function test_sessions_revoked_observer_exception_is_isolated(): void {
		$user_row = $this->make_user_row( 'user-bulk' );
		$token    = 'token_bulk';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'current-sess-id',
					'user_id'    => 'user-bulk',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )
			->with( 'user-bulk' )
			->willReturn( $user_row );
		$this->db->method( 'query' )->willReturn( 2 );

		$executed = false;
		\add_action(
			'sessions_revoked',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'sessions_revoked observer exploded' );
			}
		);

		$count = $this->service->revoke_other_sessions( $request );

		$this->assertSame( 2, $count );
		$this->assertTrue( $executed );
	}

	// =========================================================================
	// 4. Session Replacement Semantics Tests
	// =========================================================================

	public function test_session_replacement_during_login_does_not_fire_revocation_actions(): void {
		$old_token  = 'old_browser_token';
		$old_signed = $this->crypto->sign_session_token( $old_token );
		$request    = $this->make_request( array( 'peakurl_session' => $old_signed ) );

		// Simulate finding prior session for replacement cleanup.
		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'old-sess-to-replace',
					'user_id'    => 'user-1',
					'token_hash' => Credentials::hash_token( $old_token ),
				)
			);

		$this->db->method( 'delete' )->willReturn( 1 );
		$this->db->method( 'query' )->willReturn( 1 );
		$this->db->method( 'insert' )->willReturn( 1 );

		$session_revoked_fired  = false;
		$sessions_revoked_fired = false;
		$session_created_fired  = false;

		\add_action(
			'session_revoked',
			function () use ( &$session_revoked_fired ): void {
				$session_revoked_fired = true;
			},
			10,
			2
		);

		\add_action(
			'sessions_revoked',
			function () use ( &$sessions_revoked_fired ): void {
				$sessions_revoked_fired = true;
			}
		);

		\add_action(
			'session_created',
			function () use ( &$session_created_fired ): void {
				$session_created_fired = true;
			}
		);

		$this->service->create_session_for_user( $request, 'user-1', true );

		// Neither revocation hook must fire on internal replacement.
		$this->assertFalse( $session_revoked_fired );
		$this->assertFalse( $sessions_revoked_fired );

		// Only session_created must fire.
		$this->assertTrue( $session_created_fired );
	}

	// =========================================================================
	// 5. sessions_pruned Hook Tests (Maintenance & Scheduler Cleanup)
	// =========================================================================

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sessions_pruned_fires_on_auth_service_prune_stale_sessions_with_deleted_count_and_cutoff(): void {
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'DELETE FROM sessions' ),
				$this->isType( 'array' )
			)
			->willReturn( 7 );

		$fired_summary = null;
		$fire_count    = 0;

		\add_action(
			'sessions_pruned',
			function ( array $summary ) use ( &$fired_summary, &$fire_count ): void {
				$fired_summary = $summary;
				++$fire_count;
			}
		);

		// prune_stale_sessions with limit = null.
		$count = $this->service->prune_stale_sessions( null );

		$this->assertSame( 7, $count );
		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_summary );
		$this->assertSame( 7, $fired_summary['deletedSessions'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $fired_summary['cutoff'] );
		// When limit is null, limit key is not included.
		$this->assertArrayNotHasKey( 'limit', $fired_summary );
	}

	public function test_sessions_pruned_includes_limit_when_batch_limit_specified(): void {
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'LIMIT 500' ),
				$this->isType( 'array' )
			)
			->willReturn( 12 );

		$fired_summary = null;
		$fire_count    = 0;

		\add_action(
			'sessions_pruned',
			function ( array $summary ) use ( &$fired_summary, &$fire_count ): void {
				$fired_summary = $summary;
				++$fire_count;
			}
		);

		$count = $this->service->prune_stale_sessions( 500 );

		$this->assertSame( 12, $count );
		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_summary );
		$this->assertSame( 12, $fired_summary['deletedSessions'] );
		$this->assertSame( 500, $fired_summary['limit'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $fired_summary['cutoff'] );
	}

	public function test_sessions_pruned_not_fired_when_zero_stale_sessions_pruned(): void {
		$this->db->method( 'query' )->willReturn( 0 );

		$fired = false;
		\add_action(
			'sessions_pruned',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$count = $this->service->prune_stale_sessions( 1000 );

		$this->assertSame( 0, $count );
		$this->assertFalse( $fired );
	}

	public function test_sessions_pruned_observer_exception_is_isolated(): void {
		$this->db->method( 'query' )->willReturn( 3 );

		$executed = false;
		\add_action(
			'sessions_pruned',
			function () use ( &$executed ): void {
				$executed = true;
				throw new RuntimeException( 'sessions_pruned observer failed' );
			}
		);

		$count = $this->service->prune_stale_sessions( 100 );

		$this->assertSame( 3, $count );
		$this->assertTrue( $executed );
	}

	public function test_session_cleanup_job_with_auth_service_delegates_and_fires_sessions_pruned_exactly_once(): void {
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'LIMIT 1000' ),
				$this->isType( 'array' )
			)
			->willReturn( 8 );

		$job     = new SessionCleanupJob( $this->db, array(), $this->service );
		$context = new ExecutionContext( 'peakurl_session_cleanup', 'run_test_1', 1, false, Date::now() );

		$fired_summary = null;
		$fire_count    = 0;

		\add_action(
			'sessions_pruned',
			function ( array $summary ) use ( &$fired_summary, &$fire_count ): void {
				$fired_summary = $summary;
				++$fire_count;
			}
		);

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_summary );
		$this->assertSame( 8, $fired_summary['deletedSessions'] );
		$this->assertSame( 1000, $fired_summary['limit'] );
	}

	public function test_session_cleanup_job_database_fallback_fires_sessions_pruned_when_auth_service_is_null(): void {
		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->stringContains( 'LIMIT 1000' ),
				$this->isType( 'array' )
			)
			->willReturn( 15 );

		// Null auth_service forces the direct DB fallback branch.
		$job     = new SessionCleanupJob( $this->db, array( Constants::SESSION_LIFETIME => 86400 ), null );
		$context = new ExecutionContext( 'peakurl_session_cleanup', 'run_test_fallback', 1, false, Date::now() );

		$fired_summary = null;
		$fire_count    = 0;

		\add_action(
			'sessions_pruned',
			function ( array $summary ) use ( &$fired_summary, &$fire_count ): void {
				$fired_summary = $summary;
				++$fire_count;
			}
		);

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $fire_count );
		$this->assertNotNull( $fired_summary );
		$this->assertSame( 15, $fired_summary['deletedSessions'] );
		$this->assertSame( 1000, $fired_summary['limit'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $fired_summary['cutoff'] );
	}

	public function test_session_cleanup_job_database_fallback_with_zero_deleted_does_not_fire_sessions_pruned(): void {
		$this->db->method( 'query' )->willReturn( 0 );

		$job     = new SessionCleanupJob( $this->db, array(), null );
		$context = new ExecutionContext( 'peakurl_session_cleanup', 'run_zero', 1, false, Date::now() );

		$fired = false;
		\add_action(
			'sessions_pruned',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $fired );
	}

	public function test_session_cleanup_job_database_failure_suppresses_sessions_pruned_and_propagates_exception(): void {
		$this->db->expects( $this->once() )
			->method( 'query' )
			->willThrowException( new RuntimeException( 'Database connection severed.' ) );

		$job     = new SessionCleanupJob( $this->db, array(), null );
		$context = new ExecutionContext( 'peakurl_session_cleanup', 'run_err', 1, false, Date::now() );

		$fired = false;
		\add_action(
			'sessions_pruned',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Database connection severed.' );

		try {
			$job->execute( $context );
		} finally {
			$this->assertFalse( $fired );
		}
	}

	// =========================================================================
	// 6. Safety & Return Compatibility Tests
	// =========================================================================

	public function test_no_listeners_registered_executes_safely_across_all_session_lifecycle_methods(): void {
		// Ensure no actions registered.
		Hooks::remove_all();

		$user_row = $this->make_user_row( 'user-safe' );
		$token    = 'token_safe';
		$signed   = $this->crypto->sign_session_token( $token );
		$request  = $this->make_request( array( 'peakurl_session' => $signed ) );

		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'query' )->willReturn( 2 );
		$this->db->method( 'delete' )->willReturn( 1 );
		$this->db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'sess-safe',
					'user_id'    => 'user-safe',
					'token_hash' => Credentials::hash_token( $token ),
				)
			);
		$this->users_api->method( 'get_user' )->willReturn( $user_row );

		// create_session_for_user
		$this->service->create_session_for_user( $request, 'user-safe', true );
		$this->assertNotEmpty( $request->get_response_cookies() );

		// revoke_session
		$this->assertTrue( $this->service->revoke_session( $request, 'sess-safe' ) );

		// revoke_other_sessions
		$this->assertSame( 2, $this->service->revoke_other_sessions( $request ) );

		// prune_stale_sessions
		$this->assertSame( 2, $this->service->prune_stale_sessions( 100 ) );

		// logout
		$this->service->logout( $request );
	}

	public function test_login_flow_response_compatibility_and_session_created_integration(): void {
		$user_row = $this->make_user_row( 'user-login', 'admin', 'loginuser' );
		$request  = $this->make_request();

		$this->db->method( 'get_row' )
			->willReturnCallback(
				function ( string $sql ) use ( $user_row ): ?array {
					if ( str_contains( $sql, 'FROM users' ) ) {
						return array_merge(
							$user_row,
							array(
								'password_hash'      => password_hash( 'ValidPassword123!', PASSWORD_DEFAULT ),
								'two_factor_enabled' => 0,
							)
						);
					}
					return null;
				}
			);
		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'query' )->willReturn( 0 );
		$this->users_api->method( 'get_user' )->willReturn( $user_row );

		$created_payload = null;
		\add_action(
			'session_created',
			function ( array $payload ) use ( &$created_payload ): void {
				$created_payload = $payload;
			}
		);

		$result = $this->service->login(
			$request,
			array(
				'identifier' => 'loginuser',
				'password'   => 'ValidPassword123!',
				'rememberMe' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['requiresTwoFactor'] );
		$this->assertSame( 'user-login', $result['user']['id'] );
		$this->assertSame( 'loginuser', $result['user']['username'] );

		// session_created action dispatched.
		$this->assertNotNull( $created_payload );
		$this->assertSame( 'user-login', $created_payload['userId'] );
		$this->assertTrue( $created_payload['remember'] );

		// Session cookie queued.
		$cookies = $request->get_response_cookies();
		$this->assertCount( 1, $cookies );
		$this->assertStringContainsString( 'peakurl_session=', $cookies[0] );
	}
}
