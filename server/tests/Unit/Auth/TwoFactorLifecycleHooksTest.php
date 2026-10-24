<?php
/**
 * Unit tests for Two-Factor Authentication lifecycle hooks.
 *
 * Verifies that:
 * - two_factor_enabled fires strictly after confirmed database transaction commit
 * - two_factor_enabled observer receives bounded metadata without credentials or backup codes
 * - two_factor_enabled is suppressed on invalid tokens, missing pending secrets, or failed activation
 * - two_factor_disabled fires strictly after confirmed database update transition (1 affected row)
 * - two_factor_disabled observer receives bounded metadata without credentials
 * - two_factor_disabled is suppressed on invalid password, already disabled accounts, or concurrent disablement
 * - two_factor_backup_codes_regenerated fires strictly after confirmed database persistence of regenerated codes
 * - two_factor_backup_codes_regenerated observer receives bounded summary without plaintext codes
 * - two_factor_backup_codes_regenerated is suppressed on invalid password, disabled 2FA, or persistence failure
 * - all observers are isolated via try/catch and error logging without altering API outcomes
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
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use RuntimeException;

class TwoFactorLifecycleHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks_service;
	private AuthValidator $validator;
	private MockObject&Totp $totp;
	private Crypto $crypto;
	private Roles $roles;
	private Authorization $authorization;
	private MockObject&AuthService $service;

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
		$this->totp             = $this->createMock( Totp::class );
		$this->roles            = new Roles();
		$this->authorization    = new Authorization( $this->roles );

		$config = array(
			Constants::SESSION_COOKIE_NAME => 'peakurl_session',
			Constants::SESSION_LIFETIME    => 2592000,
			Constants::AUTH_KEY            => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
			Constants::AUTH_SALT           => 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
		);

		$this->crypto = new Crypto( $config );

		$this->service = $this->getMockBuilder( AuthService::class )
			->setConstructorArgs(
				array(
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
					$this->webhooks_service,
				)
			)
			->onlyMethods( array( 'get_current_user' ) )
			->getMock();
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();

		parent::tearDown();
	}

	/**
	 * Create sample user row array.
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
				'password_hash'             => password_hash( 'valid-current-password', PASSWORD_DEFAULT ),
				'two_factor_enabled'        => 0,
				'two_factor_secret'         => null,
				'two_factor_pending_secret' => 'BASE32PENDINGSECRETVAL123456',
				'backup_codes_json'         => null,
				'backup_codes_generated_at' => null,
				'created_at'                => '2026-01-01 00:00:00',
				'updated_at'                => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	/**
	 * Create a dummy incoming request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 * @return Request
	 */
	private function create_request( string $method = 'POST', string $path = '/two-factor/verify' ): Request {
		return new Request( $method, $path, array(), array() );
	}

	/**
	 * Record dispatched action arguments.
	 *
	 * @param string $hook_name     Action hook name.
	 * @param int    $accepted_args Number of accepted arguments.
	 * @return ArrayObject<int, array<int, mixed>>
	 */
	private function record_action( string $hook_name, int $accepted_args = 1 ): ArrayObject {
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
	// Two-Factor Enabled (two_factor_enabled) Tests
	// =========================================================================

	public function test_verify_two_factor_dispatches_action_after_commit_with_bounded_payload(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->expects( $this->once() )
			->method( 'verify_code' )
			->with( 'BASE32PENDINGSECRETVAL123456', '654321' )
			->willReturn( true );

		$execution_order = array();

		$this->db->expects( $this->once() )
			->method( 'begin_transaction' )
			->willReturnCallback(
				function () use ( &$execution_order ): void {
					$execution_order[] = 'begin_transaction';
				}
			);

		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->callback(
					function ( string $sql ): bool {
						return str_contains( $sql, 'UPDATE users' )
							&& str_contains( $sql, 'AND two_factor_pending_secret = :pending_secret' )
							&& str_contains( $sql, 'AND two_factor_enabled = 0' );
					}
				),
				$this->callback(
					function ( array $params ): bool {
						return '101' === $params['id']
							&& 'BASE32PENDINGSECRETVAL123456' === $params['secret']
							&& 'BASE32PENDINGSECRETVAL123456' === $params['pending_secret']
							&& isset( $params['updated_at'] );
					}
				)
			)
			->willReturnCallback(
				function () use ( &$execution_order ) {
					$execution_order[] = 'query_update';
					return 1;
				}
			);

		$this->db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback(
					function ( array $data ): bool {
						return isset( $data['backup_codes_json'] )
							&& isset( $data['backup_codes_generated_at'] )
							&& isset( $data['updated_at'] );
					}
				),
				array( 'id' => '101' )
			)
			->willReturnCallback(
				function () use ( &$execution_order ) {
					$execution_order[] = 'update_backup_codes';
					return 1;
				}
			);

		$this->db->expects( $this->once() )
			->method( 'commit' )
			->willReturnCallback(
				function () use ( &$execution_order ): void {
					$execution_order[] = 'commit';
				}
			);

		$calls = $this->record_action( 'two_factor_enabled', 1 );
		\add_action(
			'two_factor_enabled',
			function () use ( &$execution_order ): void {
				$execution_order[] = 'two_factor_enabled_action';
			},
			9
		);

		$codes = $this->service->verify_two_factor( $request, '654321' );

		// Confirm execution ordering: action fired strictly AFTER transaction commit
		$this->assertSame(
			array( 'begin_transaction', 'query_update', 'update_backup_codes', 'commit', 'two_factor_enabled_action' ),
			$execution_order
		);

		// Confirm observer called exactly once
		$this->assertCount( 1, $calls );
		$payload = $calls[0][0];

		// Check payload properties and bounds
		$this->assertSame( '101', $payload['id'] );
		$this->assertSame( 'editor_jane', $payload['username'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['occurredAt'] );
		$this->assertSame(
			array( 'id', 'username', 'role', 'occurredAt' ),
			array_keys( $payload )
		);

		// Verify confidentiality: sensitive credentials strictly excluded from payload
		$this->assertArrayNotHasKey( 'two_factor_secret', $payload );
		$this->assertArrayNotHasKey( 'two_factor_pending_secret', $payload );
		$this->assertArrayNotHasKey( 'secret', $payload );
		$this->assertArrayNotHasKey( 'token', $payload );
		$this->assertArrayNotHasKey( 'codes', $payload );
		$this->assertArrayNotHasKey( 'backup_codes', $payload );
		$this->assertArrayNotHasKey( 'password_hash', $payload );

		// Confirm return value contains plaintext backup codes
		$this->assertCount( 8, $codes );
		$this->assertSame( 8, count( array_unique( $codes ) ) );
	}

	public function test_verify_two_factor_fails_when_token_is_empty(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'begin_transaction' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Verification token is required.' );

		try {
			$this->service->verify_two_factor( $request, '' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_fails_when_token_is_whitespace_only(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'begin_transaction' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Verification token is required.' );

		try {
			$this->service->verify_two_factor( $request, '   ' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_fails_when_no_pending_setup(): void {
		$user    = $this->sample_user( array( 'two_factor_pending_secret' => null ) );
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'begin_transaction' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'No two-factor setup is pending for this account.' );

		try {
			$this->service->verify_two_factor( $request, '123456' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_fails_when_totp_code_is_invalid(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->expects( $this->once() )
			->method( 'verify_code' )
			->with( 'BASE32PENDINGSECRETVAL123456', '000000' )
			->willReturn( false );

		$this->db->expects( $this->never() )->method( 'begin_transaction' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Invalid verification code.' );

		try {
			$this->service->verify_two_factor( $request, '000000' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_rolls_back_and_suppresses_action_on_concurrent_activation(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->method( 'verify_code' )->willReturn( true );

		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 0 );
		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Two-factor setup is no longer pending or has already been enabled.' );

		try {
			$this->service->verify_two_factor( $request, '654321' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_rolls_back_and_suppresses_action_on_database_query_exception(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->method( 'verify_code' )->willReturn( true );

		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )
			->method( 'query' )
			->willThrowException( new PDOException( 'Lock wait timeout exceeded' ) );
		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( PDOException::class );

		try {
			$this->service->verify_two_factor( $request, '654321' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_rolls_back_and_suppresses_action_on_backup_codes_persistence_failure(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->method( 'verify_code' )->willReturn( true );

		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 1 );
		// Backup codes update affects 0 rows, triggering RuntimeException in Credentials
		$this->db->expects( $this->once() )->method( 'update' )->willReturn( 0 );
		$this->db->method( 'in_transaction' )->willReturn( true );
		$this->db->expects( $this->once() )->method( 'roll_back' );
		$this->db->expects( $this->never() )->method( 'commit' );

		$calls = $this->record_action( 'two_factor_enabled', 1 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to persist backup codes.' );

		try {
			$this->service->verify_two_factor( $request, '654321' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_verify_two_factor_isolates_observer_exceptions(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->method( 'verify_code' )->willReturn( true );

		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 1 );
		$this->db->expects( $this->once() )->method( 'update' )->willReturn( 1 );
		$this->db->expects( $this->once() )->method( 'commit' );

		$observer_invoked = false;
		\add_action(
			'two_factor_enabled',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer threw unexpected error' );
			}
		);

		$codes = $this->service->verify_two_factor( $request, '654321' );

		// Confirm throwing observer was invoked and execution continued safely
		$this->assertTrue( $observer_invoked );
		$this->assertCount( 8, $codes );
	}

	public function test_verify_two_factor_executes_safely_without_listeners(): void {
		$user    = $this->sample_user();
		$request = $this->create_request( 'POST', '/two-factor/verify' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->totp->method( 'verify_code' )->willReturn( true );

		$this->db->expects( $this->once() )->method( 'begin_transaction' );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 1 );
		$this->db->expects( $this->once() )->method( 'update' )->willReturn( 1 );
		$this->db->expects( $this->once() )->method( 'commit' );

		$codes = $this->service->verify_two_factor( $request, '654321' );

		$this->assertCount( 8, $codes );
	}

	// =========================================================================
	// Two-Factor Disabled (two_factor_disabled) Tests
	// =========================================================================

	public function test_disable_two_factor_dispatches_action_after_confirmed_update(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );

		$this->db->expects( $this->once() )
			->method( 'query' )
			->with(
				$this->callback(
					function ( string $sql ): bool {
						return str_contains( $sql, 'UPDATE users' )
							&& str_contains( $sql, 'AND two_factor_enabled = 1' );
					}
				),
				$this->callback(
					function ( array $params ): bool {
						return '101' === $params['id'] && isset( $params['updated_at'] );
					}
				)
			)
			->willReturn( 1 );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->service->disable_two_factor( $request, 'valid-current-password' );

		// Confirm observer called exactly once
		$this->assertCount( 1, $calls );
		$payload = $calls[0][0];

		// Check payload properties and bounds
		$this->assertSame( '101', $payload['id'] );
		$this->assertSame( 'editor_jane', $payload['username'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['occurredAt'] );
		$this->assertSame(
			array( 'id', 'username', 'role', 'occurredAt' ),
			array_keys( $payload )
		);

		// Verify confidentiality: sensitive credentials strictly excluded from payload
		$this->assertArrayNotHasKey( 'two_factor_secret', $payload );
		$this->assertArrayNotHasKey( 'two_factor_pending_secret', $payload );
		$this->assertArrayNotHasKey( 'secret', $payload );
		$this->assertArrayNotHasKey( 'backup_codes_json', $payload );
		$this->assertArrayNotHasKey( 'password_hash', $payload );
	}

	public function test_disable_two_factor_fails_when_password_is_empty(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'query' );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Current password is required to disable two-factor authentication.' );

		try {
			$this->service->disable_two_factor( $request, '' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_disable_two_factor_fails_when_password_is_incorrect(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'query' );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Current password is incorrect.' );

		try {
			$this->service->disable_two_factor( $request, 'wrong-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_disable_two_factor_fails_when_two_factor_is_not_enabled(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 0 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'query' );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Two-factor authentication is not enabled for this account.' );

		try {
			$this->service->disable_two_factor( $request, 'valid-current-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_disable_two_factor_suppresses_action_on_concurrent_disablement(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );

		// Concurrent update set two_factor_enabled = 0 first, so update affects 0 rows
		$this->db->expects( $this->once() )
			->method( 'query' )
			->willReturn( 0 );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->service->disable_two_factor( $request, 'valid-current-password' );

		// Action must not fire when zero rows were updated
		$this->assertCount( 0, $calls );
	}

	public function test_disable_two_factor_suppresses_action_on_database_exception(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );

		$this->db->expects( $this->once() )
			->method( 'query' )
			->willThrowException( new PDOException( 'Connection dropped' ) );

		$calls = $this->record_action( 'two_factor_disabled', 1 );

		$this->expectException( PDOException::class );

		try {
			$this->service->disable_two_factor( $request, 'valid-current-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_disable_two_factor_isolates_observer_exceptions(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 1 );

		$observer_invoked = false;
		\add_action(
			'two_factor_disabled',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer failure' );
			}
		);

		// Must invoke observer and complete cleanly without rethrowing observer exception
		$this->service->disable_two_factor( $request, 'valid-current-password' );
		$this->assertTrue( $observer_invoked );
	}

	public function test_disable_two_factor_executes_safely_without_listeners(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/disable' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'query' )->willReturn( 1 );

		$this->service->disable_two_factor( $request, 'valid-current-password' );

		$this->assertSame( 0, \did_action( 'two_factor_disabled' ) );
	}

	// =========================================================================
	// Two-Factor Backup Codes Regenerated Tests
	// =========================================================================

	public function test_regenerate_backup_codes_dispatches_action_after_confirmed_persistence(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );

		$this->db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback(
					function ( array $data ): bool {
						return isset( $data['backup_codes_json'] )
							&& isset( $data['backup_codes_generated_at'] )
							&& isset( $data['updated_at'] );
					}
				),
				array(
					'id'                 => '101',
					'two_factor_enabled' => 1,
				)
			)
			->willReturn( 1 );

		$calls = $this->record_action( 'two_factor_backup_codes_regenerated', 1 );

		$codes = $this->service->regenerate_backup_codes( $request, 'valid-current-password' );

		// Confirm observer called exactly once
		$this->assertCount( 1, $calls );
		$summary = $calls[0][0];

		// Check summary properties and bounds
		$this->assertSame( '101', $summary['userId'] );
		$this->assertSame( 8, $summary['generated'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $summary['occurredAt'] );
		$this->assertSame(
			array( 'userId', 'generated', 'occurredAt' ),
			array_keys( $summary )
		);

		// Verify confidentiality: plaintext backup codes strictly excluded from summary
		$this->assertArrayNotHasKey( 'codes', $summary );
		$this->assertArrayNotHasKey( 'backup_codes', $summary );
		$this->assertArrayNotHasKey( 'backup_codes_json', $summary );

		// Verify plaintext backup codes are returned to caller
		$this->assertCount( 8, $codes );
		$this->assertSame( 8, count( array_unique( $codes ) ) );
	}

	public function test_regenerate_backup_codes_fails_when_password_is_empty(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'update' );

		$calls = $this->record_action( 'two_factor_backup_codes_regenerated', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Current password is required to regenerate backup codes.' );

		try {
			$this->service->regenerate_backup_codes( $request, '' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_regenerate_backup_codes_fails_when_password_is_incorrect(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'update' );

		$calls = $this->record_action( 'two_factor_backup_codes_regenerated', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Current password is incorrect.' );

		try {
			$this->service->regenerate_backup_codes( $request, 'wrong-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_regenerate_backup_codes_fails_when_two_factor_is_not_enabled(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 0 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'update' );

		$calls = $this->record_action( 'two_factor_backup_codes_regenerated', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );
		$this->expectExceptionMessage( 'Two-factor authentication is not enabled for this account.' );

		try {
			$this->service->regenerate_backup_codes( $request, 'valid-current-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_regenerate_backup_codes_suppresses_action_on_concurrent_disablement_or_persistence_failure(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );

		// Update affects 0 rows because two_factor_enabled became 0 concurrently
		$this->db->expects( $this->once() )
			->method( 'update' )
			->willReturn( 0 );

		$calls = $this->record_action( 'two_factor_backup_codes_regenerated', 1 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to persist backup codes.' );

		try {
			$this->service->regenerate_backup_codes( $request, 'valid-current-password' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_regenerate_backup_codes_isolates_observer_exceptions(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'update' )->willReturn( 1 );

		$observer_invoked = false;
		\add_action(
			'two_factor_backup_codes_regenerated',
			function () use ( &$observer_invoked ): void {
				$observer_invoked = true;
				throw new RuntimeException( 'Observer failure' );
			}
		);

		$codes = $this->service->regenerate_backup_codes( $request, 'valid-current-password' );

		// Confirm throwing observer was invoked and execution continued safely
		$this->assertTrue( $observer_invoked );
		$this->assertCount( 8, $codes );
	}

	public function test_regenerate_backup_codes_executes_safely_without_listeners(): void {
		$user    = $this->sample_user( array( 'two_factor_enabled' => 1 ) );
		$request = $this->create_request( 'POST', '/two-factor/backup-codes/regenerate' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user );
		$this->db->expects( $this->once() )->method( 'update' )->willReturn( 1 );

		$codes = $this->service->regenerate_backup_codes( $request, 'valid-current-password' );

		$this->assertCount( 8, $codes );
	}

	// =========================================================================
	// Credentials replace_backup_codes Unit Tests
	// =========================================================================

	public function test_credentials_replace_backup_codes_updates_without_require_enabled(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->callback(
					function ( array $data ): bool {
						return isset( $data['backup_codes_json'] )
							&& isset( $data['backup_codes_generated_at'] )
							&& isset( $data['updated_at'] );
					}
				),
				array( 'id' => 'user-42' )
			)
			->willReturn( 1 );

		$codes = $credentials->replace_backup_codes( 'user-42', false );

		$this->assertCount( 8, $codes );
		$this->assertSame( 8, count( array_unique( $codes ) ) );
	}

	public function test_credentials_replace_backup_codes_includes_two_factor_enabled_in_where_when_required(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$db->expects( $this->once() )
			->method( 'update' )
			->with(
				'users',
				$this->isType( 'array' ),
				array(
					'id'                 => 'user-42',
					'two_factor_enabled' => 1,
				)
			)
			->willReturn( 1 );

		$codes = $credentials->replace_backup_codes( 'user-42', true );

		$this->assertCount( 8, $codes );
	}

	public function test_credentials_replace_backup_codes_throws_when_update_affects_zero_rows(): void {
		$db          = $this->createMock( PeakURL_DB::class );
		$credentials = new Credentials( $db );

		$db->expects( $this->once() )
			->method( 'update' )
			->willReturn( 0 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to persist backup codes.' );

		$credentials->replace_backup_codes( 'user-42', true );
	}
}
