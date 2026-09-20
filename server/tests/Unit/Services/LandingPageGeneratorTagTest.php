<?php
/**
 * Unit tests for landing page generator tag injection and preservation.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class LandingPageGeneratorTagTest extends TestCase {

	public function test_landing_page_html_injects_canonical_generator_tag(): void {
		$template  = "<!doctype html>\n<html>\n<head>\n<title>Test</title>\n</head>\n<body>Hello</body>\n</html>";
		$temp_file = tempnam( sys_get_temp_dir(), 'landing_test_' );
		$this->assertNotFalse( $temp_file );

		try {
			file_put_contents( $temp_file, $template );
			$html = \get_landing_page_html( $temp_file );

			$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
			$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', $html );
		} finally {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}

	public function test_landing_page_html_deduplicates_existing_generator_tag(): void {
		$template  = "<!doctype html>\n<html>\n<head>\n<meta name=\"generator\" content=\"PeakURL Old-Version\">\n<title>Test</title>\n</head>\n<body>Hello</body>\n</html>";
		$temp_file = tempnam( sys_get_temp_dir(), 'landing_test_' );
		$this->assertNotFalse( $temp_file );

		try {
			file_put_contents( $temp_file, $template );
			$html = \get_landing_page_html( $temp_file );

			$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
			$this->assertStringNotContainsString( 'Old-Version', $html );
			$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', $html );
		} finally {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}

	public function test_landing_page_html_handles_template_without_head(): void {
		$template  = '<div>No head tag here</div>';
		$temp_file = tempnam( sys_get_temp_dir(), 'landing_test_' );
		$this->assertNotFalse( $temp_file );

		try {
			file_put_contents( $temp_file, $template );
			$html = \get_landing_page_html( $temp_file );

			$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
			$this->assertStringContainsString( '<div>No head tag here</div>', $html );
		} finally {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}

	public function test_landing_page_file_on_disk_is_not_modified(): void {
		$real_landing_file = \PeakURL\Core\Config\Environment::get_instance()->get_content_path() . '/landing-page.html';
		if ( file_exists( $real_landing_file ) ) {
			$before_content = (string) file_get_contents( $real_landing_file );
			$rendered       = \get_landing_page_html( $real_landing_file );
			$after_content  = (string) file_get_contents( $real_landing_file );

			$this->assertSame( $before_content, $after_content, 'Landing page file on disk must never be modified by rendering.' );
			$this->assertSame( 1, substr_count( $rendered, '<meta name="generator"' ) );
		}
	}

	public function test_landing_page_data_does_not_contain_version(): void {
		$data = \get_landing_page_data();
		$this->assertArrayNotHasKey( 'version', $data );
	}
}
