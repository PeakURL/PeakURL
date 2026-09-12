<?php
/**
 * Unit tests for PeakURL Environment runtime abstraction.
 *
 * @package PeakURL\Tests\Unit\Config
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Config;

use PeakURL\Core\Config\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase {

	private ?Environment $original_instance = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_instance = Environment::get_instance();
	}

	protected function tearDown(): void {
		Environment::set_instance( $this->original_instance );
		parent::tearDown();
	}

	public function test_development_layout_paths(): void {
		$repo_root   = dirname( __DIR__, 4 );
		$environment = new Environment( $repo_root, true );

		$this->assertTrue( $environment->is_development() );
		$this->assertSame( $repo_root, $environment->get_source_root() );
		$this->assertSame( $repo_root . '/server', $environment->get_runtime_root() );
		$this->assertSame( $repo_root . '/server/vendor', $environment->get_vendor_path() );
		$this->assertSame( $repo_root . '/server/vendor/autoload.php', $environment->get_vendor_autoload_path() );
		$this->assertSame( $repo_root . '/server/public/index.php', $environment->get_api_entrypoint() );
		$this->assertSame( $repo_root . '/index.html', $environment->get_app_html_path() );
		$this->assertSame( $repo_root . '/assets', $environment->get_assets_path() );
		$this->assertSame( $repo_root . '/content', $environment->get_content_path() );
		$this->assertSame( $repo_root . '/server/database/schema.sql', $environment->get_database_schema_path() );
		$this->assertSame( $repo_root . '/server/bin', $environment->get_bin_path() );
		$this->assertSame( $repo_root . '/server/public/default-favicon.png', $environment->get_default_favicon_path() );
		$this->assertSame( $repo_root . '/server/public/default-site.webmanifest', $environment->get_default_manifest_path() );
		$this->assertSame( 'php server/bin/update-geoip.php', $environment->get_geoip_command() );
	}

	public function test_production_layout_paths(): void {
		$prod_root = sys_get_temp_dir() . '/peakurl_test_prod_' . bin2hex( random_bytes( 8 ) );
		mkdir( $prod_root );

		try {
			$environment = new Environment( $prod_root, false );

			$this->assertFalse( $environment->is_development() );
			$this->assertSame( $prod_root, $environment->get_source_root() );
			$this->assertSame( $prod_root, $environment->get_runtime_root() );
			$this->assertSame( $prod_root . '/vendor', $environment->get_vendor_path() );
			$this->assertSame( $prod_root . '/vendor/autoload.php', $environment->get_vendor_autoload_path() );
			$this->assertSame( $prod_root . '/api/index.php', $environment->get_api_entrypoint() );
			$this->assertSame( $prod_root . '/index.html', $environment->get_app_html_path() );
			$this->assertSame( $prod_root . '/assets', $environment->get_assets_path() );
			$this->assertSame( $prod_root . '/content', $environment->get_content_path() );
			$this->assertSame( $prod_root . '/database/schema.sql', $environment->get_database_schema_path() );
			$this->assertSame( $prod_root . '/bin', $environment->get_bin_path() );
			$this->assertSame( $prod_root . '/assets/default-favicon.png', $environment->get_default_favicon_path() );
			$this->assertSame( $prod_root . '/assets/default-site.webmanifest', $environment->get_default_manifest_path() );
			$this->assertSame( 'php bin/update-geoip.php', $environment->get_geoip_command() );
		} finally {
			rmdir( $prod_root );
		}
	}

	public function test_parse_dev_flag_variants(): void {
		$this->assertTrue( Environment::parse_dev_flag( true ) );
		$this->assertTrue( Environment::parse_dev_flag( 'true' ) );
		$this->assertTrue( Environment::parse_dev_flag( 'TRUE' ) );
		$this->assertTrue( Environment::parse_dev_flag( '1' ) );
		$this->assertTrue( Environment::parse_dev_flag( 1 ) );

		$this->assertFalse( Environment::parse_dev_flag( false ) );
		$this->assertFalse( Environment::parse_dev_flag( 'false' ) );
		$this->assertFalse( Environment::parse_dev_flag( '0' ) );
		$this->assertFalse( Environment::parse_dev_flag( 0 ) );
		$this->assertFalse( Environment::parse_dev_flag( null ) );
		$this->assertFalse( Environment::parse_dev_flag( '' ) );
		$this->assertFalse( Environment::parse_dev_flag( 'unknown' ) );
	}

	public function test_negative_mode_failure_when_server_dir_missing(): void {
		$dummy_root = sys_get_temp_dir() . '/peakurl_test_missing_server_' . bin2hex( random_bytes( 8 ) );
		mkdir( $dummy_root );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage(
			sprintf(
				'PEAKURL_DEV is set to true, but the development directory "server/" was not found at %s.',
				$dummy_root
			)
		);

		try {
			new Environment( $dummy_root, true );
		} finally {
			rmdir( $dummy_root );
		}
	}
}
