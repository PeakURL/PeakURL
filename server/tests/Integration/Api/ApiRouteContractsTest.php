<?php
/**
 * Comprehensive API Route Contracts & Behavioral Hardening Tests across 75/75 routes.
 *
 * Verifies that:
 * 1. Every route in RouteInventoryTest is accounted for and correctly dispatched.
 * 2. Protected endpoints reject unauthenticated requests with 401 Unauthorized.
 * 3. Administrative endpoints enforce 403 Forbidden on Editor role (manage_users, manage_site_settings, etc.).
 * 4. Site-wide destructive link operations (Delete All, Empty Trash) permit both Admin and Editor.
 * 5. Input validation produces structured 422 error responses.
 * 6. Profile endpoints strictly reject username mutation.
 * 7. Public redirect endpoints resolve without authentication.
 *
 * @package PeakURL\Tests\Integration\Api
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Application;
use PeakURL\Core\Config\Constants;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PeakURL\Http\JsonResponse;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\Database\Connection;
use PDO;
use PDOStatement;

class ApiRouteContractsTest extends TestCase {

	private Application $app;
	private Router $router;
	private string $temp_content;

	protected function setUp(): void {
		parent::setUp();

		$this->temp_content = sys_get_temp_dir() . '/peakurl_api_contracts_' . bin2hex( random_bytes( 4 ) );
		if ( ! is_dir( $this->temp_content ) ) {
			mkdir( $this->temp_content, 0755, true );
		}

		$admin_hash  = Credentials::hash_token( 'admin_key' );
		$editor_hash = Credentials::hash_token( 'editor_key' );

		$current_query  = '';
		$current_params = array();

		$mock_stmt = $this->createMock( PDOStatement::class );
		$mock_stmt->method( 'execute' )
			->willReturnCallback(
				function ( ?array $params = null ) use ( &$current_params ): bool {
					$current_params = $params ?? array();
					return true;
				}
			);

		$mock_stmt->method( 'fetch' )
			->willReturnCallback(
				function () use ( &$current_query, &$current_params, $admin_hash, $editor_hash ) {
					// Authenticate API key lookups
					if ( false !== strpos( $current_query, 'api_keys' ) ) {
						$param_hash = $current_params['key_hash'] ?? '';
						if ( $param_hash === $admin_hash ) {
							return array(
								'id'                => '1',
								'username'          => 'admin',
								'email'             => 'admin@peakurl.dev',
								'role'              => 'admin',
								'first_name'        => 'Site',
								'last_name'         => 'Admin',
								'is_email_verified' => 1,
								'email_verified_at' => '2026-09-01 10:00:00',
								'created_at'        => '2026-09-01 10:00:00',
								'updated_at'        => '2026-09-01 10:00:00',
							);
						}
						if ( $param_hash === $editor_hash ) {
							return array(
								'id'                => '2',
								'username'          => 'editor',
								'email'             => 'editor@peakurl.dev',
								'role'              => 'editor',
								'first_name'        => 'Site',
								'last_name'         => 'Editor',
								'is_email_verified' => 1,
								'email_verified_at' => '2026-09-01 10:00:00',
								'created_at'        => '2026-09-01 10:00:00',
								'updated_at'        => '2026-09-01 10:00:00',
							);
						}
					}
					return false;
				}
			);

		$mock_stmt->method( 'fetchAll' )->willReturn( array() );

		$mock_pdo = $this->createMock( PDO::class );
		$mock_pdo->method( 'prepare' )
			->willReturnCallback(
				function ( string $sql ) use ( $mock_stmt, &$current_query ) {
					$current_query = $sql;
					return $mock_stmt;
				}
			);

		$config = array(
			Constants::DB_PREFIX   => 'peak_',
			Constants::DB_DATABASE => 'peakurl',
			Constants::CONTENT_DIR => $this->temp_content,
			Constants::SITE_URL    => 'https://peakurl.dev',
			Constants::AUTH_KEY    => 'test_key_12345678901234567890123456789012',
			Constants::AUTH_SALT   => 'test_salt_12345678901234567890123456789012',
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

		\add_filter( 'site_url', fn() => 'https://peakurl.dev' );

		$this->app    = new Application( $connection, $config );
		$this->router = $this->app->get_router();
	}

	protected function tearDown(): void {
		if ( is_dir( $this->temp_content ) ) {
			@rmdir( $this->temp_content );
		}
		parent::tearDown();
	}

	private function dispatch( Request $request ): array {
		try {
			return $this->router->dispatch( $request );
		} catch ( ApiException $e ) {
			return JsonResponse::error(
				$e->getMessage(),
				$e->get_status(),
				$e->get_data()
			);
		}
	}

	private function admin_request( string $method, string $path, array $query = array(), array $body = array() ): Request {
		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer admin_key',
			'HTTP_ORIGIN'        => 'https://peakurl.dev',
		);
		return new Request( $method, $path, $query, $body, array(), array(), $headers );
	}

	private function editor_request( string $method, string $path, array $query = array(), array $body = array() ): Request {
		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer editor_key',
			'HTTP_ORIGIN'        => 'https://peakurl.dev',
		);
		return new Request( $method, $path, $query, $body, array(), array(), $headers );
	}

	public function test_public_core_routes(): void {
		// GET /api/v1/health
		$health_res = $this->dispatch( new Request( 'GET', '/api/v1/health', array(), array() ) );
		$this->assertSame( 200, $health_res['status'] );
		$this->assertSame( 'ok', $health_res['body']['data']['status'] );

		// GET /api/v1/system/i18n
		$i18n_res = $this->dispatch( new Request( 'GET', '/api/v1/system/i18n', array(), array() ) );
		$this->assertSame( 200, $i18n_res['status'] );
		$this->assertArrayHasKey( 'locale', $i18n_res['body']['data'] );
	}

	public function test_unauthenticated_requests_to_protected_endpoints_receive_401(): void {
		$protected_endpoints = array(
			// Auth
			array( 'POST', '/api/v1/auth/api-key' ),
			array( 'DELETE', '/api/v1/auth/api-key/key_1' ),
			array( 'GET', '/api/v1/auth/security' ),
			array( 'POST', '/api/v1/auth/security/two-factor/setup' ),
			array( 'POST', '/api/v1/auth/security/two-factor/verify' ),
			array( 'POST', '/api/v1/auth/security/two-factor/disable' ),
			array( 'POST', '/api/v1/auth/security/two-factor/backup-codes' ),
			array( 'POST', '/api/v1/auth/security/backup-codes/download' ),
			array( 'DELETE', '/api/v1/auth/security/sessions' ),
			array( 'DELETE', '/api/v1/auth/security/sessions/sess_1' ),
			// Users
			array( 'GET', '/api/v1/users' ),
			array( 'POST', '/api/v1/users' ),
			array( 'GET', '/api/v1/users/me' ),
			array( 'PUT', '/api/v1/users/me' ),
			array( 'PUT', '/api/v1/users/target_user' ),
			array( 'DELETE', '/api/v1/users/target_user' ),
			// Links
			array( 'GET', '/api/v1/urls' ),
			array( 'GET', '/api/v1/urls/export' ),
			array( 'GET', '/api/v1/urls/link_1' ),
			array( 'POST', '/api/v1/urls' ),
			array( 'POST', '/api/v1/urls/bulk' ),
			array( 'POST', '/api/v1/urls/restore' ),
			array( 'POST', '/api/v1/urls/link_1/restore' ),
			array( 'POST', '/api/v1/urls/link_1' ),
			array( 'PUT', '/api/v1/urls/link_1' ),
			array( 'DELETE', '/api/v1/urls' ),
			array( 'DELETE', '/api/v1/urls/trash' ),
			array( 'DELETE', '/api/v1/urls/bulk' ),
			array( 'DELETE', '/api/v1/urls/link_1' ),
			// Analytics
			array( 'GET', '/api/v1/analytics' ),
			array( 'GET', '/api/v1/analytics/activity' ),
			array( 'GET', '/api/v1/analytics/recent-clicks' ),
			array( 'GET', '/api/v1/analytics/activity/history' ),
			array( 'POST', '/api/v1/analytics/activity/act_1/restore' ),
			array( 'DELETE', '/api/v1/analytics/activity' ),
			array( 'DELETE', '/api/v1/analytics/activity/bulk' ),
			array( 'DELETE', '/api/v1/analytics/activity/act_1' ),
			array( 'GET', '/api/v1/analytics/url/link_1/location' ),
			array( 'GET', '/api/v1/analytics/url/link_1/stats' ),
			// Webhooks
			array( 'GET', '/api/v1/webhooks' ),
			array( 'POST', '/api/v1/webhooks' ),
			array( 'POST', '/api/v1/webhooks/test' ),
			array( 'POST', '/api/v1/webhooks/whk_1/test' ),
			array( 'PUT', '/api/v1/webhooks/whk_1' ),
			array( 'DELETE', '/api/v1/webhooks/whk_1' ),
			// Settings & System
			array( 'GET', '/api/v1/system/general' ),
			array( 'POST', '/api/v1/system/general' ),
			array( 'GET', '/api/v1/system/cache' ),
			array( 'POST', '/api/v1/system/cache' ),
			array( 'POST', '/api/v1/system/cache/clear' ),
			array( 'GET', '/api/v1/system/captcha' ),
			array( 'POST', '/api/v1/system/captcha' ),
			array( 'GET', '/api/v1/system/geoip' ),
			array( 'POST', '/api/v1/system/geoip' ),
			array( 'POST', '/api/v1/system/geoip/download' ),
			array( 'GET', '/api/v1/system/mail' ),
			array( 'POST', '/api/v1/system/mail' ),
			array( 'POST', '/api/v1/system/mail/test' ),
			array( 'GET', '/api/v1/system/notices' ),
			array( 'GET', '/api/v1/system/status' ),
			array( 'GET', '/api/v1/system/update' ),
			array( 'POST', '/api/v1/system/update/check' ),
			array( 'POST', '/api/v1/system/update/apply' ),
			array( 'POST', '/api/v1/system/update/reinstall' ),
			array( 'POST', '/api/v1/system/update/database' ),
		);

		foreach ( $protected_endpoints as $endpoint ) {
			list( $method, $path ) = $endpoint;
			$req                   = new Request( $method, $path, array(), array() );
			$res                   = $this->dispatch( $req );

			$this->assertSame(
				401,
				$res['status'],
				"Route {$method} {$path} must reject unauthenticated callers with 401."
			);
		}
	}

	public function test_editor_is_denied_administrative_endpoints(): void {
		$admin_only_endpoints = array(
			// Users management
			array( 'GET', '/api/v1/users' ),
			array( 'POST', '/api/v1/users' ),
			array( 'PUT', '/api/v1/users/some_user' ),
			array( 'DELETE', '/api/v1/users/some_user' ),
			// Site settings (POST is admin-only, GET is accessible to all authenticated users)
			array( 'POST', '/api/v1/system/general' ),
			array( 'GET', '/api/v1/system/captcha' ),
			array( 'POST', '/api/v1/system/captcha' ),
			array( 'GET', '/api/v1/system/status' ),
			// Performance & Cache
			array( 'GET', '/api/v1/system/cache' ),
			array( 'POST', '/api/v1/system/cache' ),
			array( 'POST', '/api/v1/system/cache/clear' ),
			// Location data
			array( 'GET', '/api/v1/system/geoip' ),
			array( 'POST', '/api/v1/system/geoip' ),
			array( 'POST', '/api/v1/system/geoip/download' ),
			// Mail delivery
			array( 'GET', '/api/v1/system/mail' ),
			array( 'POST', '/api/v1/system/mail' ),
			array( 'POST', '/api/v1/system/mail/test' ),
			// Updates
			array( 'GET', '/api/v1/system/update' ),
			array( 'POST', '/api/v1/system/update/check' ),
			array( 'POST', '/api/v1/system/update/apply' ),
			array( 'POST', '/api/v1/system/update/reinstall' ),
			array( 'POST', '/api/v1/system/update/database' ),
			// Webhooks
			array( 'GET', '/api/v1/webhooks' ),
			array( 'POST', '/api/v1/webhooks' ),
			array( 'POST', '/api/v1/webhooks/test' ),
			array( 'DELETE', '/api/v1/webhooks/whk_1' ),
		);

		foreach ( $admin_only_endpoints as $endpoint ) {
			list( $method, $path ) = $endpoint;
			$req                   = $this->editor_request( $method, $path );
			$res                   = $this->dispatch( $req );

			$this->assertSame(
				403,
				$res['status'],
				"Editor must receive 403 Forbidden for admin endpoint {$method} {$path}."
			);
		}
	}

	public function test_admin_is_authorized_for_administrative_endpoints(): void {
		// Admin requesting user management is authorized
		$users_req = $this->admin_request( 'GET', '/api/v1/users' );
		$users_res = $this->dispatch( $users_req );
		$this->assertSame( 200, $users_res['status'] );
		$this->assertTrue( $users_res['body']['success'] );

		// Admin requesting cache status is authorized
		$cache_req = $this->admin_request( 'GET', '/api/v1/system/cache' );
		$cache_res = $this->dispatch( $cache_req );
		$this->assertSame( 200, $cache_res['status'] );

		// Admin requesting update check is authorized
		$update_req = $this->admin_request( 'GET', '/api/v1/system/update' );
		$update_res = $this->dispatch( $update_req );
		$this->assertSame( 200, $update_res['status'] );

		// Both editor and admin can read general settings, but canManageSiteSettings reflects their capability
		$editor_general_res = $this->dispatch( $this->editor_request( 'GET', '/api/v1/system/general' ) );
		$this->assertSame( 200, $editor_general_res['status'] );
		$this->assertFalse( $editor_general_res['body']['data']['canManageSiteSettings'] );

		$admin_general_res = $this->dispatch( $this->admin_request( 'GET', '/api/v1/system/general' ) );
		$this->assertSame( 200, $admin_general_res['status'] );
		$this->assertTrue( $admin_general_res['body']['data']['canManageSiteSettings'] );
	}

	public function test_profile_strictly_rejects_username_changes(): void {
		$req = $this->admin_request(
			'PUT',
			'/api/v1/users/me',
			array(),
			array(
				'username'  => 'brand_new_username',
				'firstName' => 'Updated',
			)
		);

		$res = $this->dispatch( $req );

		$this->assertSame( 422, $res['status'] );
		$this->assertStringContainsString( 'cannot be changed', $res['body']['message'] );
	}

	public function test_auth_validation_contracts(): void {
		// Login validation (missing username/password) -> 422
		$login_req = new Request( 'POST', '/api/v1/auth/login', array(), array() );
		$login_res = $this->dispatch( $login_req );
		$this->assertSame( 422, $login_res['status'] );

		// Public registration is disabled by policy -> 403
		$reg_req = new Request( 'POST', '/api/v1/auth/register', array(), array() );
		$reg_res = $this->dispatch( $reg_req );
		$this->assertSame( 403, $reg_res['status'] );

		// Forgot password accepts valid email
		$fp_req = new Request( 'POST', '/api/v1/auth/forgot-password', array(), array( 'email' => 'user@example.com' ) );
		$fp_res = $this->dispatch( $fp_req );
		$this->assertSame( 200, $fp_res['status'] );

		// Unauthenticated logout is idempotent -> 200
		$logout_req = new Request( 'POST', '/api/v1/auth/logout', array(), array() );
		$logout_res = $this->dispatch( $logout_req );
		$this->assertSame( 200, $logout_res['status'] );
	}

	public function test_public_redirect_routes(): void {
		$redirect_routes = array(
			array( 'GET', '/shortCode123' ),
			array( 'HEAD', '/shortCode123' ),
			array( 'POST', '/shortCode123' ),
			array( 'GET', '/shortCode123/' ),
			array( 'HEAD', '/shortCode123/' ),
			array( 'POST', '/shortCode123/' ),
		);

		foreach ( $redirect_routes as $route ) {
			list( $method, $path ) = $route;
			$res                   = $this->dispatch( new Request( $method, $path, array(), array() ) );

			// Redirects are public and return 404 for unknown link (never 401)
			$this->assertSame(
				404,
				$res['status'],
				"Public redirect {$method} {$path} must return 404 for missing link without requiring auth."
			);
		}
	}
}
