<?php
/**
 * Unit tests for GeoIP R2Source distribution, verification, and race recovery.
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
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip\Context as GeoipContext;
use PeakURL\Services\Geoip\Filesystem;
use PeakURL\Services\Geoip\Lookup;
use PeakURL\Services\Geoip\R2Source;
use PeakURL\Utils\Date;
use PeakURL\Utils\File;
use RuntimeException;

class GeoipR2SourceTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_ms_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	private function create_context( ?SettingsApi $settings_api = null ): GeoipContext {
		if ( ! $settings_api ) {
			$settings_api = $this->createMock( SettingsApi::class );
		}
		$crypto = $this->createMock( Crypto::class );

		$config = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::GEOIP_DB_PATH => $this->temp_dir . '/GeoLite2-City.mmdb',
			Constants::VERSION       => '1.7.2',
		);

		return new GeoipContext( $config, $settings_api, $crypto );
	}

	/**
	 * Generate a deterministic, valid GeoLite2-City MMDB binary with a real searchable IP record.
	 *
	 * Any IPv4 (such as 8.8.8.8) resolves to:
	 * country: { iso_code: "US", names: { en: "United States" } }
	 * city: { names: { en: "Mountain View" } }
	 *
	 * @param int $build_epoch Build epoch timestamp.
	 * @return string Binary MMDB content.
	 */
	private function generate_synthetic_city_mmdb( int $build_epoch = 1790952592 ): string {
		$metadata_raw = base64_decode(
			'q83vTWF4TWluZC5jb23pW2JpbmFyeV9mb3JtYXRfbWFqb3JfdmVyc2lvbqECW2JpbmFyeV9mb3JtYXRfbWlub3JfdmVyc2lvbqBLYnVpbGRfZXBvY2gEAmq/xJBNZGF0YWJhc2VfdHlwZU1HZW9MaXRlMi1DaXR5S2Rlc2NyaXB0aW9u4UJlblZHZW9MaXRlMiBDaXR5IGRhdGFiYXNlSmlwX3ZlcnNpb26hBklsYW5ndWFnZXMIBEJkZUJlbkJlc0JmckJqYUVwdC1CUkJydUV6aC1DTkpub2RlX2NvdW50w1xEM0tyZWNvcmRfc2l6ZaEc'
		);
		$metadata_raw = str_replace( "node_count\xc3\x5c\x44\x33", "node_count\xc1\x01", $metadata_raw );
		$metadata_raw = str_replace( "record_size\xa1\x1c", "record_size\xa1\x18", $metadata_raw );

		// Pack the custom build epoch into metadata if different from default 1790952592 (0x6acb3f10).
		$default_epoch_bytes = "\x04\x02\x6a\xcb\x3f\x10";
		$custom_epoch_bytes  = "\x04\x02" . pack( 'N', $build_epoch );
		$metadata_raw        = str_replace( $default_epoch_bytes, $custom_epoch_bytes, $metadata_raw );

		$city_val     = "\xe1\x45names\xe1\x42en\x4dMountain View";
		$country_val  = "\xe2\x48iso_code\x42US\x45names\xe1\x42en\x4dUnited States";
		$data_section = "\xe2\x47country" . $country_val . "\x44city" . $city_val;
		$tree         = "\x00\x00\x11\x00\x00\x11";

		return $tree . str_repeat( "\x00", 16 ) . $data_section . $metadata_raw;
	}

	public function test_validate_manifest_payload_enforces_strict_schema(): void {
		$context = $this->create_context();
		$fs      = new Filesystem();
		$ms      = new R2Source( $context, $fs );

		// 1. Empty payload
		try {
			$ms->validate_manifest_payload( '' );
			$this->fail( 'Expected empty payload rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Received empty GeoIP release manifest', $e->getMessage() );
		}

		// 2. Corrupt JSON
		try {
			$ms->validate_manifest_payload( '{ invalid json ...' );
			$this->fail( 'Expected invalid JSON rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'contains invalid JSON', $e->getMessage() );
		}

		// 3. Non-object JSON
		try {
			$ms->validate_manifest_payload( '[1, 2, 3]' );
			$this->fail( 'Expected JSON object check.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Unsupported GeoIP manifest schema version', $e->getMessage() );
		}

		$valid = array(
			'schemaVersion'  => 1,
			'edition'        => 'GeoLite2-City',
			'buildEpoch'     => 1790952592,
			'downloadUrl'    => 'https://releases.peakurl.org/geoip/GeoLite2-City.mmdb.gz',
			'checksumSha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			'sizeBytes'      => 45000000,
		);

		// 4. Unsupported schema version
		$bad_schema = array_merge( $valid, array( 'schemaVersion' => 2 ) );
		try {
			$ms->validate_manifest_payload( json_encode( $bad_schema ) );
			$this->fail( 'Expected unsupported schema rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Unsupported GeoIP manifest schema version', $e->getMessage() );
		}

		// 5. Wrong edition
		$bad_edition = array_merge( $valid, array( 'edition' => 'GeoLite2-Country' ) );
		try {
			$ms->validate_manifest_payload( json_encode( $bad_edition ) );
			$this->fail( 'Expected edition rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Unexpected GeoIP database edition', $e->getMessage() );
		}

		// 6. Non-positive buildEpoch
		$bad_epoch = array_merge( $valid, array( 'buildEpoch' => 0 ) );
		try {
			$ms->validate_manifest_payload( json_encode( $bad_epoch ) );
			$this->fail( 'Expected buildEpoch rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid or non-positive build epoch', $e->getMessage() );
		}

		// 7. Non-positive or out-of-bounds sizeBytes
		$bad_size = array_merge( $valid, array( 'sizeBytes' => 0 ) );
		try {
			$ms->validate_manifest_payload( json_encode( $bad_size ) );
			$this->fail( 'Expected sizeBytes rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid or out-of-bounds archive size', $e->getMessage() );
		}

		// 8. Malformed SHA-256
		$bad_sha = array_merge( $valid, array( 'checksumSha256' => 'NOT_A_VALID_LOWERCASE_HEX_HASH' ) );
		try {
			$ms->validate_manifest_payload( json_encode( $bad_sha ) );
			$this->fail( 'Expected SHA format rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid SHA-256 checksum format', $e->getMessage() );
		}

		// 9. Valid payload passes cleanly
		$result = $ms->validate_manifest_payload( json_encode( $valid ) );
		$this->assertSame( 1, $result['schemaVersion'] );
		$this->assertSame( 'GeoLite2-City', $result['edition'] );
		$this->assertSame( 1790952592, $result['buildEpoch'] );
	}

	public function test_url_validation_enforces_canonical_origin_and_endpoints(): void {
		$context = $this->create_context();
		$fs      = new Filesystem();
		$ms      = new R2Source( $context, $fs );

		// 1. Insecure HTTP rejected
		try {
			$ms->validate_manifest_url( 'http://releases.peakurl.org/geoip/manifest.json' );
			$this->fail( 'Expected HTTP rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'require an encrypted HTTPS connection', $e->getMessage() );
		}

		// 2. Untrusted domain rejected
		try {
			$ms->validate_manifest_url( 'https://untrusted-domain.com/geoip/manifest.json' );
			$this->fail( 'Expected untrusted domain rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Untrusted download origin rejected', $e->getMessage() );
		}

		// 3. Non-443 port rejected
		try {
			$ms->validate_manifest_url( 'https://releases.peakurl.org:8443/geoip/manifest.json' );
			$this->fail( 'Expected port rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'require standard HTTPS port 443', $e->getMessage() );
		}

		// 4. Userinfo in URL rejected
		try {
			$ms->validate_manifest_url( 'https://user:pass@releases.peakurl.org/geoip/manifest.json' );
			$this->fail( 'Expected credentials in URL rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'URLs containing credentials are forbidden', $e->getMessage() );
		}

		// 5. Unexpected endpoint path rejected
		try {
			$ms->validate_manifest_url( 'https://releases.peakurl.org/geoip/other-file.json' );
			$this->fail( 'Expected path rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Unexpected endpoint path', $e->getMessage() );
		}

		// 6. Canonical URLs accepted
		$this->assertSame(
			'https://releases.peakurl.org/geoip/manifest.json',
			$ms->validate_manifest_url( 'https://releases.peakurl.org/geoip/manifest.json' )
		);
		$this->assertSame(
			'https://releases.peakurl.org/geoip/GeoLite2-City.mmdb.gz',
			$ms->validate_archive_url( 'https://releases.peakurl.org/geoip/GeoLite2-City.mmdb.gz' )
		);
	}

	public function test_full_r2_download_streaming_decompression_and_lookup(): void {
		$port        = 58400 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/r2_server.php';
		$mmdb_data   = $this->generate_synthetic_city_mmdb( 1790952592 );
		$gz_data     = gzencode( $mmdb_data, 9 );

		$manifest_payload = array(
			'schemaVersion'  => 1,
			'edition'        => 'GeoLite2-City',
			'buildEpoch'     => 1790952592,
			'downloadUrl'    => 'http://127.0.0.1:' . $port . '/geoip/GeoLite2-City.mmdb.gz',
			'checksumSha256' => hash( 'sha256', $gz_data ),
			'sizeBytes'      => strlen( $gz_data ),
		);

		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
if ( "/geoip/manifest.json" === $uri ) {
    header( "Content-Type: application/json" );
    echo ' . var_export( json_encode( $manifest_payload ), true ) . ';
    exit;
}
if ( "/geoip/GeoLite2-City.mmdb.gz" === $uri ) {
    header( "Content-Type: application/gzip" );
    echo base64_decode("' . base64_encode( $gz_data ) . '");
    exit;
}
';
		file_put_contents( $server_file, $server_code );

		$cmd         = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $server_file );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc        = proc_open( $cmd, $descriptors, $pipes );

		$bound = false;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing test server socket.
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				$bound = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $bound ) {
			$this->markTestSkipped( 'Could not start built-in PHP test server on port ' . $port );
		}

		try {
			$settings_api = $this->createMock( SettingsApi::class );
			$settings_api->expects( $this->atLeastOnce() )
				->method( 'update_option' )
				->with( 'geoip_last_checked_at', $this->anything(), $this->anything(), false );

			$context = $this->create_context( $settings_api );
			$fs      = new Filesystem();
			$ms      = new R2Source( $context, $fs );

			$base = 'http://127.0.0.1:' . $port;
			$ms->set_test_origin( $base );

			$lock_handle = $fs->acquire_lock( $context->get_db_path() );
			$this->assertIsResource( $lock_handle );

			// 1. Initial sync installs new database.
			$result = $ms->sync_database(
				$lock_handle,
				false,
				$base . '/geoip/manifest.json',
				$base . '/geoip/GeoLite2-City.mmdb.gz'
			);

			$this->assertTrue( $result['activated'] );
			$this->assertSame( 'installed', $result['status'] );
			$this->assertSame( 1790952592, $result['buildEpoch'] );
			$this->assertFileExists( $context->get_db_path() );

			// 2. Verify actual MaxMind record lookup on the newly activated database.
			$lookup = new Lookup( $context );
			$geo    = $lookup->lookup_location( '8.8.8.8' );
			$this->assertSame( 'US', $geo['country_code'] );
			$this->assertSame( 'United States', $geo['country_name'] );
			$this->assertSame( 'Mountain View', $geo['city_name'] );

			// 4. Verify private and loopback IPs return nulls without errors.
			$private_geo = $lookup->lookup_location( '127.0.0.1' );
			$this->assertNull( $private_geo['country_code'] );
			$this->assertNull( $private_geo['country_name'] );
			$this->assertNull( $private_geo['city_name'] );

			// 5. Subsequent sync when database is current is a no-op (activated = false).
			$noop_result = $ms->sync_database(
				$lock_handle,
				false,
				$base . '/geoip/manifest.json',
				$base . '/geoip/GeoLite2-City.mmdb.gz'
			);

			$this->assertFalse( $noop_result['activated'] );
			$this->assertSame( 'current', $noop_result['status'] );
			$this->assertSame( 1790952592, $noop_result['buildEpoch'] );

			$fs->release_lock( $lock_handle );
		} finally {
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						fclose( $p );
					}
				}
				proc_terminate( $proc );
				proc_close( $proc );
			}
		}
	}

	public function test_mutable_cdn_race_recovery_retries_and_succeeds(): void {
		$port        = 58500 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/race_server.php';
		$state_file  = $this->temp_dir . '/state.txt';
		file_put_contents( $state_file, '0' );

		$mmdb_data = $this->generate_synthetic_city_mmdb( 1790952592 );
		$gz_data   = gzencode( $mmdb_data, 9 );

		// Server serves a hash mismatch on attempt 1, and matches on attempt 2.
		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
$state_file = "' . $state_file . '";
$attempt = (int) file_get_contents($state_file);

if ( "/geoip/manifest.json" === $uri ) {
    file_put_contents($state_file, (string)($attempt + 1));
    header( "Content-Type: application/json" );
    // On attempt 1, manifest checksum matches gz_data.
    // On attempt 2, manifest checksum matches gz_data.
    $payload = [
        "schemaVersion"  => 1,
        "edition"        => "GeoLite2-City",
        "buildEpoch"     => 1790952592,
        "downloadUrl"    => "http://127.0.0.1:' . $port . '/geoip/GeoLite2-City.mmdb.gz",
        "checksumSha256" => "' . hash( 'sha256', $gz_data ) . '",
        "sizeBytes"      => ' . strlen( $gz_data ) . ',
    ];
    echo json_encode($payload);
    exit;
}
if ( "/geoip/GeoLite2-City.mmdb.gz" === $uri ) {
    header( "Content-Type: application/gzip" );
    if ( 1 === $attempt ) {
        // Return mismatched bytes on first attempt (simulating stale CDN cache).
        echo "MISMATCHED_BYTES_FROM_STALE_CDN_OBJECT";
        exit;
    }
    // Return fresh valid bytes on second attempt.
    echo base64_decode("' . base64_encode( $gz_data ) . '");
    exit;
}
';
		file_put_contents( $server_file, $server_code );

		$cmd         = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $server_file );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc        = proc_open( $cmd, $descriptors, $pipes );

		$bound = false;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing test server socket.
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				$bound = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $bound ) {
			$this->markTestSkipped( 'Could not start built-in PHP test server on port ' . $port );
		}

		try {
			$context = $this->create_context();
			$fs      = new Filesystem();
			$ms      = new R2Source( $context, $fs );

			$base = 'http://127.0.0.1:' . $port;
			$ms->set_test_origin( $base );

			$lock_handle = $fs->acquire_lock( $context->get_db_path() );

			// sync_database encounters failure on attempt 1, retries, and succeeds on attempt 2!
			$result = $ms->sync_database(
				$lock_handle,
				false,
				$base . '/geoip/manifest.json',
				$base . '/geoip/GeoLite2-City.mmdb.gz'
			);

			$this->assertTrue( $result['activated'] );
			$this->assertSame( 'installed', $result['status'] );
			$this->assertFileExists( $context->get_db_path() );

			$fs->release_lock( $lock_handle );
		} finally {
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						fclose( $p );
					}
				}
				proc_terminate( $proc );
				proc_close( $proc );
			}
		}
	}

	public function test_terminal_download_failure_preserves_existing_database_completely(): void {
		$port        = 58600 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/fail_server.php';

		// Server always serves corrupted archive bytes.
		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
if ( "/geoip/manifest.json" === $uri ) {
    header( "Content-Type: application/json" );
    $payload = [
        "schemaVersion"  => 1,
        "edition"        => "GeoLite2-City",
        "buildEpoch"     => 1790959999,
        "downloadUrl"    => "http://127.0.0.1:' . $port . '/geoip/GeoLite2-City.mmdb.gz",
        "checksumSha256" => "' . hash( 'sha256', 'CORRUPT_BYTES' ) . '",
        "sizeBytes"      => 13,
    ];
    echo json_encode($payload);
    exit;
}
if ( "/geoip/GeoLite2-City.mmdb.gz" === $uri ) {
    header( "Content-Type: application/gzip" );
    echo "CORRUPT_BYTES"; // Not a valid gzip archive
    exit;
}
';
		file_put_contents( $server_file, $server_code );

		$cmd         = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $server_file );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc        = proc_open( $cmd, $descriptors, $pipes );

		$bound = false;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing test server socket.
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				$bound = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $bound ) {
			$this->markTestSkipped( 'Could not start built-in PHP test server on port ' . $port );
		}

		try {
			$context = $this->create_context();
			$fs      = new Filesystem();
			$ms      = new R2Source( $context, $fs );

			// Seed existing valid database
			$existing_data = $this->generate_synthetic_city_mmdb( 1790950000 );
			file_put_contents( $context->get_db_path(), $existing_data );
			$original_hash = hash_file( 'sha256', $context->get_db_path() );

			$base = 'http://127.0.0.1:' . $port;
			$ms->set_test_origin( $base );

			$lock_handle = $fs->acquire_lock( $context->get_db_path() );

			// Attempt sync which will fail on decompression error across all retries.
			try {
				$ms->sync_database(
					$lock_handle,
					false,
					$base . '/geoip/manifest.json',
					$base . '/geoip/GeoLite2-City.mmdb.gz'
				);
				$this->fail( 'Expected terminal sync failure.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'GeoIP update failed', $e->getMessage() );
			}

			// Pre-existing database remains completely intact and byte-for-byte identical!
			$this->assertFileExists( $context->get_db_path() );
			$this->assertSame( $original_hash, hash_file( 'sha256', $context->get_db_path() ) );

			$fs->release_lock( $lock_handle );
		} finally {
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						fclose( $p );
					}
				}
				proc_terminate( $proc );
				proc_close( $proc );
			}
		}
	}

	public function test_successful_activation_preserves_database_integrity_and_reports_truthful_result(): void {
		$port        = 58700 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/success_server.php';

		$candidate_epoch = 1790952592;
		$candidate_mmdb  = $this->generate_synthetic_city_mmdb( $candidate_epoch );
		$candidate_gz    = gzencode( $candidate_mmdb, 9 );
		$candidate_sha   = hash( 'sha256', $candidate_gz );
		$candidate_size  = strlen( $candidate_gz );

		$gz_file = $this->temp_dir . '/test_candidate.mmdb.gz';
		file_put_contents( $gz_file, $candidate_gz );

		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
if ( "/geoip/manifest.json" === $uri ) {
    header( "Content-Type: application/json" );
    $payload = [
        "schemaVersion"  => 1,
        "edition"        => "GeoLite2-City",
        "buildEpoch"     => ' . $candidate_epoch . ',
        "downloadUrl"    => "http://127.0.0.1:' . $port . '/geoip/GeoLite2-City.mmdb.gz",
        "checksumSha256" => "' . $candidate_sha . '",
        "sizeBytes"      => ' . $candidate_size . ',
    ];
    echo json_encode($payload);
    exit;
}
if ( "/geoip/GeoLite2-City.mmdb.gz" === $uri ) {
    header( "Content-Type: application/gzip" );
    echo file_get_contents( "' . addslashes( $gz_file ) . '" );
    exit;
}
';
		file_put_contents( $server_file, $server_code );

		$cmd         = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $server_file );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc        = proc_open( $cmd, $descriptors, $pipes );

		$bound = false;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing test server socket.
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				$bound = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $bound ) {
			$this->markTestSkipped( 'Could not start built-in PHP test server on port ' . $port );
		}

		try {
			$settings_api    = $this->createMock( SettingsApi::class );
			$updated_options = array();
			$settings_api->method( 'update_option' )->willReturnCallback(
				function ( $key, $value ) use ( &$updated_options ) {
					$updated_options[ $key ] = $value;
					return true;
				}
			);

			$context = $this->create_context( $settings_api );
			$fs      = new Filesystem();
			$ms      = new R2Source( $context, $fs );

			$base = 'http://127.0.0.1:' . $port;
			$ms->set_test_origin( $base );

			$lock_handle = $fs->acquire_lock( $context->get_db_path() );

			// Sync candidate database
			$result = $ms->sync_database(
				$lock_handle,
				false,
				$base . '/geoip/manifest.json',
				$base . '/geoip/GeoLite2-City.mmdb.gz'
			);

			$fs->release_lock( $lock_handle );

			// 1. Activation result is truthful: database was activated and installed
			$this->assertTrue( $result['activated'], 'Activation must be reported as true.' );
			$this->assertSame( 'installed', $result['status'], 'Status must be reported as installed.' );
			$this->assertSame( $candidate_epoch, $result['buildEpoch'] );

			// 2. Candidate database is active on disk and structurally valid
			$this->assertFileExists( $context->get_db_path() );
			$reader = new Reader( $context->get_db_path() );
			$this->assertSame( $candidate_epoch, (int) $reader->metadata()->buildEpoch );
			$record = $reader->get( '8.8.8.8' );
			$this->assertSame( 'US', $record['country']['iso_code'] );
			$reader->close();

			// 3. Last checked timestamp was updated
			$this->assertArrayHasKey( 'geoip_last_checked_at', $updated_options );
		} finally {
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						fclose( $p );
					}
				}
				proc_terminate( $proc );
				proc_close( $proc );
			}
		}
	}

	/**
	 * Test that a publication-window race condition (new manifest served with stale/unpurged archive)
	 * fails safely on checksum verification without corrupting or deleting the active on-disk database.
	 */
	public function test_publication_window_manifest_archive_mismatch_preserves_existing_database(): void {
		$port        = 58800 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/mismatch_server.php';

		// Stale archive (version A).
		$stale_mmdb = $this->generate_synthetic_city_mmdb( 1790950000 );
		$stale_gz   = gzencode( $stale_mmdb, 9 );

		// New manifest (version B), advertising higher epoch and different checksum.
		$new_epoch = 1790999999;
		$new_sha   = hash( 'sha256', 'SIMULATED_VERSION_B_COMPRESSED_BYTES' );

		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
if ( "/geoip/manifest.json" === $uri ) {
    header( "Content-Type: application/json" );
    $payload = [
        "schemaVersion"  => 1,
        "edition"        => "GeoLite2-City",
        "buildEpoch"     => ' . $new_epoch . ',
        "downloadUrl"    => "http://127.0.0.1:' . $port . '/geoip/GeoLite2-City.mmdb.gz",
        "checksumSha256" => "' . $new_sha . '",
        "sizeBytes"      => ' . strlen( $stale_gz ) . ',
    ];
    echo json_encode( $payload );
    exit;
}
if ( "/geoip/GeoLite2-City.mmdb.gz" === $uri ) {
    header( "Content-Type: application/gzip" );
    echo base64_decode("' . base64_encode( $stale_gz ) . '");
    exit;
}
';
		file_put_contents( $server_file, $server_code );

		$cmd         = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $server_file );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc        = proc_open( $cmd, $descriptors, $pipes );

		$bound = false;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing test server socket.
			$conn = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.05 );
			if ( is_resource( $conn ) ) {
				fclose( $conn );
				$bound = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $bound ) {
			$this->markTestSkipped( 'Could not start built-in PHP test server on port ' . $port );
		}

		try {
			$context = $this->create_context();
			$fs      = new Filesystem();
			$ms      = new R2Source( $context, $fs );

			// Seed existing valid database on disk
			$existing_data = $this->generate_synthetic_city_mmdb( 1790950000 );
			file_put_contents( $context->get_db_path(), $existing_data );
			$original_hash = hash_file( 'sha256', $context->get_db_path() );

			$base = 'http://127.0.0.1:' . $port;
			$ms->set_test_origin( $base );

			$lock_handle = $fs->acquire_lock( $context->get_db_path() );

			// Sync attempts download, encounters checksum mismatch on every attempt, fails safely.
			try {
				$ms->sync_database(
					$lock_handle,
					false,
					$base . '/geoip/manifest.json',
					$base . '/geoip/GeoLite2-City.mmdb.gz'
				);
				$this->fail( 'Expected terminal sync failure due to checksum mismatch.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'GeoIP update failed', $e->getMessage() );
			}

			// Pre-existing database remains completely intact and byte-for-byte identical!
			$this->assertFileExists( $context->get_db_path() );
			$this->assertSame( $original_hash, hash_file( 'sha256', $context->get_db_path() ) );

			$fs->release_lock( $lock_handle );
		} finally {
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						fclose( $p );
					}
				}
				proc_terminate( $proc );
				proc_close( $proc );
			}
		}
	}
}
