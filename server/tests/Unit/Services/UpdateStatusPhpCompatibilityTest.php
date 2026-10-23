<?php
/**
 * Unit tests for updater PHP compatibility evaluation.
 *
 * @package PeakURL\Tests\Unit\Services
 * @since 1.7.3
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\Update\Context as UpdateContext;
use PeakURL\Services\Update\Filesystem;
use PeakURL\Services\Update\Manifest;
use PeakURL\Services\Update\Status;
use PeakURL\Services\Update\Workspace;

class UpdateStatusPhpCompatibilityTest extends TestCase {

	/**
	 * Create a Status service instance with mocked context, manifest, and workspace.
	 *
	 * @param bool $availability_allowed Whether context reports allowed availability.
	 * @return Status
	 */
	private function create_status_service( bool $availability_allowed = true ): Status {
		$context = $this->createMock( UpdateContext::class );
		$context->method( 'get_current_version' )->willReturn( '1.7.2' );
		$context->method( 'get_availability' )->willReturn(
			array(
				'allowed' => $availability_allowed,
				'reason'  => $availability_allowed ? null : 'Updates are locked.',
			)
		);

		$manifest = $this->createMock( Manifest::class );
		$manifest->method( 'get_manifest_url' )->willReturn( 'https://api.peakurl.org/v1/update' );

		$workspace = $this->createMock( Workspace::class );
		$workspace->method( 'is_locked' )->willReturn( false );

		return new Status( $context, $manifest, $workspace );
	}

	public function test_status_disables_apply_when_php_incompatible(): void {
		$service = $this->create_status_service( true );

		$status = $service->get_status(
			array(
				'version'     => '1.8.0',
				'minimumPhp'  => '99.0.0',
				'downloadUrl' => 'https://releases.peakurl.org/latest.zip',
				'packageUrl'  => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			),
			'2026-10-22 12:00:00',
			null
		);

		$this->assertFalse( $status['phpCompatible'] );
		$this->assertFalse( $status['canApply'] );
		$this->assertNotNull( $status['applyDisabledReason'] );
		$this->assertStringContainsString( '99.0.0', $status['applyDisabledReason'] );
		$this->assertStringContainsString( PHP_VERSION, $status['applyDisabledReason'] );
	}

	public function test_status_enables_apply_when_php_compatible(): void {
		$service = $this->create_status_service( true );

		$status = $service->get_status(
			array(
				'version'     => '1.8.0',
				'minimumPhp'  => '8.3.0',
				'downloadUrl' => 'https://releases.peakurl.org/latest.zip',
				'packageUrl'  => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			),
			'2026-10-22 12:00:00',
			null
		);

		$this->assertTrue( $status['phpCompatible'] );
		$this->assertTrue( $status['canApply'] );
		$this->assertNull( $status['applyDisabledReason'] );
	}

	public function test_status_enables_apply_when_minimum_php_is_omitted(): void {
		$service = $this->create_status_service( true );

		$status = $service->get_status(
			array(
				'version'     => '1.8.0',
				'minimumPhp'  => '',
				'downloadUrl' => 'https://releases.peakurl.org/latest.zip',
				'packageUrl'  => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			),
			'2026-10-22 12:00:00',
			null
		);

		$this->assertTrue( $status['phpCompatible'] );
		$this->assertTrue( $status['canApply'] );
		$this->assertNull( $status['applyDisabledReason'] );
	}

	public function test_status_preserves_context_disabled_reason_when_already_disallowed(): void {
		$service = $this->create_status_service( false );

		$status = $service->get_status(
			array(
				'version'     => '1.8.0',
				'minimumPhp'  => '99.0.0',
				'downloadUrl' => 'https://releases.peakurl.org/latest.zip',
				'packageUrl'  => 'https://releases.peakurl.org/package/peakurl-1.8.0.zip',
			),
			'2026-10-22 12:00:00',
			null
		);

		$this->assertFalse( $status['phpCompatible'] );
		$this->assertFalse( $status['canApply'] );
		$this->assertSame( 'Updates are locked.', $status['applyDisabledReason'] );
	}
}
