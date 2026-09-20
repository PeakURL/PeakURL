<?php
/**
 * Unit tests for maintenance page generator tag.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class MaintenanceGeneratorTagTest extends TestCase {

	public function test_maintenance_page_html_contains_generator_tag(): void {
		$data = \get_maintenance_view_data();
		$html = \render_maintenance_page( $data );

		$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
		$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', $html );
	}

	public function test_maintenance_page_html_with_explicit_version(): void {
		$data            = \get_maintenance_view_data();
		$data['version'] = '9.9.9';
		$html            = \render_maintenance_page( $data );

		$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
		$this->assertStringContainsString( '<meta name="generator" content="PeakURL 9.9.9">', $html );
	}
}
