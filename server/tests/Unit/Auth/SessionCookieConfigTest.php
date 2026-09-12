<?php
/**
 * Unit tests for session cookie configuration fallback contracts.
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
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PeakURL\Services\Geoip;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Connection;
use PeakURL\Http\Request;
use PDO;
use PDOStatement;

class SessionCookieConfigTest extends TestCase {

	public function test_logout_expires_default_cookie_name_when_config_key_omitted(): void {
		$mock_stmt = $this->createMock( PDOStatement::class );
		$mock_stmt->method( 'execute' )->willReturn( true );
		$mock_stmt->method( 'fetch' )->willReturn( false );

		$mock_pdo = $this->createMock( PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );

		$config = array(
			Constants::SITE_URL => 'https://peakurl.dev',
			// SESSION_COOKIE_NAME is intentionally omitted
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

		$service = new AuthService(
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

		$request = new Request( 'POST', '/api/v1/auth/logout', array(), array() );
		$service->logout( $request );

		$response_cookies = $request->get_response_cookies();
		$found_cookie     = false;
		foreach ( $response_cookies as $cookie_header ) {
			if ( str_starts_with( $cookie_header, Constants::DEFAULT_SESSION_COOKIE_NAME . '=' ) ) {
				$found_cookie = true;
				break;
			}
		}
		$this->assertTrue(
			$found_cookie,
			'Logout must expire default cookie name when SESSION_COOKIE_NAME config is omitted.'
		);
	}

	public function test_logout_expires_custom_cookie_name_when_configured(): void {
		$mock_stmt = $this->createMock( PDOStatement::class );
		$mock_stmt->method( 'execute' )->willReturn( true );
		$mock_stmt->method( 'fetch' )->willReturn( false );

		$mock_pdo = $this->createMock( PDO::class );
		$mock_pdo->method( 'prepare' )->willReturn( $mock_stmt );

		$config = array(
			Constants::SITE_URL            => 'https://peakurl.dev',
			Constants::SESSION_COOKIE_NAME => 'custom_peak_session',
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

		$service = new AuthService(
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

		$request = new Request( 'POST', '/api/v1/auth/logout', array(), array() );
		$service->logout( $request );

		$response_cookies = $request->get_response_cookies();
		$found_cookie     = false;
		foreach ( $response_cookies as $cookie_header ) {
			if ( str_starts_with( $cookie_header, 'custom_peak_session=' ) ) {
				$found_cookie = true;
				break;
			}
		}
		$this->assertTrue(
			$found_cookie,
			'Logout must expire configured custom cookie name.'
		);
	}
}
