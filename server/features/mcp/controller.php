<?php
/**
 * Model Context Protocol (MCP) HTTP controller.
 *
 * Exposes the streamable MCP protocol endpoint, RFC 9728 and RFC 8414
 * discovery metadata, OAuth 2.1 authorization consent and token exchange,
 * and authenticated dashboard administration REST routes.
 *
 * @package PeakURL\Features\Mcp
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Mcp;

use PeakURL\Core\Auth\Authorization as CoreAuthorization;
use PeakURL\Core\Config\Constants as CoreConstants;
use PeakURL\Core\Controller as BaseController;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — HTTP handlers for MCP and OAuth endpoints.
 *
 * @since 1.8.0
 */
class Controller extends BaseController {

	/**
	 * MCP service.
	 *
	 * @var Service
	 * @since 1.8.0
	 */
	private readonly Service $service;

	/**
	 * MCP authorization service.
	 *
	 * @var Authorization
	 * @since 1.8.0
	 */
	private readonly Authorization $authorization;

	/**
	 * Auth service.
	 *
	 * @var AuthService
	 * @since 1.8.0
	 */
	private readonly AuthService $auth_service;

	/**
	 * Core authorization service.
	 *
	 * @var CoreAuthorization
	 * @since 1.8.0
	 */
	private readonly CoreAuthorization $core_authorization;

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.8.0
	 */
	private readonly array $config;

	/**
	 * Create an MCP controller instance.
	 *
	 * @param Service           $service            MCP service.
	 * @param Authorization     $authorization      MCP authorization manager.
	 * @param AuthService       $auth_service       Auth domain service.
	 * @param CoreAuthorization $core_authorization Core role authorization manager.
	 * @param array<string, mixed> $config          Runtime configuration.
	 * @since 1.8.0
	 */
	public function __construct(
		Service $service,
		Authorization $authorization,
		AuthService $auth_service,
		CoreAuthorization $core_authorization,
		array $config
	) {
		$this->service            = $service;
		$this->authorization      = $authorization;
		$this->auth_service       = $auth_service;
		$this->core_authorization = $core_authorization;
		$this->config             = $config;
	}

	/**
	 * Handle incoming POST requests to /api/mcp (JSON-RPC protocol endpoint).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function handle_mcp( Request $request ): array {
		return $this->service->handle_request( $request );
	}

	/**
	 * Handle GET requests to /api/mcp.
	 *
	 * In conformance with the MCP Streamable HTTP specification (2025-03-26),
	 * a GET request to the protocol endpoint must return HTTP 405 Method Not Allowed
	 * with an Allow header when server-to-client streaming is not supported.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function handle_mcp_get( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return JsonResponse::error( __( 'PeakURL MCP Server is disabled on this instance.', 'peakurl' ), 503 );
		}

		$headers = array(
			'Allow'                => 'POST, OPTIONS',
			'MCP-Protocol-Version' => Constants::PROTOCOL_VERSION,
		);

		return JsonResponse::raw(
			array(
				'error'             => 'method_not_allowed',
				'error_description' => 'Server-to-client streaming via GET is not supported. Send JSON-RPC requests via POST.',
			),
			405,
			$headers
		);
	}

	/**
	 * RFC 9728 Protected Resource Metadata endpoint.
	 *
	 * Served at /.well-known/oauth-protected-resource/api/mcp and /.well-known/oauth-protected-resource.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function oauth_protected_resource_metadata( Request $request ): array {
		$site_url = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );

		$metadata = array(
			'resource'                 => $site_url . CoreConstants::API_MCP_PATH,
			'authorization_servers'    => array( $site_url ),
			'scopes_supported'         => Constants::ALL_SCOPES,
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation'   => 'https://peakurl.org/docs',
		);

		return JsonResponse::raw( $metadata, 200 );
	}

	/**
	 * RFC 8414 Authorization Server Metadata endpoint.
	 *
	 * Served at /.well-known/oauth-authorization-server.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function oauth_authorization_server_metadata( Request $request ): array {
		$site_url = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );

		$metadata = array(
			'issuer'                                => $site_url,
			'authorization_endpoint'                => $site_url . '/oauth/authorize',
			'token_endpoint'                        => $site_url . CoreConstants::API_MCP_PATH . '/oauth/token',
			'revocation_endpoint'                   => $site_url . CoreConstants::API_MCP_PATH . '/oauth/revoke',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => Constants::ALL_SCOPES,
		);

		return JsonResponse::raw( $metadata, 200 );
	}

	/**
	 * OAuth 2.1 authorization consent screen (GET /oauth/authorize).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured HTML or redirect response.
	 * @since 1.8.0
	 */
	public function oauth_authorize( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return JsonResponse::error( __( 'PeakURL MCP Server is currently disabled.', 'peakurl' ), 503 );
		}

