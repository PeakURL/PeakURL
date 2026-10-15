<?php
/**
 * Offline unit and contract tests for GeoIP R2 Publisher workflow and scripts.
 *
 * Runs completely offline without real MaxMind credentials, R2 access, Cloudflare
 * tokens, external network, or legal authorization. Uses synthetic fixtures and mocks.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use MaxMind\Db\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Constants;
use PeakURL\Scripts\Geoip\GeoipPreparer;
use PeakURL\Services\Geoip\R2Source;
use PeakURL\Tests\Support\GeoipTestFixture;
use PeakURL\Utils\File;
use RuntimeException;

require_once dirname( __DIR__, 4 ) . '/scripts/geoip/prepare-geoip.php';
require_once dirname( __DIR__, 2 ) . '/Support/GeoipTestFixture.php';

class GeoipPublisherTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_pub_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	/**
	 * Prepare a valid upstream distribution directory for tests.
	 *
	 * @param string $dist_dir Target dist directory.
	 * @param int    $epoch    Build epoch timestamp.
	 * @return array<string, mixed> Preparation result.
	 */
	private function prepare_upstream_dist( string $dist_dir, int $epoch = GeoipTestFixture::DEFAULT_BUILD_EPOCH ): array {
		$tarball_path = $this->temp_dir . '/upstream_' . bin2hex( random_bytes( 4 ) ) . '.tar.gz';
		GeoipTestFixture::create_valid_upstream_archive( $tarball_path, $epoch );

		return GeoipPreparer::prepare(
			array(
				'archive'    => $tarball_path,
				'output_dir' => $dist_dir,
			)
		);
	}

	/**
	 * Run the publisher script with mock AWS CLI and cURL commands.
	 *
	 * @param string                $dist_dir    Distribution directory path.
	 * @param string                $aws_script  Shell body for mock AWS command.
	 * @param string                $curl_script Shell body for mock cURL command.
	 * @param array<string, string> $extra_env   Additional environment variables.
	 * @param string                $extra_args  Additional command line flags.
	 * @return array{exit_code: int, output: string, log: string}
	 */
	private function run_mock_publisher(
		string $dist_dir,
		string $aws_script,
		string $curl_script,
		array $extra_env = array(),
		string $extra_args = ''
	): array {
		$mock_log  = $this->temp_dir . '/mock_exec_' . bin2hex( random_bytes( 4 ) ) . '.log';
		$mock_aws  = $this->temp_dir . '/mock_aws_' . bin2hex( random_bytes( 4 ) ) . '.sh';
		$mock_curl = $this->temp_dir . '/mock_curl_' . bin2hex( random_bytes( 4 ) ) . '.sh';

		file_put_contents( $mock_aws, "#!/usr/bin/env bash\necho \"AWS: \$*\" >> \"$mock_log\"\n" . $aws_script );
		chmod( $mock_aws, 0755 );

		file_put_contents( $mock_curl, "#!/usr/bin/env bash\necho \"CURL: \$*\" >> \"$mock_log\"\n" . $curl_script );
		chmod( $mock_curl, 0755 );

		$default_env = array(
			'AWS_CMD'               => $mock_aws,
			'CURL_CMD'              => $mock_curl,
			'R2_ACCOUNT_ID'         => 'dummy_account',
			'R2_BUCKET'             => 'dummy_bucket',
			'AWS_ACCESS_KEY_ID'     => 'dummy_key',
			'AWS_SECRET_ACCESS_KEY' => 'dummy_secret',
			'CLOUDFLARE_API_TOKEN'  => 'dummy_token',
			'CLOUDFLARE_ZONE_ID'    => 'dummy_zone',
		);

		$env = array_merge( $default_env, $extra_env );

		$env_prefix = '';
		foreach ( $env as $key => $val ) {
			if ( '' === $val ) {
				$env_prefix .= sprintf( '%s= ', $key );
			} else {
				$env_prefix .= sprintf( '%s=%s ', $key, escapeshellarg( $val ) );
			}
		}

		$script_path = dirname( __DIR__, 4 ) . '/scripts/geoip/publish-geoip.sh';
		$cmd         = sprintf(
			'%sbash %s --dist-dir=%s %s 2>&1',
			$env_prefix,
			escapeshellarg( $script_path ),
			escapeshellarg( $dist_dir ),
			$extra_args
		);

		$output    = array();
		$exit_code = 0;
		exec( $cmd, $output, $exit_code );

		return array(
			'exit_code' => $exit_code,
			'output'    => implode( "\n", $output ),
			'log'       => file_exists( $mock_log ) ? (string) file_get_contents( $mock_log ) : '',
		);
	}

	/**
	 * Default mock AWS script handler that simulates an absent remote manifest (404 / NoSuchKey).
	 *
	 * @param int $archive_size Expected archive size bytes.
	 * @return string Bash script body.
	 */
	private function get_default_aws_script( int $archive_size, string $dist_dir ): string {
		$manifest_size = file_exists( $dist_dir . '/manifest.json' ) ? (int) filesize( $dist_dir . '/manifest.json' ) : 280;

		return <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "fatal error: An error occurred (404) when calling the GetObject operation: Key not found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
fi
if [ "\$1" = "s3api" ] && [ "\$2" = "head-object" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "geoip/manifest.json" ]; then
            echo '{"ContentLength": $manifest_size, "ContentType": "application/json"}'
            exit 0
        fi
    done
    echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
    exit 0
fi
exit 0
BASH;
	}

	/**
	 * Test that upstream preparation generates valid schema-1 manifest and archive.
	 */
	public function test_prepare_generates_valid_schema1_manifest_and_archive(): void {
		$output_dir = $this->temp_dir . '/dist';
		$result     = $this->prepare_upstream_dist( $output_dir, 1790952592 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'GeoLite2-City', $result['edition'] );
		$this->assertSame( 1790952592, $result['buildEpoch'] );

		// 1. Verify files exist on disk.
		$archive_path   = $output_dir . '/GeoLite2-City.mmdb.gz';
		$manifest_path  = $output_dir . '/manifest.json';
		$license_path   = $output_dir . '/LICENSE.txt';
		$copyright_path = $output_dir . '/COPYRIGHT.txt';

		$this->assertFileExists( $archive_path );
		$this->assertFileExists( $manifest_path );
		$this->assertFileExists( $license_path );
		$this->assertFileExists( $copyright_path );

		// 2. Verify archive size and hash match manifest.
		$archive_bytes = (int) filesize( $archive_path );
		$archive_sha   = hash_file( 'sha256', $archive_path );

		$this->assertGreaterThan( 0, $archive_bytes );
		$this->assertSame( $archive_bytes, $result['sizeBytes'] );
		$this->assertSame( $archive_sha, $result['checksumSha256'] );

		// 3. Verify manifest JSON is strictly accepted by PeakURL R2Source static schema parser.
		$manifest_raw = (string) file_get_contents( $manifest_path );
		$validated    = R2Source::validate_manifest_schema( $manifest_raw );

		$this->assertSame( 1, $validated['schemaVersion'] );
		$this->assertSame( 'GeoLite2-City', $validated['edition'] );
		$this->assertSame( 1790952592, $validated['buildEpoch'] );
		$this->assertSame( Constants::DEFAULT_GEOIP_ARCHIVE_URL, $validated['downloadUrl'] );
		$this->assertSame( $archive_sha, $validated['checksumSha256'] );
		$this->assertSame( $archive_bytes, $validated['sizeBytes'] );
	}

	/**
	 * Test that raw gzip decompression yields the exact expected MMDB bytes.
	 */
	public function test_round_trip_decompression_yields_exact_raw_mmdb(): void {
		$output_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $output_dir, 1790952592 );

		$gz_path = $output_dir . '/GeoLite2-City.mmdb.gz';
		$gz      = gzopen( $gz_path, 'rb' );
		$this->assertNotFalse( $gz );

		$decompressed_content = '';
		while ( ! gzeof( $gz ) ) {
			$chunk = gzread( $gz, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$decompressed_content .= $chunk;
		}
		gzclose( $gz );

		$decompressed_file = $this->temp_dir . '/extracted.mmdb';
		file_put_contents( $decompressed_file, $decompressed_content );

		// Ensure it is a valid MMDB readable by MaxMind\Db\Reader.
		$reader   = new Reader( $decompressed_file );
		$metadata = $reader->metadata();
		$reader->close();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 'GeoLite2-City', $metadata->databaseType );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 1790952592, $metadata->buildEpoch );
	}

	/**
	 * Test table-driven rejection of invalid or malformed upstream archives.
	 *
	 * @param string $case_type        Case identifier.
	 * @param string $expected_message Expected substring in exception message.
	 */
	#[DataProvider( 'provide_malformed_archive_cases' )]
	public function test_prepare_rejects_malformed_upstream_archives( string $case_type, string $expected_message ): void {
		$tarball_path = $this->temp_dir . "/{$case_type}.tar.gz";

		switch ( $case_type ) {
			case 'path_traversal':
				$py_script = <<<PYTHON
import tarfile, io
with tarfile.open('{$tarball_path}', mode='w:gz') as tar:
    for name in ['foo:bar.txt', 'GeoLite2-City_20261015/GeoLite2-City.mmdb', 'GeoLite2-City_20261015/LICENSE.txt', 'GeoLite2-City_20261015/COPYRIGHT.txt']:
        ti = tarfile.TarInfo(name=name)
        data = b'data'
        ti.size = len(data)
        tar.addfile(ti, io.BytesIO(data))
PYTHON;
				exec( 'python3 -c ' . escapeshellarg( $py_script ) );
				break;

			case 'multiple_mmdb_files':
				GeoipTestFixture::create_tar_gz(
					$tarball_path,
					array(
						'dir/GeoLite2-City.mmdb'  => 'data1',
						'dir/GeoLite2-City2.mmdb' => 'data2',
						'dir/LICENSE.txt'         => 'lic',
						'dir/COPYRIGHT.txt'       => 'cpr',
					)
				);
				break;

			case 'missing_notices':
				GeoipTestFixture::create_tar_gz(
					$tarball_path,
					array(
						'dir/GeoLite2-City.mmdb' => 'data1',
					)
				);
				break;

			case 'empty_archive':
				touch( $tarball_path );
				break;

			case 'too_many_entries':
				$entries = array(
					'dir/GeoLite2-City.mmdb' => 'data',
					'dir/LICENSE.txt'        => 'lic',
					'dir/COPYRIGHT.txt'      => 'cpr',
				);
				for ( $i = 1; $i <= 50; $i++ ) {
					$entries[ "dir/extra_{$i}.txt" ] = "extra content {$i}";
				}
				GeoipTestFixture::create_tar_gz( $tarball_path, $entries );
				break;

			case 'oversized_notice':
				$synthetic_mmdb = $this->temp_dir . '/source.mmdb';
				GeoipTestFixture::generate_city_mmdb( $synthetic_mmdb, 1790123456 );
				GeoipTestFixture::create_tar_gz(
					$tarball_path,
					array(
						'dir/GeoLite2-City.mmdb' => (string) file_get_contents( $synthetic_mmdb ),
						'dir/LICENSE.txt'        => str_repeat( 'A', 1048577 ),
						'dir/COPYRIGHT.txt'      => "Copyright\n",
					)
				);
				break;

			case 'excessive_uncompressed_bytes':
				$sparse_file = $this->temp_dir . '/sparse.bin';
				$py_script   = <<<PYTHON
import tarfile, os
with open('{$sparse_file}', 'wb') as f:
    f.seek(265 * 1024 * 1024 - 1)
    f.write(b'\\0')
with tarfile.open('{$tarball_path}', 'w:gz') as tar:
    tar.add('{$sparse_file}', arcname='GeoLite2-City_20261015/GeoLite2-City.mmdb')
    tar.addfile(tarfile.TarInfo('GeoLite2-City_20261015/LICENSE.txt'))
    tar.addfile(tarfile.TarInfo('GeoLite2-City_20261015/COPYRIGHT.txt'))
if os.path.exists('{$sparse_file}'):
    os.remove('{$sparse_file}')
PYTHON;
				exec( 'python3 -c ' . escapeshellarg( $py_script ) );
				break;

			case 'non_city_database':
				$country_mmdb = $this->temp_dir . '/country.mmdb';
				GeoipTestFixture::generate_city_mmdb( $country_mmdb, 1790123456, 'GeoLite2-Country' );
				GeoipTestFixture::create_tar_gz(
					$tarball_path,
					array(
						'dir/GeoLite2-Country.mmdb' => (string) file_get_contents( $country_mmdb ),
						'dir/LICENSE.txt'           => "License\n",
						'dir/COPYRIGHT.txt'         => "Copyright\n",
					)
				);
				break;
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $expected_message );

		GeoipPreparer::prepare(
			array(
				'archive'    => $tarball_path,
				'output_dir' => $this->temp_dir . '/dist',
			)
		);
	}

	/**
	 * Data provider for malformed archive test cases.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_malformed_archive_cases(): array {
		return array(
			'path_traversal'               => array( 'path_traversal', 'path traversal' ),
			'multiple_mmdb_files'          => array( 'multiple_mmdb_files', 'multiple database files' ),
			'missing_notices'              => array( 'missing_notices', 'missing required' ),
			'empty_archive'                => array( 'empty_archive', 'empty' ),
			'too_many_entries'             => array( 'too_many_entries', 'Archive exceeds maximum permitted entry count' ),
			'oversized_notice'             => array( 'oversized_notice', 'exceeds maximum allowed size' ),
			'excessive_uncompressed_bytes' => array( 'excessive_uncompressed_bytes', 'Archive total uncompressed size exceeds maximum permitted limit' ),
			'non_city_database'            => array( 'non_city_database', 'does not match expected' ),
		);
	}

	/**
	 * Test that the shell publisher script works in dry-run mode without network.
	 */
	public function test_publisher_dry_run_executes_without_network(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		$script_path = dirname( __DIR__, 4 ) . '/scripts/geoip/publish-geoip.sh';
		$cmd         = sprintf( 'bash %s --dist-dir=%s --dry-run', escapeshellarg( $script_path ), escapeshellarg( $dist_dir ) );

		$output    = array();
		$exit_code = 0;
		exec( $cmd, $output, $exit_code );

		$this->assertSame( 0, $exit_code );
		$output_str = implode( "\n", $output );
		$this->assertStringContainsString( 'Pre-flight validation passed', $output_str );
		$this->assertStringContainsString( '[DRY RUN] Would upload', $output_str );
		$this->assertStringContainsString( 'GeoIP Release Publication Succeeded!', $output_str );
		$this->assertStringContainsString( 'Mode:                 Dry Run', $output_str );
	}

	/**
	 * Test full mocked publisher run verifying exact stage execution order on initial publish.
	 */
	public function test_publisher_mock_simulation_executes_ordered_stages(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$archive_size  = $prep['sizeBytes'];
		$manifest_size = (int) filesize( $dist_dir . '/manifest.json' );

		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "fatal error: An error occurred (404) when calling the GetObject operation: Key not found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
fi
if [ "\$1" = "s3api" ] && [ "\$2" = "head-object" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "geoip/GeoLite2-City.mmdb.gz" ]; then
            echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
            exit 0
        elif [ "\$arg" = "geoip/manifest.json" ]; then
            echo '{"ContentLength": $manifest_size, "ContentType": "application/json"}'
            exit 0
        fi
    done
fi
exit 0
BASH;

		$curl_script = <<<BASH
echo '{"success": true, "result": {"id": "mock_zone_purge"}}'
echo "HTTP_STATUS:200"
exit 0
BASH;

		$res = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 0, $res['exit_code'], $res['output'] );
		$this->assertStringContainsString( 'Remote manifest not found in R2 (first release publish)', $res['output'] );

		$log_lines = array_values( array_filter( explode( "\n", trim( $res['log'] ) ) ) );
		$this->assertNotEmpty( $log_lines );

		// Verify ordered sequence of actions:
		// 0. Pre-flight check bucket accessibility in R2
		$this->assertStringContainsString( 'head-bucket', $log_lines[0] );

		// 1. Pre-flight check manifest in R2
		$this->assertStringContainsString( 'manifest.json -', $log_lines[1] );

		// 2. Upload notices (LICENSE, COPYRIGHT)
		$this->assertStringContainsString( 'LICENSE.txt', $log_lines[2] );
		$this->assertStringContainsString( 'COPYRIGHT.txt', $log_lines[3] );

		// 3. Upload archive
		$this->assertStringContainsString( 'GeoLite2-City.mmdb.gz', $log_lines[4] );

		// 4. Head-object archive and streaming readback
		$this->assertStringContainsString( 'head-object', $log_lines[5] );
		$this->assertStringContainsString( 'GeoLite2-City.mmdb.gz', $log_lines[5] );
		$this->assertStringContainsString( 'GeoLite2-City.mmdb.gz -', $log_lines[6] );

		// 5. Purge archive in Cloudflare
		$this->assertStringContainsString( 'purge', $log_lines[7] );
		$this->assertStringContainsString( 'GeoLite2-City.mmdb.gz', $log_lines[7] );

		// 6. Upload manifest
		$this->assertStringContainsString( 'manifest.json', $log_lines[8] );

		// 7. Head-object manifest
		$this->assertStringContainsString( 'head-object', $log_lines[9] );
		$this->assertStringContainsString( 'manifest.json', $log_lines[9] );

		// 8. Purge manifest in Cloudflare
		$this->assertStringContainsString( 'purge', $log_lines[10] );
		$this->assertStringContainsString( 'manifest.json', $log_lines[10] );
	}

	/**
	 * Test that publisher aborts when target R2 bucket is inaccessible (NoSuchBucket or 404).
	 */
	public function test_publisher_aborts_when_target_bucket_is_inaccessible(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		// Simulate head-bucket failure with NoSuchBucket.
		$aws_script = <<<BASH
if [ "\$1" = "s3api" ] && [ "\$2" = "head-bucket" ]; then
    echo "An error occurred (404) when calling the HeadBucket operation: Not Found (NoSuchBucket)" >&2
    exit 1
fi
exit 0
BASH;

		$curl_script = "exit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( "Target R2 bucket 'dummy_bucket' is inaccessible or does not exist", $res['output'] );
		$this->assertStringContainsString( 'remote_manifest_preflight_failed', $res['output'] );

		// Verify no objects were uploaded.
		$this->assertStringNotContainsString( 'LICENSE.txt', $res['log'] );
		$this->assertStringNotContainsString( 'GeoLite2-City.mmdb.gz', $res['log'] );
	}

	/**
	 * Test that publisher aborts when remote manifest preflight fails with an unexpected error (e.g. 403 Forbidden).
	 */
	public function test_publisher_aborts_when_remote_manifest_preflight_fails_with_unexpected_error(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		// Simulate unexpected 403 Forbidden error on manifest read.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "fatal error: An error occurred (403) when calling the GetObject operation: Forbidden (AccessDenied)" >&2
        exit 1
    fi
fi
exit 0
BASH;

		$curl_script = "exit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'Failed to inspect remote manifest in R2', $res['output'] );
		$this->assertStringContainsString( 'remote_manifest_preflight_failed', $res['output'] );

		// Verify no objects were uploaded.
		$this->assertStringNotContainsString( 'LICENSE.txt', $res['log'] );
		$this->assertStringNotContainsString( 'GeoLite2-City.mmdb.gz', $res['log'] );
	}

	/**
	 * Test that publisher aborts when archive HEAD check encounters an unexpected error (e.g. 403 Forbidden).
	 */
	public function test_publisher_aborts_when_archive_head_fails_with_unexpected_error_in_same_build_preflight(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$manifest_json = $prep['manifestJson'];

		// Remote manifest matches, but head-object on archive fails with 403 Forbidden.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        cat <<'JSON'
$manifest_json
JSON
        exit 0
    fi
fi
if [ "\$1" = "s3api" ] && [ "\$2" = "head-object" ]; then
    echo "An error occurred (403) when calling the HeadObject operation: Forbidden (AccessDenied)" >&2
    exit 1
fi
exit 0
BASH;

		$curl_script = "exit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'Failed to verify existing archive in R2', $res['output'] );
		$this->assertStringContainsString( 'remote_archive_preflight_failed', $res['output'] );

		// Verify no objects were uploaded.
		$this->assertStringNotContainsString( 'LICENSE.txt', $res['log'] );
		$this->assertStringNotContainsString( 's3 cp ' . $dist_dir . '/GeoLite2-City.mmdb.gz', $res['log'] );
	}

	/**
	 * Test that publisher proceeds with publication when remote manifest matches but archive is missing in R2.
	 */
	public function test_publisher_proceeds_when_remote_manifest_matches_but_archive_is_missing_in_r2(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$manifest_json = $prep['manifestJson'];
		$archive_size  = $prep['sizeBytes'];
		$manifest_size = (int) filesize( $dist_dir . '/manifest.json' );

		// Remote manifest matches, but head-object on archive fails with 404.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        cat <<'JSON'
$manifest_json
JSON
        exit 0
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
fi
HEAD_COUNT_FILE="$dist_dir/.head_count"
if [ "\$1" = "s3api" ] && [ "\$2" = "head-object" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "geoip/GeoLite2-City.mmdb.gz" ]; then
            if [ ! -f "\$HEAD_COUNT_FILE" ]; then
                touch "\$HEAD_COUNT_FILE"
                echo "An error occurred (404) when calling the HeadObject operation: Not Found" >&2
                exit 1
            fi
            echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
            exit 0
        elif [ "\$arg" = "geoip/manifest.json" ]; then
            echo '{"ContentLength": $manifest_size, "ContentType": "application/json"}'
            exit 0
        fi
    done
fi
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		// Publication should NOT skip; it should repair the missing archive!
		$this->assertSame( 0, $res['exit_code'], $res['output'] );
		$this->assertStringContainsString( 'remote archive is missing from R2', $res['output'] );
		$this->assertStringContainsString( 'GeoIP Release Publication Succeeded!', $res['output'] );

		// Verify archive was uploaded.
		$this->assertStringContainsString( 'GeoLite2-City.mmdb.gz', $res['log'] );
	}

	/**
	 * Test that publisher fails closed when Cloudflare archive purge returns failure.
	 *
	 * Asserts that the manifest is NEVER uploaded if archive purge fails.
	 */
	public function test_publisher_stops_if_archive_purge_fails_and_manifest_is_not_uploaded(): void {
		$dist_dir     = $this->temp_dir . '/dist';
		$prep         = $this->prepare_upstream_dist( $dist_dir );
		$archive_size = $prep['sizeBytes'];

		$aws_script = $this->get_default_aws_script( $archive_size, $dist_dir );

		// Curl returns JSON failure.
		$curl_script = <<<BASH
echo '{"success": false, "errors": [{"code": 1000, "message": "Simulated Cloudflare purge error"}]}'
echo "HTTP_STATUS:200"
exit 0
BASH;

		$res = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'archive_purge_failed', $res['output'] );
		$this->assertStringContainsString( 'Manifest will NOT be published', $res['output'] );

		// Check mock log to guarantee manifest was NEVER uploaded.
		$this->assertStringNotContainsString( $dist_dir . '/manifest.json', $res['log'] );
		$this->assertStringNotContainsString( 'purge https://releases.peakurl.org/geoip/manifest.json', $res['log'] );
	}

	/**
	 * Test that publisher stops immediately if archive upload fails.
	 */
	public function test_publisher_stops_if_archive_upload_fails(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		// Fail when uploading GeoLite2-City.mmdb.gz.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "404 Not Found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]]; then
        exit 1
    fi
fi
for arg in "\$@"; do
    if [[ "\$arg" == *"GeoLite2-City.mmdb.gz"* ]]; then
        exit 1
    fi
done
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'archive_upload_failed', $res['output'] );
		$this->assertStringNotContainsString( 'CURL', $res['log'] );
		$this->assertStringNotContainsString( $dist_dir . '/manifest.json', $res['log'] );
	}

	/**
	 * Test that publisher stops immediately if archive R2 size does not match local archive.
	 */
	public function test_publisher_stops_if_archive_size_mismatches_in_r2(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		// Return wrong size for archive.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ] && [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
    echo "404 Not Found" >&2
    exit 1
fi
if [ "\$1" = "s3api" ]; then
    echo '{"ContentLength": 99999999, "ContentType": "application/gzip"}'
fi
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'archive_size_mismatch', $res['output'] );
		$this->assertStringNotContainsString( 'CURL', $res['log'] );
		$this->assertStringNotContainsString( $dist_dir . '/manifest.json', $res['log'] );
	}

	/**
	 * Test that publisher stops immediately if R2 readback SHA does not match local archive.
	 */
	public function test_publisher_stops_if_r2_readback_sha_mismatches(): void {
		$dist_dir     = $this->temp_dir . '/dist';
		$prep         = $this->prepare_upstream_dist( $dist_dir );
		$archive_size = $prep['sizeBytes'];

		// Corrupted read-back stream from R2.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "404 Not Found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        echo "corrupted-readback-data"
        exit 0
    fi
fi
if [ "\$1" = "s3api" ]; then
    echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
    exit 0
fi
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'archive_readback_sha_mismatch', $res['output'] );
		$this->assertStringNotContainsString( 'CURL', $res['log'] );
		$this->assertStringNotContainsString( $dist_dir . '/manifest.json', $res['log'] );
	}

	/**
	 * Test that publisher stops immediately if manifest upload fails.
	 */
	public function test_publisher_stops_if_manifest_upload_fails(): void {
		$dist_dir     = $this->temp_dir . '/dist';
		$prep         = $this->prepare_upstream_dist( $dist_dir );
		$archive_size = $prep['sizeBytes'];

		// Fail when uploading manifest.json.
		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "404 Not Found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
    for arg in "\$@"; do
        if [[ "\$arg" == *"manifest.json"* ]]; then
            exit 1
        fi
    done
fi
if [ "\$1" = "s3api" ]; then
    echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
    exit 0
fi
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'manifest_upload_failed', $res['output'] );

		// Verify manifest was never purged (only 1 curl call for archive purge).
		$log_lines = array_filter( explode( "\n", trim( $res['log'] ) ), fn( $l ) => str_starts_with( $l, 'CURL:' ) );
		$this->assertCount( 1, $log_lines );
	}

	/**
	 * Test that publisher reports error if manifest purge fails.
	 */
	public function test_publisher_stops_if_manifest_purge_fails(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$archive_size  = $prep['sizeBytes'];
		$manifest_size = (int) filesize( $dist_dir . '/manifest.json' );

		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        echo "404 Not Found" >&2
        exit 1
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
fi
if [ "\$1" = "s3api" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "geoip/GeoLite2-City.mmdb.gz" ]; then
            echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
            exit 0
        elif [ "\$arg" = "geoip/manifest.json" ]; then
            echo '{"ContentLength": $manifest_size, "ContentType": "application/json"}'
            exit 0
        fi
    done
fi
exit 0
BASH;

		// First curl (archive purge) succeeds, second curl (manifest purge) fails.
		$curl_counter = $this->temp_dir . '/curl_count.txt';
		file_put_contents( $curl_counter, '0' );

		$curl_script = <<<BASH
COUNT=\$(cat "$curl_counter")
COUNT=\$((COUNT + 1))
echo "\$COUNT" > "$curl_counter"

if [ "\$COUNT" -eq 1 ]; then
    echo '{"success": true}'
    echo "HTTP_STATUS:200"
    exit 0
else
    echo '{"success": false, "errors": [{"message": "Rate limited"}]}'
    echo "HTTP_STATUS:200"
    exit 0
fi
BASH;

		$res = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'manifest_purge_failed', $res['output'] );
	}

	/**
	 * Test that publisher preflight check skips redundant publication when remote manifest and archive are identical.
	 */
	public function test_publisher_preflight_skips_when_r2_manifest_is_identical(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$archive_size  = $prep['sizeBytes'];
		$manifest_json = $prep['manifestJson'];

		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ] && [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
    cat <<'JSON'
$manifest_json
JSON
    exit 0
fi
if [ "\$1" = "s3api" ] && [ "\$2" = "head-object" ]; then
    echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
    exit 0
fi
exit 0
BASH;

		$curl_script = "exit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script );

		$this->assertSame( 0, $res['exit_code'] );
		$this->assertStringContainsString( 'Preflight status: UP_TO_DATE', $res['output'] );
		$this->assertStringNotContainsString( 's3 cp ' . $dist_dir . '/GeoLite2-City.mmdb.gz', $res['log'] );
		$this->assertStringNotContainsString( 'CURL', $res['log'] );
	}

	/**
	 * Test that publisher forces publication when --force is provided despite identical remote manifest.
	 */
	public function test_publisher_preflight_forces_when_force_flag_is_specified(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$archive_size  = $prep['sizeBytes'];
		$manifest_size = (int) filesize( $dist_dir . '/manifest.json' );
		$manifest_json = $prep['manifestJson'];

		$aws_script = <<<BASH
if [ "\$1" = "s3" ] && [ "\$2" = "cp" ]; then
    if [[ "\$3" == *"manifest.json"* ]] && [ "\$4" = "-" ]; then
        cat <<'JSON'
$manifest_json
JSON
        exit 0
    fi
    if [[ "\$3" == *"GeoLite2-City.mmdb.gz"* ]] && [ "\$4" = "-" ]; then
        cat "$dist_dir/GeoLite2-City.mmdb.gz"
        exit 0
    fi
fi
if [ "\$1" = "s3api" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "geoip/GeoLite2-City.mmdb.gz" ]; then
            echo '{"ContentLength": $archive_size, "ContentType": "application/gzip"}'
            exit 0
        elif [ "\$arg" = "geoip/manifest.json" ]; then
            echo '{"ContentLength": $manifest_size, "ContentType": "application/json"}'
            exit 0
        fi
    done
fi
exit 0
BASH;

		$curl_script = "echo '{\"success\": true}'\necho 'HTTP_STATUS:200'\nexit 0\n";
		$res         = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script, array(), '--force' );

		$this->assertSame( 0, $res['exit_code'] );
		$this->assertStringContainsString( '--force was specified. Proceeding', $res['output'] );
		$this->assertStringContainsString( 'GeoIP Release Publication Succeeded!', $res['output'] );
	}

	/**
	 * Test that publisher fails closed when required environment variables are missing.
	 */
	public function test_publisher_fails_closed_when_credentials_are_missing(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		$script_path = dirname( __DIR__, 4 ) . '/scripts/geoip/publish-geoip.sh';
		$cmd         = sprintf( 'bash %s --dist-dir=%s 2>&1', escapeshellarg( $script_path ), escapeshellarg( $dist_dir ) );

		$output    = array();
		$exit_code = 0;
		exec( $cmd, $output, $exit_code );

		$this->assertSame( 1, $exit_code );
		$this->assertStringContainsString( 'Missing required environment variables', implode( "\n", $output ) );
	}

	/**
	 * Test that publisher refuses to publish synthetic release without dry-run.
	 */
	public function test_publisher_refuses_to_publish_synthetic_release_without_dry_run(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		// Mark artifact directory as synthetic in origin metadata.
		file_put_contents( $dist_dir . '/.release-origin.json', json_encode( array( 'origin' => 'synthetic' ) ) );

		$script_path = dirname( __DIR__, 4 ) . '/scripts/geoip/publish-geoip.sh';
		$cmd         = sprintf(
			'R2_ACCOUNT_ID=dummy_account R2_BUCKET=dummy_bucket AWS_ACCESS_KEY_ID=dummy_key AWS_SECRET_ACCESS_KEY=dummy_secret CLOUDFLARE_API_TOKEN=dummy_token CLOUDFLARE_ZONE_ID=dummy_zone bash %s --dist-dir=%s 2>&1',
			escapeshellarg( $script_path ),
			escapeshellarg( $dist_dir )
		);

		$output    = array();
		$exit_code = 0;
		exec( $cmd, $output, $exit_code );

		$this->assertSame( 1, $exit_code );
		$this->assertStringContainsString( 'Refusing to publish synthetic/test release artifacts to production R2', implode( "\n", $output ) );
	}

	/**
	 * Test that publisher refuses non-canonical base URL in live mode.
	 */
	public function test_publisher_refuses_custom_base_url_in_live_publish(): void {
		$dist_dir = $this->temp_dir . '/dist';
		$this->prepare_upstream_dist( $dist_dir );

		$script_path = dirname( __DIR__, 4 ) . '/scripts/geoip/publish-geoip.sh';
		$cmd         = sprintf(
			'PUBLIC_BASE_URL=https://custom.example.com R2_ACCOUNT_ID=dummy_account R2_BUCKET=dummy_bucket AWS_ACCESS_KEY_ID=dummy_key AWS_SECRET_ACCESS_KEY=dummy_secret CLOUDFLARE_API_TOKEN=dummy_token CLOUDFLARE_ZONE_ID=dummy_zone bash %s --dist-dir=%s 2>&1',
			escapeshellarg( $script_path ),
			escapeshellarg( $dist_dir )
		);

		$output    = array();
		$exit_code = 0;
		exec( $cmd, $output, $exit_code );

		$this->assertSame( 1, $exit_code );
		$this->assertStringContainsString( "PUBLIC_BASE_URL must be 'https://releases.peakurl.org'", implode( "\n", $output ) );
	}

	/**
	 * Test that GitHub Actions workflow YAML parses and enforces one-time gate, permissions, and separate dry-run preflight.
	 */
	public function test_workflow_file_syntax_and_preflight(): void {
		$workflow_file = dirname( __DIR__, 4 ) . '/.github/workflows/update-geoip.yml';
		$this->assertFileExists( $workflow_file );

		$content = (string) file_get_contents( $workflow_file );

		// 1. Verify concurrency group is dedicated to geoip-r2-publisher (not publish-release-r2).
		$this->assertStringContainsString( 'group: geoip-r2-publisher', $content );
		$this->assertStringContainsString( 'cancel-in-progress: false', $content );

		// 2. Verify top-level permissions are read-only.
		$this->assertStringContainsString( "permissions:\n  contents: read", $content );

		// 3. Verify publish job has one-time gate and canonical branch.
		$this->assertStringContainsString( "github.ref == 'refs/heads/main'", $content );
		$this->assertStringContainsString( "vars.PEAKURL_GEOIP_PUBLISH_APPROVED == 'true'", $content );

		// 4. Verify publish job targets protected environment.
		$this->assertStringContainsString( 'environment: production-geoip', $content );

		// 5. Verify weekly schedule is Wednesday 04:00 UTC.
		$this->assertStringContainsString( 'cron: "0 4 * * 3"', $content );

		// 6. Verify MaxMind credentials are required for both live and dry-run, but R2/Cloudflare only for live.
		$this->assertStringContainsString( 'MISSING_MAXMIND=()', $content );
		$this->assertStringContainsString( 'MISSING_PUBLISH=()', $content );
	}

	/**
	 * Test that publisher with --verify-cdn succeeds when CDN endpoints return matching artifacts.
	 */
	public function test_publisher_verify_cdn_succeeds_when_cdn_matches(): void {
		$dist_dir      = $this->temp_dir . '/dist';
		$prep          = $this->prepare_upstream_dist( $dist_dir );
		$archive_size  = $prep['sizeBytes'];
		$manifest_json = $prep['manifestJson'];
		$aws_script    = $this->get_default_aws_script( $archive_size, $dist_dir );

		// Curl script: handles zone purges AND GET requests for CDN verification.
		$archive_file = $dist_dir . '/GeoLite2-City.mmdb.gz';
		$curl_script  = <<<BASH
if [[ "\$*" == *"manifest.json"* ]] && [[ "\$*" != *"purge_cache"* ]]; then
    cat <<'JSON'
$manifest_json
JSON
    exit 0
fi
if [[ "\$*" == *"GeoLite2-City.mmdb.gz"* ]] && [[ "\$*" != *"purge_cache"* ]]; then
    for ((i=1; i<=\$#; i++)); do
        if [ "\${!i}" = "--output" ]; then
            next=\$((i+1))
            cat "$archive_file" > "\${!next}"
            exit 0
        fi
    done
    exit 0
fi
echo '{"success": true}'
echo "HTTP_STATUS:200"
exit 0
BASH;

		$res = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script, array(), '--verify-cdn' );

		$this->assertSame( 0, $res['exit_code'], $res['output'] );
		$this->assertStringContainsString( 'Stage 8 complete: CDN verified identical archive and manifest', $res['output'] );
		$this->assertStringContainsString( 'GeoIP Release Publication Succeeded!', $res['output'] );
	}

	/**
	 * Test that publisher with --verify-cdn detects CDN HTTP failure.
	 */
	public function test_publisher_verify_cdn_detects_http_failure(): void {
		$dist_dir     = $this->temp_dir . '/dist';
		$prep         = $this->prepare_upstream_dist( $dist_dir );
		$archive_size = $prep['sizeBytes'];
		$aws_script   = $this->get_default_aws_script( $archive_size, $dist_dir );

		// Curl script: Purges succeed, but GET manifest fails with 404/502.
		$curl_script = <<<BASH
if [[ "\$*" == *"manifest.json"* ]] && [[ "\$*" != *"purge_cache"* ]]; then
    echo "curl: (22) The requested URL returned error: 502" >&2
    exit 22
fi
echo '{"success": true}'
echo "HTTP_STATUS:200"
exit 0
BASH;

		$res = $this->run_mock_publisher( $dist_dir, $aws_script, $curl_script, array(), '--verify-cdn' );

		$this->assertSame( 1, $res['exit_code'] );
		$this->assertStringContainsString( 'CDN served manifest request failed', $res['output'] );
		$this->assertStringContainsString( 'cdn_manifest_fetch_failed', $res['output'] );
	}
}
