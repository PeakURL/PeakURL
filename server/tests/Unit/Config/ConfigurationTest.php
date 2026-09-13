<?php
/**
 * Unit tests for PeakURL Configuration loader and helpers.
 *
 * @package PeakURL\Tests\Unit\Config
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use RuntimeException;

class ConfigurationTest extends TestCase {

	private ?Environment $original_instance = null;
	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->original_instance = Environment::get_instance();
		$this->temp_dir          = sys_get_temp_dir() . '/peakurl_cfg_test_' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		Environment::set_instance( $this->original_instance );
		$this->recursive_delete( $this->temp_dir );
		parent::tearDown();
	}

	public function test_normalize_db_prefix_validates_characters(): void {
		$this->assertSame( '', Configuration::normalize_db_prefix( '' ) );
		$this->assertSame( 'peak_', Configuration::normalize_db_prefix( 'peak_' ) );
		$this->assertSame( '_custom123', Configuration::normalize_db_prefix( '_custom123' ) );
		$this->assertSame( 'wp_', Configuration::normalize_db_prefix( ' wp_ ' ) );
	}

	public function test_normalize_db_prefix_rejects_invalid_characters(): void {
		$this->expectException( RuntimeException::class );
		Configuration::normalize_db_prefix( '123_invalid' );
	}

	public function test_normalize_db_prefix_rejects_hyphens(): void {
		$this->expectException( RuntimeException::class );
		Configuration::normalize_db_prefix( 'invalid-prefix' );
	}

	public function test_normalize_db_name_validates_characters(): void {
		$this->assertSame( 'peakurl', Configuration::normalize_db_name( 'peakurl' ) );
		$this->assertSame( 'peakurl_prod_1', Configuration::normalize_db_name( 'peakurl_prod_1' ) );
	}

	public function test_normalize_db_name_rejects_empty(): void {
		$this->expectException( RuntimeException::class );
		Configuration::normalize_db_name( '' );
	}

	public function test_normalize_db_name_rejects_hyphens(): void {
		$this->expectException( RuntimeException::class );
		Configuration::normalize_db_name( 'peakurl-test' );
	}

	public function test_hash_keys_produces_deterministic_md5(): void {
		$config = array(
			'site_url' => 'https://peakurl.dev',
			'debug'    => true,
		);

		$hash1 = Configuration::hash_keys( $config, array( 'site_url', 'debug' ), array( 'extra' => 'val' ) );
		$hash2 = Configuration::hash_keys( $config, array( 'site_url', 'debug' ), array( 'extra' => 'val' ) );
		$hash3 = Configuration::hash_keys( $config, array( 'site_url' ), array( 'extra' => 'val' ) );

		$this->assertSame( 32, strlen( $hash1 ) );
		$this->assertSame( $hash1, $hash2 );
		$this->assertNotSame( $hash1, $hash3 );
	}

	public function test_has_database_configuration_detects_credentials_in_config(): void {
		$this->assertFalse( Configuration::has_database_configuration( $this->temp_dir ) );

		file_put_contents(
			$this->temp_dir . '/config.php',
			"<?php\ndefine('DB_DATABASE', 'testdb');\ndefine('DB_USERNAME', 'dbuser');\n"
		);

		$this->assertTrue( Configuration::has_database_configuration( $this->temp_dir ) );
	}

	public function test_load_reads_config_and_env_precedence(): void {
		file_put_contents(
			$this->temp_dir . '/config.php',
			"<?php\ndefine('PEAKURL_WORKSPACE_SLUG', 'config-slug');\ndefine('PEAKURL_WORKSPACE_NAME', 'Config Workspace');\n"
		);
		file_put_contents(
			$this->temp_dir . '/.version',
			'1.9.9'
		);
		file_put_contents(
			$this->temp_dir . '/.env',
			"PEAKURL_WORKSPACE_NAME=\"Env Workspace\"\n"
		);

		$env = new Environment( $this->temp_dir, false );
		Environment::set_instance( $env );

		$config = Configuration::load();

		$this->assertSame( '1.9.9', $config[ Constants::VERSION ] );
		// Environment variables in phpunit.xml (PEAKURL_ENV=testing) take top precedence
		$this->assertSame( 'testing', $config[ Constants::ENV ] );
		// Config file values when not in .env
		$this->assertSame( 'config-slug', $config[ Constants::WORKSPACE_SLUG ] );
		// Root .env should override config.php
		$this->assertSame( 'Env Workspace', $config[ Constants::WORKSPACE_NAME ] );
	}

	public function test_runtime_env_overrides_root_env_in_development_layout(): void {
		$server_dir = $this->temp_dir . '/server';
		mkdir( $server_dir, 0755, true );

		file_put_contents(
			$this->temp_dir . '/config.php',
			"<?php\ndefine('PEAKURL_WORKSPACE_NAME', 'Original');\n"
		);
		file_put_contents(
			$this->temp_dir . '/.env',
			"PEAKURL_WORKSPACE_NAME=\"Root Env\"\n"
		);
		file_put_contents(
			$server_dir . '/.env',
			"PEAKURL_WORKSPACE_NAME=\"Runtime Env Override\"\n"
		);

		$env = new Environment( $this->temp_dir, true );
		Environment::set_instance( $env );

		$config = Configuration::load( $server_dir );

		// Runtime .env takes precedence over root .env when distinct
		$this->assertSame( 'Runtime Env Override', $config[ Constants::WORKSPACE_NAME ] );
	}

	public function test_configuration_hierarchy_matrix(): void {
		$server_dir = $this->temp_dir . '/server';
		mkdir( $server_dir, 0755, true );

		// 1. Default baseline check.
		$env = new Environment( $this->temp_dir, false );
		Environment::set_instance( $env );
		$config = Configuration::load();
		$this->assertSame( Constants::CACHE_DEFAULT_DRIVER, $config[ Constants::CACHE_DRIVER ] );

		// 2. config.php overrides default.
		file_put_contents(
			$this->temp_dir . '/config.php',
			"<?php\ndefine('PEAKURL_CACHE_DRIVER', 'file');\n"
		);
		$config = Configuration::load();
		$this->assertSame( 'file', $config[ Constants::CACHE_DRIVER ] );

		// 3. root .env overrides config.php.
		file_put_contents(
			$this->temp_dir . '/.env',
			"PEAKURL_CACHE_DRIVER=\"apcu\"\n"
		);
		$config = Configuration::load();
		$this->assertSame( 'apcu', $config[ Constants::CACHE_DRIVER ] );

		// 4. runtime .env in dev mode overrides root .env.
		$dev_env = new Environment( $this->temp_dir, true );
		Environment::set_instance( $dev_env );
		file_put_contents(
			$server_dir . '/.env',
			"PEAKURL_CACHE_DRIVER=\"redis\"\n"
		);
		$config = Configuration::load( $server_dir );
		$this->assertSame( 'redis', $config[ Constants::CACHE_DRIVER ] );

		// 5. $_SERVER overrides file-based config.
		$_SERVER['PEAKURL_CACHE_DRIVER'] = 'none';
		$config                          = Configuration::load( $server_dir );
		$this->assertSame( 'none', $config[ Constants::CACHE_DRIVER ] );

		// 6. $_ENV overrides $_SERVER.
		$_ENV['PEAKURL_CACHE_DRIVER'] = 'file';
		$config                       = Configuration::load( $server_dir );
		$this->assertSame( 'file', $config[ Constants::CACHE_DRIVER ] );

		// 7. getenv() overrides $_ENV and all lower sources.
		putenv( 'PEAKURL_CACHE_DRIVER=apcu' );
		$config = Configuration::load( $server_dir );
		$this->assertSame( 'apcu', $config[ Constants::CACHE_DRIVER ] );

		// Clean up global environment mutations.
		putenv( 'PEAKURL_CACHE_DRIVER' );
		unset( $_ENV['PEAKURL_CACHE_DRIVER'], $_SERVER['PEAKURL_CACHE_DRIVER'] );
	}

	public function test_missing_config_files_fail_safe_without_warnings(): void {
		$empty_dir = $this->temp_dir . '/empty_install';
		mkdir( $empty_dir, 0755, true );

		$env = new Environment( $empty_dir, false );
		Environment::set_instance( $env );

		$warning_triggered = false;
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warning_triggered ): bool {
				$warning_triggered = true;
				return true;
			}
		);

		try {
			$config = Configuration::load();
			$this->assertIsArray( $config );
			$this->assertFalse( $warning_triggered, 'Missing config files should not trigger PHP warnings or notices.' );
			$this->assertSame( Constants::DEFAULT_VERSION, $config[ Constants::VERSION ] );
			$this->assertSame( Constants::CACHE_DEFAULT_DRIVER, $config[ Constants::CACHE_DRIVER ] );
			$this->assertSame( Constants::CACHE_DEFAULT_ENABLED, $config[ Constants::CACHE_ENABLED ] );
		} finally {
			restore_error_handler();
		}
	}

	private function recursive_delete( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

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
				$this->recursive_delete( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
