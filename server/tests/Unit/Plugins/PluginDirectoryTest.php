<?php
/**
 * Published plugin package verification tests.
 *
 * @package PeakURL\Tests\Unit\Plugins
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Plugins;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Plugins\Directory;

class PluginDirectoryTest extends TestCase {

	#[DataProvider( 'provide_packages' )]
	public function test_verifies_the_exact_published_release( string $package, bool $valid ): void {
		$release_url = 'https://api.peakurl.org/v1/plugins/social-preview-2/releases/1.0.0';
		$metadata    = json_encode(
			array(
				'data' => array(
					'version' => '1.0.0',
					'package' => array(
						'sha256'           => hash( 'sha256', 'published package' ),
						'packageSizeBytes' => strlen( 'published package' ),
						'downloadUrl'      => 'https://example.org/package.zip',
					),
				),
			)
		);
		$directory   = $this->getMockBuilder( Directory::class )->onlyMethods( array( 'download' ) )->getMock();
		$downloads   = array(
			$release_url               => $metadata,
			$release_url . '/download' => $package,
		);
		$directory->expects( $this->exactly( 2 ) )->method( 'download' )->willReturnCallback(
			function ( string $url, string $accept, $destination, int $max_bytes ) use ( $downloads, $release_url, $package ): void {
				$this->assertArrayHasKey( $url, $downloads );
				if ( $release_url !== $url ) {
					$this->assertSame( 'application/zip', $accept );
					$this->assertSame( strlen( $package ), $max_bytes );
				}
				fwrite( $destination, $downloads[ $url ] );
			}
		);

		$archive = tmpfile();
		try {
			if ( ! $valid ) {
				$this->expectException( ApiException::class );
				$this->expectExceptionMessage( 'The downloaded plugin package failed verification.' );
			}
			$directory->download_release( 'social-preview-2', '1.0.0', $archive, '1.8.0' );
			rewind( $archive );
			$this->assertSame( $package, stream_get_contents( $archive ) );
		} finally {
			fclose( $archive );
		}
	}

	public static function provide_packages(): array {
		return array(
			'published bytes'             => array( 'published package', true ),
			'tampered bytes of same size' => array( 'published packagE', false ),
		);
	}
}
