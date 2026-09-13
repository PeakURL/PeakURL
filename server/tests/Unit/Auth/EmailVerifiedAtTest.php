<?php
/**
 * Unit tests for user email_verified_at formatting null-safety contracts.
 *
 * @package PeakURL\Tests\Unit\Auth
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Api\UsersApi;
use PeakURL\Services\Crypto;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PeakURL\Services\Geoip;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Connection;
use PDO;
use PDOStatement;

class EmailVerifiedAtTest extends TestCase {

	private AuthService $service;

	protected function setUp(): void {
		parent::setUp();

		$mock_stmt = $this->createMock( PDOStatement::class );
		$mock_pdo  = $this->createMock( PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );

		$config = array(
			Constants::SITE_URL => 'https://peakurl.dev',
		);

		$connection = new class( $config, $mock_pdo ) extends Connection {
			private PDO $mock_pdo;

			public function __construct( array $config, PDO $mock_pdo ) {
				parent::__construct( $config );
				$this->mock_pdo = $mock_pdo;
			}

			public function get_connection(): PDO {
				return $this->mock_pdo;
			}
		};

		$db          = new PeakURL_DB( $connection );
		$roles       = new Roles();
		$auth        = new Authorization( $roles );
		$crypto      = new Crypto( $config );
		$users_api   = new UsersApi( $db );
		$credentials = new Credentials( $db );

		$this->service = new AuthService(
			$db,
			$users_api,
			$credentials,
			new AuthValidator(),
			new Totp(),
			$this->createMock( Notifications::class ),
			$crypto,
			$roles,
			$auth,
			$this->createMock( Geoip::class ),
			$config
		);
	}

	public function test_format_user_with_valid_datetime_produces_iso_string(): void {
		$user_row = array(
			'id'                => '1',
			'username'          => 'verified_user',
			'role'              => 'admin',
			'is_email_verified' => 1,
			'email_verified_at' => '2026-09-01 12:00:00',
			'created_at'        => '2026-09-01 10:00:00',
			'updated_at'        => '2026-09-01 10:00:00',
		);

		$formatted = $this->service->format_user( $user_row );
		$this->assertTrue( $formatted['isEmailVerified'] );
		$this->assertSame( '2026-09-01T12:00:00+00:00', $formatted['emailVerifiedAt'] );
	}

	public function test_format_user_with_null_produces_null(): void {
		$user_row = array(
			'id'                => '2',
			'username'          => 'unverified_user',
			'role'              => 'editor',
			'is_email_verified' => 0,
			'email_verified_at' => null,
			'created_at'        => '2026-09-01 10:00:00',
			'updated_at'        => '2026-09-01 10:00:00',
		);

		$formatted = $this->service->format_user( $user_row );
		$this->assertFalse( $formatted['isEmailVerified'] );
		$this->assertNull( $formatted['emailVerifiedAt'] );
	}

	public function test_format_user_with_empty_string_produces_null(): void {
		$user_row = array(
			'id'                => '3',
			'username'          => 'empty_string_user',
			'role'              => 'editor',
			'is_email_verified' => 0,
			'email_verified_at' => '',
			'created_at'        => '2026-09-01 10:00:00',
			'updated_at'        => '2026-09-01 10:00:00',
		);

		$formatted = $this->service->format_user( $user_row );
		$this->assertFalse( $formatted['isEmailVerified'] );
		$this->assertNull( $formatted['emailVerifiedAt'] );
	}

	public function test_format_user_with_missing_key_produces_null_without_warning(): void {
		$user_row = array(
			'id'         => '4',
			'username'   => 'missing_key_user',
			'role'       => 'editor',
			'created_at' => '2026-09-01 10:00:00',
			'updated_at' => '2026-09-01 10:00:00',
			// email_verified_at key intentionally omitted
		);

		$formatted = $this->service->format_user( $user_row );
		$this->assertFalse( $formatted['isEmailVerified'] );
		$this->assertNull( $formatted['emailVerifiedAt'] );
	}
}
