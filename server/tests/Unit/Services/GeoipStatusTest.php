<?php
/**
 * Unit tests for GeoIP Status helper and corrupt database rejection.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip\Context;
use PeakURL\Services\Geoip\Credentials;
use PeakURL\Services\Geoip\Status;
use PeakURL\Utils\File;

class GeoipStatusTest extends TestCase {

	private string $temp_dir;
	private Context $context;
	private Credentials $credentials;
	private Status $status_helper;
	private string $db_file;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_status_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );

		$this->db_file = $this->temp_dir . '/GeoLite2-City.mmdb';

		$config = array(
			Constants::GEOIP_DB_PATH => $this->db_file,
			Constants::CONTENT_DIR   => $this->temp_dir,
		);

		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'get_option' )->willReturn( null );
		$settings_api->method( 'table_exists' )->willReturn( true );

		$crypto_service      = new \PeakURL\Services\Crypto( $config );
		$this->context       = new Context( $config, $settings_api, $crypto_service );
		$this->credentials   = new Credentials( $this->context );
		$this->status_helper = new Status( $this->context, $this->credentials );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	/**
	 * Generate a minimal valid GeoLite2-City binary database.
	 *
	 * @param int $epoch Build epoch timestamp.
	 * @return string
	 */
	private function generate_valid_city_mmdb( int $epoch = 1714000000 ): string {
		$metadata = base64_decode(
			'q83vTWF4TWluZC5jb23pW2JpbmFyeV9mb3JtYXRfbWFqb3JfdmVyc2lvbqECW2JpbmFyeV9mb3JtYXRfbWlub3JfdmVyc2lvbqBLYnVpbGRfZXBvY2gEAmq/xJBNZGF0YWJhc2VfdHlwZU1HZW9MaXRlMi1DaXR5S2Rlc2NyaXB0aW9u4UJlblZHZW9MaXRlMiBDaXR5IGRhdGFiYXNlSmlwX3ZlcnNpb26hBklsYW5ndWFnZXMIBEJkZUJlbkJlc0JmckJqYUVwdC1CUkJydUV6aC1DTkpub2RlX2NvdW50w1xEM0tyZWNvcmRfc2l6ZaEc'
		);
		$metadata = str_replace( "node_count\xc3\x5c\x44\x33", "node_count\xc1\x01", $metadata );
		$metadata = str_replace( "record_size\xa1\x1c", "record_size\xa1\x18", $metadata );

		// Pack given epoch into build_epoch integer (4 bytes big-endian)
		$packed_epoch = pack( 'N', $epoch );
		$metadata     = str_replace( "\x04\x02\x6a\xbf\xc4\x90", "\x04\x02" . $packed_epoch, $metadata );

		return str_repeat( "\x00", 6 ) . str_repeat( "\x00", 16 ) . $metadata;
	}

	public function test_get_status_reports_unready_when_database_file_missing(): void {
		$status = $this->status_helper->get_status();

		$this->assertFalse( $status['databaseExists'] );
		$this->assertFalse( $status['databaseReadable'] );
		$this->assertFalse( $status['locationAnalyticsReady'] );
		$this->assertFalse( $status['installed'] );
		$this->assertTrue( $status['isStale'] );
	}

	public function test_get_status_rejects_corrupted_database_file_for_readiness(): void {
		// Place a readable but corrupted / non-MMDB file
		file_put_contents( $this->db_file, 'corrupted binary contents not valid maxmind database' );

		$status = $this->status_helper->get_status();

		$this->assertTrue( $status['databaseExists'], 'Database file exists on disk.' );
		$this->assertTrue( $status['databaseReadable'], 'Corrupt file is readable.' );
		$this->assertFalse( $status['locationAnalyticsReady'], 'locationAnalyticsReady must be false for corrupt file.' );
		$this->assertFalse( $status['installed'], 'installed must be false for corrupt file.' );
		$this->assertFalse( $status['is_installed'] );
		$this->assertTrue( $status['isStale'], 'isStale must be true when database is invalid.' );
	}

	public function test_get_status_accepts_valid_city_database(): void {
		$now   = time();
		$valid = $this->generate_valid_city_mmdb( $now - ( 5 * 86400 ) ); // 5 days old
		file_put_contents( $this->db_file, $valid );

		$status = $this->status_helper->get_status();

		$this->assertTrue( $status['databaseExists'] );
		$this->assertTrue( $status['databaseReadable'] );
		$this->assertTrue( $status['locationAnalyticsReady'], 'Valid City database should enable location analytics.' );
		$this->assertTrue( $status['installed'] );
		$this->assertTrue( $status['is_installed'] );
		$this->assertFalse( $status['isStale'], '5-day-old database should not be stale.' );
		$this->assertSame( 5, $status['databaseAgeDays'] );
	}

	public function test_get_status_flags_stale_when_database_is_over_30_days_old(): void {
		$now   = time();
		$valid = $this->generate_valid_city_mmdb( $now - ( 40 * 86400 ) ); // 40 days old
		file_put_contents( $this->db_file, $valid );

		$status = $this->status_helper->get_status();

		$this->assertTrue( $status['locationAnalyticsReady'] );
		$this->assertTrue( $status['isStale'], '40-day-old database must be flagged as stale.' );
		$this->assertSame( 40, $status['databaseAgeDays'] );
	}
}
