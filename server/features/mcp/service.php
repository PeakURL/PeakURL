<?php
/**
 * Model Context Protocol (MCP) server service.
 *
 * Implements JSON-RPC 2.0 protocol dispatching, capability negotiation,
 * authentication verification, tool listing, and tool execution.
 *
 * @package PeakURL\Features\Mcp
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Mcp;

use PeakURL\Core\Config\Constants as CoreConstants;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — MCP protocol engine and tool dispatcher.
 *
 * @since 1.8.0
 */
class Service {

	/**
	 * MCP authorization service.
	 *
	 * @var Authorization
	 * @since 1.8.0
	 */
	private readonly Authorization $authorization;

	/**
	 * Links domain service.
	 *
	 * @var LinksService
	 * @since 1.8.0
	 */
	private readonly LinksService $links;

	/**
	 * Analytics domain service.
	 *
	 * @var AnalyticsService
	 * @since 1.8.0
	 */
	private readonly AnalyticsService $analytics;

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.8.0
	 */
	private readonly array $config;

	/**
	 * Create an MCP service instance.
	 *
	 * @param Authorization    $authorization Authorization manager.
	 * @param LinksService     $links         Links domain service.
	 * @param AnalyticsService $analytics     Analytics domain service.
	 * @param array<string, mixed> $config    Runtime configuration.
	 * @since 1.8.0
	 */
	public function __construct(
		Authorization $authorization,
		LinksService $links,
		AnalyticsService $analytics,
		array $config
	) {
		$this->authorization = $authorization;
		$this->links         = $links;
		$this->analytics     = $analytics;
		$this->config        = $config;
	}

