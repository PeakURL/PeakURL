<?php
/**
 * Integration tests for post-install managed GeoIP bootstrap scheduling.
 *
 * @package PeakURL\Tests\Integration\Install
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Install;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Install\Initializer;
use PeakURL\Utils\Date;
use PeakURL\Utils\File;
use PDO;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class InstallBootstrapGeoipTest extends TestCase {

	private PDO $pdo;
	private array $base_config;
	private string $isolated_prefix;
	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->base_config     = Configuration::get_current();
		$this->isolated_prefix = 'pkboot_' . bin2hex( random_bytes( 4 ) ) . '_';
		$connection            = Connection::get_instance( $this->base_config );
		$this->pdo             = $connection->get_connection();

		$this->temp_dir = sys_get_temp_dir() . '/peakurl_boot_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );

		$this->drop_isolated_tables();
	}

	protected function tearDown(): void {
		$this->drop_isolated_tables();
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	private function get_isolated_config( array $overrides = array() ): array {
		return array_merge(
			$this->base_config,
			array(
				Constants::DB_PREFIX        => $this->isolated_prefix,
				Constants::OWNER_USERNAME   => 'bootadmin',
				Constants::OWNER_EMAIL      => 'bootadmin@example.com',
				Constants::OWNER_PASSWORD   => 'BootAdmin12345!',
				Constants::OWNER_FIRST_NAME => 'Boot',
				Constants::OWNER_LAST_NAME  => 'Admin',
				Constants::WORKSPACE_NAME   => 'Bootstrap Site',
				Constants::WORKSPACE_SLUG   => 'bootstrap-site',
				Constants::CONTENT_DIR      => $this->temp_dir,
				Constants::GEOIP_DB_PATH    => $this->temp_dir . '/GeoLite2-City.mmdb',
			),
			$overrides
		);
	}

	private function drop_isolated_tables(): void {
		$stmt   = $this->pdo->query( "SHOW TABLES LIKE '{$this->isolated_prefix}%'" );
		$tables = $stmt->fetchAll( PDO::FETCH_COLUMN );

		if ( ! empty( $tables ) ) {
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tables as $table ) {
				$this->pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
			}
			$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}

	public function test_geoip_bootstrap_scheduled_when_no_database(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		// Execute post-install bootstrap request.
		Initializer::request_geoip_bootstrap( $connection, $config );

		$stmt = $this->pdo->query(
			"SELECT * FROM {$this->isolated_prefix}cron_jobs WHERE id = 'peakurl_geoip_update'"
		);
		$job  = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertNotEmpty( $job, 'peakurl_geoip_update job must exist in cron_jobs table.' );
		$this->assertSame( 'peakurl_geoip_update', $job['id'] );
		$this->assertSame( 604800, (int) $job['schedule_interval'] );

		// next_run_at must be scheduled immediately (near-term / now)
		$next_run = strtotime( $job['next_run_at'] . ' UTC' );
		$now      = time();
		$this->assertLessThanOrEqual( $now + 5, $next_run );
		$this->assertGreaterThanOrEqual( $now - 60, $next_run );
	}

	public function test_geoip_bootstrap_not_scheduled_when_credentials_present(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		// Supply MaxMind credentials in settings
		$db           = new PeakURL_DB( $connection, $this->isolated_prefix );
		$settings_api = new SettingsApi( $db );
		$now          = Date::now();
		$settings_api->update_option( 'maxmind_account_id', '123456', $now, false );
		$settings_api->update_option( 'maxmind_license_key_encrypted', 'myencryptedsecretkey', $now, false );

		Initializer::request_geoip_bootstrap( $connection, $config );

		$stmt = $this->pdo->query(
			"SELECT * FROM {$this->isolated_prefix}cron_jobs WHERE id = 'peakurl_geoip_update'"
		);
		$job  = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertEmpty( $job, 'Bootstrap job must not be inserted when direct credentials exist.' );
	}

	public function test_geoip_bootstrap_not_scheduled_when_valid_database_exists(): void {
		$config       = $this->get_isolated_config();
		$runtime_root = Environment::get_instance()->get_runtime_root();

		Initializer::initialize_schema( $config, $runtime_root );

		$connection = new Connection( $config );
		Initializer::bootstrap_site( $connection, $config );

		// Seed valid database file at target path
		$metadata_raw = base64_decode(
			'q83vTWF4TWluZC5jb23pW2JpbmFyeV9mb3JtYXRfbWFqb3JfdmVyc2lvbqECW2JpbmFyeV9mb3JtYXRfbWlub3JfdmVyc2lvbqBLYnVpbGRfZXBvY2gEAmq/xJBNZGF0YWJhc2VfdHlwZU1HZW9MaXRlMi1DaXR5S2Rlc2NyaXB0aW9u4UJlblZHZW9MaXRlMiBDaXR5IGRhdGFiYXNlSmlwX3ZlcnNpb26hBklsYW5ndWFnZXMIBEJkZUJlbkJlc0JmckJqYUVwdC1CUkJydUV6aC1DTkpub2RlX2NvdW50w1xEM0tyZWNvcmRfc2l6ZaEc'
		);
		$metadata_raw = str_replace( "node_count\xc3\x5c\x44\x33", "node_count\xc1\x01", $metadata_raw );
		$metadata_raw = str_replace( "record_size\xa1\x1c", "record_size\xa1\x18", $metadata_raw );
		$valid_mmdb   = str_repeat( "\x00", 6 ) . str_repeat( "\x00", 16 ) . $metadata_raw;
		file_put_contents( $config[ Constants::GEOIP_DB_PATH ], $valid_mmdb );

		Initializer::request_geoip_bootstrap( $connection, $config );

		$stmt = $this->pdo->query(
			"SELECT * FROM {$this->isolated_prefix}cron_jobs WHERE id = 'peakurl_geoip_update'"
		);
		$job  = $stmt->fetch( PDO::FETCH_ASSOC );

		$this->assertEmpty( $job, 'Bootstrap job must not be scheduled when a valid database already exists on disk.' );
	}
}
