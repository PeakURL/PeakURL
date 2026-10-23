<?php
/**
 * Unit tests for MCP Tool Registry.
 *
 * @package PeakURL\Tests\Unit\Mcp
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Mcp\Constants;
use PeakURL\Features\Mcp\Registry;

class McpRegistryTest extends TestCase {

	public function test_read_tools_catalog_contains_expected_tools(): void {
		$tools = Registry::get_tools( false );

		$this->assertArrayHasKey( 'whoami', $tools );
		$this->assertArrayHasKey( 'list_links', $tools );
		$this->assertArrayHasKey( 'get_link', $tools );
		$this->assertArrayHasKey( 'get_analytics', $tools );
		$this->assertArrayHasKey( 'get_link_stats', $tools );
		$this->assertArrayHasKey( 'get_link_locations', $tools );
		$this->assertArrayHasKey( 'list_activity', $tools );

		// Write tools should NOT be included when include_write is false.
		$this->assertArrayNotHasKey( 'create_link', $tools );
		$this->assertArrayNotHasKey( 'update_link', $tools );
	}

	public function test_write_tools_included_when_requested(): void {
		$tools = Registry::get_tools( true );

		$this->assertArrayHasKey( 'create_link', $tools );
		$this->assertArrayHasKey( 'update_link', $tools );
		$this->assertTrue( $tools['create_link']['is_write'] );
		$this->assertTrue( $tools['update_link']['is_write'] );
		$this->assertSame( Constants::SCOPE_LINKS_WRITE, $tools['create_link']['scope'] );
		$this->assertSame( Constants::SCOPE_LINKS_WRITE, $tools['update_link']['scope'] );
	}

	public function test_all_tools_have_valid_schemas(): void {
		$tools = Registry::get_tools( true );

		foreach ( $tools as $name => $tool ) {
			$this->assertSame( $name, $tool['name'] );
			$this->assertNotEmpty( $tool['description'] );
			$this->assertArrayHasKey( 'inputSchema', $tool );
			$this->assertSame( 'object', $tool['inputSchema']['type'] );
			$this->assertArrayHasKey( 'properties', $tool['inputSchema'] );
		}
	}

	public function test_get_tool_by_name(): void {
		$whoami = Registry::get_tool( 'whoami' );
		$this->assertNotNull( $whoami );
		$this->assertSame( 'whoami', $whoami['name'] );

		$create_link = Registry::get_tool( 'create_link' );
		$this->assertNotNull( $create_link );
		$this->assertSame( 'create_link', $create_link['name'] );

		$nonexistent = Registry::get_tool( 'nonexistent_tool' );
		$this->assertNull( $nonexistent );
	}
}