		$response_type         = (string) $request->get_query_param( 'response_type', '' );
		$client_id             = trim( (string) $request->get_query_param( 'client_id', '' ) );
		$redirect_uri          = trim( (string) $request->get_query_param( 'redirect_uri', '' ) );
		$scope                 = (string) $request->get_query_param( 'scope', implode( ' ', Constants::DEFAULT_SCOPES ) );
		$state                 = (string) $request->get_query_param( 'state', '' );
		$code_challenge        = trim( (string) $request->get_query_param( 'code_challenge', '' ) );
		$code_challenge_method = trim( (string) $request->get_query_param( 'code_challenge_method', '' ) );

		if ( 'code' !== $response_type ) {
			return JsonResponse::error( __( 'Unsupported response_type. Expected "code".', 'peakurl' ), 400 );
		}

		if ( 'S256' !== $code_challenge_method || '' === $code_challenge ) {
			return JsonResponse::error( __( 'PKCE code_challenge with method S256 is required.', 'peakurl' ), 400 );
		}

		if ( '' === $client_id ) {
			return JsonResponse::error( __( 'client_id is required.', 'peakurl' ), 400 );
		}

		if ( ! $this->authorization->validate_redirect_uri( $redirect_uri ) ) {
			return JsonResponse::error( __( 'The requested redirect_uri is not allowed.', 'peakurl' ), 400 );
		}

		// Verify user session.
		$user = $this->auth_service->current_user( $request );
		if ( ! $user ) {
			$site_url     = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );
			$login_target = $site_url . '/login?redirect=' . rawurlencode( $request->get_path() . '?' . ( $_SERVER['QUERY_STRING'] ?? '' ) );

