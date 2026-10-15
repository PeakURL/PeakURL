<?php
/**
 * Unit tests for Geoip Filesystem integrity validation, locking, and rollback-safe replacement.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use MaxMind\Db\Reader;
use PHPUnit\Framework\TestCase;
use PeakURL\Services\Geoip\Filesystem;
use PeakURL\Utils\File;
use RuntimeException;

class GeoipFilesystemTest extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/peakurl_fs_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->temp_dir ) ) {
			File::delete( $this->temp_dir, true );
		}
		parent::tearDown();
	}

	/**
	 * Generate a deterministic, valid, minimal 263-byte GeoLite2-City database for testing.
	 *
	 * Contains a search tree, separator, and complete valid metadata map readable
	 * by MaxMind\Db\Reader.
	 *
	 * @return string Binary database bytes.
	 */
	private function generate_valid_city_mmdb(): string {
		$metadata = base64_decode(
			'q83vTWF4TWluZC5jb23pW2JpbmFyeV9mb3JtYXRfbWFqb3JfdmVyc2lvbqECW2JpbmFyeV9mb3JtYXRfbWlub3JfdmVyc2lvbqBLYnVpbGRfZXBvY2gEAmq/xJBNZGF0YWJhc2VfdHlwZU1HZW9MaXRlMi1DaXR5S2Rlc2NyaXB0aW9u4UJlblZHZW9MaXRlMiBDaXR5IGRhdGFiYXNlSmlwX3ZlcnNpb26hBklsYW5ndWFnZXMIBEJkZUJlbkJlc0JmckJqYUVwdC1CUkJydUV6aC1DTkpub2RlX2NvdW50w1xEM0tyZWNvcmRfc2l6ZaEc'
		);
		$metadata = str_replace( "node_count\xc3\x5c\x44\x33", "node_count\xc1\x01", $metadata );
		$metadata = str_replace( "record_size\xa1\x1c", "record_size\xa1\x18", $metadata );

		// 1 node (24 bits = 6 bytes) + 16 bytes data separator + metadata marker & dictionary.
		return str_repeat( "\x00", 6 ) . str_repeat( "\x00", 16 ) . $metadata;
	}

	public function test_validate_database_file_accepts_valid_city_database(): void {
		$fixture = $this->temp_dir . '/valid.mmdb';
		file_put_contents( $fixture, $this->generate_valid_city_mmdb() );

		$fs = new Filesystem();
		$this->assertTrue( $fs->validate_database_file( $fixture ) );
	}

	public function test_validate_database_file_returns_false_for_non_existent_file(): void {
		$fs = new Filesystem();
		$this->assertFalse( $fs->validate_database_file( $this->temp_dir . '/missing.mmdb' ) );
	}

	public function test_validate_database_file_returns_false_for_truncated_file(): void {
		$truncated = $this->temp_dir . '/truncated.mmdb';
		file_put_contents( $truncated, str_repeat( 'X', 50 ) ); // Less than MIN_DATABASE_BYTES.

		$fs = new Filesystem();
		$this->assertFalse( $fs->validate_database_file( $truncated ) );
	}

	public function test_validate_database_file_returns_false_for_corrupt_binary(): void {
		$corrupt = $this->temp_dir . '/corrupt.mmdb';
		file_put_contents( $corrupt, random_bytes( 5000 ) );

		$fs = new Filesystem();
		$this->assertFalse( $fs->validate_database_file( $corrupt ) );
	}

	public function test_validate_database_file_returns_false_for_wrong_database_type(): void {
		$mmdb = $this->generate_valid_city_mmdb();
		// Tamper database_type from GeoLite2-City to GeoLite2-Cnnt.
		$tampered = str_replace( 'GeoLite2-City', 'GeoLite2-Cnnt', $mmdb );

		$fixture = $this->temp_dir . '/non_city.mmdb';
		file_put_contents( $fixture, $tampered );

		$fs = new Filesystem();
		$this->assertFalse( $fs->validate_database_file( $fixture ) );
	}

	public function test_replace_database_file_successfully_activates_database(): void {
		$candidate = $this->temp_dir . '/candidate.mmdb';
		file_put_contents( $candidate, $this->generate_valid_city_mmdb() );

		$fs          = new Filesystem();
		$target_path = $this->temp_dir . '/active/GeoLite2-City.mmdb';

		$fs->replace_database_file( $candidate, $target_path );

		$this->assertFileExists( $target_path );
		$this->assertSame( filesize( $candidate ), filesize( $target_path ) );

		// Verify reader can open the newly activated file and inspect metadata.
		$reader = new Reader( $target_path );
		$this->assertStringContainsString( 'City', (string) $reader->metadata()->databaseType );
		$this->assertSame( 1790952592, $reader->metadata()->buildEpoch );
		$reader->close();
	}

	public function test_failed_validation_preserves_existing_database_completely_intact(): void {
		$valid_data = $this->generate_valid_city_mmdb();
		$candidate  = $this->temp_dir . '/good_initial.mmdb';
		file_put_contents( $candidate, $valid_data );

		$fs          = new Filesystem();
		$target_path = $this->temp_dir . '/active/GeoLite2-City.mmdb';

		// 1. Establish initial valid active database.
		$fs->replace_database_file( $candidate, $target_path );
		$original_hash = hash_file( 'sha256', $target_path );

		// 2. Attempt replacement with a corrupt candidate file.
		$corrupt_candidate = $this->temp_dir . '/bad_update.mmdb';
		file_put_contents( $corrupt_candidate, 'CORRUPTED_BYTES_NOT_A_VALID_DATABASE' );

		try {
			$fs->replace_database_file( $corrupt_candidate, $target_path );
			$this->fail( 'Expected replacement to fail on invalid candidate.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'failed integrity verification', $exception->getMessage() );
		}

		// 3. Confirm target database was NOT destroyed and remains byte-for-byte identical.
		$this->assertFileExists( $target_path );
		$this->assertSame( $original_hash, hash_file( 'sha256', $target_path ) );

		// 4. Confirm the active database is still fully readable.
		$reader = new Reader( $target_path );
		$this->assertStringContainsString( 'City', (string) $reader->metadata()->databaseType );
		$reader->close();
	}

	public function test_concurrent_lock_prevents_simultaneous_updates_and_recovers_after_release(): void {
		$candidate = $this->temp_dir . '/candidate.mmdb';
		file_put_contents( $candidate, $this->generate_valid_city_mmdb() );

		$fs          = new Filesystem();
		$target_dir  = $this->temp_dir . '/active';
		$target_path = $target_dir . '/GeoLite2-City.mmdb';
		mkdir( $target_dir, 0755, true );

		// Simulate another process holding the update lock.
		$lock_file = $target_dir . '/.geoip_update.lock';
		$lock_fp   = fopen( $lock_file, 'c+' );
		$this->assertIsResource( $lock_fp );
		$this->assertTrue( flock( $lock_fp, LOCK_EX | LOCK_NB ) );

		// First attempt must fail with lock contention exception.
		try {
			$fs->replace_database_file( $candidate, $target_path );
			$this->fail( 'Expected lock contention exception.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'Another GeoIP database update is currently in progress.', $exception->getMessage() );
		}

		// Release the lock from the simulated first process.
		flock( $lock_fp, LOCK_UN );
		fclose( $lock_fp );

		// Second attempt must succeed cleanly once lock is released.
		$fs->replace_database_file( $candidate, $target_path );
		$this->assertFileExists( $target_path );

		// Advisory lock file must remain on disk (not deleted) to maintain flock stability.
		$this->assertFileExists( $lock_file );
	}

	public function test_concurrent_independent_process_lock_safety(): void {
		$target_dir  = $this->temp_dir . '/active_proc';
		$target_path = $target_dir . '/GeoLite2-City.mmdb';
		mkdir( $target_dir, 0755, true );

		$lock_file = $target_dir . '/.geoip_update.lock';
		$candidate = $this->temp_dir . '/candidate_proc.mmdb';
		file_put_contents( $candidate, $this->generate_valid_city_mmdb() );

		$script      = '<?php
$fp = fopen($argv[1], "c+");
if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
    echo "ACQUIRED\n";
    flush();
    usleep(1500000); // Hold lock for 1.5s
    flock($fp, LOCK_UN);
    fclose($fp);
}
';
		$script_path = $this->temp_dir . '/subproc_lock.php';
		file_put_contents( $script_path, $script );

		$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script_path ) . ' ' . escapeshellarg( $lock_file );
		$proc = proc_open(
			$cmd,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $proc );
		$output = fgets( $pipes[1] );
		$this->assertSame( "ACQUIRED\n", $output );

		$fs = new Filesystem();

		// While independent child process holds lock, parent attempt must fail immediately with contention exception.
		try {
			$fs->replace_database_file( $candidate, $target_path );
			$this->fail( 'Expected lock contention from independent process.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Another GeoIP database update is currently in progress.', $e->getMessage() );
		}

		// Wait for child process to finish and release lock.
		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );

		// Now parent attempt succeeds cleanly without error.
		$fs->replace_database_file( $candidate, $target_path );
		$this->assertFileExists( $target_path );
	}

	public function test_target_symlink_is_rejected(): void {
		$candidate = $this->temp_dir . '/candidate.mmdb';
		file_put_contents( $candidate, $this->generate_valid_city_mmdb() );

		$real_dest    = $this->temp_dir . '/real_destination.mmdb';
		$symlink_dest = $this->temp_dir . '/symlink_destination.mmdb';
		touch( $real_dest );
		symlink( $real_dest, $symlink_dest );

		$fs = new Filesystem();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Target database path cannot be a symbolic link.' );

		$fs->replace_database_file( $candidate, $symlink_dest );
	}
}
