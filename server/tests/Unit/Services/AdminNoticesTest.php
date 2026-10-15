<?php
/**
 * Unit tests for AdminNotices service.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Services\AdminNotices;
use PeakURL\Services\AdminNotices\Registry;

class AdminNoticesTest extends TestCase {

	public function test_get_notices_does_not_return_location_notice_when_unconfigured_or_unready(): void {
		$service = new AdminNotices();

		$context = array(
			'user'         => array(
				'id'   => '1',
				'role' => 'admin',
			),
			'capabilities' => array(
				'manageUpdates'      => false,
				'manageLocationData' => true,
			),
			'geoipStatus'  => array(
				'locationAnalyticsReady' => false,
				'credentialsConfigured'  => false,
			),
		);

		$notices = $service->get_notices( $context );

		$location_notices = array_filter(
			$notices,
			static fn( array $notice ) => 'location_data_setup' === ( $notice['id'] ?? null )
		);

		$this->assertEmpty(
			$location_notices,
			'Global admin notices must not contain location_data_setup notice.'
		);
	}

	public function test_get_notices_returns_update_notice_when_update_available(): void {
		$service = new AdminNotices();

		$context = array(
			'user'         => array(
				'id'   => '1',
				'role' => 'admin',
			),
			'capabilities' => array(
				'manageUpdates' => true,
			),
			'updateStatus' => array(
				'updateAvailable' => true,
				'latestVersion'   => '1.8.0',
				'currentVersion'  => '1.7.1',
			),
		);

		$notices = $service->get_notices( $context );

		$update_notices = array_filter(
			$notices,
			static fn( array $item ) => 'update_available' === ( $item['id'] ?? null )
		);

		$this->assertNotEmpty( $update_notices, 'update_available notice should be registered.' );
		$update_notice = reset( $update_notices );
		$this->assertSame( 'info', $update_notice['type'] );
		$this->assertStringContainsString( '1.8.0', $update_notice['title'] );
	}

	public function test_admin_notices_action_and_filter_hooks_remain_functional(): void {
		$service = new AdminNotices();

		\add_action(
			'admin_notices',
			static function ( Registry $registry, array $context ): void {
				$registry->add(
					array(
						'id'      => 'custom_hook_notice',
						'type'    => 'info',
						'title'   => 'Custom Extension Notice',
						'message' => 'Custom notice message.',
					)
				);
			},
			10,
			2
		);

		\add_filter(
			'admin_notice_items',
			static function ( array $notices, array $context ): array {
				foreach ( $notices as &$notice ) {
					if ( 'custom_hook_notice' === ( $notice['id'] ?? null ) ) {
						$notice['title'] = 'Filtered Custom Title';
					}
				}
				return $notices;
			},
			10,
			2
		);

		$notices = $service->get_notices( array() );

		$custom_notices = array_filter(
			$notices,
			static fn( array $item ) => 'custom_hook_notice' === ( $item['id'] ?? null )
		);

		$this->assertNotEmpty( $custom_notices );
		$custom_notice = reset( $custom_notices );
		$this->assertSame( 'Filtered Custom Title', $custom_notice['title'] );
	}

	public function test_system_service_notice_context_does_not_fetch_geoip_status(): void {
		$db             = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$connection     = $this->createMock( \PeakURL\Services\Database\Connection::class );
		$auth_service   = $this->createMock( \PeakURL\Features\Auth\Service::class );
		$settings_api   = $this->createMock( \PeakURL\Api\SettingsApi::class );
		$geoip_service  = $this->createMock( \PeakURL\Services\Geoip::class );
		$mailer_service = $this->createMock( \PeakURL\Services\Mailer::class );
		$schema_service = $this->createMock( \PeakURL\Services\Database\Schema::class );
		$i18n_service   = $this->createMock( \PeakURL\Services\I18n::class );
		$roles          = $this->createMock( \PeakURL\Core\Auth\Roles::class );
		$authorization  = $this->createMock( \PeakURL\Core\Auth\Authorization::class );

		// Geoip service get_status must NEVER be called solely for notice context
		$geoip_service->expects( $this->never() )->method( 'get_status' );
		$roles->method( 'has_capability' )->willReturn( true );

		$system_service = new \PeakURL\Features\System\Service(
			$db,
			$connection,
			$auth_service,
			$settings_api,
			$geoip_service,
			$mailer_service,
			$schema_service,
			$i18n_service,
			$roles,
			$authorization,
			array()
		);

		$user    = array(
			'id'   => '1',
			'role' => 'admin',
		);
		$context = $system_service->get_admin_notice_context( $user );

		$this->assertArrayNotHasKey( 'geoipStatus', $context, 'Notice context must not include geoipStatus.' );
		$this->assertArrayNotHasKey( 'manageLocationData', $context['capabilities'], 'Capabilities must not check manageLocationData.' );
	}
}
