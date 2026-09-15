<?php
/**
 * Integration tests for session revocation and age/ownership predicate enforcement.
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

class SessionRevocationTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private AuthService $auth_service;
	private string $table_prefix;

	private array $test_user_ids    = array( '888101', '888102' );
	private array $test_session_ids = array( 'sess_active_1', 'sess_active_2', 'sess_stale_1', 'sess_other_user' );

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

		$this->pdo->exec( "DELETE FROM {$this->table_prefix}api_keys WHERE user_id IN ('{$user_ids}')" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id IN ('{$user_ids}') OR id IN ('{$sess_ids}')" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id IN ('{$user_ids}')" );
	}

	private function create_test_context( string $user_id, string $token ): Request {
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
			'DELETE',
			'/api/v1/auth/security/sessions',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $token )
		);
	}

	public function test_revoke_session_deletes_eligible_active_session(): void {
		$user_id = '888101';
		$token   = 'token_888101_test';
		$request = $this->create_test_context( $user_id, $token );

		$session_id = 'sess_active_1';
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, ip_address, user_agent, last_active_at, created_at)
			VALUES ('{$session_id}', '{$user_id}', 'hash1', '127.0.0.1', 'PHPUnit', NOW(), NOW())"
		);

		$result = $this->auth_service->revoke_session( $request, $session_id );
		$this->assertTrue( $result );

		$row = $this->db->get_row_by( 'sessions', array( 'id' => $session_id ) );
		$this->assertNull( $row );
	}

	public function test_revoke_session_does_not_delete_stale_ineligible_session(): void {
		$user_id = '888101';
		$token   = 'token_888101_test';
		$request = $this->create_test_context( $user_id, $token );

		// Create a session whose last_active_at is older than the session lifetime (e.g. 60 days ago)
		$session_id = 'sess_stale_1';
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - ( 60 * 86400 ) );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, ip_address, user_agent, last_active_at, created_at)
			VALUES ('{$session_id}', '{$user_id}', 'hash_stale', '127.0.0.1', 'PHPUnit', '{$stale_time}', '{$stale_time}')"
		);

		$result = $this->auth_service->revoke_session( $request, $session_id );
		$this->assertFalse( $result );
	}

	public function test_revoke_session_cannot_delete_another_users_session(): void {
		$user_id       = '888101';
		$other_user_id = '888102';
		$token         = 'token_888101_test';
		$request       = $this->create_test_context( $user_id, $token );

		// Seed second user and a session belonging to that second user
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, two_factor_enabled, created_at, updated_at)
			VALUES ('{$other_user_id}', 'user_{$other_user_id}', 'u{$other_user_id}@example.com', 'Other', 'User', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'editor', 1, 0, NOW(), NOW())"
		);

		$session_id = 'sess_other_user';
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, ip_address, user_agent, last_active_at, created_at)
			VALUES ('{$session_id}', '{$other_user_id}', 'hash_other', '127.0.0.1', 'PHPUnit', NOW(), NOW())"
		);

		$result = $this->auth_service->revoke_session( $request, $session_id );
		$this->assertFalse( $result );

		$row = $this->db->get_row_by( 'sessions', array( 'id' => $session_id ) );
		$this->assertNotNull( $row );
		$this->assertSame( $other_user_id, (string) $row['user_id'] );
	}

	public function test_revoke_session_returns_false_for_nonexistent_session(): void {
		$user_id = '888101';
		$token   = 'token_888101_test';
		$request = $this->create_test_context( $user_id, $token );

		$result = $this->auth_service->revoke_session( $request, 'non_existent_session_id' );
		$this->assertFalse( $result );
	}
}
