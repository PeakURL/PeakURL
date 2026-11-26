<?php
/**
 * Unit tests for Favicon service defaults, custom precedence, and SVG hiding.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Favicon;
use PeakURL\Utils\File;
use PHPUnit\Framework\TestCase;

class FaviconServiceTest extends TestCase {

	private string $temp_content_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->temp_content_dir = sys_get_temp_dir() . '/peakurl_favicon_test_' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temp_content_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( '' !== $this->temp_content_dir && is_dir( $this->temp_content_dir ) ) {
			$this->remove_directory( $this->temp_content_dir );
		}
		parent::tearDown();
	}

	public function test_default_favicon_exposes_adaptive_svg_and_png(): void {
		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_SITE_FAVICON )
			->willReturn( null );

		$service  = new Favicon( array( Constants::CONTENT_DIR => $this->temp_content_dir ), $settings_api );
		$settings = $service->get_settings( 'PeakURL' );
		$assets   = $service->get_assets( 'PeakURL' );

		$this->assertTrue( $settings['configured'] );
		$this->assertFalse( $settings['isCustom'] );
		$this->assertIsString( $settings['svgUrl'] );
		$this->assertStringContainsString( '/favicon.svg', $settings['svgUrl'] );
		$this->assertIsString( $settings['lightSvgUrl'] );
		$this->assertStringContainsString( '/favicon-light.svg', $settings['lightSvgUrl'] );
		$this->assertIsString( $settings['darkSvgUrl'] );
		$this->assertStringContainsString( '/favicon-dark.svg', $settings['darkSvgUrl'] );

		$this->assertTrue( $assets['configured'] );
		$this->assertFalse( $assets['isCustom'] );
		$this->assertNotEmpty( $assets['svgIconPath'] );
		$this->assertFileExists( (string) $assets['svgIconPath'] );
		$this->assertStringEndsWith( 'default-favicon.svg', (string) $assets['svgIconPath'] );
		$this->assertFileExists( (string) $assets['lightSvgIconPath'] );
		$this->assertStringEndsWith( 'default-favicon-light.svg', (string) $assets['lightSvgIconPath'] );
		$this->assertFileExists( (string) $assets['darkSvgIconPath'] );
		$this->assertStringEndsWith( 'default-favicon-dark.svg', (string) $assets['darkSvgIconPath'] );
		$this->assertStringEndsWith( 'default-favicon.png', (string) $assets['iconPath'] );
		$this->assertStringEndsWith( 'default-app-icon.png', (string) $assets['appIconPath'] );
		$this->assertStringEndsWith( 'default-site.webmanifest', (string) $assets['manifestPath'] );
	}

	public function test_custom_favicon_takes_precedence_and_suppresses_default_svg(): void {
		$upload_dir = $this->temp_content_dir . '/uploads/favicon';
		mkdir( $upload_dir, 0755, true );
		$custom_icon_path = $upload_dir . '/favicon.png';
		// Minimal valid 1x1 PNG file bytes.
		$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		file_put_contents( $custom_icon_path, $png_bytes );

		$metadata_json = json_encode(
			array(
				'width'     => 256,
				'height'    => 256,
				'updatedAt' => '2026-11-25 12:00:00',
			),
			JSON_THROW_ON_ERROR
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )
			->with( Constants::SETTING_SITE_FAVICON )
			->willReturn( $metadata_json );

		$service  = new Favicon( array( Constants::CONTENT_DIR => $this->temp_content_dir ), $settings_api );
		$settings = $service->get_settings( 'Custom Site' );
		$assets   = $service->get_assets( 'Custom Site' );

		$this->assertTrue( $settings['configured'] );
		$this->assertTrue( $settings['isCustom'] );
		$this->assertNull( $settings['svgUrl'] );
		$this->assertNull( $settings['lightSvgUrl'] );
		$this->assertNull( $settings['darkSvgUrl'] );
		$this->assertStringContainsString( '/favicon.png', (string) $settings['url'] );

		$this->assertTrue( $assets['configured'] );
		$this->assertTrue( $assets['isCustom'] );
		$this->assertSame( '', $assets['svgIconPath'] );
		$this->assertSame( '', $assets['lightSvgIconPath'] );
		$this->assertSame( '', $assets['darkSvgIconPath'] );
		$this->assertSame( $custom_icon_path, $assets['iconPath'] );
		$this->assertSame( $custom_icon_path, $assets['appIconPath'] );
		$this->assertSame( $upload_dir . '/site.webmanifest', $assets['manifestPath'] );
		$this->assertFileExists( $upload_dir . '/site.webmanifest' );
	}

	private function remove_directory( string $dir ): void {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
