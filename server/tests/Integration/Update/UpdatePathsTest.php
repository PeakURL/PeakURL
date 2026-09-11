<?php
/**
 * Integration tests for Updater layout paths (QA-010 regression).
 *
 * @package PeakURL\Tests\Integration\Update
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Update;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\Update\Context;
use PeakURL\Services\Update\Filesystem;
use PeakURL\Services\Update\Installer;
use PeakURL\Services\Update\Client;
use PeakURL\Services\Update\Workspace;
use PeakURL\Services\Update\ReleaseFiles;
use ReflectionClass;

class UpdatePathsTest extends TestCase {

	public function test_is_release_root_recognizes_server_directory(): void {
		$context    = new Context( array(), new Filesystem() );
		$filesystem = new Filesystem();
		$installer  = new Installer(
			$context,
			$filesystem,
			new Client( $context ),
			new Workspace( $context, $filesystem ),
			new ReleaseFiles( $context, $filesystem )
		);

		$ref    = new ReflectionClass( Installer::class );
		$method = $ref->getMethod( 'is_release_root' );

		// Create a temporary directory structure mimicking the new server/ release layout.
		$temp_dir = sys_get_temp_dir() . '/peakurl_test_release_' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_dir );
		touch( $temp_dir . '/index.php' );
		mkdir( $temp_dir . '/server' );

		try {
			$this->assertTrue( $method->invoke( $installer, $temp_dir ) );
		} finally {
			unlink( $temp_dir . '/index.php' );
			rmdir( $temp_dir . '/server' );
			rmdir( $temp_dir );
		}
	}
}
