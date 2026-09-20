<?php
/**
 * Integration tests for password reset and email verification lifecycles.
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

class PasswordResetLifecycleTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private AuthService $auth_service;
	private string $table_prefix;

	private array $test_user_ids    = array( '999101', '999102', '999103' );
	private array $test_session_ids = array( 'sess_pwd_reset_1' );

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection );

		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$this->auth_service = new AuthService(
			$this->db,
			new UsersApi( $this->db ),
			new Credentials( $this->db ),
			new AuthValidator(),
			new Totp(),
			new Notifications(),
			new Crypto( $config ),
			$roles,
			$authorization,
			null,
			$config
		);

		$this->clean_test_fixtures();
	}

	protected function tearDown(): void {
		$this->clean_test_fixtures();
		parent::tearDown();
	}

	private function clean_test_fixtures(): void {
		$user_ids = implode( "','", $this->test_user_ids );
		$sess_ids = implode( "','", $this->test_session_ids );

		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id IN ('{$user_ids}') OR id IN ('{$sess_ids}')" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id IN ('{$user_ids}')" );
	}

	public function test_reset_password_updates_hash_and_clears_reset_token(): void {
		$user_id    = '999101';
		$raw_token  = 'test_reset_token_secret_12345';
		$token_hash = Credentials::hash_lookup_token( $raw_token );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + 3600 );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (
				id, username, email, first_name, last_name, password_hash,
				role, is_email_verified, password_reset_token, password_reset_expires_at,
				two_factor_enabled, created_at, updated_at
			) VALUES (
				'{$user_id}', 'pwd_reset_user', 'pwd_reset@example.com', 'Reset', 'User',
				'\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'editor', 1, '{$token_hash}', '{$expires_at}',
				0, NOW(), NOW()
			)"
		);

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (
				id, user_id, token_hash, ip_address, user_agent, created_at, last_active_at
			) VALUES (
				'sess_pwd_reset_1', '{$user_id}', 'hash_sess_pwd_reset_1', '127.0.0.1', 'PHPUnit', NOW(), NOW()
			)"
		);

		$request = new Request(
			'POST',
			'/api/v1/auth/reset-password',
			array(),
			array(),
			array(),
			array(),
			array()
		);

		$new_password = 'BrandNewSecurePassword2026!';
		$result       = $this->auth_service->reset_password( $request, $raw_token, $new_password );

		$this->assertTrue( $result );

		$stmt = $this->pdo->prepare( "SELECT * FROM {$this->table_prefix}users WHERE id = :id" );
		$stmt->execute( array( 'id' => $user_id ) );
		$updated_user = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertNotEmpty( $updated_user );
		$this->assertNull( $updated_user['password_reset_token'] );
		$this->assertNull( $updated_user['password_reset_expires_at'] );
		$this->assertTrue( password_verify( $new_password, $updated_user['password_hash'] ) );

		$stmt_sess = $this->pdo->prepare( "SELECT * FROM {$this->table_prefix}sessions WHERE id = :id" );
		$stmt_sess->execute( array( 'id' => 'sess_pwd_reset_1' ) );
		$updated_session = $stmt_sess->fetch( PDO::FETCH_ASSOC );

		$this->assertNotEmpty( $updated_session );
		$this->assertNotNull( $updated_session['revoked_at'] );
	}

	public function test_verify_email_updates_verified_status_and_clears_verification_token(): void {
		$user_id    = '999102';
		$raw_token  = 'test_verify_email_token_12345';
		$token_hash = Credentials::hash_lookup_token( $raw_token );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + 3600 );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (
				id, username, email, first_name, last_name, password_hash,
				role, is_email_verified, email_verification_token, email_verification_expires_at,
				two_factor_enabled, created_at, updated_at
			) VALUES (
				'{$user_id}', 'verify_email_user', 'verify_email@example.com', 'Verify', 'User',
				'\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'editor', 0, '{$token_hash}', '{$expires_at}',
				0, NOW(), NOW()
			)"
		);

		$result = $this->auth_service->verify_email( $raw_token );

		$this->assertTrue( $result );

		$stmt = $this->pdo->prepare( "SELECT * FROM {$this->table_prefix}users WHERE id = :id" );
		$stmt->execute( array( 'id' => $user_id ) );
		$updated_user = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertNotEmpty( $updated_user );
		$this->assertSame( 1, (int) $updated_user['is_email_verified'] );
		$this->assertNotNull( $updated_user['email_verified_at'] );
		$this->assertNull( $updated_user['email_verification_token'] );
		$this->assertNull( $updated_user['email_verification_expires_at'] );
	}

	public function test_resend_verification_updates_verification_token_in_database(): void {
		$user_id = '999103';
		$email   = 'resend_verify@example.com';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (
				id, username, email, first_name, last_name, password_hash,
				role, is_email_verified,
				two_factor_enabled, created_at, updated_at
			) VALUES (
				'{$user_id}', 'resend_verify_user', '{$email}', 'Resend', 'User',
				'\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'editor', 0,
				0, NOW(), NOW()
			)"
		);

		$request = new Request(
			'POST',
			'/api/v1/auth/resend-verification',
			array(),
			array(),
			array(),
			array(),
			array()
		);

		$this->auth_service->resend_verification( $request, $email );

		$stmt = $this->pdo->prepare( "SELECT * FROM {$this->table_prefix}users WHERE id = :id" );
		$stmt->execute( array( 'id' => $user_id ) );
		$updated_user = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertNotEmpty( $updated_user );
		$this->assertSame( 0, (int) $updated_user['is_email_verified'] );
		$this->assertNotNull( $updated_user['email_verification_token'] );
		$this->assertNotNull( $updated_user['email_verification_expires_at'] );
	}
}
