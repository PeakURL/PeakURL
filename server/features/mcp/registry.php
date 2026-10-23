<?php
/**
 * Model Context Protocol (MCP) tool registry.
 *
 * Defines explicit tool schemas, capability requirements, and OAuth scope
 * associations. Tools are never auto-registered from arbitrary endpoints.
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
 * Registry — catalog of MCP tools and schema definitions.
 *
 * @since 1.8.0
 */
class Registry {

	/**
	 * Return the full catalog of registered MCP tools.
	 *
	 * @param bool $include_write Whether to include write tools.
	 * @return array<string, array<string, mixed>> Tool definitions keyed by name.
	 * @since 1.8.0
	 */
	public static function get_tools( bool $include_write = false ): array {
		$tools = array(
			'whoami'             => array(
				'name'        => 'whoami',
				'description' => 'Get the authenticated PeakURL user identity, role, and granted capabilities.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_READ,
				'capability'  => null,
				'is_write'    => false,
			),
			'list_links'         => array(
				'name'        => 'list_links',
				'description' => 'List, search, and paginate short links. Returns link metadata, destination URL, short URL, and click counts.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Optional search query matching title, alias, or destination URL.',
						),
						'tag'    => array(
							'type'        => 'string',
							'description' => 'Optional tag to filter links.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => 'Number of links to return (1-100, default 20).',
						),
						'offset' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Pagination offset (default 0).',
						),
						'sort'   => array(
							'type'        => 'string',
							'enum'        => array( 'created_at', 'clicks', 'title' ),
							'description' => 'Sort field: created_at, clicks, or title (default created_at).',
						),
						'order'  => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => 'Sort order: asc or desc (default desc).',
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_READ,
				'capability'  => 'view_links',
				'is_write'    => false,
			),
			'get_link'           => array(
				'name'        => 'get_link',
				'description' => 'Get detailed information for a single short link by its ID or alias.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => 'Unique link ID or short alias.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_READ,
				'capability'  => 'view_links',
				'is_write'    => false,
			),
			'get_analytics'      => array(
				'name'        => 'get_analytics',
				'description' => 'Get aggregate analytics summary (total clicks, unique visitors, browser breakdown, top referrers) across links for the specified number of days.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'days' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 90,
							'description' => 'Number of days to summarize (1-90, default 30).',
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_ANALYTICS_READ,
				'capability'  => 'view_analytics',
				'is_write'    => false,
			),
			'get_link_stats'     => array(
				'name'        => 'get_link_stats',
				'description' => 'Get time-series click statistics and timeline for a specific link.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'string',
							'description' => 'Unique link ID or alias.',
						),
						'days' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 90,
							'description' => 'Number of days for statistics (1-90, default 30).',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_ANALYTICS_READ,
				'capability'  => 'view_analytics',
				'is_write'    => false,
			),
			'get_link_locations' => array(
				'name'        => 'get_link_locations',
				'description' => 'Get geographic click distribution (country and city aggregates) for a specific link.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'string',
							'description' => 'Unique link ID or alias.',
						),
						'days' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 90,
							'description' => 'Number of days for geographic data (1-90, default 30).',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_ANALYTICS_READ,
				'capability'  => 'view_analytics',
				'is_write'    => false,
			),
			'list_activity'      => array(
				'name'        => 'list_activity',
				'description' => 'Get the recent activity and audit log for links.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 50,
							'description' => 'Number of activity items to return (1-50, default 20).',
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_READ,
				'capability'  => 'view_links',
				'is_write'    => false,
			),
		);

		if ( $include_write ) {
			$tools['create_link'] = array(
				'name'        => 'create_link',
				'description' => 'Create a new shortened link with optional alias, title, and UTM campaign parameters.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'          => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Destination URL to shorten (must be valid HTTP/HTTPS URL).',
						),
						'alias'        => array(
							'type'        => 'string',
							'description' => 'Optional custom short alias (alphanumeric and dashes).',
						),
						'title'        => array(
							'type'        => 'string',
							'description' => 'Optional human-readable title for the link.',
						),
						'utm_source'   => array(
							'type'        => 'string',
							'description' => 'Optional UTM source campaign parameter.',
						),
						'utm_medium'   => array(
							'type'        => 'string',
							'description' => 'Optional UTM medium campaign parameter.',
						),
						'utm_campaign' => array(
							'type'        => 'string',
							'description' => 'Optional UTM campaign name parameter.',
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_WRITE,
				'capability'  => 'create_links',
				'is_write'    => true,
			);

			$tools['update_link'] = array(
				'name'        => 'update_link',
				'description' => 'Update an existing short link destination URL, title, or status.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'string',
							'description' => 'Unique link ID to update.',
						),
						'url'    => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Optional new destination URL.',
						),
						'title'  => array(
							'type'        => 'string',
							'description' => 'Optional new title.',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive' ),
							'description' => 'Optional new status: active or inactive.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'scope'       => Constants::SCOPE_LINKS_WRITE,
				'capability'  => 'edit_links',
				'is_write'    => true,
			);
		}

		return $tools;
	}

	/**
	 * Get a specific tool definition by name.
	 *
	 * @param string $name Tool identifier.
	 * @return array<string, mixed>|null Tool definition or null if not found.
	 * @since 1.8.0
	 */
	public static function get_tool( string $name ): ?array {
		$tools = self::get_tools( true );

		return $tools[ $name ] ?? null;
	}
}