			return JsonResponse::redirect( $login_target );
		}

		$requested_scopes = array_values( array_filter( explode( ' ', $scope ) ) );
		if ( empty( $requested_scopes ) ) {
			$requested_scopes = Constants::DEFAULT_SCOPES;
		}

		try {
			$transaction = $this->authorization->create_authorization_transaction(
				(string) $user['id'],
				$client_id,
				$redirect_uri,
				$code_challenge,
				$code_challenge_method,
				$requested_scopes,
				$state
			);
		} catch ( \InvalidArgumentException $e ) {
			return JsonResponse::error( $e->getMessage(), 400 );
		}

		$html = $this->render_consent_screen(
			$user,
			$client_id,
			$requested_scopes,
			$transaction['transaction_id'],
			$transaction['csrf_token']
		);

		return JsonResponse::text( $html, 200, 'text/html; charset=utf-8' );
	}

	/**
	 * OAuth 2.1 authorization consent submission (POST /oauth/authorize).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function oauth_authorize_submit( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return JsonResponse::error( __( 'PeakURL MCP Server is currently disabled.', 'peakurl' ), 503 );
		}

		$user = $this->auth_service->current_user( $request );
		if ( ! $user ) {
			return JsonResponse::error( __( 'Session expired. Please sign in again.', 'peakurl' ), 401 );
		}

		$transaction_id = trim( (string) $request->get_body_param( 'transaction_id', '' ) );
		$csrf_token     = trim( (string) $request->get_body_param( 'csrf_token', '' ) );
		$action         = (string) $request->get_body_param( 'action', 'approve' );

		try {
			$tx = $this->authorization->consume_authorization_transaction(
				$transaction_id,
				$csrf_token,
				(string) $user['id']
			);
		} catch ( \InvalidArgumentException $e ) {
			return JsonResponse::error( $e->getMessage(), 400 );
		}

		$redirect_uri = (string) ( $tx['redirect_uri'] ?? '' );
		$state        = (string) ( $tx['state'] ?? '' );

		if ( 'deny' === $action ) {
			$deny_url = $redirect_uri . ( str_contains( $redirect_uri, '?' ) ? '&' : '?' ) .
				'error=access_denied&error_description=' . rawurlencode( 'User denied the authorization request.' );

			if ( '' !== $state ) {
				$deny_url .= '&state=' . rawurlencode( $state );
			}

			return JsonResponse::redirect( $deny_url );
		}

		try {
			$code = $this->authorization->create_authorization_code(
				(string) $tx['user_id'],
				(string) $tx['client_id'],
				$redirect_uri,
				(string) $tx['code_challenge'],
				(string) $tx['code_challenge_method'],
				(array) ( $tx['scopes'] ?? array() ),
				(string) $tx['client_id']
			);

			$redirect_target = $redirect_uri . ( str_contains( $redirect_uri, '?' ) ? '&' : '?' ) .
				'code=' . rawurlencode( $code );

			if ( '' !== $state ) {
				$redirect_target .= '&state=' . rawurlencode( $state );
			}

			return JsonResponse::redirect( $redirect_target );
		} catch ( \InvalidArgumentException $e ) {
			return JsonResponse::error( $e->getMessage(), 400 );
		}
	}

	/**
	 * OAuth 2.1 token endpoint (POST /api/mcp/oauth/token).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function oauth_token( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return JsonResponse::raw(
				array(
					'error'             => 'temporarily_unavailable',
					'error_description' => 'PeakURL MCP Server is currently disabled.',
				),
				503
			);
		}

		$grant_type = (string) $request->get_body_param( 'grant_type', '' );

		if ( 'authorization_code' === $grant_type ) {
			$code          = (string) $request->get_body_param( 'code', '' );
			$code_verifier = (string) $request->get_body_param( 'code_verifier', '' );
			$redirect_uri  = (string) $request->get_body_param( 'redirect_uri', '' );
			$client_id     = (string) $request->get_body_param( 'client_id', '' );

			try {
				$tokens = $this->authorization->exchange_authorization_code(
					$code,
					$code_verifier,
					$redirect_uri,
					$client_id
				);

				return JsonResponse::raw( $tokens, 200 );
			} catch ( \InvalidArgumentException $e ) {
				return JsonResponse::raw(
					array(
						'error'             => 'invalid_grant',
						'error_description' => $e->getMessage(),
					),
					400
				);
			}
		}

		if ( 'refresh_token' === $grant_type ) {
			$refresh_token = (string) $request->get_body_param( 'refresh_token', '' );
			$client_id     = (string) $request->get_body_param( 'client_id', '' );

			try {
				$tokens = $this->authorization->refresh_token(
					$refresh_token,
					'' !== $client_id ? $client_id : null
				);

				return JsonResponse::raw( $tokens, 200 );
			} catch ( \InvalidArgumentException $e ) {
				return JsonResponse::raw(
					array(
						'error'             => 'invalid_grant',
						'error_description' => $e->getMessage(),
					),
					400
				);
			}
		}

		return JsonResponse::raw(
			array(
				'error'             => 'unsupported_grant_type',
				'error_description' => sprintf( 'Grant type "%s" is not supported.', $grant_type ),
			),
			400
		);
	}

	/**
	 * OAuth token revocation endpoint (POST /api/mcp/oauth/revoke).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function oauth_revoke( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return JsonResponse::raw(
				array(
					'error'             => 'temporarily_unavailable',
					'error_description' => 'PeakURL MCP Server is currently disabled.',
				),
				503
			);
		}

		$token = (string) $request->get_body_param( 'token', '' );

		if ( '' !== trim( $token ) ) {
			$this->authorization->revoke_token( $token );
		}

		return JsonResponse::raw( array( 'revoked' => true ), 200 );
	}

	/**
	 * Get MCP dashboard configuration and health status (GET /api/v1/system/mcp/settings).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function get_settings( Request $request ): array {
		$this->require_mcp_management( $request );

		$site_url = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );

		$data = array(
			'enabled'             => $this->authorization->is_enabled(),
			'writeEnabled'        => $this->authorization->is_write_enabled(),
			'endpoint'            => $site_url . CoreConstants::API_MCP_PATH,
			'protocolVersion'     => Constants::PROTOCOL_VERSION,
			'allowedRedirectUris' => $this->authorization->get_allowed_redirect_uris(),
			'supportedScopes'     => Constants::ALL_SCOPES,
			'isHttps'             => str_starts_with( $site_url, 'https://' ),
		);

		return JsonResponse::success( $data, __( 'MCP settings retrieved.', 'peakurl' ) );
	}

	/**
	 * Update MCP dashboard configuration (PUT /api/v1/system/mcp/settings).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function update_settings( Request $request ): array {
		$this->require_mcp_management( $request );

		$body = $request->get_body_params();

		$new_enabled               = null;
		$new_write_enabled         = null;
		$new_allowed_redirect_uris = null;

		// 1. Validate enabled if supplied.
		if ( array_key_exists( 'enabled', $body ) ) {
			if ( ! is_bool( $body['enabled'] ) ) {
				return JsonResponse::error(
					__( 'The "enabled" setting must be a boolean.', 'peakurl' ),
					400
				);
			}
			$new_enabled = $body['enabled'];
		}

		// 2. Validate writeEnabled if supplied.
		if ( array_key_exists( 'writeEnabled', $body ) ) {
			if ( ! is_bool( $body['writeEnabled'] ) ) {
				return JsonResponse::error(
					__( 'The "writeEnabled" setting must be a boolean.', 'peakurl' ),
					400
				);
			}
			$new_write_enabled = $body['writeEnabled'];
		}

		// 3. Validate allowedRedirectUris if supplied.
		if ( array_key_exists( 'allowedRedirectUris', $body ) ) {
			$uris = $body['allowedRedirectUris'];
			if ( ! is_array( $uris ) ) {
				return JsonResponse::error(
					__( 'The "allowedRedirectUris" setting must be an array of URL strings.', 'peakurl' ),
					400
				);
			}

			if ( ! empty( $uris ) && array_keys( $uris ) !== range( 0, count( $uris ) - 1 ) ) {
				return JsonResponse::error(
					__( 'The "allowedRedirectUris" setting must be a sequential list of URL strings.', 'peakurl' ),
					400
				);
			}

			$clean_uris = array();
			foreach ( $uris as $uri ) {
				if ( ! is_string( $uri ) ) {
					return JsonResponse::error(
						__( 'Each redirect URI must be a URL string.', 'peakurl' ),
						400
					);
				}

				$err = null;
				if ( ! $this->authorization->validate_redirect_uri_structure( $uri, $err ) ) {
					return JsonResponse::error(
						$err ?? __( 'Invalid redirect URI.', 'peakurl' ),
						400
					);
				}

				$clean_uris[] = trim( $uri );
			}

			$new_allowed_redirect_uris = array_values( array_unique( $clean_uris ) );
		}

		// 4. All validations passed; persist updates atomically.
		try {
			if ( null !== $new_enabled ) {
				$this->authorization->set_enabled( $new_enabled );
			}

			if ( null !== $new_write_enabled ) {
				$this->authorization->set_write_enabled( $new_write_enabled );
			}

			if ( null !== $new_allowed_redirect_uris ) {
				$this->authorization->set_allowed_redirect_uris( $new_allowed_redirect_uris );
			}
		} catch ( \Throwable $e ) {
			return JsonResponse::error(
				__( 'Failed to persist MCP settings.', 'peakurl' ),
				500
			);
		}

		return $this->get_settings( $request );
	}

	/**
	 * List connected MCP clients (GET /api/v1/system/mcp/connections).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function list_connections( Request $request ): array {
		$this->require_mcp_management( $request );

		$connections = $this->authorization->list_connections();

		return JsonResponse::success(
			array(
				'connections' => $connections,
				'total'       => count( $connections ),
			),
			__( 'Connected clients retrieved.', 'peakurl' )
		);
	}

	/**
	 * Revoke a single connected client (DELETE /api/v1/system/mcp/connections/{id}).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function revoke_connection( Request $request ): array {
		$this->require_mcp_management( $request );

		$id      = (string) $request->get_route_param( 'id', '' );
		$revoked = $this->authorization->revoke_connection( $id );

		if ( ! $revoked ) {
			return JsonResponse::error( __( 'Connection not found or already revoked.', 'peakurl' ), 404 );
		}

		return JsonResponse::success(
			array( 'revoked' => true ),
			__( 'Connection grant revoked.', 'peakurl' )
		);
	}

	/**
	 * Revoke all connected clients (POST /api/v1/system/mcp/connections/revoke-all).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function revoke_all_connections( Request $request ): array {
		$this->require_mcp_management( $request );

		$count = $this->authorization->revoke_all_connections();

		return JsonResponse::success(
			array( 'revokedCount' => $count ),
			sprintf(
				/* translators: %d: count of revoked connections */
				__( '%d active connection(s) revoked.', 'peakurl' ),
				$count
			)
		);
	}

	/**
	 * List registered MCP tools for dashboard inspection (GET /api/v1/system/mcp/tools).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response.
	 * @since 1.8.0
	 */
	public function list_tools( Request $request ): array {
		$this->require_mcp_management( $request );

		$tools = array_values( Registry::get_tools( true ) );

		return JsonResponse::success(
			array(
				'tools'        => $tools,
				'writeEnabled' => $this->authorization->is_write_enabled(),
			),
			__( 'Registered MCP tools retrieved.', 'peakurl' )
		);
	}

	/**
	 * Require permission to manage MCP settings.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Authenticated authorized user.
	 * @since 1.8.0
	 */
	private function require_mcp_management( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->core_authorization->validate_capability(
			$user,
			'manage_mcp',
			__( 'You do not have permission to manage MCP server.', 'peakurl' )
		);

		return $user;
	}

	/**
	 * Render HTML OAuth consent screen with strict escaping and CSRF protection.
	 *
	 * @param array<string, mixed> $user           Logged in user.
	 * @param string               $client_id      Client identifier.
	 * @param array<int, string>   $scopes         Requested scopes.
	 * @param string               $transaction_id Server-side authorization transaction ID.
	 * @param string               $csrf_token     Anti-CSRF token.
	 * @return string Rendered HTML.
	 * @since 1.8.0
	 */
	private function render_consent_screen(
		array $user,
		string $client_id,
		array $scopes,
		string $transaction_id,
		string $csrf_token
	): string {
		$site_url      = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );
		$client_title  = htmlspecialchars( $client_id, ENT_QUOTES, 'UTF-8' );
		$user_name     = htmlspecialchars( (string) ( $user['display_name'] ?? ( $user['username'] ?? 'User' ) ), ENT_QUOTES, 'UTF-8' );
		$user_email    = htmlspecialchars( (string) ( $user['email'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$escaped_tx_id = htmlspecialchars( $transaction_id, ENT_QUOTES, 'UTF-8' );
		$escaped_csrf  = htmlspecialchars( $csrf_token, ENT_QUOTES, 'UTF-8' );

		$scope_items = '';
		foreach ( $scopes as $s ) {
			$desc = match ( $s ) {
				Constants::SCOPE_LINKS_READ     => 'View short links, aliases, and destinations',
				Constants::SCOPE_ANALYTICS_READ => 'View aggregate analytics and click statistics',
				Constants::SCOPE_LINKS_WRITE    => 'Create and update short links on your behalf',
				default                         => $s,
			};
			$scope_items .= '<li><strong>' . htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) . '</strong>: ' . htmlspecialchars( (string) $desc, ENT_QUOTES, 'UTF-8' ) . '</li>';
		}

		$post_action = htmlspecialchars( $site_url . '/oauth/authorize', ENT_QUOTES, 'UTF-8' );

		$content_html = <<<HTML
<p class="status-eyebrow">OAuth 2.0 Authorization</p>
<h1 id="page-heading" class="status-title">Connect to PeakURL</h1>
<p class="status-description">
	<strong>{$client_title}</strong> is requesting authorization to access your PeakURL instance.
</p>

<div style="margin: 24px 0; padding: 16px 20px; background: var(--status-surface); border: 1px solid var(--status-line); border-radius: 8px;">
	<p style="margin: 0 0 10px 0; font-size: 13px; color: var(--status-muted);">
		Signed in as <strong style="color: var(--status-ink);">{$user_name}</strong> ({$user_email})
	</p>
	<div style="border-top: 1px solid var(--status-line); padding-top: 12px;">
		<p style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--status-muted);">
			Requested Permissions
		</p>
		<ul style="margin: 0; padding-inline-start: 20px; font-size: 13px; line-height: 1.6; color: var(--status-ink);">
			{$scope_items}
		</ul>
	</div>
</div>

<form method="POST" action="{$post_action}">
	<input type="hidden" name="transaction_id" value="{$escaped_tx_id}">
	<input type="hidden" name="csrf_token" value="{$escaped_csrf}">

	<div class="status-actions" style="display: flex; gap: 12px;">
		<button type="submit" name="action" value="deny" class="status-button" style="background: transparent; color: var(--status-ink); border: 1px solid var(--status-line); cursor: pointer;">Deny</button>
		<button type="submit" name="action" value="approve" class="status-button" style="cursor: pointer;">Authorize</button>
	</div>
</form>
HTML;

		$support_html = '<section class="status-support"><h2 class="status-support-title">Security note</h2><p>Only authorize clients you trust. You can inspect or revoke authorized connections at any time from your PeakURL dashboard under Settings &rarr; MCP Server.</p></section>';

		return \PeakURL\Services\StatusPage::render_document(
			sprintf( 'Authorize %s', $client_title ),
			$content_html,
			array(),
			$support_html,
			'verification'
		);
	}
}
