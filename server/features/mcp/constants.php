<?php
/**
 * Model Context Protocol (MCP) constants.
 *
 * Defines protocol version strings, JSON-RPC 2.0 error codes, OAuth scopes,
 * token lifetimes, and setting keys used throughout the MCP feature.
 *
 * @package PeakURL\Features\Mcp
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Mcp;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * MCP runtime constants.
 *
 * @since 1.8.0
 */
class Constants {

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/** Standard Model Context Protocol (MCP) Streamable HTTP version. */
	public const string PROTOCOL_VERSION = '2025-03-26';

	/** List of supported protocol versions. */
	public const array SUPPORTED_PROTOCOL_VERSIONS = array(
		self::PROTOCOL_VERSION,
	);

	/** Standard JSON-RPC 2.0 error codes. */
	public const int JSONRPC_PARSE_ERROR      = -32700;
	public const int JSONRPC_INVALID_REQUEST  = -32600;
	public const int JSONRPC_METHOD_NOT_FOUND = -32601;
	public const int JSONRPC_INVALID_PARAMS   = -32602;
	public const int JSONRPC_INTERNAL_ERROR   = -32603;

	/** MCP application error codes. */
	public const int MCP_UNAUTHORIZED = -32001;
	public const int MCP_FORBIDDEN    = -32003;

	/** Supported OAuth scopes. */
	public const string SCOPE_LINKS_READ     = 'links:read';
	public const string SCOPE_ANALYTICS_READ = 'analytics:read';
	public const string SCOPE_LINKS_WRITE    = 'links:write';

	/** All supported OAuth scopes. */
	public const array ALL_SCOPES = array(
		self::SCOPE_LINKS_READ,
		self::SCOPE_ANALYTICS_READ,
		self::SCOPE_LINKS_WRITE,
	);

	/** Default read-only scopes. */
	public const array DEFAULT_SCOPES = array(
		self::SCOPE_LINKS_READ,
		self::SCOPE_ANALYTICS_READ,
	);

	/** Authorization code lifetime in seconds (10 minutes). */
	public const int AUTH_CODE_LIFETIME = 600;

	/** Access token lifetime in seconds (1 hour). */
	public const int ACCESS_TOKEN_LIFETIME = 3600;

	/** Refresh token lifetime in seconds (30 days). */
	public const int REFRESH_TOKEN_LIFETIME = 2592000;

	/** Settings key storing MCP global enabled flag. */
	public const string SETTING_MCP_ENABLED = 'mcp_enabled';

	/** Settings key storing MCP write tools enabled flag. */
	public const string SETTING_MCP_WRITE_ENABLED = 'mcp_write_enabled';

	/** Settings key storing JSON array of allowed redirect URIs. */
	public const string SETTING_MCP_ALLOWED_REDIRECT_URIS = 'mcp_allowed_redirect_uris';

	/** Settings key storing persisted active MCP client grants / connections. */
	public const string SETTING_MCP_CONNECTIONS = 'mcp_connections_json';

	/** Settings key storing transient OAuth authorization codes. */
	public const string SETTING_MCP_AUTH_CODES = 'mcp_auth_codes_json';

	/** Settings key storing pending OAuth authorization transactions. */
	public const string SETTING_MCP_AUTH_TRANSACTIONS = 'mcp_auth_transactions_json';

	/** Server display name reported during protocol handshake. */
	public const string SERVER_NAME = 'PeakURL MCP Server';

	/** Default allowed redirect URIs for well-known MCP clients. */
	public const array DEFAULT_ALLOWED_REDIRECT_URIS = array(
		'https://chatgpt.com/connector_platform_oauth_redirect',
		'https://claude.ai/api/mcp/oauth/callback',
		'http://localhost/callback',
		'http://127.0.0.1/callback',
	);
}
