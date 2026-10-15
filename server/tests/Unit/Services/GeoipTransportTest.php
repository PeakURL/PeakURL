<?php
/**
 * Unit tests for GeoIP Transport HTTP operations, streaming, and safety bounds.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip\Context as GeoipContext;
use PeakURL\Services\Geoip\Transport;
use PeakURL\Utils\File;
use RuntimeException;

class GeoipTransportTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_trans_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	private function create_context(): GeoipContext {
		$settings_api = $this->createMock( SettingsApi::class );
		$crypto       = $this->createMock( Crypto::class );

		$config = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::GEOIP_DB_PATH => $this->temp_dir . '/GeoLite2-City.mmdb',
			Constants::VERSION       => '1.7.2',
		);

		return new GeoipContext( $config, $settings_api, $crypto );
	}

	public function test_set_test_origin_enforces_loopback(): void {
		$transport = new Transport( $this->create_context() );

		// Valid loopback endpoints
		$transport->set_test_origin( 'http://127.0.0.1:8080' );
		$this->assertSame( 'http://127.0.0.1:8080', $transport->get_test_origin() );

		$transport->set_test_origin( 'https://localhost:9000' );
		$this->assertSame( 'https://localhost:9000', $transport->get_test_origin() );

		$transport->set_test_origin( null );
		$this->assertNull( $transport->get_test_origin() );

		// Invalid endpoints
		try {
			$transport->set_test_origin( 'http://example.com' );
			$this->fail( 'Expected exception for non-loopback host.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Test origin must be a loopback', $e->getMessage() );
		}

		try {
			$transport->set_test_origin( 'ftp://127.0.0.1' );
			$this->fail( 'Expected exception for non-http scheme.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Test origin must be a loopback', $e->getMessage() );
		}
	}

	public function test_sanitize_error_message_redacts_credentials(): void {
		$context = $this->create_context();
		$context->set_credentials( 'my_test_account_id', 'supersecretlicensekey123' );
		$client = new \PeakURL\Services\Geoip\Client( $context );

		$raw       = 'Failed to connect: account my_test_account_id with key supersecretlicensekey123 encountered an error.';
		$sanitized = $client->sanitize_error_message( $raw );

		$this->assertStringNotContainsString( 'supersecretlicensekey123', $sanitized );
		$this->assertStringNotContainsString( 'my_test_account_id', $sanitized );
		$this->assertStringContainsString( '••••••••', $sanitized );
	}

	public function test_fetch_string_and_download_to_file_with_mock_server(): void {
		$port        = 58300 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/transport_server.php';

		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
if ( "/json" === $uri ) {
    header( "Content-Type: application/json" );
    echo json_encode(["status" => "ok", "message" => "test_data"]);
    exit;
}
if ( "/large" === $uri ) {
    header( "Content-Type: text/plain" );
    echo str_repeat("A", 10000);
    exit;
}
if ( "/truncated" === $uri ) {
    header( "Content-Length: 10000" );
    echo "ONLY_A_FEW_BYTES";
    exit;
}
if ( "/stalled" === $uri ) {
    // Send a few bytes then exit or sleep
    header( "Content-Type: application/octet-stream" );
    echo "START";
    flush();
    usleep(2500000); // 2.5s stall
    echo "END";
    exit;
}
if ( "/file-ok" === $uri ) {
    header( "Content-Type: application/octet-stream" );
    echo str_repeat("STREAM_PAYLOAD_CHUNK_", 50);
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
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing socket readiness.
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
			$transport = new Transport( $this->create_context() );
			$base      = 'http://127.0.0.1:' . $port;
			$transport->set_test_origin( $base );

			// 1. fetch_string successful
			$json_resp = $transport->fetch_string( $base . '/json' );
			$this->assertJson( $json_resp );
			$this->assertStringContainsString( 'test_data', $json_resp );

			// 2. fetch_string size limit exceeded
			try {
				$transport->fetch_string( $base . '/large', array( 'max_bytes' => 500 ) );
				$this->fail( 'Expected size limit exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'exceeded maximum allowed size', $e->getMessage() );
			}

			// 3. download_to_file with cURL driver successful
			$dest_curl = $this->temp_dir . '/download_curl.bin';
			$bytes     = $transport->download_to_file(
				$base . '/file-ok',
				$dest_curl,
				array( 'driver' => 'curl' )
			);
			$this->assertSame( 1050, $bytes );
			$this->assertFileExists( $dest_curl );
			$this->assertSame( 1050, filesize( $dest_curl ) );
			$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );

			// 4. download_to_file with stream driver successful
			$dest_stream  = $this->temp_dir . '/download_stream.bin';
			$bytes_stream = $transport->download_to_file(
				$base . '/file-ok',
				$dest_stream,
				array( 'driver' => 'stream' )
			);
			$this->assertSame( 1050, $bytes_stream );
			$this->assertFileExists( $dest_stream );
			$this->assertSame( 1050, filesize( $dest_stream ) );
			$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );

			// 5. Truncated Content-Length detection in cURL
			$dest_trunc_curl = $this->temp_dir . '/trunc_curl.bin';
			try {
				$transport->download_to_file(
					$base . '/truncated',
					$dest_trunc_curl,
					array( 'driver' => 'curl' )
				);
				$this->fail( 'Expected truncation exception in cURL.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Incomplete GeoLite2 download', $e->getMessage() );
				$this->assertFileDoesNotExist( $dest_trunc_curl );
				$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );
			}

			// 6. Truncated Content-Length detection in stream wrapper
			$dest_trunc_stream = $this->temp_dir . '/trunc_stream.bin';
			try {
				$transport->download_to_file(
					$base . '/truncated',
					$dest_trunc_stream,
					array( 'driver' => 'stream' )
				);
				$this->fail( 'Expected truncation exception in stream.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Incomplete GeoLite2 download', $e->getMessage() );
				$this->assertFileDoesNotExist( $dest_trunc_stream );
				$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );
			}

			// 7. Stream stall / deadline timeout handling
			$dest_stall = $this->temp_dir . '/stalled.bin';
			try {
				$transport->download_to_file(
					$base . '/stalled',
					$dest_stall,
					array(
						'driver'  => 'stream',
						'timeout' => 1, // 1 second timeout while server sleeps 2.5s
					)
				);
				$this->fail( 'Expected timeout exception in stream.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'timed out', $e->getMessage() );
				$this->assertFileDoesNotExist( $dest_stall );
				$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );
			}

			// 8. Size limit boundary enforcement during streaming
			$dest_limit = $this->temp_dir . '/oversize.bin';
			try {
				$transport->download_to_file(
					$base . '/file-ok',
					$dest_limit,
					array(
						'driver'    => 'curl',
						'max_bytes' => 200, // 200 bytes max while response is 1050 bytes
					)
				);
				$this->fail( 'Expected max bytes exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'exceeded the maximum allowed size', $e->getMessage() );
				$this->assertFileDoesNotExist( $dest_limit );
				$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );
			}
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
