<?php
/**
 * Unit tests for site URL resolution helper (get_site_url).
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Api\SettingsApi;
use RuntimeException;

class SiteUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\remove_all_filters( 'site_url' );
	}

	protected function tearDown(): void {
		\remove_all_filters( 'site_url' );
		parent::tearDown();
	}

	public function test_get_site_url_returns_url_from_settings_when_present(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willReturn( 'https://settings.peakurl.dev/' );

		$url = \get_site_url( 'dashboard/links', null, $settings );
		$this->assertSame( 'https://settings.peakurl.dev/dashboard/links', $url );
	}

	public function test_get_site_url_normalizes_trailing_slash_and_appends_path(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willReturn( 'https://settings.peakurl.dev/' );

		$url = \get_site_url( '/api/v1/urls', null, $settings );
		$this->assertSame( 'https://settings.peakurl.dev/api/v1/urls', $url );
	}

	public function test_get_site_url_supports_scheme_override(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willReturn( 'https://settings.peakurl.dev/' );

		$http_url = \get_site_url( 'test', 'http', $settings );
		$this->assertSame( 'http://settings.peakurl.dev/test', $http_url );

		$relative_url = \get_site_url( 'test', 'relative', $settings );
		$this->assertSame( '/test', $relative_url );
	}

	public function test_get_site_url_falls_back_to_config_when_setting_is_empty(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willReturn( '' );

		$url = \get_site_url( 'api/v1', null, $settings );
		$this->assertNotEmpty( $url );
		$this->assertStringEndsWith( '/api/v1', $url );
	}

	public function test_get_site_url_does_not_swallow_database_exceptions(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willThrowException( new RuntimeException( 'Database connection failed during read' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Database connection failed during read' );

		\get_site_url( '', null, $settings );
	}

	public function test_get_site_url_supports_wordpress_blog_id_and_path_signature(): void {
		$settings = $this->createMock( SettingsApi::class );
		$settings->method( 'get_option' )
			->with( 'site_url' )
			->willReturn( 'https://settings.peakurl.dev/' );

		// 1. WordPress signature with null blog_id and path
		$url_null_id = \get_site_url( null, 'my-custom-alias', null, $settings );
		$this->assertSame( 'https://settings.peakurl.dev/my-custom-alias', $url_null_id );

		// 2. WordPress signature with integer blog_id and path
		$url_int_id = \get_site_url( 1, 'another-alias', null, $settings );
		$this->assertSame( 'https://settings.peakurl.dev/another-alias', $url_int_id );

		// 3. WordPress signature with null blog_id and empty path
		$url_base = \get_site_url( null, '', null, $settings );
		$this->assertSame( 'https://settings.peakurl.dev', $url_base );
	}
}
