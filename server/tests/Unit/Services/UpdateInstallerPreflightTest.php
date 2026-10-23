<?php
/**
 * Unit tests for updater installer preflight checks.
 *
 * @package PeakURL\Tests\Unit\Services
 * @since 1.7.3
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\Update\Client;
use PeakURL\Services\Update\Context as UpdateContext;
use PeakURL\Services\Update\Filesystem;
use PeakURL\Services\Update\Installer;
use PeakURL\Services\Update\ReleaseFiles;
use PeakURL\Services\Update\Workspace;
use RuntimeException;

class UpdateInstallerPreflightTest extends TestCase {

	private function create_installer( bool $availability_allowed = true ): Installer {
		$context = $this->createMock( UpdateContext::class );
		$context->method( 'get_availability' )->willReturn(
			array(
				'allowed' => $availability_allowed,
				'reason'  => $availability_allowed ? null : 'Updates are locked.',
			)
		);

		$filesystem = $this->createMock( Filesystem::class );
		$client     = $this->createMock( Client::class );
		$workspace  = $this->createMock( Workspace::class );
		$files      = $this->createMock( ReleaseFiles::class );

		return new Installer( $context, $filesystem, $client, $workspace, $files );
	}

	public function test_installer_rejects_incompatible_php_manifest(): void {
		$installer = $this->create_installer( true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PeakURL 1.8.0 requires PHP 99.0.0 or higher' );

		$installer->apply(
			array(
				'version'    => '1.8.0',
				'minimumPhp' => '99.0.0',
				'packageUrl' => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			)
		);
	}

	public function test_installer_rejects_missing_version_or_package_url(): void {
		$installer = $this->create_installer( true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'missing a package URL or version' );

		$installer->apply(
			array(
				'version'    => '',
				'packageUrl' => '',
			)
		);
	}

	public function test_installer_respects_context_availability_disallowed(): void {
		$installer = $this->create_installer( false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Updates are locked.' );

		$installer->apply(
			array(
				'version'    => '1.8.0',
				'minimumPhp' => '8.3.0',
				'packageUrl' => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			)
		);
	}
}
