<?php
/**
 * Integration test verifying that MCP write tools traverse shared link lifecycle hooks.
 *
 * @package PeakURL\Tests\Integration\Hooks
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Hooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization as CoreAuthorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Hooks\Hooks;
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
use PeakURL\Features\Mcp\Controller as McpController;
use PeakURL\Features\Mcp\Service as McpService;
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

class McpWriteHooksIntegrationTest extends TestCase {

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

		Hooks::remove_all();
		Hooks::reset_actions();

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

		$settings_api         = new SettingsApi( $this->db );
		$geoip                = new Geoip( $this->config, $settings_api, $crypto );
		$analytics_repository = new AnalyticsRepository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$core_authorization,
			null,
			null,
			$this->config
		);
		$links_api            = new LinksApi( $this->db );

		$this->analytics_service = new AnalyticsService(
			$analytics_repository,
			$this->db,
			$this->auth_service,
			$roles,
			$core_authorization,
			$this->config,
			$links_api
		);

		$social_preview = new SocialPreview( $this->config, $settings_api );
		$resolver       = new HealthResolver( static fn() => array( '93.184.216.34' ) );
		$probe          = new HealthProbe(
			new HealthContext( $this->config ),
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$health_checker = new HealthChecker( $resolver, $probe );

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
			'id'                => '99982',
			'username'          => 'mcphookadmin',
			'email'             => 'mcphookadmin@example.com',
			'first_name'        => 'MCP',
			'last_name'         => 'Admin',
			'display_name'      => 'MCP Hook Admin',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'admin',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$stmt = $this->pdo->prepare(
			"INSERT INTO {$this->table_prefix}users
			(id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (:id, :username, :email, :first_name, :last_name, :display_name, :password_hash, :role, :is_email_verified, :created_at, :updated_at)"
		);
		$stmt->execute( $this->admin_user );

		$this->mcp_authorization->set_enabled( true );
		$this->mcp_authorization->set_write_enabled( true );
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		$this->cleanup_test_data();
		parent::tearDown();
	}

	private function cleanup_test_data(): void {
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id = 99982" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE destination_url LIKE '%hook-mcp-integration%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}settings WHERE setting_key LIKE 'mcp_%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id = 99982" );
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
		);
		foreach ( $headers as $k => $v ) {
			$server[ $k ] = $v;
		}

		return new Request(
			$method,
			$path,
			array(),
			$body,
			array(),
			$headers,
			$server
		);
	}

	/**
	 * Test that MCP create_link and update_link write tools traverse the standard link lifecycle hooks exactly once.
	 */
	public function test_mcp_write_tools_traverse_link_lifecycle_hooks(): void {
		// 1. Obtain an authorized OAuth token with links:write scope.
		$code_verifier  = bin2hex( random_bytes( 32 ) );
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->mcp_authorization->create_authorization_code(
			(string) $this->admin_user['id'],
			'hook-test-bot',
			self::TEST_REDIRECT_URI,
			$code_challenge,
			'S256',
			array( 'links:read', 'analytics:read', 'links:write' ),
			'Hook Test Bot'
		);

		$token_request = $this->create_request(
			'POST',
			'/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'hook-test-bot',
				'redirect_uri'  => self::TEST_REDIRECT_URI,
				'code_verifier' => $code_verifier,
			)
		);

		$token_response = $this->mcp_controller->oauth_token( $token_request );
		$access_token   = $token_response['body']['access_token'];

		// 2. Attach listeners to pre_create_link and link_created hooks.
		$pre_create_events = array();
		add_filter(
			'pre_create_link',
			function ( array $payload, ?Request $request, ?array $user ) use ( &$pre_create_events ): array {
				$pre_create_events[] = array(
					'payload' => $payload,
					'request' => $request,
					'user'    => $user,
				);
				return $payload;
			},
			10,
			3
		);

		$link_created_events = array();
		add_action(
			'link_created',
			function ( array $url, ?Request $request, ?array $user ) use ( &$link_created_events ): void {
				$link_created_events[] = array(
					'url'     => $url,
					'request' => $request,
					'user'    => $user,
				);
			},
			10,
			3
		);

		$destination_url_events = array();
		add_filter(
			'link_destination_url',
			function ( string $url, array $link, array $user ) use ( &$destination_url_events ): string {
				$destination_url_events[] = array(
					'url'  => $url,
					'link' => $link,
					'user' => $user,
				);
				return $url;
			},
			10,
			3
		);

		$alias_events = array();
		add_filter(
			'link_alias',
			function ( string $alias, array $link, array $user ) use ( &$alias_events ): string {
				$alias_events[] = array(
					'alias' => $alias,
					'link'  => $link,
					'user'  => $user,
				);
				return $alias;
			},
			10,
			3
		);

		$title_events = array();
		add_filter(
			'link_title',
			function ( string $title, array $link, array $user ) use ( &$title_events ): string {
				$title_events[] = array(
					'title' => $title,
					'link'  => $link,
					'user'  => $user,
				);
				return $title;
			},
			10,
			3
		);

		// 3. Execute MCP tools/call for create_link.
		$create_request = $this->create_request(
			'POST',
			'/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 101,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'create_link',
					'arguments' => array(
						'url'   => 'https://example.com/hook-mcp-integration-test',
						'title' => 'Hook MCP Test Link',
					),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$create_response = $this->mcp_controller->handle_mcp( $create_request );

		$this->assertSame( 200, $create_response['status'] );
		$this->assertFalse( $create_response['body']['result']['isError'] ?? false );

		$created_json = json_decode( $create_response['body']['result']['content'][0]['text'], true );
		$this->assertIsArray( $created_json );
		$this->assertSame( 'https://example.com/hook-mcp-integration-test', $created_json['destinationUrl'] );
		$link_id = $created_json['id'];

		// 4. Assert hook invocations: exactly once for each hook.
		$this->assertCount( 1, $pre_create_events, 'pre_create_link must be invoked exactly once during MCP create_link' );
		$this->assertSame( 'https://example.com/hook-mcp-integration-test', $pre_create_events[0]['payload']['destinationUrl'] );

		$this->assertCount( 1, $link_created_events, 'link_created must be invoked exactly once during MCP create_link' );
		$this->assertSame( $link_id, $link_created_events[0]['url']['id'] );
		$this->assertSame( 'https://example.com/hook-mcp-integration-test', $link_created_events[0]['url']['destinationUrl'] );

		$this->assertCount( 1, $destination_url_events, 'link_destination_url must be invoked during MCP create_link' );
		$this->assertSame( 'https://example.com/hook-mcp-integration-test', $destination_url_events[0]['url'] );
		$this->assertCount( 1, $alias_events, 'link_alias must be invoked during MCP create_link' );
		$this->assertCount( 1, $title_events, 'link_title must be invoked during MCP create_link' );
		$this->assertSame( 'Hook MCP Test Link', $title_events[0]['title'] );

		// 5. Attach listeners for update_link lifecycle hooks: pre_update_link and link_updated.
		$pre_update_events = array();
		add_filter(
			'pre_update_link',
			function ( array $payload, string $id, array $existing, ?Request $request, ?array $user ) use ( &$pre_update_events ): array {
				$pre_update_events[] = array(
					'payload'  => $payload,
					'id'       => $id,
					'existing' => $existing,
				);
				return $payload;
			},
			10,
			5
		);

		$link_updated_events = array();
		add_action(
			'link_updated',
			function ( array $url, array $existing, ?Request $request, ?array $user ) use ( &$link_updated_events ): void {
				$link_updated_events[] = array(
					'url'      => $url,
					'existing' => $existing,
				);
			},
			10,
			4
		);

		// 6. Execute MCP tools/call for update_link.
		$update_request = $this->create_request(
			'POST',
			'/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 102,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'update_link',
					'arguments' => array(
						'id'    => $link_id,
						'title' => 'Updated Hook MCP Test Link',
					),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$update_response = $this->mcp_controller->handle_mcp( $update_request );

		$this->assertSame( 200, $update_response['status'] );
		$this->assertFalse( $update_response['body']['result']['isError'] ?? false );

		// 7. Assert update hook invocations: exactly once for each hook.
		$this->assertCount( 1, $pre_update_events, 'pre_update_link must be invoked exactly once during MCP update_link' );
		$this->assertSame( $link_id, $pre_update_events[0]['id'] );
		$this->assertSame( 'Updated Hook MCP Test Link', $pre_update_events[0]['payload']['title'] );

		$this->assertCount( 1, $link_updated_events, 'link_updated must be invoked exactly once during MCP update_link' );
		$this->assertSame( $link_id, $link_updated_events[0]['url']['id'] );

		$this->assertCount( 2, $title_events, 'link_title must be invoked during MCP update_link' );
		$this->assertSame( 'Updated Hook MCP Test Link', $title_events[1]['title'] );
		$this->assertCount( 1, $destination_url_events, 'link_destination_url must not fire during update when destination is omitted' );
		$this->assertCount( 1, $alias_events, 'link_alias must not fire during update when alias is omitted' );

		// 8. Attach listener for link_status_changed and verify status transition via MCP update_link.
		$status_changed_events = array();
		add_action(
			'link_status_changed',
			function ( array $url, string $old_status, string $new_status, ?array $user, ?Request $request ) use ( &$status_changed_events ): void {
				$status_changed_events[] = array(
					'url'        => $url,
					'old_status' => $old_status,
					'new_status' => $new_status,
					'user'       => $user,
					'request'    => $request,
				);
			},
			10,
			5
		);

		$status_update_request = $this->create_request(
			'POST',
			'/mcp',
			array(
				'jsonrpc' => '2.0',
				'id'      => 103,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'update_link',
					'arguments' => array(
						'id'     => $link_id,
						'status' => 'inactive',
					),
				),
			),
			array(
				'HTTP_AUTHORIZATION' => "Bearer {$access_token}",
			)
		);

		$status_response = $this->mcp_controller->handle_mcp( $status_update_request );
		$this->assertSame( 200, $status_response['status'] );
		$this->assertFalse( $status_response['body']['result']['isError'] ?? false );

		$this->assertCount( 1, $status_changed_events, 'link_status_changed must be invoked exactly once during MCP status update' );
		$this->assertSame( 'active', $status_changed_events[0]['old_status'] );
		$this->assertSame( 'inactive', $status_changed_events[0]['new_status'] );
		$this->assertSame( $link_id, $status_changed_events[0]['url']['id'] );
		$this->assertSame( 'inactive', $status_changed_events[0]['url']['status'] );
	}
}
