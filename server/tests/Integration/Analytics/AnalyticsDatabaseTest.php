<?php
/**
 * Integration tests for Analytics service and repository.
 *
 * @package PeakURL\Tests\Integration\Analytics
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Analytics;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Repository;
use PeakURL\Features\Analytics\Service;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use ReflectionClass;

class AnalyticsDatabaseTest extends TestCase {

	public function test_peakurl_db_has_get_results_and_get_var(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$this->assertTrue( $ref->hasMethod( 'get_results' ) );
		$this->assertTrue( $ref->hasMethod( 'get_var' ) );
		$this->assertTrue( $ref->hasMethod( 'get_row' ) );
		$this->assertTrue( $ref->hasMethod( 'query' ) );
	}

	public function test_peakurl_db_does_not_have_obsolete_query_all(): void {
		$ref = new ReflectionClass( PeakURL_DB::class );

		$this->assertFalse( $ref->hasMethod( 'query_all' ) );
		$this->assertFalse( $ref->hasMethod( 'query_value' ) );
		$this->assertFalse( $ref->hasMethod( 'prepare' ) );
	}

	public function test_link_location_executes_authorization_without_type_error(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		$user = array(
			'id'   => 'user-admin-1',
			'role' => 'admin',
		);

		$auth_service->method( 'get_current_user' )
			->willReturn( $user );

		$db->method( 'get_row' )
			->willReturn(
				array(
					'id'         => 'link-123',
					'user_id'    => 'user-admin-1',
					'created_at' => '2026-09-01 10:00:00',
				)
			);

		$db->method( 'get_results' )
			->willReturn( array() );

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request  = new Request( 'GET', '/analytics/link-123/location', array(), array() );
		$location = $service->link_location( $request, 'link-123', '7d' );

		$this->assertIsArray( $location );
		$this->assertArrayHasKey( 'countries', $location );
		$this->assertArrayHasKey( 'cities', $location );
	}

	public function test_link_click_history_days_groups_by_timezone_correctly(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$settings_api->method( 'get_option' )
			->with( 'site_timezone' )
			->willReturn( 'Europe/London' );

		$db->method( 'get_results' )
			->willReturn(
				array(
					array(
						'clicked_at'  => '2026-09-14 23:30:00',
						'visitor_key' => 'visitor-1',
					),
					array(
						'clicked_at'  => '2026-09-14 23:45:00',
						'visitor_key' => 'visitor-1',
					),
				)
			);

		$repository = new Repository( $db, $settings_api, $geoip, $roles, $authorization );
		$days       = $repository->get_link_click_history_days( 'link-123' );

		$this->assertCount( 1, $days );
		// In Europe/London on Sept 14 (BST: UTC+1), 23:30 UTC is Sept 15 00:30 BST
		$this->assertSame( '2026-09-15', $days[0]['date'] );
		$this->assertSame( 2, $days[0]['totalClicks'] );
		$this->assertSame( 1, $days[0]['uniqueClicks'] );
	}

	public function test_link_stats_rejects_cross_account_access_for_non_admin_with_403(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		// User is an editor (has view_analytics, but does NOT own the link).
		$user = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		// The link belongs to another user (admin).
		$db->method( 'get_row' )->willReturn(
			array(
				'id'         => 'link-admin-1',
				'user_id'    => 'user-admin-99',
				'created_at' => '2026-09-01 10:00:00',
			)
		);

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request = new Request( 'GET', '/analytics/link-admin-1/stats', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to view analytics for this link.' );

		$service->link_stats( $request, 'link-admin-1' );
	}

	public function test_link_location_rejects_cross_account_access_for_non_admin_with_403(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		// User is an editor (has view_analytics, but does NOT own the link).
		$user = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		// The link belongs to another user (admin).
		$db->method( 'get_row' )->willReturn(
			array(
				'id'         => 'link-admin-1',
				'user_id'    => 'user-admin-99',
				'created_at' => '2026-09-01 10:00:00',
			)
		);

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request = new Request( 'GET', '/analytics/link-admin-1/location', array(), array() );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessage( 'You do not have permission to view analytics for this link.' );

		$service->link_location( $request, 'link-admin-1' );
	}

	public function test_link_stats_allows_admin_access_to_cross_account_link(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		// User is admin.
		$user = array(
			'id'   => 'user-admin-1',
			'role' => 'admin',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		// The link belongs to an editor.
		$db->method( 'get_row' )->willReturn(
			array(
				'id'         => 'link-editor-1',
				'user_id'    => 'user-editor-55',
				'created_at' => '2026-09-01 10:00:00',
			)
		);
		$db->method( 'get_results' )->willReturn( array() );
		$db->method( 'get_var' )->willReturn( '0' );

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request = new Request( 'GET', '/analytics/link-editor-1/stats', array(), array() );
		$stats   = $service->link_stats( $request, 'link-editor-1', '7d' );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'totalClicks', $stats );
		$this->assertArrayHasKey( 'traffic', $stats );
	}

	public function test_link_stats_allows_owner_access_to_own_link(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		// User is editor and is the owner.
		$user = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		$db->method( 'get_row' )->willReturn(
			array(
				'id'         => 'link-editor-1',
				'user_id'    => 'user-editor-1',
				'created_at' => '2026-09-01 10:00:00',
			)
		);
		$db->method( 'get_results' )->willReturn( array() );
		$db->method( 'get_var' )->willReturn( '0' );

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request = new Request( 'GET', '/analytics/link-editor-1/stats', array(), array() );
		$stats   = $service->link_stats( $request, 'link-editor-1', '7d' );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'totalClicks', $stats );
		$this->assertArrayHasKey( 'traffic', $stats );
	}

	public function test_link_stats_returns_null_when_link_not_found(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		$user = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		// Link does not exist in database.
		$db->method( 'get_row' )->willReturn( null );

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request = new Request( 'GET', '/analytics/nonexistent/stats', array(), array() );
		$stats   = $service->link_stats( $request, 'nonexistent' );

		$this->assertNull( $stats );
	}

	public function test_link_location_returns_null_when_link_not_found(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$auth_service  = $this->createMock( AuthService::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		$user = array(
			'id'   => 'user-editor-1',
			'role' => 'editor',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $user );

		$db->method( 'get_row' )->willReturn( null );

		$service = new Service(
			$repository,
			$db,
			$auth_service,
			$roles,
			$authorization,
			array(),
			new LinksApi( $db )
		);

		$request  = new Request( 'GET', '/analytics/nonexistent/location', array(), array() );
		$location = $service->link_location( $request, 'nonexistent' );

		$this->assertNull( $location );
	}

	public function test_record_click_persists_raw_user_agent_and_normalized_classification_fields(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		$geoip->method( 'lookup_location' )->willReturn(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'New York',
			)
		);

		// Dedup check: no recent click.
		$db->method( 'get_var' )->willReturn( false );

		$captured_payload = null;
		$db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$captured_payload ): bool {
						$captured_payload = $payload;
						return true;
					}
				)
			);

		$raw_ua  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
		$request = new Request(
			'GET',
			'/test-short-code',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_USER_AGENT' => $raw_ua,
				'REMOTE_ADDR'     => '192.0.2.1',
			)
		);

		$url = array(
			'id'           => 'url-test-123',
			'utm_source'   => null,
			'utm_medium'   => null,
			'utm_campaign' => null,
		);

		$repository->record_click( $url, $request );

		$this->assertNotNull( $captured_payload );
		$this->assertSame( $raw_ua, $captured_payload['user_agent'] );
		$this->assertSame( 'Safari', $captured_payload['browser'] );
		$this->assertSame( 'iOS', $captured_payload['operating_system'] );
		$this->assertSame( 'Mobile', $captured_payload['device'] );
		$this->assertSame( 'US', $captured_payload['country_code'] );
		$this->assertNotEmpty( $captured_payload['visitor_hash'] );
	}

	public function test_record_click_still_records_bot_requests_with_fallback_classification(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		$geoip->method( 'lookup_location' )->willReturn(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			)
		);

		$db->method( 'get_var' )->willReturn( false );

		$captured_payload = null;
		$db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$captured_payload ): bool {
						$captured_payload = $payload;
						return true;
					}
				)
			);

		$raw_bot_ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$request    = new Request(
			'GET',
			'/test-short-code',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_USER_AGENT' => $raw_bot_ua,
				'REMOTE_ADDR'     => '66.249.66.1',
			)
		);

		$url = array(
			'id'           => 'url-test-456',
			'utm_source'   => null,
			'utm_medium'   => null,
			'utm_campaign' => null,
		);

		$repository->record_click( $url, $request );

		$this->assertNotNull( $captured_payload );
		$this->assertSame( $raw_bot_ua, $captured_payload['user_agent'] );
		$this->assertSame( 'Browser', $captured_payload['browser'] );
		$this->assertSame( 'Unknown OS', $captured_payload['operating_system'] );
		$this->assertSame( 'Unknown Device', $captured_payload['device'] );
	}

	public function test_record_click_suppresses_prefetch_requests(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$settings_api  = $this->createMock( SettingsApi::class );
		$geoip         = $this->createMock( Geoip::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$repository    = new Repository( $db, $settings_api, $geoip, $roles, $authorization );

		// insert should NEVER be called on prefetch
		$db->expects( $this->never() )->method( 'insert' );

		$request = new Request(
			'GET',
			'/test-short-code',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
				'HTTP_PURPOSE'    => 'prefetch',
			)
		);

		$url = array( 'id' => 'url-test-789' );
		$repository->record_click( $url, $request );
	}
}