	/**
	 * Handle an incoming MCP HTTP request.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response array.
	 * @since 1.8.0
	 */
	public function handle_request( Request $request ): array {
		if ( ! $this->authorization->is_enabled() ) {
			return $this->error_response(
				null,
				Constants::MCP_UNAUTHORIZED,
				'MCP server is disabled on this instance.',
				503
			);
		}

		// Defend against DNS rebinding and cross-origin attacks.
		$host_error = $this->validate_origin_and_host( $request );
		if ( null !== $host_error ) {
			return $this->error_response(
				null,
				Constants::MCP_FORBIDDEN,
				$host_error,
				403
			);
		}

		// Negotiate and validate protocol version header if provided.
		$proto_header = $request->get_header( 'mcp-protocol-version' );
		if ( null !== $proto_header && '' !== trim( (string) $proto_header ) ) {
			if ( ! in_array( trim( (string) $proto_header ), Constants::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
				return $this->error_response(
					null,
					Constants::JSONRPC_INVALID_REQUEST,
					sprintf( 'Unsupported MCP-Protocol-Version: %s', $proto_header ),
					400
				);
			}
		}

		// Validate Content-Type: Streamable HTTP requires application/json.
		$content_type = $request->get_header( 'Content-Type' );
		if ( null === $content_type || ! str_contains( strtolower( (string) $content_type ), 'application/json' ) ) {
			return $this->error_response(
				null,
				Constants::JSONRPC_INVALID_REQUEST,
				'Unsupported Media Type: Content-Type must be application/json.',
				415
			);
		}

		// Validate Accept header if provided.
		$accept_header = $request->get_header( 'Accept' );
		if ( null !== $accept_header && '' !== trim( (string) $accept_header ) ) {
			$accept_lower = strtolower( (string) $accept_header );
			if (
				! str_contains( $accept_lower, 'application/json' ) &&
				! str_contains( $accept_lower, 'text/event-stream' ) &&
				! str_contains( $accept_lower, '*/*' ) &&
				! str_contains( $accept_lower, 'application/*' )
			) {
				return $this->error_response(
					null,
					Constants::JSONRPC_INVALID_REQUEST,
					'Not Acceptable: Client must accept application/json or text/event-stream.',
					406
				);
			}
		}

		$raw_body = $request->get_raw_body();
		if ( '' === trim( $raw_body ) ) {
			$raw_body = (string) json_encode( $request->get_body_params() );
		}

		// Enforce maximum payload size (1MB).
		if ( strlen( $raw_body ) > 1048576 ) {
			return $this->error_response(
				null,
				Constants::JSONRPC_INVALID_REQUEST,
				'Payload Too Large: MCP request body exceeds 1MB limit.',
				413
			);
		}

		$payload = json_decode( (string) $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return $this->error_response(
				null,
				Constants::JSONRPC_PARSE_ERROR,
				'Invalid JSON: unable to parse request body.',
				400
			);
		}

		// Reject JSON-RPC batching (unsupported by Streamable HTTP MCP transport).
		if ( array_is_list( $payload ) ) {
			return $this->error_response(
				null,
				Constants::JSONRPC_INVALID_REQUEST,
				'Invalid Request: JSON-RPC batching is not supported by PeakURL MCP transport.',
				400
			);
		}

		$id      = $payload['id'] ?? null;
		$jsonrpc = $payload['jsonrpc'] ?? '';
		$method  = $payload['method'] ?? '';

		if ( '2.0' !== $jsonrpc || ! is_string( $method ) || '' === trim( $method ) ) {
			return $this->error_response(
				$id,
				Constants::JSONRPC_INVALID_REQUEST,
				'Invalid Request: expected JSON-RPC 2.0 object with method string.',
				400
			);
		}

		// Handle methods that do not require OAuth authentication.
		if ( 'initialize' === $method ) {
			return $this->initialize( $id, (array) ( $payload['params'] ?? array() ) );
		}

		if ( 'notifications/initialized' === $method ) {
			return JsonResponse::raw( (object) array(), 202, array( 'MCP-Protocol-Version' => Constants::PROTOCOL_VERSION ) );
		}

		if ( 'ping' === $method ) {
			return $this->success_response( $id, (object) array() );
		}

		// All subsequent methods require Bearer token authentication.
		$auth_context = $this->authorization->authenticate_request( $request );
		if ( ! $auth_context ) {
			return $this->unauthorized_response( $id );
		}

		return match ( $method ) {
			'tools/list' => $this->list_tools( $id, $auth_context ),
			'tools/call' => $this->call_tool( $id, (array) ( $payload['params'] ?? array() ), $auth_context, $request ),
			default      => $this->error_response(
				$id,
				Constants::JSONRPC_METHOD_NOT_FOUND,
				sprintf( 'Method not found: %s', $method ),
				404
			),
		};
	}

	/**
	 * Handle MCP initialize handshake.
	 *
	 * @param mixed                $id     JSON-RPC request ID.
	 * @param array<string, mixed> $params Client initialize params.
	 * @return array<string, mixed> Response array.
	 * @since 1.8.0
	 */
	private function initialize( $id, array $params ): array {
		$client_version   = (string) ( $params['protocolVersion'] ?? '' );
		$negotiated_proto = in_array( $client_version, Constants::SUPPORTED_PROTOCOL_VERSIONS, true )
			? $client_version
			: Constants::PROTOCOL_VERSION;

		$result = array(
			'protocolVersion' => $negotiated_proto,
			'capabilities'    => array(
				'tools' => array(
					'listChanged' => false,
				),
			),
			'serverInfo'      => array(
				'name'    => Constants::SERVER_NAME,
				'version' => (string) ( $this->config[ CoreConstants::VERSION ] ?? CoreConstants::DEFAULT_VERSION ),
			),
		);

		return $this->success_response( $id, $result );
	}

	/**
	 * Handle tools/list request.
	 *
	 * @param mixed                $id           JSON-RPC request ID.
	 * @param array<string, mixed> $auth_context Authenticated context.
	 * @return array<string, mixed> Response array.
	 * @since 1.8.0
	 */
	private function list_tools( $id, array $auth_context ): array {
		$all_tools     = Registry::get_tools( $this->authorization->is_write_enabled() );
		$allowed_tools = array();

		foreach ( $all_tools as $tool_def ) {
			if ( $this->authorization->can_call_tool( $auth_context, $tool_def ) ) {
				$allowed_tools[] = array(
					'name'        => $tool_def['name'],
					'description' => $tool_def['description'],
					'inputSchema' => $tool_def['inputSchema'],
				);
			}
		}

		return $this->success_response(
			$id,
			array(
				'tools' => $allowed_tools,
			)
		);
	}

	/**
	 * Handle tools/call request.
	 *
	 * @param mixed                $id           JSON-RPC request ID.
	 * @param array<string, mixed> $params       Tool call params.
	 * @param array<string, mixed> $auth_context Authenticated context.
	 * @param Request              $request      Original HTTP request.
	 * @return array<string, mixed> Response array.
	 * @since 1.8.0
	 */
	private function call_tool(
		$id,
		array $params,
		array $auth_context,
		Request $request
	): array {
		$tool_name = (string) ( $params['name'] ?? '' );
		$arguments = (array) ( $params['arguments'] ?? array() );

		if ( '' === $tool_name ) {
			return $this->error_response(
				$id,
				Constants::JSONRPC_INVALID_PARAMS,
				'Missing tool name in tools/call parameters.'
			);
		}

		$tool_def = Registry::get_tool( $tool_name );
		if ( ! $tool_def ) {
			return $this->error_response(
				$id,
				Constants::JSONRPC_METHOD_NOT_FOUND,
				sprintf( 'Unknown tool: %s', $tool_name )
			);
		}

		if ( ! $this->authorization->can_call_tool( $auth_context, $tool_def ) ) {
			$needed_scope = $tool_def['scope'] ?? 'peakurl:read';
			return $this->error_response(
				$id,
				Constants::MCP_FORBIDDEN,
				sprintf( 'Forbidden: Insufficient privileges or scope to execute tool "%s".', $tool_name ),
				403,
				array(
					'WWW-Authenticate' => sprintf( 'Bearer error="insufficient_scope", scope="%s"', $needed_scope ),
				)
			);
		}

		$validation_error = $this->validate_tool_arguments( $tool_def, $arguments );
		if ( null !== $validation_error ) {
			return $this->error_response(
				$id,
				Constants::JSONRPC_INVALID_PARAMS,
				$validation_error,
				400
			);
		}

		try {
			$tool_result = $this->execute_tool( $tool_name, $arguments, $auth_context, $request );

			return $this->success_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $tool_result['text'],
						),
					),
					'isError' => ! empty( $tool_result['is_error'] ),
				)
			);
		} catch ( \Throwable $e ) {
			if (
				$e instanceof \InvalidArgumentException ||
				$e instanceof \DomainException ||
				$e instanceof \PeakURL\Core\Errors\ApiException
			) {
				$message = $e->getMessage();
			} else {
				error_log( sprintf( 'PeakURL MCP tool [%s] internal error: %s', $tool_name, $e->getMessage() ) );
				$message = 'An internal server error occurred while executing the tool. Please check server logs.';
			}

			return $this->success_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => sprintf( 'Tool execution failed: %s', $message ),
						),
					),
					'isError' => true,
				)
			);
		}
	}

	/**
	 * Execute a registered tool.
	 *
	 * @param string               $name         Tool name.
	 * @param array<string, mixed> $arguments    Tool arguments.
	 * @param array<string, mixed> $auth_context Authenticated context.
	 * @param Request              $request      Original HTTP request.
	 * @return array<string, mixed> Array with 'text' and optional 'is_error'.
	 * @since 1.8.0
	 */
	public function execute_tool(
		string $name,
		array $arguments,
		array $auth_context,
		Request $request
	): array {
		return match ( $name ) {
			'whoami'             => $this->tool_whoami( $auth_context ),
			'list_links'         => $this->tool_list_links( $arguments, $request ),
			'get_link'           => $this->tool_get_link( $arguments, $request ),
			'get_analytics'      => $this->tool_get_analytics( $arguments, $request ),
			'get_link_stats'     => $this->tool_get_link_stats( $arguments, $request ),
			'get_link_locations' => $this->tool_get_link_locations( $arguments, $request ),
			'list_activity'      => $this->tool_list_activity( $arguments, $request ),
			'create_link'        => $this->tool_create_link( $arguments, $request ),
			'update_link'        => $this->tool_update_link( $arguments, $request ),
			default              => array(
				'text'     => sprintf( 'Unrecognized tool: %s', $name ),
				'is_error' => true,
			),
		};
	}

	/**
	 * Execute whoami tool.
	 *
	 * @param array<string, mixed> $auth_context Authenticated context.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_whoami( array $auth_context ): array {
		$user       = $auth_context['user'];
		$connection = $auth_context['connection'];

		$data = array(
			'userId'        => (string) ( $user['id'] ?? '' ),
			'username'      => (string) ( $user['username'] ?? '' ),
			'displayName'   => (string) ( $user['display_name'] ?? ( $user['username'] ?? '' ) ),
			'role'          => (string) ( $user['role'] ?? 'editor' ),
			'siteUrl'       => (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ),
			'grantedScopes' => $auth_context['scopes'] ?? array(),
			'client'        => $connection['client_name'] ?? ( $connection['client_id'] ?? 'MCP Client' ),
		);

		return array(
			'text' => (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute list_links tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_list_links( array $arguments, Request $request ): array {
		$query = array(
			'search'    => (string) ( $arguments['search'] ?? '' ),
			'tag'       => (string) ( $arguments['tag'] ?? '' ),
			'limit'     => min( 100, max( 1, (int) ( $arguments['limit'] ?? 20 ) ) ),
			'offset'    => max( 0, (int) ( $arguments['offset'] ?? 0 ) ),
			'sortBy'    => (string) ( $arguments['sort'] ?? 'created_at' ),
			'sortOrder' => (string) ( $arguments['order'] ?? 'desc' ),
		);

		$result = $this->links->list_urls( $request, $query );
		$urls   = $result['items'] ?? ( $result['urls'] ?? ( $result['data'] ?? array() ) );

		$summary = array(
			'total' => $result['meta']['total'] ?? count( $urls ),
			'count' => count( $urls ),
			'links' => array_map(
				static function ( array $url ): array {
					return array(
						'id'          => $url['id'] ?? '',
						'shortCode'   => $url['shortCode'] ?? ( $url['short_code'] ?? '' ),
						'shortUrl'    => $url['shortUrl'] ?? '',
						'title'       => $url['title'] ?? '',
						'originalUrl' => $url['destinationUrl'] ?? ( $url['destination_url'] ?? ( $url['originalUrl'] ?? '' ) ),
						'clicks'      => (int) ( $url['clicks'] ?? 0 ),
						'status'      => $url['status'] ?? 'active',
						'createdAt'   => $url['createdAt'] ?? ( $url['created_at'] ?? '' ),
					);
				},
				$urls
			),
		);

		return array(
			'text' => (string) json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute get_link tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_get_link( array $arguments, Request $request ): array {
		$id = trim( (string) ( $arguments['id'] ?? '' ) );
		if ( '' === $id ) {
			return array(
				'text'     => 'Link ID or alias is required.',
				'is_error' => true,
			);
		}

		$url = $this->links->find_url( $request, $id );
		if ( ! $url ) {
			return array(
				'text'     => sprintf( 'Link not found: %s', $id ),
				'is_error' => true,
			);
		}

		return array(
			'text' => (string) json_encode( $url, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute get_analytics tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_get_analytics( array $arguments, Request $request ): array {
		$days = min( 90, max( 1, (int) ( $arguments['days'] ?? 30 ) ) );

		$summary = $this->analytics->analytics_summary( $request, $days );

		return array(
			'text' => (string) json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute get_link_stats tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_get_link_stats( array $arguments, Request $request ): array {
		$id = trim( (string) ( $arguments['id'] ?? '' ) );
		if ( '' === $id ) {
			return array(
				'text'     => 'Link ID or alias is required.',
				'is_error' => true,
			);
		}

		$url = $this->links->find_url( $request, $id );
		if ( ! $url ) {
			return array(
				'text'     => sprintf( 'Link not found: %s', $id ),
				'is_error' => true,
			);
		}

		$days  = min( 90, max( 1, (int) ( $arguments['days'] ?? 30 ) ) );
		$stats = $this->analytics->link_stats( $request, (string) $url['id'], 'last' . $days );

		return array(
			'text' => (string) json_encode( $stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute get_link_locations tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_get_link_locations( array $arguments, Request $request ): array {
		$id = trim( (string) ( $arguments['id'] ?? '' ) );
		if ( '' === $id ) {
			return array(
				'text'     => 'Link ID or alias is required.',
				'is_error' => true,
			);
		}

		$url = $this->links->find_url( $request, $id );
		if ( ! $url ) {
			return array(
				'text'     => sprintf( 'Link not found: %s', $id ),
				'is_error' => true,
			);
		}

		$locations = $this->analytics->link_location( $request, (string) $url['id'] );

		return array(
			'text' => (string) json_encode( $locations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute list_activity tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_list_activity( array $arguments, Request $request ): array {
		$limit = min( 50, max( 1, (int) ( $arguments['limit'] ?? 20 ) ) );

		$history = $this->analytics->activity_history( $request, array( 'limit' => $limit ) );

		return array(
			'text' => (string) json_encode( $history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute create_link write tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_create_link( array $arguments, Request $request ): array {
		$url = trim( (string) ( $arguments['url'] ?? '' ) );
		if ( '' === $url ) {
			return array(
				'text'     => 'Valid destination URL is required.',
				'is_error' => true,
			);
		}

		$payload = array(
			'destinationUrl' => $url,
			'customSlug'     => ! empty( $arguments['alias'] ) ? trim( (string) $arguments['alias'] ) : null,
			'title'          => ! empty( $arguments['title'] ) ? trim( (string) $arguments['title'] ) : null,
			'utmSource'      => ! empty( $arguments['utm_source'] ) ? trim( (string) $arguments['utm_source'] ) : null,
			'utmMedium'      => ! empty( $arguments['utm_medium'] ) ? trim( (string) $arguments['utm_medium'] ) : null,
			'utmCampaign'    => ! empty( $arguments['utm_campaign'] ) ? trim( (string) $arguments['utm_campaign'] ) : null,
		);

		$created = $this->links->create_url( $request, array_filter( $payload, static fn( $v ) => null !== $v ) );

		return array(
			'text' => (string) json_encode( $created, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Execute update_link write tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param Request              $request   Original HTTP request.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function tool_update_link( array $arguments, Request $request ): array {
		$id = trim( (string) ( $arguments['id'] ?? '' ) );
		if ( '' === $id ) {
			return array(
				'text'     => 'Link ID is required.',
				'is_error' => true,
			);
		}

		$payload = array();
		if ( isset( $arguments['url'] ) ) {
			$payload['destinationUrl'] = trim( (string) $arguments['url'] );
		}
		if ( isset( $arguments['title'] ) ) {
			$payload['title'] = trim( (string) $arguments['title'] );
		}
		if ( isset( $arguments['status'] ) ) {
			$payload['status'] = trim( (string) $arguments['status'] );
		}

		$updated = $this->links->update_url( $request, $id, $payload );

		return array(
			'text' => (string) json_encode( $updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Validate arguments against tool inputSchema.
	 *
	 * @param array<string, mixed> $tool_def  Tool definition from Registry.
	 * @param array<string, mixed> $arguments Caller arguments.
	 * @return string|null Error message or null if valid.
	 * @since 1.8.0
	 */
	public function validate_tool_arguments( array $tool_def, array $arguments ): ?string {
		$schema     = (array) ( $tool_def['inputSchema'] ?? array() );
		$required   = (array) ( $schema['required'] ?? array() );
		$properties = (array) ( $schema['properties'] ?? array() );

		// 1. Check additionalProperties: reject unknown arguments if false.
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( array_keys( $arguments ) as $arg_key ) {
				if ( ! array_key_exists( $arg_key, $properties ) ) {
					return sprintf( 'Unexpected argument: "%s" is not allowed.', $arg_key );
				}
			}
		}

		// 2. Verify required arguments.
		foreach ( $required as $req_field ) {
			if ( ! array_key_exists( $req_field, $arguments ) || null === $arguments[ $req_field ] || '' === trim( (string) $arguments[ $req_field ] ) ) {
				return sprintf( 'Missing required argument: %s', $req_field );
			}
		}

		// 3. Verify argument types, bounds, enums, and formats.
		foreach ( $arguments as $key => $val ) {
			if ( ! isset( $properties[ $key ] ) ) {
				continue;
			}

			if ( null === $val ) {
				continue;
			}

			$prop          = (array) $properties[ $key ];
			$expected_type = (string) ( $prop['type'] ?? 'string' );

			switch ( $expected_type ) {
				case 'integer':
					if ( is_float( $val ) || ( is_string( $val ) && str_contains( $val, '.' ) ) || false === filter_var( $val, FILTER_VALIDATE_INT ) ) {
						return sprintf( 'Argument "%s" must be an integer.', $key );
					}
					$int_val = (int) $val;
					if ( isset( $prop['minimum'] ) && $int_val < (int) $prop['minimum'] ) {
						return sprintf( 'Argument "%s" must be at least %d.', $key, (int) $prop['minimum'] );
					}
					if ( isset( $prop['maximum'] ) && $int_val > (int) $prop['maximum'] ) {
						return sprintf( 'Argument "%s" must be at most %d.', $key, (int) $prop['maximum'] );
					}
					break;

				case 'number':
					if ( ! is_numeric( $val ) ) {
						return sprintf( 'Argument "%s" must be a number.', $key );
					}
					$num_val = (float) $val;
					if ( isset( $prop['minimum'] ) && $num_val < (float) $prop['minimum'] ) {
						return sprintf( 'Argument "%s" must be at least %s.', $key, $prop['minimum'] );
					}
					if ( isset( $prop['maximum'] ) && $num_val > (float) $prop['maximum'] ) {
						return sprintf( 'Argument "%s" must be at most %s.', $key, $prop['maximum'] );
					}
					break;

				case 'boolean':
					if ( ! is_bool( $val ) && 'true' !== $val && 'false' !== $val && 1 !== $val && 0 !== $val && '1' !== $val && '0' !== $val ) {
						return sprintf( 'Argument "%s" must be a boolean.', $key );
					}
					break;

				case 'array':
					if ( ! is_array( $val ) ) {
						return sprintf( 'Argument "%s" must be an array.', $key );
					}
					break;

				case 'string':
				default:
					if ( ! is_scalar( $val ) ) {
						return sprintf( 'Argument "%s" must be a string.', $key );
					}
					$str_val = (string) $val;
					if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) {
						if ( ! in_array( $str_val, $prop['enum'], true ) ) {
							return sprintf(
								'Argument "%s" must be one of: %s.',
								$key,
								implode( ', ', $prop['enum'] )
							);
						}
					}
					if ( ( isset( $prop['format'] ) && 'uri' === $prop['format'] ) || 'url' === $key ) {
						$url_str = trim( $str_val );
						if ( ! filter_var( $url_str, FILTER_VALIDATE_URL ) || ( ! str_starts_with( $url_str, 'http://' ) && ! str_starts_with( $url_str, 'https://' ) ) ) {
							if ( 'url' === $key ) {
								return 'The destination URL must be a valid HTTP or HTTPS web address.';
							}
							return sprintf( 'Argument "%s" must be a valid HTTP or HTTPS URL.', $key );
						}
					}
					break;
			}
		}

		return null;
	}

	/**
	 * Validate Host and Origin headers to defend against DNS rebinding and cross-origin attacks.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return string|null Error message if invalid, null if valid.
	 * @since 1.8.0
	 */
	private function validate_origin_and_host( Request $request ): ?string {
		$site_url  = (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' );
		$site_host = strtolower( (string) parse_url( $site_url, PHP_URL_HOST ) );

		$allowed_hosts = array( 'localhost', '127.0.0.1', '::1', 'peakurl.dev', 'api.peakurl.dev', 'peakurl.test' );
		if ( '' !== $site_host ) {
			$allowed_hosts[] = $site_host;
			if ( str_starts_with( $site_host, 'api.' ) ) {
				$allowed_hosts[] = substr( $site_host, 4 );
			} else {
				$allowed_hosts[] = 'api.' . $site_host;
			}
		}

		// 1. Host header validation (if provided).
		$host_header = $request->get_header( 'Host' );
		if ( null !== $host_header && '' !== trim( (string) $host_header ) ) {
			$request_host = strtolower( explode( ':', trim( (string) $host_header ) )[0] );
			if ( ! in_array( $request_host, $allowed_hosts, true ) ) {
				return sprintf( 'Invalid Host header: "%s".', $request_host );
			}
		}

		// 2. Origin header validation (if provided).
		$origin_header = $request->get_header( 'Origin' );
		if ( null !== $origin_header && '' !== trim( (string) $origin_header ) ) {
			$origin_host = strtolower( (string) parse_url( trim( (string) $origin_header ), PHP_URL_HOST ) );
			if ( '' !== $origin_host && ! in_array( $origin_host, $allowed_hosts, true ) ) {
				return sprintf( 'Forbidden: Cross-origin request not allowed from origin "%s".', $origin_header );
			}
		}

		return null;
	}

	/**
	 * Create a success JSON-RPC response with protocol headers.
	 *
	 * @param mixed $id     JSON-RPC request ID.
	 * @param mixed $result Result payload.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function success_response( $id, $result ): array {
		return JsonResponse::raw(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200,
			array(
				'MCP-Protocol-Version' => Constants::PROTOCOL_VERSION,
			)
		);
	}

	/**
	 * Create an error JSON-RPC response with protocol headers.
	 *
	 * @param mixed                 $id            JSON-RPC request ID.
	 * @param int                   $code          Error code.
	 * @param string                $message       Error message.
	 * @param int                   $status        HTTP status code (default 200 for protocol errors).
	 * @param array<string, string> $extra_headers Optional additional HTTP headers.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function error_response(
		$id,
		int $code,
		string $message,
		int $status = 200,
		array $extra_headers = array()
	): array {
		$headers = array_merge(
			array(
				'MCP-Protocol-Version' => Constants::PROTOCOL_VERSION,
			),
			$extra_headers
		);

		return JsonResponse::raw(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status,
			$headers
		);
	}

	/**
	 * Create an unauthorized 401 response with RFC 9728 metadata header.
	 *
	 * @param mixed $id JSON-RPC request ID.
	 * @return array<string, mixed>
	 * @since 1.8.0
	 */
	private function unauthorized_response( $id ): array {
		$site_url     = rtrim( (string) ( $this->config[ CoreConstants::SITE_URL ] ?? '' ), '/' );
		$resource_url = $site_url . '/.well-known/oauth-protected-resource/api/mcp';

		$headers = array(
			'WWW-Authenticate'     => 'Bearer error="invalid_token", error_description="The access token is invalid or expired."',
			'Link'                 => '<' . $resource_url . '>; rel="oauth-protected-resource"',
			'MCP-Protocol-Version' => Constants::PROTOCOL_VERSION,
		);

		return JsonResponse::raw(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => Constants::MCP_UNAUTHORIZED,
					'message' => 'Unauthorized: Valid Bearer token required.',
				),
			),
			401,
			$headers
		);
	}
}
