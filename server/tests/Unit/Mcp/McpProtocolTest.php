<?php
/**
 * Unit tests for MCP JSON-RPC protocol handling.
 *
 * @package PeakURL\Tests\Unit\Mcp
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Mcp\Authorization;
use PeakURL\Features\Mcp\Constants;
use PeakURL\Features\Mcp\Service;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;

class McpProtocolTest extends TestCase {

	private Service $service;
	private Authorization $auth;
	/** @var array<string, string> */
	private array $settings_store = array();
	/** @var array<string, array<string, mixed>> */
	private array $users_store = array();

	protected function setUp(): void {
		parent::setUp();

		$this->settings_store = array();
		$this->users_store    = array(
			'1' => array(
				'id'           => '1',
				'username'     => 'admin',
				'email'        => 'admin@example.com',
				'display_name' => 'Administrator',
				'role'         => 'admin',
			),
			'2' => array(
				'id'           => '2',
				'username'     => 'editor',
				'email'        => 'editor@example.com',
				'display_name' => 'Editor User',
				'role'         => 'editor',
			),
		);

		$mock_db = $this->createMock( PeakURL_DB::class );
		$mock_db->method( 'get_row_by' )
			->willReturnCallback(
				function ( string $table, array $where ) {
					if ( 'users' === $table && isset( $where['id'] ) ) {
						return $this->users_store[ (string) $where['id'] ] ?? null;
					}
					return null;
				}
			);

		$settings_api = new class( $this->settings_store ) extends SettingsApi {
			/** @var array<string, string> */
			private array $store;

			public function __construct( array &$store ) {
				$this->store = &$store;
			}

			public function get_option( string $name ): ?string {
				return $this->store[ $name ] ?? null;
			}

			public function update_option( string $name, string $value, string $updated_at = '', bool $autoload = true ): void {
				$this->store[ $name ] = (string) $value;
			}

			public function update_option_with_lock( string $name, callable $modifier ): ?string {
				$val     = $this->store[ $name ] ?? null;
				$new_val = $modifier( $val );
				if ( null === $new_val ) {
					unset( $this->store[ $name ] );
				} else {
					$this->store[ $name ] = (string) $new_val;
				}
				return $new_val;
			}
		};

		$users_api = new UsersApi( $mock_db );
		$roles     = new Roles( $settings_api, $users_api );

		$config = array(
			'SITE_URL'        => 'https://peakurl.dev',
			'PEAKURL_VERSION' => '1.8.0',
		);

		$this->auth = new Authorization(
			$settings_api,
			$users_api,
			$roles,
			$config
		);

		$mock_links     = $this->createMock( LinksService::class );
		$mock_analytics = $this->createMock( AnalyticsService::class );

		$this->service = new Service(
			$this->auth,
			$mock_links,
			$mock_analytics,
			$config
		);
	}

	private function make_request( string $body, array $headers = array() ): Request {
		$server = array(
			'CONTENT_TYPE' => 'application/json',
		);
		foreach ( $headers as $k => $v ) {
			$canonical                      = strtoupper( str_replace( '-', '_', $k ) );
			$server[ 'HTTP_' . $canonical ] = $v;
		}

		$parsed_body = json_decode( $body, true );

		return new Request(
			'POST',
			'/mcp',
			array(),
			is_array( $parsed_body ) ? $parsed_body : array(),
			array(),
			array(),
			$server,
			$body
		);
	}

	public function test_mcp_disabled_by_default_rejects_requests(): void {
		$request  = $this->make_request( '{"jsonrpc": "2.0", "id": 1, "method": "ping"}' );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 503, $response['status'] );
		$this->assertSame( Constants::MCP_UNAUTHORIZED, $response['body']['error']['code'] );
		$this->assertStringContainsString( 'disabled', $response['body']['error']['message'] );
	}

	public function test_invalid_json_returns_parse_error(): void {
		$this->auth->set_enabled( true );

		$request  = $this->make_request( '{invalid-json' );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( Constants::JSONRPC_PARSE_ERROR, $response['body']['error']['code'] );
	}

	public function test_invalid_rpc_envelope_returns_invalid_request(): void {
		$this->auth->set_enabled( true );

		$request  = $this->make_request( '{"id": 1, "method": "ping"}' );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( Constants::JSONRPC_INVALID_REQUEST, $response['body']['error']['code'] );
	}

	public function test_initialize_handshake_returns_capabilities(): void {
		$this->auth->set_enabled( true );

		$init_body = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'init-1',
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Constants::PROTOCOL_VERSION,
					'clientInfo'      => array(
						'name'    => 'ChatGPT',
						'version' => '1.0.0',
					),
				),
			)
		);

		$request  = $this->make_request( (string) $init_body );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( '2.0', $response['body']['jsonrpc'] );
		$this->assertSame( 'init-1', $response['body']['id'] );
		$this->assertSame( Constants::PROTOCOL_VERSION, $response['body']['result']['protocolVersion'] );
		$this->assertSame( Constants::SERVER_NAME, $response['body']['result']['serverInfo']['name'] );
		$this->assertArrayHasKey( 'tools', $response['body']['result']['capabilities'] );
	}

	public function test_unsupported_protocol_version_header_returns_400(): void {
		$this->auth->set_enabled( true );

		$request  = $this->make_request(
			'{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
			array(
				'Mcp-Protocol-Version' => '2024-11-05',
			)
		);
		$response = $this->service->handle_request( $request );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( Constants::JSONRPC_INVALID_REQUEST, $response['body']['error']['code'] );
		$this->assertStringContainsString( 'Unsupported MCP-Protocol-Version', $response['body']['error']['message'] );
	}

	public function test_ping_returns_success_result(): void {
		$this->auth->set_enabled( true );

		$request  = $this->make_request( '{"jsonrpc": "2.0", "id": 42, "method": "ping"}' );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 42, $response['body']['id'] );
		$this->assertEmpty( (array) $response['body']['result'] );
	}

	public function test_unauthenticated_tools_list_returns_401_with_rfc9728_headers(): void {
		$this->auth->set_enabled( true );

		$request  = $this->make_request( '{"jsonrpc": "2.0", "id": 100, "method": "tools/list"}' );
		$response = $this->service->handle_request( $request );

		$this->assertSame( 401, $response['status'] );
		$this->assertArrayHasKey( 'WWW-Authenticate', $response['headers'] );
		$this->assertArrayHasKey( 'Link', $response['headers'] );
		$this->assertStringContainsString( 'oauth-protected-resource', $response['headers']['Link'] );
		$this->assertSame( Constants::MCP_UNAUTHORIZED, $response['body']['error']['code'] );
	}

	public function test_authenticated_tools_list_and_whoami(): void {
		$this->auth->set_enabled( true );

		$verifier  = 'verifier_abc_123_456_789_000';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->auth->create_authorization_code(
			'1',
			'chatgpt',
			'https://chatgpt.com/connector_platform_oauth_redirect',
			$challenge,
			'S256',
			array( Constants::SCOPE_LINKS_READ, Constants::SCOPE_ANALYTICS_READ, Constants::SCOPE_LINKS_WRITE ),
			'ChatGPT Cloud'
		);

		$tokens = $this->auth->exchange_authorization_code(
			$code,
			$verifier,
			'https://chatgpt.com/connector_platform_oauth_redirect',
			'chatgpt'
		);

		// Test tools/list.
		$list_body = '{"jsonrpc": "2.0", "id": 201, "method": "tools/list"}';
		$list_req  = $this->make_request( $list_body, array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ) );
		$list_res  = $this->service->handle_request( $list_req );

		$this->assertSame( 200, $list_res['status'] );
		$tools = $list_res['body']['result']['tools'];
		$this->assertNotEmpty( $tools );

		$tool_names = array_column( $tools, 'name' );
		$this->assertContains( 'whoami', $tool_names );
		$this->assertContains( 'list_links', $tool_names );

		// Test tools/call whoami.
		$call_body = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 202,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'whoami',
					'arguments' => (object) array(),
				),
			)
		);

		$call_req = $this->make_request( (string) $call_body, array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ) );
		$call_res = $this->service->handle_request( $call_req );

		$this->assertSame( 200, $call_res['status'] );
		$content = $call_res['body']['result']['content'][0]['text'];
		$this->assertStringContainsString( '"username": "admin"', $content );
		$this->assertStringContainsString( '"role": "admin"', $content );
		$this->assertStringContainsString( 'ChatGPT Cloud', $content );
	}

	public function test_tools_call_argument_validation(): void {
		$this->auth->set_enabled( true );
		$this->auth->set_write_enabled( true );

		$verifier  = 'verifier_def_789_012_345';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->auth->create_authorization_code(
			'1',
			'chatgpt',
			'https://chatgpt.com/connector_platform_oauth_redirect',
			$challenge,
			'S256',
			array( Constants::SCOPE_LINKS_READ, Constants::SCOPE_LINKS_WRITE ),
			'ChatGPT Cloud'
		);

		$tokens = $this->auth->exchange_authorization_code(
			$code,
			$verifier,
			'https://chatgpt.com/connector_platform_oauth_redirect',
			'chatgpt'
		);

		// Missing required argument for get_link.
		$bad_req1 = $this->make_request(
			'{"jsonrpc": "2.0", "id": 301, "method": "tools/call", "params": {"name": "get_link", "arguments": {}}}',
			array( 'Authorization' => 'Bearer ' . $tokens['access_token'] )
		);
		$bad_res1 = $this->service->handle_request( $bad_req1 );
		$this->assertSame( 400, $bad_res1['status'] );
		$this->assertSame( Constants::JSONRPC_INVALID_PARAMS, $bad_res1['body']['error']['code'] );
		$this->assertStringContainsString( 'Missing required argument', $bad_res1['body']['error']['message'] );

		// Invalid URL format for create_link.
		$bad_req2 = $this->make_request(
			'{"jsonrpc": "2.0", "id": 302, "method": "tools/call", "params": {"name": "create_link", "arguments": {"url": "not-a-valid-url"}}}',
			array( 'Authorization' => 'Bearer ' . $tokens['access_token'] )
		);
		$bad_res2 = $this->service->handle_request( $bad_req2 );
		$this->assertSame( 400, $bad_res2['status'] );
		$this->assertSame( Constants::JSONRPC_INVALID_PARAMS, $bad_res2['body']['error']['code'] );
		$this->assertStringContainsString( 'destination URL must be a valid HTTP or HTTPS', $bad_res2['body']['error']['message'] );
	}

	public function test_host_and_origin_validation(): void {
		$this->auth->set_enabled( true );

		// Invalid host (DNS rebinding attempt).
		$bad_host_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
			array( 'Host' => 'attacker.evil.com' )
		);
		$bad_host_res = $this->service->handle_request( $bad_host_req );
		$this->assertSame( 403, $bad_host_res['status'] );
		$this->assertStringContainsString( 'Invalid Host header', $bad_host_res['body']['error']['message'] );

		// Invalid origin (CSRF attempt).
		$bad_origin_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 2, "method": "ping"}',
			array( 'Origin' => 'https://malicious-site.com' )
		);
		$bad_origin_res = $this->service->handle_request( $bad_origin_req );
		$this->assertSame( 403, $bad_origin_res['status'] );
		$this->assertStringContainsString( 'Forbidden: Cross-origin request not allowed', $bad_origin_res['body']['error']['message'] );

		// Opaque / null origin.
		$null_origin_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 21, "method": "ping"}',
			array( 'Origin' => 'null' )
		);
		$null_origin_res = $this->service->handle_request( $null_origin_req );
		$this->assertSame( 403, $null_origin_res['status'] );
		$this->assertStringContainsString( 'Forbidden: Cross-origin request not allowed', $null_origin_res['body']['error']['message'] );

		// Malformed unparseable origin.
		$malformed_origin_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 22, "method": "ping"}',
			array( 'Origin' => 'invalid-not-a-url' )
		);
		$malformed_origin_res = $this->service->handle_request( $malformed_origin_req );
		$this->assertSame( 403, $malformed_origin_res['status'] );
		$this->assertStringContainsString( 'Forbidden: Cross-origin request not allowed', $malformed_origin_res['body']['error']['message'] );

		// Valid host.
		$good_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 3, "method": "ping"}',
			array( 'Host' => 'peakurl.dev' )
		);
		$good_res = $this->service->handle_request( $good_req );
		$this->assertSame( 200, $good_res['status'] );
	}

	public function test_content_type_enforcement(): void {
		$this->auth->set_enabled( true );

		// Unsupported content-type (text/plain).
		$server = array(
			'CONTENT_TYPE' => 'text/plain',
			'HTTP_HOST'    => 'peakurl.dev',
		);
		$req    = new Request(
			'POST',
			'/mcp',
			array(),
			array(),
			array(),
			array(),
			$server,
			'{"jsonrpc": "2.0", "id": 1, "method": "ping"}'
		);
		$res    = $this->service->handle_request( $req );
		$this->assertSame( 415, $res['status'] );
		$this->assertStringContainsString( 'Unsupported Media Type', $res['body']['error']['message'] );

		// Valid content-type with charset.
		$server['CONTENT_TYPE'] = 'application/json; charset=utf-8';
		$valid_req              = new Request(
			'POST',
			'/mcp',
			array(),
			array(),
			array(),
			array(),
			$server,
			'{"jsonrpc": "2.0", "id": 2, "method": "ping"}'
		);
		$valid_res              = $this->service->handle_request( $valid_req );
		$this->assertSame( 200, $valid_res['status'] );
	}

	public function test_accept_header_validation(): void {
		$this->auth->set_enabled( true );

		// Unsupported accept header.
		$bad_accept_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
			array( 'Accept' => 'text/html' )
		);
		$bad_accept_res = $this->service->handle_request( $bad_accept_req );
		$this->assertSame( 406, $bad_accept_res['status'] );
		$this->assertStringContainsString( 'Not Acceptable', $bad_accept_res['body']['error']['message'] );

		// Valid accept header (text/event-stream).
		$good_accept_req = $this->make_request(
			'{"jsonrpc": "2.0", "id": 2, "method": "ping"}',
			array( 'Accept' => 'text/event-stream' )
		);
		$good_accept_res = $this->service->handle_request( $good_accept_req );
		$this->assertSame( 200, $good_accept_res['status'] );
	}

	public function test_batch_requests_execution(): void {
		$this->auth->set_enabled( true );

		$batch_body = '[{"jsonrpc": "2.0", "id": 1, "method": "ping"}, {"jsonrpc": "2.0", "id": 2, "method": "ping"}]';
		$req        = $this->make_request( $batch_body );
		$res        = $this->service->handle_request( $req );

		$this->assertSame( 200, $res['status'] );
		$this->assertIsArray( $res['body'] );
		$this->assertCount( 2, $res['body'] );
		$this->assertSame( 1, $res['body'][0]['id'] );
		$this->assertSame( 2, $res['body'][1]['id'] );
	}

	public function test_empty_batch_returns_400(): void {
		$this->auth->set_enabled( true );

		$req = $this->make_request( '[]' );
		$res = $this->service->handle_request( $req );

		$this->assertSame( 400, $res['status'] );
		$this->assertSame( Constants::JSONRPC_INVALID_REQUEST, $res['body']['error']['code'] );
	}

	public function test_batch_with_notifications_omits_notification_responses(): void {
		$this->auth->set_enabled( true );

		$batch_body = '[{"jsonrpc": "2.0", "method": "notifications/initialized"}, {"jsonrpc": "2.0", "id": 10, "method": "ping"}]';
		$req        = $this->make_request( $batch_body );
		$res        = $this->service->handle_request( $req );

		$this->assertSame( 200, $res['status'] );
		$this->assertIsArray( $res['body'] );
		$this->assertCount( 1, $res['body'] );
		$this->assertSame( 10, $res['body'][0]['id'] );
	}

	public function test_batch_all_notifications_returns_202(): void {
		$this->auth->set_enabled( true );

		$batch_body = '[{"jsonrpc": "2.0", "method": "notifications/initialized"}, {"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": "1"}}]';
		$req        = $this->make_request( $batch_body );
		$res        = $this->service->handle_request( $req );

		$this->assertSame( 202, $res['status'] );
		$this->assertSame( '', $res['body'] );
	}

	public function test_unauthenticated_notification_for_tools_enforces_authorization_boundary(): void {
		$this->auth->set_enabled( true );

		// tool call without id (notification attempt) must enforce auth and return 401
		$req = $this->make_request( '{"jsonrpc": "2.0", "method": "tools/call", "params": {"name": "whoami"}}' );
		$res = $this->service->handle_request( $req );

		$this->assertSame( 401, $res['status'] );
		$this->assertSame( Constants::MCP_UNAUTHORIZED, $res['body']['error']['code'] );
	}

	public function test_notification_initialized_returns_202_with_empty_body(): void {
		$this->auth->set_enabled( true );

		$req = $this->make_request( '{"jsonrpc": "2.0", "method": "notifications/initialized"}' );
		$res = $this->service->handle_request( $req );

		$this->assertSame( 202, $res['status'] );
		$this->assertSame( Constants::PROTOCOL_VERSION, $res['headers']['MCP-Protocol-Version'] );
		$this->assertSame( '', $res['body'], 'Notification response must have no body' );

		// Custom notification also returns 202 without body.
		$cancel_req = $this->make_request( '{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": "123"}}' );
		$cancel_res = $this->service->handle_request( $cancel_req );

		$this->assertSame( 202, $cancel_res['status'] );
		$this->assertSame( '', $cancel_res['body'], 'Notification response must have no body' );
	}

	public function test_additional_properties_and_schema_validation(): void {
		$this->auth->set_enabled( true );
		$this->auth->set_write_enabled( true );

		$tool_def = \PeakURL\Features\Mcp\Registry::get_tool( 'list_links' );
		$this->assertNotNull( $tool_def );

		// 1. Unexpected argument rejected.
		$err1 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'unexpected_extra_property' => 'evil' )
		);
		$this->assertStringContainsString( 'Unexpected argument: "unexpected_extra_property"', $err1 );

		// 2. Float where integer is required rejected.
		$err2 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'limit' => 25.5 )
		);
		$this->assertStringContainsString( 'Argument "limit" must be an integer.', $err2 );

		// 3. String with decimal point rejected for integer.
		$err3 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'limit' => '25.5' )
		);
		$this->assertStringContainsString( 'Argument "limit" must be an integer.', $err3 );

		// 4. Integer below minimum rejected.
		$err4 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'limit' => 0 )
		);
		$this->assertStringContainsString( 'Argument "limit" must be at least 1.', $err4 );

		// 5. Integer above maximum rejected.
		$err5 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'limit' => 150 )
		);
		$this->assertStringContainsString( 'Argument "limit" must be at most 100.', $err5 );

		// 6. Invalid enum rejected.
		$err6 = $this->service->validate_tool_arguments(
			$tool_def,
			array( 'sort' => 'invalid_column' )
		);
		$this->assertStringContainsString( 'Argument "sort" must be one of', $err6 );

		// 7. Valid arguments pass.
		$err7 = $this->service->validate_tool_arguments(
			$tool_def,
			array(
				'limit' => 50,
				'sort'  => 'created_at',
				'order' => 'desc',
			)
		);
		$this->assertNull( $err7 );
	}
}
