<?php
/**
 * Cross-entry-point integration tests for GeoIP download and timestamp persistence.
 *
 * Verifies that the canonical GeoIP download/update operation is shared
 * across Settings manual download, CLI updater, and Scheduler cron/Run Now paths,
 * and that last-downloaded timestamp persistence is owned once by the canonical operation.
 *
 * @package PeakURL\Tests\Integration\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Features\System\Jobs\GeoipUpdateJob;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Geoip\Downloader;
use PeakURL\Utils\Date;
use RuntimeException;

class GeoipCrossEntryPointIntegrationTest extends TestCase {

	private SettingsApi $settings_api;
	private Crypto $crypto;
	private Geoip $geoip;
	private ?string $original_timestamp   = null;
	private ?string $original_account_id  = null;
	private ?string $original_license_key = null;

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$connection         = Connection::get_instance( $config );
		$db                 = new PeakURL_DB( $connection );
		$this->settings_api = new SettingsApi( $db );
		$this->crypto       = new Crypto( $config );
		$this->geoip        = new Geoip( $config, $this->settings_api, $this->crypto );

		$this->original_timestamp   = $this->settings_api->get_option( 'geoip_last_downloaded_at' );
		$this->original_account_id  = $this->settings_api->get_option( 'maxmind_account_id' );
		$this->original_license_key = $this->settings_api->get_option( 'maxmind_license_key' );

		// Set test credentials so GeoIP is recognized as configured.
		$this->geoip->save_credentials(
			defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 4 ),
			array(
				'accountId'  => '999999',
				'licenseKey' => 'test_key_secret_123',
			)
		);
	}

	protected function tearDown(): void {
		$now = Date::now();

		if ( null !== $this->original_timestamp ) {
			$this->settings_api->update_option(
				'geoip_last_downloaded_at',
				$this->original_timestamp,
				$now,
				false
			);
		} else {
			$this->settings_api->delete_options( array( 'geoip_last_downloaded_at' ) );
		}

		if ( null !== $this->original_account_id ) {
			$this->settings_api->update_option(
				'maxmind_account_id',
				$this->original_account_id,
				$now,
				false
			);
		} else {
			$this->settings_api->delete_options( array( 'maxmind_account_id' ) );
		}

		if ( null !== $this->original_license_key ) {
			$this->settings_api->update_option(
				'maxmind_license_key',
				$this->original_license_key,
				$now,
				false
			);
		} else {
			$this->settings_api->delete_options( array( 'maxmind_license_key' ) );
		}

		parent::tearDown();
	}

	public function test_scheduled_and_run_now_update_persists_timestamp_and_reflects_in_settings_status(): void {
		$initial_timestamp = '2026-09-01 12:00:00';
		$this->settings_api->update_option(
			'geoip_last_downloaded_at',
			$initial_timestamp,
			$initial_timestamp,
			false
		);

		$initial_status = $this->geoip->get_status();
		$this->assertSame(
			Date::to_iso( $initial_timestamp ),
			$initial_status['lastDownloadedAt']
		);

		// Mock the downloader so network archive downloading is bypassed in test.
		$fake_downloader = $this->createMock( Downloader::class );
		$fake_downloader->expects( $this->once() )
			->method( 'download_database' );

		$this->geoip->set_downloader( $fake_downloader );

		// Execute through the scheduled job / Run Now path.
		$job     = new GeoipUpdateJob( $this->geoip );
		$context = new ExecutionContext(
			'peakurl_geoip_update',
			'run_test_geoip_1',
			1,
			true,
			'2026-09-13 10:00:00'
		);

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		// Verify the persistence layer (settings table) contains a newer timestamp.
		$persisted_timestamp = $this->settings_api->get_option( 'geoip_last_downloaded_at' );
		$this->assertNotNull( $persisted_timestamp );
		$this->assertNotSame( $initial_timestamp, $persisted_timestamp );
		$this->assertGreaterThan(
			strtotime( $initial_timestamp . ' UTC' ),
			strtotime( (string) $persisted_timestamp . ' UTC' )
		);

		// Verify normal Settings -> Location Data status immediately exposes the new timestamp.
		$refreshed_status = $this->geoip->get_status();
		$this->assertSame(
			Date::to_iso( (string) $persisted_timestamp ),
			$refreshed_status['lastDownloadedAt']
		);
	}

	public function test_failed_download_leaves_previous_timestamp_unchanged(): void {
		$initial_timestamp = '2026-09-01 12:00:00';
		$this->settings_api->update_option(
			'geoip_last_downloaded_at',
			$initial_timestamp,
			$initial_timestamp,
			false
		);

		// Set a failing downloader.
		$failing_downloader = $this->createMock( Downloader::class );
		$failing_downloader->expects( $this->once() )
			->method( 'download_database' )
			->willThrowException( new RuntimeException( 'Network download timed out' ) );

		$this->geoip->set_downloader( $failing_downloader );

		$job     = new GeoipUpdateJob( $this->geoip );
		$context = new ExecutionContext(
			'peakurl_geoip_update',
			'run_test_geoip_fail',
			1,
			true,
			'2026-09-13 10:00:00'
		);

		$result = $job->execute( $context );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( 'Network download timed out', (string) $result->get_error() );

		// The persistence layer must NOT have changed.
		$current_timestamp = $this->settings_api->get_option( 'geoip_last_downloaded_at' );
		$this->assertSame( $initial_timestamp, $current_timestamp );

		// Settings status must still show original timestamp.
		$status = $this->geoip->get_status();
		$this->assertSame(
			Date::to_iso( $initial_timestamp ),
			$status['lastDownloadedAt']
		);
	}

	public function test_settings_manual_download_and_cron_converge_on_single_persistence_operation(): void {
		$initial_timestamp = '2026-09-01 08:00:00';
		$this->settings_api->update_option(
			'geoip_last_downloaded_at',
			$initial_timestamp,
			Date::now(),
			false
		);

		$fake_downloader = $this->createMock( Downloader::class );
		$fake_downloader->expects( $this->once() )
			->method( 'download_database' );

		$this->geoip->set_downloader( $fake_downloader );

		// Calling the canonical download_database() directly (which is what SettingsService does).
		$status = $this->geoip->download_database();

		$persisted = $this->settings_api->get_option( 'geoip_last_downloaded_at' );
		$this->assertNotNull( $persisted );
		$this->assertNotSame( $initial_timestamp, $persisted );
		$this->assertSame( Date::to_iso( (string) $persisted ), $status['lastDownloadedAt'] );

		// Subsequent get_status() call also sees the exact same persisted value.
		$fresh_status = $this->geoip->get_status();
		$this->assertSame( $status['lastDownloadedAt'], $fresh_status['lastDownloadedAt'] );
	}
}
