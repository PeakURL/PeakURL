<?php
/**
 * Unit tests for user_email_verified lifecycle hook.
 *
 * Verifies that:
 * - user_email_verified fires strictly after atomic, concurrency-safe email verification
 * - listener receives bounded user payload containing only safe identity fields
 * - identity fields come from the matched token lookup and emailVerifiedAt from confirmed write
 * - no unnecessary secondary database query is executed after confirmed atomic write
 * - empty, invalid, and expired tokens throw 422 ApiException without firing the hook
 * - concurrent redemptions or zero affected rows throw 422 ApiException without firing the hook
 * - token invalidations between lookup and update throw 422 ApiException without firing the hook
 * - database exceptions propagate and prevent hook dispatch
 * - observer exceptions are isolated and do not disrupt successful email verification
 * - credentials, tokens, hashes, and 2FA secrets are strictly excluded from the payload
 *
 * @package PeakURL\Tests\Unit\Auth
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use ArrayObject;
use PDOException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use RuntimeException;

class UserEmailVerifiedHookTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private MockObject&Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
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

		$this->db            = $this->createMock( PeakURL_DB::class );
		$this->users_api     = $this->createMock( UsersApi::class );
		$this->credentials   = $this->createMock( Credentials::class );
		$this->notifications = $this->createMock( Notifications::class );
		$this->geoip         = $this->createMock( Geoip::class );
		$this->validator     = new AuthValidator();
		$this->totp          = new Totp();
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );
		$this->crypto        = new Crypto( array() );

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
			array()
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
				'id'                            => '201',
				'username'                      => 'verify_tester',
				'email'                         => 'verify@example.com',
				'first_name'                    => 'Verify',
				'last_name'                     => 'Tester',
				'display_name'                  => 'Verify Tester',
				'password_hash'                 => '$2y$10$super_secret_password_hash_value',
				'two_factor_secret'             => 'SECRET2FAVALUEDOESNOTLEAK',
				'two_factor_pending_secret'     => null,
				'backup_codes_json'             => '["codeA","codeB"]',
				'role'                          => 'editor',
				'is_email_verified'             => 0,
				'email_verification_token'      => Credentials::hash_lookup_token( 'test_token_123' ),
				'email_verification_expires_at' => '2026-12-31 23:59:59',
				'email_verified_at'             => null,
				'created_at'                    => '2026-01-01 00:00:00',
				'updated_at'                    => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

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

	public function test_successful_email_verification_fires_user_email_verified_with_bounded_payload(): void {
		$raw_token   = 'valid_token_xyz_456';
		$token_hash  = Credentials::hash_lookup_token( $raw_token );
		$lookup_user = $this->sample_user_row(
			array(
				'id'                       => '301',
				'username'                 => 'confirmed_user',
				'email'                    => 'confirmed@example.com',
				'role'                     => 'editor',
				'email_verification_token' => $token_hash,
			)
		);

		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		$this->db->method( 'query' )->willReturn( 1 );

		// Verify UsersApi::get_user is never called after successful write
		$this->users_api->expects( $this->never() )->method( 'get_user' );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$result = $this->service->verify_email( $raw_token );

		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );

		$payload = $calls[0][0];

		$this->assertSame( '301', $payload['id'] );
		$this->assertSame( 'confirmed_user', $payload['username'] );
		$this->assertSame( 'confirmed@example.com', $payload['email'] );
		$this->assertSame( 'editor', $payload['role'] );
		$this->assertNotEmpty( $payload['emailVerifiedAt'] );
	}

	public function test_empty_or_whitespace_token_throws_and_does_not_fire_hook(): void {
		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->db->expects( $this->never() )->method( 'get_row' );
		$this->db->expects( $this->never() )->method( 'query' );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->verify_email( '   ' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_unknown_token_throws_and_does_not_fire_hook(): void {
		$this->db->method( 'get_row' )->willReturn( null );
		$this->db->expects( $this->never() )->method( 'query' );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->verify_email( 'non_existent_token_value' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_expired_token_filtered_by_lookup_throws_and_does_not_fire_hook(): void {
		// When token is expired, database query returns null due to email_verification_expires_at > :now condition
		$this->db->method( 'get_row' )->willReturn( null );
		$this->db->expects( $this->never() )->method( 'query' );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->verify_email( 'expired_token_value' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_concurrent_redemption_or_already_verified_zero_affected_throws_and_suppresses_hook(): void {
		$lookup_user = $this->sample_user_row();
		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		// Another concurrent request redeemed the token; atomic update updates 0 rows
		$this->db->method( 'query' )->willReturn( 0 );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->verify_email( 'test_token_123' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_token_invalidated_concurrently_between_lookup_and_update_throws_and_suppresses_hook(): void {
		$lookup_user = $this->sample_user_row();
		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		// Token was re-issued or changed; atomic UPDATE matches 0 rows
		$this->db->method( 'query' )->willReturn( 0 );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 422 );

		try {
			$this->service->verify_email( 'test_token_123' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_database_query_exception_suppresses_hook(): void {
		$lookup_user = $this->sample_user_row();
		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		$this->db->method( 'query' )->willThrowException(
			new PDOException( 'Database connection dropped' )
		);

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->expectException( PDOException::class );

		try {
			$this->service->verify_email( 'test_token_123' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_observer_exception_is_isolated_and_does_not_fail_verification(): void {
		$lookup_user = $this->sample_user_row();

		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		$this->db->method( 'query' )->willReturn( 1 );

		// Register an observer that throws
		\add_action(
			'user_email_verified',
			function (): void {
				throw new RuntimeException( 'Audit logger hook crashed' );
			}
		);

		// Execution must succeed and return true without throwing
		$result = $this->service->verify_email( 'test_token_123' );
		$this->assertTrue( $result );
	}

	public function test_payload_strictly_excludes_sensitive_credentials_tokens_and_hashes(): void {
		$lookup_user = $this->sample_user_row();

		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		$this->db->method( 'query' )->willReturn( 1 );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$this->service->verify_email( 'test_token_123' );

		$this->assertCount( 1, $calls );
		$payload = $calls[0][0];

		// Check sensitive fields are not leaked
		$this->assertArrayNotHasKey( 'password_hash', $payload );
		$this->assertArrayNotHasKey( 'two_factor_secret', $payload );
		$this->assertArrayNotHasKey( 'two_factor_pending_secret', $payload );
		$this->assertArrayNotHasKey( 'backup_codes_json', $payload );
		$this->assertArrayNotHasKey( 'email_verification_token', $payload );
		$this->assertArrayNotHasKey( 'password_reset_token', $payload );

		// Check allowed whitelist keys
		$expected_keys = array( 'id', 'username', 'email', 'role', 'emailVerifiedAt' );
		$this->assertSame( $expected_keys, array_keys( $payload ) );
	}

	public function test_atomic_update_executes_with_correct_sql_and_parameters(): void {
		$raw_token   = 'test_verify_token_sample';
		$token_hash  = Credentials::hash_lookup_token( $raw_token );
		$lookup_user = $this->sample_user_row(
			array(
				'id'                       => '909',
				'email_verification_token' => $token_hash,
			)
		);

		$this->db->method( 'get_row' )->willReturn( $lookup_user );

		$executed_sql    = '';
		$executed_params = array();

		$this->db->method( 'query' )->willReturnCallback(
			function ( string $sql, array $params ) use ( &$executed_sql, &$executed_params ): int {
				$executed_sql    = $sql;
				$executed_params = $params;
				return 1;
			}
		);

		$result = $this->service->verify_email( $raw_token );

		$this->assertTrue( $result );

		// Check SQL structure
		$this->assertStringContainsString( 'UPDATE users', $executed_sql );
		$this->assertStringContainsString( 'SET is_email_verified = 1', $executed_sql );
		$this->assertStringContainsString( 'email_verification_token = NULL', $executed_sql );
		$this->assertStringContainsString( 'email_verification_expires_at = NULL', $executed_sql );
		$this->assertStringContainsString( 'WHERE id = :id', $executed_sql );
		$this->assertStringContainsString( 'AND email_verification_token = :token_hash', $executed_sql );
		$this->assertStringContainsString( 'AND email_verification_expires_at > :now', $executed_sql );
		$this->assertStringContainsString( 'AND is_email_verified = 0', $executed_sql );

		// Check SQL parameters
		$this->assertSame( '909', $executed_params['id'] );
		$this->assertSame( $token_hash, $executed_params['token_hash'] );
		$this->assertArrayHasKey( 'now', $executed_params );
		$this->assertArrayHasKey( 'email_verified_at', $executed_params );
		$this->assertArrayHasKey( 'updated_at', $executed_params );
	}

	public function test_no_unnecessary_post_verification_user_query_executed(): void {
		$lookup_user = $this->sample_user_row();
		$this->db->method( 'get_row' )->willReturn( $lookup_user );
		$this->db->method( 'query' )->willReturn( 1 );

		// Explicitly assert UsersApi::get_user is never called regardless of observers
		$this->users_api->expects( $this->never() )->method( 'get_user' );

		$calls = $this->record_action( 'user_email_verified', 1 );

		$result = $this->service->verify_email( 'test_token_123' );
		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );
	}
}
