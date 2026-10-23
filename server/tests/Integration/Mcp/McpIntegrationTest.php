<?php
/**
 * Integration tests for native Model Context Protocol (MCP) server and OAuth 2.0 PKCE.
 *
 * @package PeakURL\Tests\Integration\Mcp
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization as CoreAuthorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants as CoreConstants;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Links\Health\Checker as HealthChecker;
use PeakURL\Features\Links\Health\Context as HealthContext;
use PeakURL\Features\Links\Health\Probe as HealthProbe;
use PeakURL\Features\Links\Health\Resolver as HealthResolver;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Mcp\Authorization as McpAuthorization;
use PeakURL\Features\Mcp\Constants as McpConstants;
use PeakURL\Features\Mcp\Controller as McpController;
use PeakURL\Features\Mcp\Registry as McpRegistry;
use PeakURL\Features\Mcp\Service as McpService;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use PDO;

class McpIntegrationTest extends TestCase {

	private const string TEST_REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private array $config;
	private string $table_prefix;
	private AuthService $auth_service;
	private LinksService $links_service;
	private AnalyticsService $analytics_service;
	private McpAuthorization $mcp_authorization;
	private McpService $mcp_service;
	private McpController $mcp_controller;
	private array $admin_user;

	protected function setUp(): void {
		parent::setUp();

		$this->config       = Configuration::get_current();
		$this->connection   = Connection::get_instance( $this->config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection );

		$roles              = new Roles();
		$core_authorization = new CoreAuthorization( $roles );
		$crypto             = new Crypto( $this->config );
		$users_api          = new UsersApi( $this->db );
		$credentials        = new AuthCredentials( $this->db );

		$this->auth_service = new AuthService(
			$this->db,
			$users_api,
			$credentials,
			new AuthValidator(),
			new Totp(),
			new Notifications(),
			$crypto,
			$roles,
			$core_authorization,
			null,
			$this->config
		);

		$settings_api            = new SettingsApi( $this->db );
		$geoip                   = new Geoip( $this->config, $settings_api, $crypto );
		$analytics_repository    = new AnalyticsRepository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$core_authorization,
			null,
			null,
			$this->config
		);
		$links_api               = new LinksApi( $this->db );
		$this->analytics_service = new AnalyticsService(
			$analytics_repository,
			$this->db,
			$this->auth_service,
			$roles,
			$core_authorization,
			$this->config,
			$links_api
		);

		$social_preview   = new SocialPreview( $this->config, $settings_api );
		$resolver         = new HealthResolver( static fn() => array( '93.184.216.34' ) );
		$probe            = new HealthProbe(
			new HealthContext( $this->config ),
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$health_checker   = new HealthChecker( $resolver, $probe );
		$links_repository = new LinksRepository( $this->db, $links_api, $core_authorization );
		$captcha          = new Captcha( $this->config, $settings_api, $crypto );
		$webhooks_service = new WebhooksService(
			$this->db,
			new WebhooksValidator(),
			$this->auth_service,
			$roles,
			$core_authorization,
			$this->config,
			$crypto
		);

		$this->links_service = new LinksService(
			$links_repository,
			new LinksValidator(),
			$settings_api,
			$this->auth_service,
			$this->analytics_service,
			$webhooks_service,
			$social_preview,
			$captcha,
			$roles,
			$core_authorization,
			$this->config,
			$health_checker
		);

		$this->mcp_authorization = new McpAuthorization(
			$settings_api,
			$users_api,
			$roles,
			$this->config
		);

		$this->auth_service->set_mcp_authorization( $this->mcp_authorization );

		$this->mcp_service = new McpService(
			$this->mcp_authorization,
			$this->links_service,
			$this->analytics_service,
			$this->config
		);

		$this->mcp_controller = new McpController(
			$this->mcp_service,
			$this->mcp_authorization,
			$this->auth_service,
			$core_authorization,
			$this->config
		);

		$this->cleanup_test_data();

		$now              = Date::now();
		$this->admin_user = array(
			'id'                => '99981',
			'username'          => 'mcpadmin',
			'email'             => 'mcpadmin@example.com',
			'first_name'        => 'MCP',
			'last_name'         => 'Admin',
			'display_name'      => 'MCP Admin',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'admin',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (99981, 'mcpadmin', 'mcpadmin@example.com', 'MCP', 'Admin', 'MCP Admin', '{$this->admin_user['password_hash']}', 'admin', 1, '{$now}', '{$now}')
			ON DUPLICATE KEY UPDATE username = VALUES(username), role = 'admin'"
		);

		$this->mcp_authorization->set_enabled( true );
		$this->mcp_authorization->set_write_enabled( true );
	}

	protected function tearDown(): void {
		$this->cleanup_test_data();
		parent::tearDown();
	}

	private function cleanup_test_data(): void {
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id = 99981" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE destination_url LIKE '%example.com/test-mcp%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}settings WHERE setting_key LIKE 'mcp_%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id = 99981" );
	}

	private function create_request(
		string $method,
		string $path,
		array $body = array(),
		array $headers = array()
	): Request {
		$server = array(
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => $path,
			'CONTENT_TYPE'   => 'application/json',
			'HTTP_ACCEPT'    => 'application/json',
			'HTTP_HOST'      => 'peakurl.dev',
		);
		foreach ( $headers as $key => $value ) {
			$server[ $key ] = $value;
		}

		$raw_body = ! empty( $body ) ? (string) json_encode( $body ) : '';

		return new Request(
			$method,
			$path,
			array(),
			$body,
			array(),
			array(),
			$server,
			$raw_body
		);
	}

	private function create_admin_request(
		string $method,
		string $path,
		array $body = array()
	): Request {
		$session_token = bin2hex( random_bytes( 16 ) );
		$session_id    = bin2hex( random_bytes( 8 ) );
		$now           = Date::now();

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, last_active_at, created_at)
			VALUES ('{$session_id}', {$this->admin_user['id']}, '" . hash( 'sha256', $session_token ) . "', '{$now}', '{$now}')"
		);

		$crypto        = new Crypto( $this->config );
		$signed_cookie = $crypto->sign_session_token( $session_token );

		$server = array(
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => $path,
		);

		$raw_body = ! empty( $body ) ? (string) json_encode( $body ) : '';

		return new Request(
			$method,
			$path,
			array(),
			$body,
			array(
				'peakurl_session' => $signed_cookie,
			),
			array(),
			$server,
			$raw_body
		);
	}

	public function test_oauth_pkce_full_flow_and_protocol_execution(): void {
		$code_verifier  = 'mcp-test-verifier-abcdef0123456789-safe-entropy-sample';
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->mcp_authorization->create_authorization_code(
			(string) $this->admin_user['id'],
			'test-client',
			self::TEST_REDIRECT_URI,
			$code_challenge,
			'S256',
			array( 'links:read', 'analytics:read', 'links:write' ),
			'Test Agent'
		);

		$token_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'test-client',
				'redirect_uri'  => self::TEST_REDIRECT_URI,
				'code_verifier' => $code_verifier,
			)
		);

		$token_response = $this->mcp_controller->oauth_token( $token_request );

		$this->assertSame( 200, $token_response['status'] );
		$this->assertNotEmpty( $token_response['body']['access_token'] );
		$this->assertNotEmpty( $token_response['body']['refresh_token'] );
		$this->assertSame( 'Bearer', $token_response['body']['token_type'] );

		$access_token = $token_response['body']['access_token'];

		// Call tools/list via protocol.
		$list_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$list_response = $this->mcp_controller->handle_mcp( $list_request );

		$this->assertSame( 200, $list_response['status'] );
		$this->assertArrayHasKey( 'tools', $list_response['body']['result'] );
		$tools = $list_response['body']['result']['tools'];
		$this->assertGreaterThanOrEqual( 9, count( $tools ) );

		// Call whoami tool.
		$whoami_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'whoami',
					'arguments' => (object) array(),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$whoami_response = $this->mcp_controller->handle_mcp( $whoami_request );

		$this->assertSame( 200, $whoami_response['status'] );
		$text = $whoami_response['body']['result']['content'][0]['text'];
		$this->assertStringContainsString( 'mcpadmin', $text );
	}

	public function test_mcp_create_link_and_query_flow(): void {
		$code_verifier  = 'verifier-for-link-crud-test-1234567890';
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->mcp_authorization->create_authorization_code(
			(string) $this->admin_user['id'],
			'link-creator-bot',
			self::TEST_REDIRECT_URI,
			$code_challenge,
			'S256',
			array( 'links:read', 'analytics:read', 'links:write' ),
			'Link Creator Bot'
		);

		$token_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'link-creator-bot',
				'redirect_uri'  => self::TEST_REDIRECT_URI,
				'code_verifier' => $code_verifier,
			)
		);

		$token_response = $this->mcp_controller->oauth_token( $token_request );
		$access_token   = $token_response['body']['access_token'];

		// Create a link via MCP tool call.
		$create_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 10,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'create_link',
					'arguments' => array(
						'url'   => 'https://example.com/test-mcp-integration-link',
						'title' => 'MCP Integration Test Link',
					),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$create_response = $this->mcp_controller->handle_mcp( $create_request );

		$this->assertSame( 200, $create_response['status'] );
		if ( ! empty( $create_response['body']['result']['isError'] ) ) {
			$this->fail( 'Create link tool failed: ' . $create_response['body']['result']['content'][0]['text'] );
		}
		$this->assertFalse( $create_response['body']['result']['isError'] ?? false );
		$created_json = json_decode( $create_response['body']['result']['content'][0]['text'], true );
		$this->assertIsArray( $created_json );
		$this->assertSame( 'https://example.com/test-mcp-integration-link', $created_json['destinationUrl'] );
		$this->assertNotEmpty( $created_json['shortUrl'] );

		$link_id = $created_json['id'];

		// Retrieve via get_link tool.
		$get_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 11,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'get_link',
					'arguments' => array( 'id' => $link_id ),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$get_response = $this->mcp_controller->handle_mcp( $get_request );
		$this->assertSame( 200, $get_response['status'] );
		$retrieved_json = json_decode( $get_response['body']['result']['content'][0]['text'], true );
		$this->assertSame( $link_id, $retrieved_json['id'] );

		// Retrieve via list_links tool.
		$list_links_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 12,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'list_links',
					'arguments' => array( 'search' => 'test-mcp-integration' ),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$list_links_response = $this->mcp_controller->handle_mcp( $list_links_request );
		$this->assertSame( 200, $list_links_response['status'] );
		$list_json = json_decode( $list_links_response['body']['result']['content'][0]['text'], true );
		$this->assertNotEmpty( $list_json['links'] );
	}

	public function test_refresh_token_rotation_and_revocation(): void {
		$code_verifier  = 'verifier-for-token-rotation-and-revocation-test';
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->mcp_authorization->create_authorization_code(
			(string) $this->admin_user['id'],
			'rotating-client',
			self::TEST_REDIRECT_URI,
			$code_challenge,
			'S256',
			array( 'links:read' ),
			'Rotating Client'
		);

		$token_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'rotating-client',
				'redirect_uri'  => self::TEST_REDIRECT_URI,
				'code_verifier' => $code_verifier,
			)
		);

		$first_token   = $this->mcp_controller->oauth_token( $token_request );
		$refresh_token = $first_token['body']['refresh_token'];
		$old_access    = $first_token['body']['access_token'];

		// Rotate via refresh token.
		$refresh_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => 'rotating-client',
			)
		);

		$rotated_token = $this->mcp_controller->oauth_token( $refresh_request );
		$this->assertSame( 200, $rotated_token['status'] );
		$new_access  = $rotated_token['body']['access_token'];
		$new_refresh = $rotated_token['body']['refresh_token'];

		$this->assertNotSame( $old_access, $new_access );
		$this->assertNotSame( $refresh_token, $new_refresh );

		// Tools list with new access token works.
		$tools_request = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 30,
				'method'  => 'tools/list',
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$new_access}",
			)
		);

		$tools_response = $this->mcp_controller->handle_mcp( $tools_request );
		$this->assertSame( 200, $tools_response['status'] );

		// Revoke the new access token.
		$revoke_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/revoke',
			array(
				'token'     => $new_access,
				'client_id' => 'rotating-client',
			)
		);

		$revoke_response = $this->mcp_controller->oauth_revoke( $revoke_request );
		$this->assertSame( 200, $revoke_response['status'] );

		// Request with revoked token fails with 401.
		$tools_after_revoke = $this->mcp_controller->handle_mcp( $tools_request );
		$this->assertSame( 401, $tools_after_revoke['status'] );
	}

	public function test_admin_dashboard_settings_and_connection_management(): void {
		$admin_req = $this->create_admin_request(
			'GET',
			'/api/v1/system/mcp/settings'
		);

		// Get settings.
		$settings_res = $this->mcp_controller->get_settings( $admin_req );
		$this->assertSame( 200, $settings_res['status'] );
		$this->assertTrue( $settings_res['body']['data']['enabled'] );
		$this->assertTrue( $settings_res['body']['data']['writeEnabled'] );

		// Disable write tools.
		$update_req = $this->create_admin_request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(
				'writeEnabled' => false,
			)
		);

		$update_res = $this->mcp_controller->update_settings( $update_req );
		$this->assertSame( 200, $update_res['status'] );
		$this->assertFalse( $update_res['body']['data']['writeEnabled'] );

		// List tools reflects write disabled.
		$tools_res = $this->mcp_controller->list_tools( $admin_req );
		$this->assertSame( 200, $tools_res['status'] );
		$this->assertFalse( $tools_res['body']['data']['writeEnabled'] );

		// Re-enable write tools.
		$restore_req = $this->create_admin_request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array( 'writeEnabled' => true )
		);
		$this->mcp_controller->update_settings( $restore_req );
	}

	public function test_mcp_bearer_token_cannot_access_unrelated_rest_endpoints(): void {
		$code_verifier  = 'iso_verifier_test_12345678901234567890';
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->mcp_authorization->create_authorization_code(
			(string) $this->admin_user['id'],
			'isolation-client',
			self::TEST_REDIRECT_URI,
			$code_challenge,
			'S256',
			array( McpConstants::SCOPE_LINKS_READ, McpConstants::SCOPE_ANALYTICS_READ, McpConstants::SCOPE_LINKS_WRITE ),
			'Isolation Test Client'
		);

		$token_request = $this->create_request(
			'POST',
			'/api/mcp/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'isolation-client',
				'redirect_uri'  => self::TEST_REDIRECT_URI,
				'code_verifier' => $code_verifier,
			)
		);

		$tokens_res = $this->mcp_controller->oauth_token( $token_request );
		$this->assertSame( 200, $tokens_res['status'] );
		$mcp_token = $tokens_res['body']['access_token'];

		// Attempt to access ordinary REST routes with the MCP Bearer token.
		// 1. GET /api/v1/users/me -> Must return null from current_user (rejected).
		$users_me_req = $this->create_request(
			'GET',
			'/api/v1/users/me',
			array(),
			array( 'HTTP_AUTHORIZATION' => "Bearer {$mcp_token}" )
		);
		$user_res     = $this->auth_service->current_user( $users_me_req );
		$this->assertNull( $user_res, 'MCP token must not authenticate /api/v1/users/me' );

		// 2. GET /api/v1/urls -> Must reject with 401 via AuthService::get_current_user.
		$urls_req = $this->create_request(
			'GET',
			'/api/v1/urls',
			array(),
			array( 'HTTP_AUTHORIZATION' => "Bearer {$mcp_token}" )
		);
		$this->assertNull( $this->auth_service->current_user( $urls_req ) );

		// 3. POST /api/v1/urls -> Must reject with 401.
		$create_req = $this->create_request(
			'POST',
			'/api/v1/urls',
			array( 'destinationUrl' => 'https://example.com/blocked' ),
			array( 'HTTP_AUTHORIZATION' => "Bearer {$mcp_token}" )
		);
		$this->assertNull( $this->auth_service->current_user( $create_req ) );

		// 4. Same token calling POST /api/mcp -> MUST succeed.
		$mcp_req = $this->create_request(
			'POST',
			'/api/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 'iso-1',
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'whoami',
					'arguments' => (object) array(),
				),
			),
			array( 'HTTP_AUTHORIZATION' => "Bearer {$mcp_token}" )
		);
		$mcp_res = $this->mcp_controller->handle_mcp( $mcp_req );
		$this->assertSame( 200, $mcp_res['status'] );
		$this->assertFalse( $mcp_res['body']['result']['isError'] );
		$this->assertStringContainsString( 'Isolation Test Client', $mcp_res['body']['result']['content'][0]['text'] );
	}
}
