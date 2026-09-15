<?php
/**
 * Integration test for Two-Factor Authentication lifecycle and backup codes.
 *
 * @package PeakURL\Tests\Integration\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PDO;
use ReflectionMethod;

class TwoFactorLifecycleTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private UsersApi $users_api;
	private Credentials $credentials;
	private Totp $totp;
	private AuthService $auth_service;
	private string $table_prefix;

	private array $test_user_ids = array( '777101', '777102', '777103', '777104', '777105' );

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection, $this->table_prefix );
		$this->users_api    = new UsersApi( $this->db );
		$this->credentials  = new Credentials( $this->db );
		$this->totp         = new Totp();

		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$this->auth_service = new AuthService(
			$this->db,
			$this->users_api,
			$this->credentials,
			new AuthValidator(),
			$this->totp,
			new Notifications(),
			new Crypto( $config ),
			$roles,
			$authorization,
			null,
			$config
		);

		$this->clean_test_users();
	}

	protected function tearDown(): void {
		$this->clean_test_users();
		parent::tearDown();
	}

	private function clean_test_users(): void {
		$ids = implode( "','", $this->test_user_ids );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}api_keys WHERE user_id IN ('{$ids}')" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id IN ('{$ids}')" );
	}

	private function create_test_user( string $user_id, string $token ): Request {
		$api_key_hash = hash( 'sha256', $token );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, two_factor_enabled, created_at, updated_at)
			VALUES ('{$user_id}', 'user_{$user_id}', 'u{$user_id}@example.com', 'Test', 'User', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'editor', 1, 0, NOW(), NOW())"
		);

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}api_keys (id, user_id, label, key_hash, key_prefix, key_last_four, created_at)
			VALUES ('key_{$user_id}', '{$user_id}', 'Test Key', '{$api_key_hash}', 'test', '1234', NOW())"
		);

		return new Request(
			'POST',
			'/api/v1/auth/security/two-factor/verify',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $token )
		);
	}

	private function calculate_totp_code( string $secret ): string {
		$totp   = new Totp();
		$method = new ReflectionMethod( Totp::class, 'calculate_code' );

		return (string) $method->invoke( $totp, $secret, (int) floor( time() / 30 ) );
	}

	public function test_two_factor_setup_and_verification_lifecycle(): void {
		$user_id = '777101';
		$token   = 'token_777101_test_secret';
		$request = $this->create_test_user( $user_id, $token );

		// 1. Start setup
		$setup = $this->auth_service->start_two_factor_setup( $request );
		$this->assertNotEmpty( $setup['secret'] );
		$this->assertStringStartsWith( 'otpauth://totp/', $setup['otpauthUrl'] );

		// Verify pending secret stored
		$row = $this->users_api->get_user( $user_id );
		$this->assertSame( $setup['secret'], $row['two_factor_pending_secret'] );
		$this->assertSame( 0, (int) $row['two_factor_enabled'] );

		// 2. Calculate valid TOTP and verify
		$valid_totp   = $this->calculate_totp_code( $setup['secret'] );
		$backup_codes = $this->auth_service->verify_two_factor( $request, $valid_totp );

		// Verify backup codes format and uniqueness
		$this->assertCount( 8, $backup_codes );
		$this->assertSame( 8, count( array_unique( $backup_codes ) ) );
		foreach ( $backup_codes as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-F0-9]{4}-[A-F0-9]{4}$/', $code );
		}

		// 3. Verify user state transitioned cleanly
		$updated_row = $this->users_api->get_user( $user_id );
		$this->assertSame( 1, (int) $updated_row['two_factor_enabled'] );
		$this->assertSame( $setup['secret'], $updated_row['two_factor_secret'] );
		$this->assertNull( $updated_row['two_factor_pending_secret'] );
		$this->assertNotNull( $updated_row['backup_codes_generated_at'] );

		$stored_backup_codes = json_decode( (string) $updated_row['backup_codes_json'], true );
		$this->assertSame( $backup_codes, $stored_backup_codes );
	}

	public function test_invalid_totp_code_rejects_and_does_not_enable_two_factor(): void {
		$user_id = '777102';
		$token   = 'token_777102_test_secret';
		$request = $this->create_test_user( $user_id, $token );

		$setup = $this->auth_service->start_two_factor_setup( $request );
		$this->assertNotEmpty( $setup['secret'] );

		// Attempt verification with an invalid code
		try {
			$this->auth_service->verify_two_factor( $request, '000000' );
			$this->fail( 'Expected ApiException for invalid verification code' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->getCode() );
			$this->assertSame( 'Invalid verification code.', $e->getMessage() );
		}

		// Verify 2FA is still disabled and pending secret remains unchanged
		$row = $this->users_api->get_user( $user_id );
		$this->assertSame( 0, (int) $row['two_factor_enabled'] );
		$this->assertNull( $row['two_factor_secret'] );
		$this->assertSame( $setup['secret'], $row['two_factor_pending_secret'] );
		$this->assertNull( $row['backup_codes_json'] );
	}

	public function test_transaction_rollback_preserves_state_on_persistence_failure(): void {
		$user_id = '777103';
		$token   = 'token_777103_test_secret';
		$request = $this->create_test_user( $user_id, $token );

		$setup      = $this->auth_service->start_two_factor_setup( $request );
		$valid_totp = $this->calculate_totp_code( $setup['secret'] );

		// Create a credentials object that fails during replace_backup_codes
		$failing_credentials = $this->getMockBuilder( Credentials::class )
			->setConstructorArgs( array( $this->db ) )
			->onlyMethods( array( 'replace_backup_codes' ) )
			->getMock();
		$failing_credentials->method( 'replace_backup_codes' )
			->willThrowException( new \RuntimeException( 'Simulated persistence failure' ) );

		$config        = Configuration::get_current();
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$service = new AuthService(
			$this->db,
			$this->users_api,
			$failing_credentials,
			new AuthValidator(),
			$this->totp,
			new Notifications(),
			new Crypto( $config ),
			$roles,
			$authorization,
			null,
			$config
		);

		try {
			$service->verify_two_factor( $request, $valid_totp );
			$this->fail( 'Expected RuntimeException during failed replace_backup_codes' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Simulated persistence failure', $e->getMessage() );
		}

		// Ensure transaction rolled back and no partial 2FA activation occurred
		$this->assertFalse( $this->db->in_transaction() );
		$row = $this->users_api->get_user( $user_id );
		$this->assertSame( 0, (int) $row['two_factor_enabled'] );
		$this->assertNull( $row['two_factor_secret'] );
		$this->assertSame( $setup['secret'], $row['two_factor_pending_secret'] );
		$this->assertNull( $row['backup_codes_json'] );
	}

	public function test_backup_code_single_use_behavior(): void {
		$user_id = '777104';
		$token   = 'token_777104_test_secret';
		$request = $this->create_test_user( $user_id, $token );

		$setup        = $this->auth_service->start_two_factor_setup( $request );
		$valid_totp   = $this->calculate_totp_code( $setup['secret'] );
		$backup_codes = $this->auth_service->verify_two_factor( $request, $valid_totp );

		$first_code = $backup_codes[0];

		// 1. Valid unused code succeeds and is consumed
		$result = $this->credentials->verify_backup_code( $user_id, $first_code );
		$this->assertTrue( $result );

		$remaining_codes = $this->credentials->list_backup_codes( $user_id );
		$this->assertCount( 7, $remaining_codes );
		$this->assertNotContains( $first_code, $remaining_codes );

		// 2. Re-using the same backup code fails
		$reuse_result = $this->credentials->verify_backup_code( $user_id, $first_code );
		$this->assertFalse( $reuse_result );

		// 3. Invalid code fails
		$invalid_result = $this->credentials->verify_backup_code( $user_id, 'INVALID-CODE' );
		$this->assertFalse( $invalid_result );
		$this->assertCount( 7, $this->credentials->list_backup_codes( $user_id ) );
	}

	public function test_two_factor_login_challenge_totp_and_backup_code_flow(): void {
		$user_id  = '777105';
		$token    = 'token_777105_test_secret';
		$password = 'Secret123!';
		$username = "user_{$user_id}";

		// Seed user with known password
		$api_key_hash = hash( 'sha256', $token );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, two_factor_enabled, created_at, updated_at)
			VALUES ('{$user_id}', '{$username}', 'u{$user_id}@example.com', 'Test', 'User', '" . password_hash( $password, PASSWORD_BCRYPT ) . "', 'editor', 1, 0, NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}api_keys (id, user_id, label, key_hash, key_prefix, key_last_four, created_at)
			VALUES ('key_{$user_id}', '{$user_id}', 'Test Key', '{$api_key_hash}', 'test', '1234', NOW())"
		);

		$auth_request = new Request(
			'POST',
			'/api/v1/auth/security/two-factor/verify',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $token )
		);

		// 1. Enable 2FA
		$setup        = $this->auth_service->start_two_factor_setup( $auth_request );
		$valid_totp   = $this->calculate_totp_code( $setup['secret'] );
		$backup_codes = $this->auth_service->verify_two_factor( $auth_request, $valid_totp );

		$login_request = new Request(
			'POST',
			'/api/v1/auth/login',
			array(),
			array(
				'identifier' => $username,
				'password'   => $password,
			)
		);

		// 2. Login without 2FA code returns challenge
		$login_challenge = $this->auth_service->login(
			$login_request,
			array(
				'identifier' => $username,
				'password'   => $password,
			)
		);
		$this->assertTrue( $login_challenge['requiresTwoFactor'] );

		// 3. Login with valid TOTP code succeeds
		$totp_login = $this->auth_service->login(
			$login_request,
			array(
				'identifier' => $username,
				'password'   => $password,
				'token'      => $this->calculate_totp_code( $setup['secret'] ),
			)
		);
		$this->assertFalse( $totp_login['requiresTwoFactor'] );
		$this->assertSame( $username, $totp_login['user']['username'] );

		// 4. Login with valid backup code succeeds
		$backup_code_to_use = $backup_codes[1];
		$backup_login       = $this->auth_service->login(
			$login_request,
			array(
				'identifier' => $username,
				'password'   => $password,
				'token'      => $backup_code_to_use,
			)
		);
		$this->assertFalse( $backup_login['requiresTwoFactor'] );
		$this->assertSame( $username, $backup_login['user']['username'] );

		// 5. Reusing the SAME backup code is rejected
		try {
			$this->auth_service->login(
				$login_request,
				array(
					'identifier' => $username,
					'password'   => $password,
					'token'      => $backup_code_to_use,
				)
			);
			$this->fail( 'Expected ApiException 401 on reused backup code' );
		} catch ( ApiException $e ) {
			$this->assertSame( 401, $e->getCode() );
			$this->assertSame( 'Invalid two-factor code.', $e->getMessage() );
		}

		// 6. Disable 2FA with current password confirmation
		$this->auth_service->disable_two_factor( $auth_request, $password );
		$disabled_user = $this->users_api->get_user( $user_id );
		$this->assertSame( 0, (int) $disabled_user['two_factor_enabled'] );
		$this->assertNull( $disabled_user['two_factor_secret'] );
		$this->assertNull( $disabled_user['backup_codes_json'] );
	}
}
