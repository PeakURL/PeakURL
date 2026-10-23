<?php
/**
 * Unit tests for MCP OAuth Discovery and Controller endpoints.
 *
 * @package PeakURL\Tests\Unit\Mcp
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization as CoreAuthorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Mcp\Authorization;
use PeakURL\Features\Mcp\Constants;
use PeakURL\Features\Mcp\Controller;
use PeakURL\Features\Mcp\Service;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;

class McpDiscoveryTest extends TestCase {

	private Controller $controller;
	private Authorization $auth;
	private Roles $roles;
	/** @var array<string, string> */
	private array $settings_store = array();
	/** @var array<string, array<string, mixed>> */
	private array $users_store = array();
	/** @var array<string, mixed> */
	private array $current_user;

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

		$users_api   = new UsersApi( $mock_db );
		$this->roles = new Roles( $settings_api, $users_api );

		$config = array(
			'SITE_URL'        => 'https://peakurl.dev',
			'PEAKURL_VERSION' => '1.8.0',
		);

		$this->auth = new Authorization(
			$settings_api,
			$users_api,
			$this->roles,
			$config
		);

		$mock_links     = $this->createMock( LinksService::class );
		$mock_analytics = $this->createMock( AnalyticsService::class );
		$mock_auth      = $this->createMock( AuthService::class );

		$this->current_user = $this->users_store['1'];
		$mock_auth->method( 'get_current_user' )
			->willReturnCallback( fn() => $this->current_user );
		$mock_auth->method( 'current_user' )
			->willReturnCallback( fn() => $this->current_user );

		$core_auth = new CoreAuthorization( $this->roles );

		$service = new Service(
			$this->auth,
			$mock_links,
			$mock_analytics,
			$config
		);

		$this->controller = new Controller(
			$service,
			$this->auth,
			$mock_auth,
			$core_auth,
			$config
		);
	}

	public function test_protected_resource_metadata_rfc9728(): void {
		$request  = new Request( 'GET', '/.well-known/oauth-protected-resource/api/mcp', array(), array() );
		$response = $this->controller->oauth_protected_resource_metadata( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'https://peakurl.dev/api/mcp', $response['body']['resource'] );
		$this->assertSame( array( 'https://peakurl.dev' ), $response['body']['authorization_servers'] );
		$this->assertSame( Constants::ALL_SCOPES, $response['body']['scopes_supported'] );
	}

	public function test_authorization_server_metadata_rfc8414(): void {
		$request  = new Request( 'GET', '/.well-known/oauth-authorization-server', array(), array() );
		$response = $this->controller->oauth_authorization_server_metadata( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'https://peakurl.dev', $response['body']['issuer'] );
		$this->assertSame( 'https://peakurl.dev/oauth/authorize', $response['body']['authorization_endpoint'] );
		$this->assertSame( 'https://peakurl.dev/api/mcp/oauth/token', $response['body']['token_endpoint'] );
		$this->assertSame( 'https://peakurl.dev/api/mcp/oauth/revoke', $response['body']['revocation_endpoint'] );
		$this->assertSame( array( 'none' ), $response['body']['token_endpoint_auth_methods_supported'] );
		$this->assertContains( 'S256', $response['body']['code_challenge_methods_supported'] );
	}

	public function test_oauth_authorize_renders_consent_screen(): void {
		$this->auth->set_enabled( true );

		$request = new Request(
			'GET',
			'/oauth/authorize',
			array(
				'response_type'         => 'code',
				'client_id'             => 'chatgpt',
				'redirect_uri'          => 'https://chatgpt.com/connector_platform_oauth_redirect',
				'scope'                 => 'links:read analytics:read',
				'state'                 => 'xyz987',
				'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSST-hEE',
				'code_challenge_method' => 'S256',
			),
			array()
		);

		$response = $this->controller->oauth_authorize( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertStringContainsString( 'Connect to PeakURL', $response['body'] );
		$this->assertStringContainsString( 'chatgpt', $response['body'] );
	}

	public function test_oauth_authorize_submit_generates_code_and_redirects(): void {
		$this->auth->set_enabled( true );

		$authorize_request = new Request(
			'GET',
			'/oauth/authorize',
			array(
				'response_type'         => 'code',
				'client_id'             => 'chatgpt',
				'redirect_uri'          => 'https://chatgpt.com/connector_platform_oauth_redirect',
				'scope'                 => 'links:read',
				'state'                 => 'state123',
				'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSST-hEE',
				'code_challenge_method' => 'S256',
			),
			array()
		);

		$consent_response = $this->controller->oauth_authorize( $authorize_request );
		$this->assertSame( 200, $consent_response['status'] );

		preg_match( '/name="transaction_id"\s+value="([^"]+)"/', $consent_response['body'], $tx_match );
		preg_match( '/name="csrf_token"\s+value="([^"]+)"/', $consent_response['body'], $csrf_match );

		$this->assertNotEmpty( $tx_match[1] ?? '' );
		$this->assertNotEmpty( $csrf_match[1] ?? '' );

		$request = new Request(
			'POST',
			'/oauth/authorize',
			array(),
			array(
				'action'         => 'approve',
				'transaction_id' => $tx_match[1],
				'csrf_token'     => $csrf_match[1],
			)
		);

		$response = $this->controller->oauth_authorize_submit( $request );

		$this->assertSame( 302, $response['status'] );
		$location = $response['headers']['Location'];
		$this->assertStringStartsWith( 'https://chatgpt.com/connector_platform_oauth_redirect?code=', $location );
		$this->assertStringContainsString( 'state=state123', $location );
	}

	public function test_oauth_endpoints_return_503_when_disabled(): void {
		$this->auth->set_enabled( false );

		$req_get = new Request( 'GET', '/oauth/authorize', array(), array() );
		$res_get = $this->controller->oauth_authorize( $req_get );
		$this->assertSame( 503, $res_get['status'] );

		$req_mcp_get = new Request( 'GET', '/api/mcp', array(), array() );
		$res_mcp_get = $this->controller->handle_mcp_get( $req_mcp_get );
		$this->assertSame( 503, $res_mcp_get['status'] );

		$req_tok = new Request( 'POST', '/api/mcp/oauth/token', array(), array( 'grant_type' => 'authorization_code' ) );
		$res_tok = $this->controller->oauth_token( $req_tok );
		$this->assertSame( 503, $res_tok['status'] );
	}

	public function test_handle_mcp_get_returns_405_method_not_allowed(): void {
		$this->auth->set_enabled( true );

		$req      = new Request( 'GET', '/api/mcp', array(), array() );
		$response = $this->controller->handle_mcp_get( $req );

		$this->assertSame( 405, $response['status'] );
		$this->assertSame( 'POST, OPTIONS', $response['headers']['Allow'] );
		$this->assertSame( '2025-03-26', $response['headers']['MCP-Protocol-Version'] );
		$this->assertSame( 'method_not_allowed', $response['body']['error'] );
	}

	public function test_dashboard_settings_get_and_update(): void {
		$get_req  = new Request( 'GET', '/api/v1/system/mcp/settings', array(), array() );
		$response = $this->controller->get_settings( $get_req );

		$this->assertSame( 200, $response['status'] );
		$this->assertFalse( $response['body']['data']['enabled'] );
		$this->assertFalse( $response['body']['data']['writeEnabled'] );
		$this->assertSame( '2025-03-26', $response['body']['data']['protocolVersion'] );
		$this->assertSame( 'https://peakurl.dev/api/mcp', $response['body']['data']['endpoint'] );

		// Update settings.
		$put_req = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'      => true,
				'writeEnabled' => true,
			)
		);

		$updated_res = $this->controller->update_settings( $put_req );
		$this->assertSame( 200, $updated_res['status'] );
		$this->assertTrue( $updated_res['body']['data']['enabled'] );
		$this->assertTrue( $updated_res['body']['data']['writeEnabled'] );
	}

	public function test_dashboard_tools_list(): void {
		$req      = new Request( 'GET', '/api/v1/system/mcp/tools', array(), array() );
		$response = $this->controller->list_tools( $req );

		$this->assertSame( 200, $response['status'] );
		$this->assertNotEmpty( $response['body']['data']['tools'] );
	}

	public function test_user_with_manage_integrations_alone_receives_403_forbidden_on_all_mcp_management_endpoints(): void {
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_integrations' => true,
					'manage_mcp'          => false,
				),
			)
		);

		$this->assertTrue( $this->roles->has_capability( $this->users_store['2'], 'manage_integrations' ) );
		$this->assertFalse( $this->roles->has_capability( $this->users_store['2'], 'manage_mcp' ) );

		$this->current_user = $this->users_store['2'];

		// 1. get_settings
		try {
			$this->controller->get_settings( new Request( 'GET', '/api/v1/system/mcp/settings', array(), array() ) );
			$this->fail( 'Expected 403 on get_settings' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 2. update_settings
		try {
			$this->controller->update_settings( new Request( 'PUT', '/api/v1/system/mcp/settings', array(), array( 'enabled' => true ) ) );
			$this->fail( 'Expected 403 on update_settings' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 3. list_connections
		try {
			$this->controller->list_connections( new Request( 'GET', '/api/v1/system/mcp/connections', array(), array() ) );
			$this->fail( 'Expected 403 on list_connections' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 4. revoke_connection
		try {
			$req = new Request( 'DELETE', '/api/v1/system/mcp/connections/test_id', array(), array() );
			$req->set_route_params( array( 'id' => 'test_id' ) );
			$this->controller->revoke_connection( $req );
			$this->fail( 'Expected 403 on revoke_connection' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 5. revoke_all_connections
		try {
			$this->controller->revoke_all_connections( new Request( 'POST', '/api/v1/system/mcp/connections/revoke-all', array(), array() ) );
			$this->fail( 'Expected 403 on revoke_all_connections' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 6. list_tools
		try {
			$this->controller->list_tools( new Request( 'GET', '/api/v1/system/mcp/tools', array(), array() ) );
			$this->fail( 'Expected 403 on list_tools' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}

	public function test_delegating_manage_mcp_allows_access_to_all_management_endpoints(): void {
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_mcp' => true,
				),
			)
		);
		$this->assertTrue( $this->roles->has_capability( $this->users_store['2'], 'manage_mcp' ) );

		$this->current_user = $this->users_store['2'];

		// 1. get_settings
		$res = $this->controller->get_settings( new Request( 'GET', '/api/v1/system/mcp/settings', array(), array() ) );
		$this->assertSame( 200, $res['status'] );

		// 2. update_settings
		$res = $this->controller->update_settings(
			new Request(
				'PUT',
				'/api/v1/system/mcp/settings',
				array(),
				array(
					'enabled'      => true,
					'writeEnabled' => true,
				)
			)
		);
		$this->assertSame( 200, $res['status'] );
		$this->assertTrue( $res['body']['data']['enabled'] );

		// Create a connection to revoke.
		$verifier  = 'verifier_delegation_test_12345';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$code      = $this->auth->create_authorization_code(
			'2',
			'test-client',
			'https://chatgpt.com/connector_platform_oauth_redirect',
			$challenge,
			'S256',
			array( Constants::SCOPE_LINKS_READ )
		);
		$this->auth->exchange_authorization_code(
			$code,
			$verifier,
			'https://chatgpt.com/connector_platform_oauth_redirect',
			'test-client'
		);

		// 3. list_connections
		$res = $this->controller->list_connections( new Request( 'GET', '/api/v1/system/mcp/connections', array(), array() ) );
		$this->assertSame( 200, $res['status'] );
		$this->assertCount( 1, $res['body']['data']['connections'] );
		$conn_id = $res['body']['data']['connections'][0]['id'];

		// 4. revoke_connection
		$req = new Request( 'DELETE', "/api/v1/system/mcp/connections/{$conn_id}", array(), array() );
		$req->set_route_params( array( 'id' => $conn_id ) );
		$res = $this->controller->revoke_connection( $req );
		$this->assertSame( 200, $res['status'] );
		$this->assertTrue( $res['body']['data']['revoked'] );

		// 5. revoke_all_connections
		$res = $this->controller->revoke_all_connections( new Request( 'POST', '/api/v1/system/mcp/connections/revoke-all', array(), array() ) );
		$this->assertSame( 200, $res['status'] );

		// 6. list_tools
		$res = $this->controller->list_tools( new Request( 'GET', '/api/v1/system/mcp/tools', array(), array() ) );
		$this->assertSame( 200, $res['status'] );
	}

	public function test_revoking_manage_mcp_restores_403_forbidden(): void {
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_mcp' => true,
				),
			)
		);
		$this->current_user = $this->users_store['2'];

		$res = $this->controller->get_settings( new Request( 'GET', '/api/v1/system/mcp/settings', array(), array() ) );
		$this->assertSame( 200, $res['status'] );

		// Revoke capability.
		$this->roles->update_role_definition(
			'editor',
			array(
				'capabilities' => array(
					'manage_mcp' => false,
				),
			)
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->controller->get_settings( new Request( 'GET', '/api/v1/system/mcp/settings', array(), array() ) );
	}

	public function test_update_settings_rejects_non_boolean_enabled_values(): void {
		$invalid_values = array(
			'string true'  => 'true',
			'string false' => 'false',
			'empty string' => '',
			'integer 1'    => 1,
			'integer 0'    => 0,
			'float 1.0'    => 1.0,
			'array'        => array(),
			'null'         => null,
		);

		foreach ( $invalid_values as $label => $value ) {
			$req = new Request(
				'PUT',
				'/api/v1/system/mcp/settings',
				array(),
				array( 'enabled' => $value )
			);
			$res = $this->controller->update_settings( $req );
			$this->assertSame( 400, $res['status'], "Failed rejecting invalid enabled value for: {$label}" );
			$this->assertFalse( $res['body']['success'] );
			$this->assertStringContainsString( 'must be a boolean', $res['body']['message'] );
		}
	}

	public function test_update_settings_rejects_non_boolean_write_enabled_values(): void {
		$invalid_values = array(
			'string true'  => 'true',
			'string false' => 'false',
			'empty string' => '',
			'integer 1'    => 1,
			'integer 0'    => 0,
			'float 1.0'    => 1.0,
			'array'        => array(),
			'null'         => null,
		);

		foreach ( $invalid_values as $label => $value ) {
			$req = new Request(
				'PUT',
				'/api/v1/system/mcp/settings',
				array(),
				array( 'writeEnabled' => $value )
			);
			$res = $this->controller->update_settings( $req );
			$this->assertSame( 400, $res['status'], "Failed rejecting invalid writeEnabled value for: {$label}" );
			$this->assertFalse( $res['body']['success'] );
			$this->assertStringContainsString( 'must be a boolean', $res['body']['message'] );
		}
	}

	public function test_update_settings_rejects_invalid_redirect_uri_collections(): void {
		$invalid_collections = array(
			'string scalar'       => 'https://chatgpt.com/callback',
			'integer'             => 12345,
			'boolean'             => true,
			'associative array'   => array( 'callback' => 'https://chatgpt.com/callback' ),
			'array with integer'  => array( 123 ),
			'array with boolean'  => array( true ),
			'array with array'    => array( array( 'https://chatgpt.com/callback' ) ),
			'array with null'     => array( null ),
			'array with empty'    => array( '' ),
			'array with spaces'   => array( '   ' ),
			'not an absolute url' => array( 'relative/path/callback' ),
			'missing scheme'      => array( 'chatgpt.com/callback' ),
			'unsupported scheme'  => array( 'ftp://example.com/callback' ),
			'javascript scheme'   => array( 'javascript:alert(1)' ),
		);

		foreach ( $invalid_collections as $label => $collection ) {
			$req = new Request(
				'PUT',
				'/api/v1/system/mcp/settings',
				array(),
				array( 'allowedRedirectUris' => $collection )
			);
			$res = $this->controller->update_settings( $req );
			$this->assertSame( 400, $res['status'], "Failed rejecting invalid redirect URIs for: {$label}" );
			$this->assertFalse( $res['body']['success'] );
		}
	}

	public function test_update_settings_accepts_valid_payload_and_updates_state(): void {
		$valid_uris = array(
			'https://chatgpt.com/connector_platform_oauth_redirect',
			'https://claude.ai/api/mcp/oauth/callback',
			'http://localhost:3000/callback',
		);

		$req = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'             => true,
				'writeEnabled'        => true,
				'allowedRedirectUris' => $valid_uris,
			)
		);

		$res = $this->controller->update_settings( $req );
		$this->assertSame( 200, $res['status'] );
		$this->assertTrue( $res['body']['success'] );
		$this->assertTrue( $res['body']['data']['enabled'] );
		$this->assertTrue( $res['body']['data']['writeEnabled'] );

		// Verify allowed redirect URIs were passed to and saved in authorization service.
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://chatgpt.com/connector_platform_oauth_redirect' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://claude.ai/api/mcp/oauth/callback' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'http://localhost:3000/callback' ) );

		// Toggle back to disabled.
		$req_off = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'      => false,
				'writeEnabled' => false,
			)
		);

		$res_off = $this->controller->update_settings( $req_off );
		$this->assertSame( 200, $res_off['status'] );
		$this->assertFalse( $res_off['body']['data']['enabled'] );
		$this->assertFalse( $res_off['body']['data']['writeEnabled'] );
	}

	public function test_update_settings_atomic_validation_prevents_partial_state_changes(): void {
		// Ensure initial state: both flags disabled.
		$this->auth->set_enabled( false );
		$this->auth->set_write_enabled( false );
		$this->assertFalse( $this->auth->is_enabled() );
		$this->assertFalse( $this->auth->is_write_enabled() );

		// 1. Valid enabled (true) + invalid writeEnabled (string).
		$req1 = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'      => true,
				'writeEnabled' => 'invalid_string',
			)
		);
		$res1 = $this->controller->update_settings( $req1 );
		$this->assertSame( 400, $res1['status'] );
		$this->assertFalse( $res1['body']['success'] );
		$this->assertFalse( $this->auth->is_enabled(), 'enabled must not be mutated if writeEnabled fails validation' );
		$this->assertFalse( $this->auth->is_write_enabled() );

		// 2. Valid enabled (true) + invalid redirect URI (insecure remote HTTP).
		$req2 = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'             => true,
				'allowedRedirectUris' => array( 'http://insecure-remote.example.com/oauth/callback' ),
			)
		);
		$res2 = $this->controller->update_settings( $req2 );
		$this->assertSame( 400, $res2['status'] );
		$this->assertFalse( $res2['body']['success'] );
		$this->assertFalse( $this->auth->is_enabled(), 'enabled must not be mutated if allowedRedirectUris fails validation' );

		// 3. Set enabled to true legitimately.
		$this->auth->set_enabled( true );
		$this->assertTrue( $this->auth->is_enabled() );

		// 4. Mutation to disable + invalid redirect URI with fragment.
		$req3 = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'enabled'             => false,
				'allowedRedirectUris' => array( 'https://chatgpt.com/callback#token=123' ),
			)
		);
		$res3 = $this->controller->update_settings( $req3 );
		$this->assertSame( 400, $res3['status'] );
		$this->assertFalse( $res3['body']['success'] );
		$this->assertTrue( $this->auth->is_enabled(), 'enabled must remain true if allowedRedirectUris fails validation' );
	}

	public function test_update_settings_rejects_fragments_and_userinfo(): void {
		// Fragment rejection.
		$req_fragment = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'allowedRedirectUris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect#access_token=xyz' ),
			)
		);
		$res_fragment = $this->controller->update_settings( $req_fragment );
		$this->assertSame( 400, $res_fragment['status'] );
		$this->assertFalse( $res_fragment['body']['success'] );
		$this->assertStringContainsString( 'fragment', $res_fragment['body']['message'] );

		// Userinfo rejection.
		$req_userinfo = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'allowedRedirectUris' => array( 'https://user:pass@claude.ai/api/mcp/oauth/callback' ),
			)
		);
		$res_userinfo = $this->controller->update_settings( $req_userinfo );
		$this->assertSame( 400, $res_userinfo['status'] );
		$this->assertFalse( $res_userinfo['body']['success'] );
		$this->assertStringContainsString( 'user information', $res_userinfo['body']['message'] );

		// Remote HTTP (non-loopback) rejection.
		$req_remote_http = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array(
				'allowedRedirectUris' => array( 'http://example.com/oauth/callback' ),
			)
		);
		$res_remote_http = $this->controller->update_settings( $req_remote_http );
		$this->assertSame( 400, $res_remote_http['status'] );
		$this->assertFalse( $res_remote_http['body']['success'] );
		$this->assertStringContainsString( 'HTTPS', $res_remote_http['body']['message'] );
	}

	public function test_update_settings_handles_persistence_failures_predictably(): void {
		$failing_auth = $this->createMock( Authorization::class );
		$failing_auth->method( 'set_enabled' )
			->willThrowException( new \RuntimeException( 'Disk error or database lock failure' ) );

		$mock_links     = $this->createMock( LinksService::class );
		$mock_analytics = $this->createMock( AnalyticsService::class );
		$mock_auth      = $this->createMock( AuthService::class );
		$mock_auth->method( 'get_current_user' )->willReturn( $this->current_user );

		$core_auth = new CoreAuthorization( $this->roles );
		$service   = new Service(
			$failing_auth,
			$mock_links,
			$mock_analytics,
			array( 'SITE_URL' => 'https://peakurl.dev' )
		);

		$controller = new Controller(
			$service,
			$failing_auth,
			$mock_auth,
			$core_auth,
			array( 'SITE_URL' => 'https://peakurl.dev' )
		);

		$req = new Request(
			'PUT',
			'/api/v1/system/mcp/settings',
			array(),
			array( 'enabled' => true )
		);

		$res = $controller->update_settings( $req );
		$this->assertSame( 500, $res['status'] );
		$this->assertFalse( $res['body']['success'] );
		$this->assertSame( 'Failed to persist MCP settings.', $res['body']['message'] );
	}
}
