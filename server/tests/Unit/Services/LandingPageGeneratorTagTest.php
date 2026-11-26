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

	public function test_default_page_uses_local_brand_assets_and_prefixed_login(): void {
		$html = \get_landing_page_html( login_path: '/tools/login' );

		$this->assertStringContainsString( '<h1 id="page-heading">Shorten, track, and own every link.</h1>', $html );
		$this->assertStringContainsString( 'href="/tools/login"', $html );
		$this->assertStringContainsString( 'data-status-theme="auto"', $html );
		$this->assertStringContainsString( 'data-status-illustration="installation" src="data:image/svg+xml;base64,', $html );
		$this->assertStringContainsString( 'class="brand-wordmark"', $html );
		$this->assertStringNotContainsString( '{{peakurl_', $html );
		$this->assertStringNotContainsString( 'cdn.jsdelivr.net', $html );
		$this->assertStringNotContainsString( 'fonts.googleapis.com', $html );
	}

	public function test_custom_template_keeps_attributes_and_existing_logo_token(): void {
		$template  = '<html lang="fr" dir="rtl" class="custom" data-label="x > y"><head><title>Title</title></head><body><h1>My links</h1><img src="{{peakurl_logo_url}}">{{unrelated_token}}</body></html>';
		$temp_file = tempnam( sys_get_temp_dir(), 'landing_test_' );
		$this->assertNotFalse( $temp_file );

		try {
			file_put_contents( $temp_file, $template );
			$html = \get_landing_page_html( $temp_file );

			$this->assertMatchesRegularExpression( '/<html lang="[^"]+" dir="ltr" class="custom" data-label="x > y">/', $html );
			$this->assertStringContainsString( '<h1>My links</h1><img src="data:image/svg+xml;base64,', $html );
			$this->assertStringContainsString( '{{unrelated_token}}', $html );
			$this->assertSame( $template, file_get_contents( $temp_file ) );
			$this->assertStringNotContainsString( '--background', $html );
		} finally {
			unlink( $temp_file );
		}
	}

	public function test_landing_page_data_does_not_contain_version(): void {
		$data = \get_landing_page_data();
		$this->assertArrayNotHasKey( 'version', $data );
	}
}
