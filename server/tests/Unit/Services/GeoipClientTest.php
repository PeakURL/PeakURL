<?php
/**
 * Unit tests for Geoip Client stream-to-disk transport, redirect security, and credential isolation.
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
use PeakURL\Services\Geoip\Client;
use PeakURL\Services\Geoip\Context as GeoipContext;
use PeakURL\Utils\File;
use RuntimeException;

class GeoipClientTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_client_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	private function create_context( string $account_id = 'test_account_123', string $license_key = 'test_license_secret_999' ): GeoipContext {
		$settings_api = $this->createMock( SettingsApi::class );
		$crypto       = $this->createMock( Crypto::class );

		$config = array(
			Constants::CONTENT_DIR   => $this->temp_dir,
			Constants::GEOIP_DB_PATH => $this->temp_dir . '/GeoLite2-City.mmdb',
			Constants::VERSION       => '1.7.2',
		);

		$context = new GeoipContext( $config, $settings_api, $crypto );
		$context->set_credentials( $account_id, $license_key );

		return $context;
	}

	public function test_initial_url_validation_enforces_production_origin_and_tls(): void {
		$client = new Client( $this->create_context() );
		$dest   = $this->temp_dir . '/out.tar.gz';

		// 1. Insecure HTTP rejected.
		try {
			$client->download_archive( $dest, 'http://download.maxmind.com/test.tar.gz' );
			$this->fail( 'Expected HTTP rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'require an encrypted HTTPS connection', $e->getMessage() );
		}

		// 2. Untrusted external domain rejected.
		try {
			$client->download_archive( $dest, 'https://attacker-controlled-site.com/evil.tar.gz' );
			$this->fail( 'Expected untrusted origin rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Untrusted download origin rejected: attacker-controlled-site.com', $e->getMessage() );
		}

		// 3. Embedded userinfo in URL rejected.
		try {
			$client->download_archive( $dest, 'https://user:pass@download.maxmind.com/test.tar.gz' );
			$this->fail( 'Expected embedded credentials rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'containing embedded credentials are forbidden', $e->getMessage() );
		}

		// 4. Non-standard port rejected.
		try {
			$client->download_archive( $dest, 'https://download.maxmind.com:8443/test.tar.gz' );
			$this->fail( 'Expected non-standard port rejection.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'require standard HTTPS port 443', $e->getMessage() );
		}
	}

	public function test_set_test_origin_rejects_non_loopback_endpoints(): void {
		$client = new Client( $this->create_context() );

		// Non-loopback public host must be rejected.
		try {
			$client->set_test_origin( 'https://remote-server.test:8000' );
			$this->fail( 'Expected non-loopback exception.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Test origin must be a loopback', $e->getMessage() );
		}

		// Loopback endpoint is accepted.
		$client->set_test_origin( 'http://127.0.0.1:54321' );
		$this->addToAssertionCount( 1 );
	}

	public function test_is_allowlisted_redirect_host(): void {
		$client = new Client( $this->create_context() );

		// 1. Direct MaxMind endpoints.
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'download.maxmind.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'updates.maxmind.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'cdn.maxmind.com' ) );

		// 2. MaxMind AWS S3 distribution endpoints.
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'geoip.maxmind.com.s3.amazonaws.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'geoip.maxmind.com.s3.us-east-1.amazonaws.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'mm-prod-geoip-databases.s3.amazonaws.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'mm-prod-geoip-databases.s3.us-west-2.amazonaws.com' ) );

		// 3. MaxMind Cloudflare R2 distribution endpoints.
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com' ) );
		$this->assertTrue( $client->is_allowlisted_redirect_host( 'mm-prod-geoip-databases.r2.cloudflarestorage.com' ) );

		// 4. Untrusted origins must be rejected.
		$this->assertFalse( $client->is_allowlisted_redirect_host( 'untrusted-bucket.r2.cloudflarestorage.com' ) );
		$this->assertFalse( $client->is_allowlisted_redirect_host( 'untrusted-s3-bucket.s3.amazonaws.com' ) );
		$this->assertFalse( $client->is_allowlisted_redirect_host( 'mm-prod-geoip-databases.attacker.com' ) );
		$this->assertFalse( $client->is_allowlisted_redirect_host( 'notmaxmind.com' ) );
		$this->assertFalse( $client->is_allowlisted_redirect_host( 'maxmind.com.attacker.com' ) );
	}

	public function test_redirect_handling_credential_isolation_and_transports(): void {
		$port        = 58200 + random_int( 1, 900 );
		$server_file = $this->temp_dir . '/server.php';
		$log_dir     = $this->temp_dir . '/logs';
		mkdir( $log_dir, 0755, true );

		$server_code = '<?php
$uri = $_SERVER["REQUEST_URI"] ?? "";
$headers = getallheaders();
$log_entry = json_encode([
    "uri" => $uri,
    "auth" => $headers["Authorization"] ?? $headers["authorization"] ?? null,
]);

if ( "/initial-auth" === $uri ) {
    file_put_contents("' . $log_dir . '/initial.log", $log_entry . "\n", FILE_APPEND);
    header( "Location: /cdn-download" );
    http_response_code( 302 );
    exit;
}
if ( "/cdn-download" === $uri ) {
    file_put_contents("' . $log_dir . '/cdn.log", $log_entry . "\n", FILE_APPEND);
    http_response_code( 200 );
    echo str_repeat( "VALID_ARCHIVE_DATA_CHUNK_TEST_", 100 );
    exit;
}
if ( "/401" === $uri ) {
    http_response_code( 401 );
    echo "Unauthorized credentials";
    exit;
}
if ( "/429" === $uri ) {
    http_response_code( 429 );
    echo "Rate limit exceeded";
    exit;
}
if ( "/503" === $uri ) {
    http_response_code( 503 );
    echo "Temporary outage";
    exit;
}
if ( "/empty" === $uri ) {
    http_response_code( 200 );
    exit;
}
if ( "/redirect-untrusted" === $uri ) {
    header( "Location: https://untrusted-s3-bucket.s3.amazonaws.com/test.tar.gz" );
    http_response_code( 302 );
    exit;
}
if ( "/redirect-insecure" === $uri ) {
    header( "Location: http://download.maxmind.com/unencrypted.tar.gz" );
    http_response_code( 302 );
    exit;
}
if ( "/redirect-loop" === $uri ) {
    header( "Location: /redirect-loop" );
    http_response_code( 302 );
    exit;
}
if ( "/truncated" === $uri ) {
    header( "Content-Length: 10000" );
    http_response_code( 200 );
    echo "TRUNCATED_BODY_ONLY_A_FEW_BYTES";
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
			$context = $this->create_context( 'test_acc_42', 'test_sec_token_99' );
			$client  = new Client( $context );
			$base    = 'http://127.0.0.1:' . $port;
			$client->set_test_origin( $base );

			// 1. cURL: Download with Redirect and Credential Isolation Verification.
			$target_curl = $this->temp_dir . '/download_curl.tar.gz';
			$client->download_archive( $target_curl, $base . '/initial-auth' );

			$this->assertFileExists( $target_curl );
			$this->assertSame( 3000, filesize( $target_curl ) );

			// Verify that initial request sent credentials, while redirected CDN request DID NOT.
			$initial_log = json_decode( trim( (string) file_get_contents( $log_dir . '/initial.log' ) ), true );
			$cdn_log     = json_decode( trim( (string) file_get_contents( $log_dir . '/cdn.log' ) ), true );

			$this->assertNotNull( $initial_log['auth'], 'Initial request to auth host must include Authorization header.' );
			$this->assertNull( $cdn_log['auth'], 'Redirected CDN download must NEVER receive forwarded Authorization credentials.' );

			// Ensure no partial download files (.part-*) remain in destination directory.
			$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );

			// 2. Stream Wrapper: Download with Redirect and Credential Isolation Verification.
			unlink( $log_dir . '/initial.log' );
			unlink( $log_dir . '/cdn.log' );

			$target_stream = $this->temp_dir . '/download_stream.tar.gz';
			$transport     = new \PeakURL\Services\Geoip\Transport( $context );
			$transport->set_test_origin( $base );
			$transport->download_to_file(
				$base . '/initial-auth',
				$target_stream,
				array(
					'driver'        => 'stream',
					'max_bytes'     => Client::MAX_ARCHIVE_BYTES,
					'timeout'       => Client::TIMEOUT_SECONDS,
					'max_redirects' => Client::MAX_REDIRECTS,
					'headers'       => $client->build_headers( $base . '/initial-auth' ),
					'validate_url'  => array( $client, 'validate_request_url' ),
					'on_redirect'   => function ( string $url ) use ( $client ): array {
						return $client->build_headers( $url );
					},
				)
			);

			$this->assertFileExists( $target_stream );
			$this->assertSame( 3000, filesize( $target_stream ) );

			$initial_log_stream = json_decode( trim( (string) file_get_contents( $log_dir . '/initial.log' ) ), true );
			$cdn_log_stream     = json_decode( trim( (string) file_get_contents( $log_dir . '/cdn.log' ) ), true );

			$this->assertNotNull( $initial_log_stream['auth'] );
			$this->assertNull( $cdn_log_stream['auth'], 'Stream wrapper must also isolate credentials on redirect.' );

			// 3. Untrusted Redirect Target Rejected by Both Transports.
			try {
				$client->download_archive( $this->temp_dir . '/untrusted_curl.tar.gz', $base . '/redirect-untrusted' );
				$this->fail( 'Expected untrusted redirect exception in cURL.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Untrusted redirect destination rejected: untrusted-s3-bucket.s3.amazonaws.com', $e->getMessage() );
			}

			try {
				$transport->download_to_file(
					$base . '/redirect-untrusted',
					$this->temp_dir . '/untrusted_stream.tar.gz',
					array(
						'driver'        => 'stream',
						'max_redirects' => Client::MAX_REDIRECTS,
						'validate_url'  => array( $client, 'validate_request_url' ),
					)
				);
				$this->fail( 'Expected untrusted redirect exception in stream wrapper.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Untrusted redirect destination rejected: untrusted-s3-bucket.s3.amazonaws.com', $e->getMessage() );
			}

			// 4. Insecure Redirect Target Rejected by Both Transports.
			try {
				$client->download_archive( $this->temp_dir . '/insecure_curl.tar.gz', $base . '/redirect-insecure' );
				$this->fail( 'Expected insecure redirect exception in cURL.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'require an encrypted HTTPS connection', $e->getMessage() );
			}

			try {
				$transport->download_to_file(
					$base . '/redirect-insecure',
					$this->temp_dir . '/insecure_stream.tar.gz',
					array(
						'driver'        => 'stream',
						'max_redirects' => Client::MAX_REDIRECTS,
						'validate_url'  => array( $client, 'validate_request_url' ),
					)
				);
				$this->fail( 'Expected insecure redirect exception in stream wrapper.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'require an encrypted HTTPS connection', $e->getMessage() );
			}

			// 5. Redirect Loop Aborts at MAX_REDIRECTS.
			try {
				$client->download_archive( $this->temp_dir . '/loop_curl.tar.gz', $base . '/redirect-loop' );
				$this->fail( 'Expected redirect loop exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Too many redirects encountered', $e->getMessage() );
			}

			// 6. Truncated Body (Content-Length Mismatch) Rejection.
			$dest_truncated = $this->temp_dir . '/truncated_target.tar.gz';
			try {
				$client->download_archive( $dest_truncated, $base . '/truncated' );
				$this->fail( 'Expected truncated body exception in cURL.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Incomplete GeoLite2 download', $e->getMessage() );
			}
			$this->assertFileDoesNotExist( $dest_truncated, 'Destination file must not be created on failed transfer.' );
			$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ), 'Part file must be cleaned up on failure.' );

			try {
				$transport->download_to_file(
					$base . '/truncated',
					$dest_truncated,
					array(
						'driver'       => 'stream',
						'validate_url' => array( $client, 'validate_request_url' ),
					)
				);
				$this->fail( 'Expected truncated body exception in stream wrapper.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Incomplete GeoLite2 download', $e->getMessage() );
			}
			$this->assertFileDoesNotExist( $dest_truncated );
			$this->assertEmpty( glob( $this->temp_dir . '/*.part*' ) );

			// 7. Empty Body Rejection.
			try {
				$client->download_archive( $this->temp_dir . '/empty.tar.gz', $base . '/empty' );
				$this->fail( 'Expected empty archive exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'downloaded GeoLite2 archive is empty', $e->getMessage() );
			}

			// 8. Classified Error Codes and Credential Sanitization.
			try {
				$client->download_archive( $this->temp_dir . '/401.tar.gz', $base . '/401' );
				$this->fail( 'Expected 401 exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'MaxMind rejected the download request', $e->getMessage() );
				$this->assertStringNotContainsString( 'test_sec_token_99', $e->getMessage() );
			}

			try {
				$client->download_archive( $this->temp_dir . '/429.tar.gz', $base . '/429' );
				$this->fail( 'Expected 429 exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'MaxMind download rate limit exceeded', $e->getMessage() );
			}

			try {
				$client->download_archive( $this->temp_dir . '/503.tar.gz', $base . '/503' );
				$this->fail( 'Expected 503 exception.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'MaxMind download service temporarily unavailable', $e->getMessage() );
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
