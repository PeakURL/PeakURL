<?php
/**
 * Unit tests for GeoIP Downloader orchestration and post-activation resilience.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use MaxMind\Db\Reader;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip as GeoipService;
use PeakURL\Services\Geoip\Client;
use PeakURL\Services\Geoip\Context;
use PeakURL\Services\Geoip\Credentials;
use PeakURL\Services\Geoip\Downloader;
use PeakURL\Services\Geoip\Filesystem;
use PeakURL\Services\Geoip\R2Source;
use PeakURL\Utils\File;
use RuntimeException;

class GeoipDownloaderTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_down_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	private function create_context( ?SettingsApi $settings_api = null ): Context {
		if ( ! $settings_api ) {
			$settings_api = $this->createMock( SettingsApi::class );
		}
		$crypto = $this->createMock( Crypto::class );

		$config = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::GEOIP_DB_PATH => $this->temp_dir . '/GeoLite2-City.mmdb',
			Constants::VERSION       => '1.7.2',
		);

		return new Context( $config, $settings_api, $crypto );
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

		$packed_epoch = pack( 'N', $epoch );
		$metadata     = str_replace( "\x04\x02\x6a\xbf\xc4\x90", "\x04\x02" . $packed_epoch, $metadata );

		return str_repeat( "\x00", 6 ) . str_repeat( "\x00", 16 ) . $metadata;
	}

	public function test_download_database_selects_maxmind_when_credentials_configured(): void {
		$context = $this->create_context();
		$context->set_credentials( '123456', 'licensekey' );
		$credentials = new Credentials( $context );
		$client      = $this->createMock( Client::class );
		$filesystem  = $this->createMock( Filesystem::class );
		$r2          = $this->createMock( R2Source::class );

		// R2Source must never be touched when credentials are set
		$r2->expects( $this->never() )->method( 'sync_database' );

		// Filesystem lock is acquired for direct download
		$filesystem->expects( $this->once() )->method( 'acquire_lock' )->willReturn( fopen( 'php://memory', 'r' ) );

		$downloader = new Downloader( $context, $credentials, $client, $filesystem, $r2 );

		try {
			$downloader->download_database();
		} catch ( \Throwable $e ) {
			// Expected to fail at download/unpack phase, not at credential routing
		}
	}

	public function test_download_database_selects_r2_when_credentials_absent(): void {
		$context     = $this->create_context();
		$credentials = new Credentials( $context );
		$client      = $this->createMock( Client::class );
		$filesystem  = new Filesystem();
		$r2          = $this->createMock( R2Source::class );

		$r2->expects( $this->once() )
			->method( 'sync_database' )
			->willReturn(
				array(
					'activated'  => true,
					'source'     => 'r2',
					'status'     => 'installed',
					'buildEpoch' => 1714000000,
				)
			);

		$downloader = new Downloader( $context, $credentials, $client, $filesystem, $r2 );
		$result     = $downloader->download_database();

		$this->assertTrue( $result['activated'] );
		$this->assertSame( 'r2', $result['source'] );
	}

	public function test_download_database_r2_failure_propagates_exception(): void {
		$context     = $this->create_context();
		$credentials = new Credentials( $context );
		$client      = $this->createMock( Client::class );
		$filesystem  = new Filesystem();
		$r2          = $this->createMock( R2Source::class );

		$r2->expects( $this->once() )
			->method( 'sync_database' )
			->willThrowException( new RuntimeException( 'Simulated R2 manifest network failure' ) );

		$downloader = new Downloader( $context, $credentials, $client, $filesystem, $r2 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Simulated R2 manifest network failure' );
		$downloader->download_database();
	}

	public function test_direct_download_activates_valid_database_and_returns_clean_result(): void {
		$context = $this->create_context( null, false );
		$context->set_credentials( '123456', 'licensekey' );
		$credentials = new Credentials( $context );
		$client      = $this->createMock( Client::class );

		$candidate_db = $this->generate_valid_city_mmdb( 1714000000 );
		$db_path      = $context->get_db_path();

		// Mock Client to download archive and mock filesystem helper
		$client->method( 'download_archive' )->willReturnCallback(
			function ( $archive_path ) {
				file_put_contents( $archive_path, 'mock_archive_bytes' );
			}
		);

		$extracted_db = $this->temp_dir . '/extracted-GeoLite2-City.mmdb';
		file_put_contents( $extracted_db, $candidate_db );

		$fs_mock = $this->getMockBuilder( Filesystem::class )
			->onlyMethods( array( 'extract_archive', 'find_database_file' ) )
			->getMock();

		$fs_mock->method( 'find_database_file' )->willReturn( $extracted_db );
		$fs_mock->method( 'extract_archive' )->willReturnCallback(
			function ( $archive, $dest ) use ( $extracted_db ) {
				// Extraction succeeds
			}
		);

		$downloader = new Downloader( $context, $credentials, $client, $fs_mock );
		$result     = $downloader->download_database();

		// 1. Activation result is truthful: database was activated and installed
		$this->assertTrue( $result['activated'], 'Activation must be true.' );
		$this->assertSame( 'installed', $result['status'] );
		$this->assertSame( 1714000000, $result['buildEpoch'] );

		// 2. Active MMDB on disk is valid and has candidate build epoch
		$this->assertFileExists( $db_path );
		$reader = new Reader( $db_path );
		$this->assertSame( 1714000000, (int) $reader->metadata()->buildEpoch );
		$reader->close();
	}

	public function test_direct_download_pre_activation_failure_preserves_existing_database(): void {
		$context = $this->create_context( null, false );
		$context->set_credentials( '123456', 'licensekey' );
		$credentials = new Credentials( $context );
		$client      = $this->createMock( Client::class );

		// Seed pre-existing database
		$original_db = $this->generate_valid_city_mmdb( 1700000000 );
		file_put_contents( $context->get_db_path(), $original_db );
		$original_hash = hash_file( 'sha256', $context->get_db_path() );

		// Mock Client to fail download
		$client->method( 'download_archive' )->willThrowException(
			new RuntimeException( 'Simulated connection failure during direct download' )
		);

		$filesystem = new Filesystem();
		$downloader = new Downloader( $context, $credentials, $client, $filesystem );

		try {
			$downloader->download_database();
			$this->fail( 'Expected direct download exception.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Simulated connection failure', $e->getMessage() );
		}

		// Pre-existing database remains completely untouched and byte-identical!
		$this->assertFileExists( $context->get_db_path() );
		$this->assertSame( $original_hash, hash_file( 'sha256', $context->get_db_path() ) );
	}

	public function test_service_download_database_settings_timestamp_failure_does_not_invalidate_activation(): void {
		$settings_api = $this->createMock( SettingsApi::class );
		$settings_api->method( 'table_exists' )->willReturn( true );
		$settings_api->method( 'get_option' )->willReturn( null );

		// update_option throws a database error when saving timestamp
		$settings_api->method( 'update_option' )->willThrowException(
			new RuntimeException( 'Simulated database deadlock during timestamp write' )
		);

		$context     = $this->create_context( $settings_api );
		$credentials = new Credentials( $context );

		$valid_db = $this->generate_valid_city_mmdb( 1714000000 );
		file_put_contents( $context->get_db_path(), $valid_db );

		$downloader = $this->createMock( Downloader::class );
		$downloader->method( 'download_database' )->willReturn(
			array(
				'activated'    => true,
				'source'       => 'r2',
				'status'       => 'installed',
				'databasePath' => $context->get_db_path(),
				'buildEpoch'   => 1714000000,
			)
		);

		$config  = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::GEOIP_DB_PATH => $context->get_db_path(),
			Constants::VERSION       => '1.7.2',
		);
		$crypto  = $this->createMock( Crypto::class );
		$service = new GeoipService( $config, $settings_api, $crypto );
		$service->set_downloader( $downloader );

		// Must not throw an unhandled exception when settings write fails
		$status = $service->download_database();

		$this->assertTrue( $status['activated'], 'Activation must remain true despite settings timestamp failure.' );
		$this->assertTrue( $status['locationAnalyticsReady'], 'Location analytics must be ready.' );
		$this->assertSame( 1714000000, $status['buildEpoch'] );
	}
}
