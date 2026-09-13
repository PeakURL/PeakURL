<?php
/**
 * Unit tests for GeoipUpdateJob execution logic and freshness gating.
 *
 * @package PeakURL\Tests\Unit\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\System\Jobs\GeoipUpdateJob;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Services\Geoip;
use RuntimeException;

class GeoipUpdateJobTest extends TestCase {

	public function test_skips_when_location_data_is_not_configured(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => false,
					'is_installed'          => false,
					'is_outdated'           => true,
				)
			);
		$geoip->expects( $this->never() )
			->method( 'download_database' );

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_1', 1, false, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'Location data is not configured', $result->get_summary() );
	}

	public function test_skips_when_database_is_fresh_and_not_forced(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => true,
					'is_installed'          => true,
					'is_outdated'           => false,
				)
			);
		$geoip->expects( $this->never() )
			->method( 'download_database' );

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_2', 1, false, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'GeoIP database is already up to date', $result->get_summary() );
	}

	public function test_updates_when_database_is_fresh_but_execution_is_forced(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => true,
					'is_installed'          => true,
					'is_outdated'           => false,
				)
			);
		$geoip->expects( $this->once() )
			->method( 'download_database' )
			->willReturn(
				array(
					'databasePath' => '/content/uploads/geoip/GeoLite2-City.mmdb',
				)
			);

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_3', 1, true, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'GeoLite2 City database refreshed', (string) $result->get_summary() );
	}

	public function test_updates_when_database_is_not_installed(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => true,
					'is_installed'          => false,
					'is_outdated'           => true,
				)
			);
		$geoip->expects( $this->once() )
			->method( 'download_database' )
			->willReturn(
				array(
					'databasePath' => '/content/uploads/geoip/GeoLite2-City.mmdb',
				)
			);

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_4', 1, false, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'GeoLite2 City database refreshed', (string) $result->get_summary() );
	}

	public function test_updates_when_database_is_outdated(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => true,
					'is_installed'          => true,
					'is_outdated'           => true,
				)
			);
		$geoip->expects( $this->once() )
			->method( 'download_database' )
			->willReturn(
				array(
					'databasePath' => '/content/uploads/geoip/GeoLite2-City.mmdb',
				)
			);

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_5', 1, false, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'GeoLite2 City database refreshed', (string) $result->get_summary() );
	}

	public function test_returns_failure_when_update_throws(): void {
		$geoip = $this->createMock( Geoip::class );
		$geoip->expects( $this->once() )
			->method( 'get_status' )
			->willReturn(
				array(
					'credentialsConfigured' => true,
					'is_installed'          => false,
					'is_outdated'           => true,
				)
			);
		$geoip->expects( $this->once() )
			->method( 'download_database' )
			->willThrowException( new RuntimeException( 'Network download timed out' ) );

		$job     = new GeoipUpdateJob( $geoip );
		$context = new ExecutionContext( 'peakurl_geoip_update', 'run_6', 1, false, '2026-09-13 10:00:00' );

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( 'Network download timed out', (string) $result->get_error() );
	}
}
