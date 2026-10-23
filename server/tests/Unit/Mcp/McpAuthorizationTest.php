<?php
/**
 * Unit tests for MCP Authorization and OAuth logic.
 *
 * @package PeakURL\Tests\Unit\Mcp
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Mcp\Authorization;
use PeakURL\Features\Mcp\Constants;
use PeakURL\Features\Mcp\Registry;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;

class McpAuthorizationTest extends TestCase {

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
	}

	public function test_enabled_and_write_enabled_toggle(): void {
		$this->assertFalse( $this->auth->is_enabled() );
		$this->assertFalse( $this->auth->is_write_enabled() );

		$this->auth->set_enabled( true );
		$this->assertTrue( $this->auth->is_enabled() );
		$this->assertFalse( $this->auth->is_write_enabled() );

		$this->auth->set_write_enabled( true );
		$this->assertTrue( $this->auth->is_write_enabled() );

		// Disabling global MCP disables write tools as well.
		$this->auth->set_enabled( false );
		$this->assertFalse( $this->auth->is_enabled() );
		$this->assertFalse( $this->auth->is_write_enabled() );
	}

	public function test_redirect_uri_validation(): void {
		// Valid default remote HTTPS endpoints.
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://chatgpt.com/connector_platform_oauth_redirect' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://claude.ai/api/mcp/oauth/callback' ) );

		// Valid local loopback callbacks (HTTP and HTTPS, localhost, 127.0.0.1, and [::1]).
		$this->assertTrue( $this->auth->validate_redirect_uri( 'http://localhost:3000/callback' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'http://127.0.0.1:8080/callback' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'http://[::1]:8080/callback' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://localhost:3000/callback' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://127.0.0.1:8080/callback' ) );

		// Custom configured allowed URI.
		$this->auth->set_allowed_redirect_uris( array( 'https://my-custom-client.com/oauth' ) );
		$this->assertTrue( $this->auth->validate_redirect_uri( 'https://my-custom-client.com/oauth' ) );

		// Negative: Exact matching against allowlist (subpaths or different domains fail).
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://my-custom-client.com/other' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://evil.com/callback' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( '' ) );

		// Negative: Fragments rejected.
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://chatgpt.com/connector_platform_oauth_redirect#token=123' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'http://localhost:3000/callback#state=abc' ) );

		// Negative: Userinfo rejected.
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://user:pass@chatgpt.com/connector_platform_oauth_redirect' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://user@chatgpt.com/connector_platform_oauth_redirect' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'http://user:pass@localhost:3000/callback' ) );

		// Negative: Remote callback over plain HTTP rejected.
		$this->assertFalse( $this->auth->validate_redirect_uri( 'http://chatgpt.com/connector_platform_oauth_redirect' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'http://claude.ai/api/mcp/oauth/callback' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'http://my-custom-client.com/oauth' ) );

		// Negative: Non-HTTP/HTTPS schemes rejected.
		$this->assertFalse( $this->auth->validate_redirect_uri( 'javascript:alert(1)' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'data:text/html,test' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'ftp://chatgpt.com/callback' ) );

		// Negative: Relative URLs and missing components.
		$this->assertFalse( $this->auth->validate_redirect_uri( '/api/callback' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'relative/path' ) );
		$this->assertFalse( $this->auth->validate_redirect_uri( 'https://' ) );
	}

	public function test_pkce_s256_authorization_code_and_token_exchange(): void {
		$this->auth->set_enabled( true );

		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$client_id = 'chatgpt';
		$redirect  = 'https://chatgpt.com/connector_platform_oauth_redirect';
		$scopes    = array( Constants::SCOPE_LINKS_READ, Constants::SCOPE_ANALYTICS_READ );

		$code = $this->auth->create_authorization_code(
			'1',
			$client_id,
			$redirect,
			$challenge,
			'S256',
			$scopes,
			'ChatGPT'
		);

		$this->assertNotEmpty( $code );

		// Exchange with wrong verifier fails.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'PKCE code_verifier challenge verification failed.' );
		$this->auth->exchange_authorization_code( $code, 'wrong_verifier', $redirect, $client_id );
	}

	public function test_successful_token_exchange_and_bearer_authentication(): void {
		$this->auth->set_enabled( true );

		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$client_id = 'chatgpt';
		$redirect  = 'https://chatgpt.com/connector_platform_oauth_redirect';
		$scopes    = array( Constants::SCOPE_LINKS_READ );

		$code = $this->auth->create_authorization_code(
			'1',
			$client_id,
			$redirect,
			$challenge,
			'S256',
			$scopes,
			'ChatGPT'
		);

		$tokens = $this->auth->exchange_authorization_code( $code, $verifier, $redirect, $client_id );

		$this->assertArrayHasKey( 'access_token', $tokens );
		$this->assertArrayHasKey( 'refresh_token', $tokens );
		$this->assertSame( 'Bearer', $tokens['token_type'] );
		$this->assertSame( Constants::SCOPE_LINKS_READ, $tokens['scope'] );

		// Authenticate request using the bearer token.
		$request = new Request(
			'POST',
			'/api/mcp',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'] )
		);

		$auth_context = $this->auth->authenticate_request( $request );
		$this->assertNotNull( $auth_context );
		$this->assertSame( '1', (string) $auth_context['user']['id'] );
		$this->assertSame( array( Constants::SCOPE_LINKS_READ ), $auth_context['scopes'] );
	}

	public function test_tool_calling_permission_evaluation(): void {
		$this->auth->set_enabled( true );

		$admin_context = array(
			'user'   => $this->users_store['1'],
			'scopes' => array( Constants::SCOPE_LINKS_READ, Constants::SCOPE_ANALYTICS_READ ),
		);

		$whoami_tool        = Registry::get_tool( 'whoami' );
		$list_links_tool    = Registry::get_tool( 'list_links' );
		$get_analytics_tool = Registry::get_tool( 'get_analytics' );
		$create_link_tool   = Registry::get_tool( 'create_link' );

		// Admin with read scopes can call read tools.
		$this->assertTrue( $this->auth->can_call_tool( $admin_context, $whoami_tool ) );
		$this->assertTrue( $this->auth->can_call_tool( $admin_context, $list_links_tool ) );
		$this->assertTrue( $this->auth->can_call_tool( $admin_context, $get_analytics_tool ) );

		// Cannot call write tools because scope is not granted AND write mode is disabled.
		$this->assertFalse( $this->auth->can_call_tool( $admin_context, $create_link_tool ) );

		// Enable write mode and grant scope.
		$this->auth->set_write_enabled( true );
		$admin_write_context = array(
			'user'   => $this->users_store['1'],
			'scopes' => array( Constants::SCOPE_LINKS_READ, Constants::SCOPE_LINKS_WRITE ),
		);
		$this->assertTrue( $this->auth->can_call_tool( $admin_write_context, $create_link_tool ) );
	}

	public function test_token_revocation(): void {
		$this->auth->set_enabled( true );

		$verifier  = 'test_verifier_string_value_1234567890';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->auth->create_authorization_code(
			'2',
			'claude',
			'https://claude.ai/api/mcp/oauth/callback',
			$challenge,
			'S256',
			array( Constants::SCOPE_LINKS_READ )
		);

		$tokens = $this->auth->exchange_authorization_code( $code, $verifier, 'https://claude.ai/api/mcp/oauth/callback', 'claude' );

		$request = new Request(
			'POST',
			'/api/mcp',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'] )
		);

		$this->assertNotNull( $this->auth->authenticate_request( $request ) );

		// Revoke the token.
		$revoked = $this->auth->revoke_token( $tokens['access_token'] );
		$this->assertTrue( $revoked );

		// Subsequent authentication fails.
		$this->assertNull( $this->auth->authenticate_request( $request ) );
	}

	public function test_touch_connection_usage_atomic_and_safe(): void {
		$this->auth->set_enabled( true );

		$verifier  = 'verifier_usage_tracking_test_12345';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		$code = $this->auth->create_authorization_code(
			'1',
			'claude-desktop',
			'https://claude.ai/api/mcp/oauth/callback',
			$challenge,
			'S256',
			array( Constants::SCOPE_LINKS_READ )
		);

		$tokens = $this->auth->exchange_authorization_code(
			$code,
			$verifier,
			'https://claude.ai/api/mcp/oauth/callback',
			'claude-desktop'
		);

		$connections = $this->auth->list_connections();
		$this->assertCount( 1, $connections );
		$conn_id   = $connections[0]['id'];
		$last_used = $connections[0]['lastUsedAt'];
		$this->assertNotNull( $last_used );

		// 1. Throttling: touch within 60s is skipped.
		$time1 = gmdate( 'Y-m-d H:i:s', strtotime( $last_used ) + 30 );
		$this->auth->touch_connection_usage( $conn_id, $time1, $last_used );

		$conns_after1 = $this->auth->list_connections();
		$this->assertSame( $last_used, $conns_after1[0]['lastUsedAt'] );

		// 2. Touch after > 60s succeeds.
		$time2 = gmdate( 'Y-m-d H:i:s', strtotime( $last_used ) + 75 );
		$this->auth->touch_connection_usage( $conn_id, $time2, $last_used );

		$conns_after2 = $this->auth->list_connections();
		$this->assertSame( \PeakURL\Utils\Date::to_iso( $time2 ), $conns_after2[0]['lastUsedAt'] );

		// 3. Revoked connection is not updated/resurrected.
		$this->auth->revoke_connection( $conn_id );
		$conns_revoked = $this->auth->list_connections();
		$this->assertFalse( $conns_revoked[0]['isActive'] );
		$this->assertSame( 'revoked', $conns_revoked[0]['status'] );
		$this->assertNotNull( $conns_revoked[0]['revokedAt'] );

		// Touch on revoked connection does not alter its lastUsedAt.
		$ghost_time = gmdate( 'Y-m-d H:i:s', strtotime( $last_used ) + 300 );
		$this->auth->touch_connection_usage( $conn_id, $ghost_time, $time2 );

		$conns_after_ghost = $this->auth->list_connections();
		$this->assertSame( \PeakURL\Utils\Date::to_iso( $time2 ), $conns_after_ghost[0]['lastUsedAt'] );
	}

	/**
	 * Test connection authorization status derivation for active, expired, and revoked grants.
	 */
	public function test_connection_status_derivation(): void {
		$now = time();

		$stored = array(
			array(
				'id'                 => 'conn-active',
				'client_id'          => 'client-1',
				'client_name'        => 'Active Client',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 'tok1' ),
				'refresh_token_hash' => hash( 'sha256', 'ref1' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 100 ),
				'last_used_at'       => gmdate( 'Y-m-d H:i:s', $now - 50 ),
				'revoked_at'         => null,
			),
			array(
				'id'                 => 'conn-expired',
				'client_id'          => 'client-2',
				'client_name'        => 'Expired Client',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 'tok2' ),
				'refresh_token_hash' => hash( 'sha256', 'ref2' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 86400 ),
				'last_used_at'       => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
				'revoked_at'         => null,
			),
			array(
				'id'                 => 'conn-revoked',
				'client_id'          => 'client-3',
				'client_name'        => 'Revoked Client',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 'tok3' ),
				'refresh_token_hash' => hash( 'sha256', 'ref3' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 500 ),
				'last_used_at'       => gmdate( 'Y-m-d H:i:s', $now - 200 ),
				'revoked_at'         => gmdate( 'Y-m-d H:i:s', $now - 100 ),
			),
		);

		$this->settings_store[ Constants::SETTING_MCP_CONNECTIONS ] = (string) json_encode( $stored );

		$connections = $this->auth->list_connections();
		$this->assertCount( 3, $connections );

		$by_id = array();
		foreach ( $connections as $c ) {
			$by_id[ $c['id'] ] = $c;
		}

		// Active
		$this->assertSame( 'active', $by_id['conn-active']['status'] );
		$this->assertTrue( $by_id['conn-active']['isActive'] );
		$this->assertNull( $by_id['conn-active']['revokedAt'] );

		// Expired
		$this->assertSame( 'expired', $by_id['conn-expired']['status'] );
		$this->assertFalse( $by_id['conn-expired']['isActive'] );
		$this->assertNull( $by_id['conn-expired']['revokedAt'] );

		// Revoked
		$this->assertSame( 'revoked', $by_id['conn-revoked']['status'] );
		$this->assertFalse( $by_id['conn-revoked']['isActive'] );
		$this->assertNotNull( $by_id['conn-revoked']['revokedAt'] );
	}

	/**
	 * Test revoke_all_connections revokes only active authorizations and preserves history.
	 */
	public function test_revoke_all_connections_only_revokes_active_grants(): void {
		$now               = time();
		$past_revoked_time = gmdate( 'Y-m-d H:i:s', $now - 3600 );

		$stored = array(
			array(
				'id'                 => 'act-1',
				'client_id'          => 'c1',
				'client_name'        => 'Active 1',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 't1' ),
				'refresh_token_hash' => hash( 'sha256', 'r1' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 100 ),
				'last_used_at'       => null,
				'revoked_at'         => null,
			),
			array(
				'id'                 => 'act-2',
				'client_id'          => 'c2',
				'client_name'        => 'Active 2',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 't2' ),
				'refresh_token_hash' => hash( 'sha256', 'r2' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 200 ),
				'last_used_at'       => null,
				'revoked_at'         => null,
			),
			array(
				'id'                 => 'exp-1',
				'client_id'          => 'c3',
				'client_name'        => 'Expired 1',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 't3' ),
				'refresh_token_hash' => hash( 'sha256', 'r3' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 86400 ),
				'last_used_at'       => null,
				'revoked_at'         => null,
			),
			array(
				'id'                 => 'rev-1',
				'client_id'          => 'c4',
				'client_name'        => 'Already Revoked',
				'user_id'            => '1',
				'token_hash'         => hash( 'sha256', 't4' ),
				'refresh_token_hash' => hash( 'sha256', 'r4' ),
				'scopes'             => array( Constants::SCOPE_LINKS_READ ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + 86400 ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now - 5000 ),
				'last_used_at'       => null,
				'revoked_at'         => $past_revoked_time,
			),
		);

		$this->settings_store[ Constants::SETTING_MCP_CONNECTIONS ] = (string) json_encode( $stored );

		$revoked_count = $this->auth->revoke_all_connections();
		$this->assertSame( 2, $revoked_count );

		$connections = $this->auth->list_connections();
		$by_id       = array();
		foreach ( $connections as $c ) {
			$by_id[ $c['id'] ] = $c;
		}

		// The 2 active connections are now revoked.
		$this->assertSame( 'revoked', $by_id['act-1']['status'] );
		$this->assertFalse( $by_id['act-1']['isActive'] );
		$this->assertNotNull( $by_id['act-1']['revokedAt'] );

		$this->assertSame( 'revoked', $by_id['act-2']['status'] );
		$this->assertFalse( $by_id['act-2']['isActive'] );
		$this->assertNotNull( $by_id['act-2']['revokedAt'] );

		// The expired connection remains expired, not marked revoked.
		$this->assertSame( 'expired', $by_id['exp-1']['status'] );
		$this->assertFalse( $by_id['exp-1']['isActive'] );
		$this->assertNull( $by_id['exp-1']['revokedAt'] );

		// The already-revoked connection kept its original revoked timestamp.
		$this->assertSame( 'revoked', $by_id['rev-1']['status'] );
		$this->assertSame( \PeakURL\Utils\Date::to_iso( $past_revoked_time ), $by_id['rev-1']['revokedAt'] );
	}
}
