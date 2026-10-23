<?php
/**
 * Unit tests for analytics and activity hooks (Phase 3).
 *
 * Verifies that:
 * - activity_recorded fires when an audit log entry is persisted
 * - analytics_period filters period date ranges
 * - analytics_metrics filters dashboard summary metrics
 * - activity_item filters presentation activity items
 *
 * @package PeakURL\Tests\Unit\Analytics
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Api\LinksApi;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;

class AnalyticsHooksTest extends TestCase {

	private MockObject&AnalyticsRepository $repository;
	private MockObject&PeakURL_DB $db;
	private MockObject&AuthService $auth_service;
	private MockObject&LinksApi $links_api;
	private Roles $roles;
	private Authorization $authorization;
	private AnalyticsService $service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->repository    = $this->createMock( AnalyticsRepository::class );
		$this->db            = $this->createMock( PeakURL_DB::class );
		$this->auth_service  = $this->createMock( AuthService::class );
		$this->links_api     = $this->createMock( LinksApi::class );
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );

		$this->service = new AnalyticsService(
			$this->repository,
			$this->db,
			$this->auth_service,
			$this->roles,
			$this->authorization,
			array( 'site_url' => 'https://peakurl.dev' ),
			$this->links_api
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	public function test_activity_recorded_action_fires_when_activity_persisted(): void {
		$this->repository->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_created', 'Created link test', 'user_1', 'link_1', array( 'alias' => 'test' ) )
			->willReturn( true );

		$captured = null;
		add_action(
			'activity_recorded',
			function ( string $type, ?string $message, ?string $user_id, ?string $link_id, array $metadata ) use ( &$captured ): void {
				$captured = array(
					'type'     => $type,
					'message'  => $message,
					'user_id'  => $user_id,
					'link_id'  => $link_id,
					'metadata' => $metadata,
				);
			},
			10,
			5
		);

		$this->service->record_activity(
			'link_created',
			'Created link test',
			'user_1',
			'link_1',
			array( 'alias' => 'test' )
		);

		$this->assertNotNull( $captured );
		$this->assertSame( 'link_created', $captured['type'] );
		$this->assertSame( 'Created link test', $captured['message'] );
		$this->assertSame( 'user_1', $captured['user_id'] );
		$this->assertSame( 'link_1', $captured['link_id'] );
		$this->assertSame( array( 'alias' => 'test' ), $captured['metadata'] );
	}

	public function test_analytics_period_filter_modifies_period(): void {
		$tz          = new \DateTimeZone( 'America/New_York' );
		$start_dt    = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 )->modify( '-6 days' );
		$target_date = $start_dt->format( 'Y-m-d' );
		$target_utc  = $start_dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$this->repository->method( 'get_analytics_period' )->with( 7 )->willReturn(
			array(
				'start_at'   => $target_utc,
				'start_date' => $target_date,
				'timezone'   => 'UTC',
			)
		);

		add_filter(
			'analytics_period',
			function ( array $period, int $days ) use ( $target_date, $target_utc ): array {
				$period['timezone']   = 'America/New_York';
				$period['start_date'] = $target_date;
				$period['start_at']   = $target_utc;
				$period['custom']     = "filtered_{$days}";
				return $period;
			},
			10,
			2
		);

		$period = $this->service->get_analytics_period( 7 );

		$this->assertSame( 'America/New_York', $period['timezone'] );
		$this->assertSame( 'filtered_7', $period['custom'] );
		$this->assertSame( $target_utc, $period['start_at'] );
		$this->assertSame( $target_date, $period['start_date'] );
	}

	public function test_activity_item_filter_decorates_presentation_activity(): void {
		$raw_row = array(
			'id'         => 'act_100',
			'type'       => 'link_created',
			'message'    => 'Created link my-link',
			'user_id'    => 'u1',
			'metadata'   => json_encode( array( 'link' => array( 'title' => 'My Link' ) ) ),
			'created_at' => '2026-10-23 10:00:00',
		);

		add_filter(
			'activity_item',
			function ( array $item, array $row ): array {
				$item['badgeColor'] = 'green';
				$item['auditRawId'] = $row['id'];
				return $item;
			},
			10,
			2
		);

		$formatted = $this->service->format_activity( $raw_row );

		$this->assertSame( 'act_100', $formatted['id'] );
		$this->assertSame( 'green', $formatted['badgeColor'] );
		$this->assertSame( 'act_100', $formatted['auditRawId'] );
	}

	public function test_analytics_metrics_filter_decorates_summary(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => '2026-10-16 00:00:00',
				'start_date' => '2026-10-16',
				'timezone'   => 'UTC',
			)
		);
		$this->repository->method( 'get_last_month_period' )->willReturn(
			array(
				'start_at'   => '2026-10-09 00:00:00',
				'end_at'     => '2026-10-15 23:59:59',
				'type'       => 'prior_cycle',
				'days'       => 7,
				'start_date' => '2026-10-09',
				'end_date'   => '2026-10-15',
			)
		);
		$this->repository->method( 'get_dashboard_click_totals' )->willReturn(
			array(
				'totalClicks'     => 100,
				'uniqueClicks'    => 80,
				'uniqueClickRate' => 80.0,
			)
		);
		$this->repository->method( 'get_dashboard_link_count' )->willReturn( 15 );
		$this->repository->method( 'group_click_metrics' )->willReturn( array() );
		$this->repository->method( 'query_traffic_series' )->willReturn( array() );

		add_filter(
			'analytics_metrics',
			function ( array $metrics, int $days, ?array $user ): array {
				$metrics['customCalculatedKPI'] = 42;
				$metrics['queriedDays']         = $days;
				return $metrics;
			},
			10,
			3
		);

		$request = new Request( 'GET', '/api/v1/analytics/summary', array(), array() );
		$summary = $this->service->analytics_summary( $request, 7 );

		$this->assertSame( 100, $summary['totalClicks'] );
		$this->assertSame( 42, $summary['customCalculatedKPI'] );
		$this->assertSame( 7, $summary['queriedDays'] );
	}

	public function test_analytics_period_filter_cannot_strip_required_bounds(): void {
		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => '2026-10-16 00:00:00',
				'start_date' => '2026-10-16',
				'timezone'   => 'UTC',
			)
		);

		add_filter(
			'analytics_period',
			function ( array $period ): array {
				// Attacker or buggy plugin tries to strip start_at and start_date.
				return array(
					'broken_key' => 'invalid',
				);
			}
		);

		$period = $this->service->get_analytics_period( 7 );

		// Bounds are preserved defensively.
		$this->assertSame( '2026-10-16 00:00:00', $period['start_at'] );
		$this->assertSame( '2026-10-16', $period['start_date'] );
		$this->assertSame( 'UTC', $period['timezone'] );
	}

	public function test_activity_recorded_observer_exception_does_not_break_activity_persistence(): void {
		$this->repository->expects( $this->once() )
			->method( 'record_activity' )
			->with( 'link_created', 'Created link test', 'u1', 'l1', array() )
			->willReturn( true );

		add_action(
			'activity_recorded',
			function (): void {
				throw new \RuntimeException( 'Simulated buggy analytics observer exception' );
			}
		);

		// Must not throw exception.
		$result = $this->service->record_activity( 'link_created', 'Created link test', 'u1', 'l1', array() );
		$this->assertTrue( $result );
	}

	public function test_analytics_period_filter_rejects_invalid_timezone_and_malformed_dates(): void {
		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => '2026-10-16 00:00:00',
				'start_date' => '2026-10-16',
				'timezone'   => 'UTC',
			)
		);

		add_filter(
			'analytics_period',
			function (): array {
				return array(
					'timezone'   => 'Not/Real_Zone',
					'start_date' => '9999-99-99',
					'start_at'   => '3099-01-01 00:00:00', // future date
				);
			}
		);

		$period = $this->service->get_analytics_period( 7 );

		// All invalid values rejected and reset to canonical.
		$this->assertSame( 'UTC', $period['timezone'] );
		$this->assertSame( '2026-10-16', $period['start_date'] );
		$this->assertSame( '2026-10-16 00:00:00', $period['start_at'] );
	}

	public function test_activity_recorded_does_not_fire_when_persistence_returns_false(): void {
		$this->repository->expects( $this->once() )
			->method( 'record_activity' )
			->willReturn( false );

		$fired = false;
		add_action(
			'activity_recorded',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$result = $this->service->record_activity( 'link_created', 'Failed insert', 'u1', 'l1', array() );

		$this->assertFalse( $result );
		$this->assertFalse( $fired, 'activity_recorded must not fire when persistence returns false' );
	}

	public function test_analytics_period_filter_rejects_inconsistent_and_future_dates(): void {
		$canonical_period = array(
			'start_at'   => '2026-10-16 00:00:00',
			'start_date' => '2026-10-16',
			'timezone'   => 'UTC',
		);
		$this->repository->method( 'get_analytics_period' )->willReturn( $canonical_period );

		add_filter(
			'analytics_period',
			function (): array {
				return array(
					'timezone'   => 'UTC',
					'start_date' => '2099-01-01',
					'start_at'   => '2026-10-16 00:00:00',
				);
			}
		);

		$period = $this->service->get_analytics_period( 7 );

		$this->assertSame( '2026-10-16', $period['start_date'] );
		$this->assertSame( '2026-10-16 00:00:00', $period['start_at'] );
		$this->assertSame( 'UTC', $period['timezone'] );
	}

	public function test_analytics_period_filter_rejects_date_exceeding_supported_days_window(): void {
		$canonical_period = array(
			'start_at'   => '2026-10-16 00:00:00',
			'start_date' => '2026-10-16',
			'timezone'   => 'UTC',
		);
		$this->repository->method( 'get_analytics_period' )->willReturn( $canonical_period );

		add_filter(
			'analytics_period',
			function (): array {
				return array(
					'timezone'   => 'UTC',
					'start_date' => '2020-01-01',
					'start_at'   => '2020-01-01 00:00:00',
				);
			}
		);

		$period = $this->service->get_analytics_period( 7 );

		$this->assertSame( '2026-10-16', $period['start_date'] );
		$this->assertSame( '2026-10-16 00:00:00', $period['start_at'] );
		$this->assertSame( 'UTC', $period['timezone'] );
	}

	public function test_analytics_metrics_filter_restores_contract_violations(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => '2026-10-16 00:00:00',
				'start_date' => '2026-10-16',
				'timezone'   => 'UTC',
			)
		);
		$this->repository->method( 'get_last_month_period' )->willReturn(
			array(
				'start_at'   => '2026-10-09 00:00:00',
				'end_at'     => '2026-10-15 23:59:59',
				'type'       => 'prior_cycle',
				'days'       => 7,
				'start_date' => '2026-10-09',
				'end_date'   => '2026-10-15',
			)
		);
		$this->repository->method( 'get_dashboard_click_totals' )->willReturn(
			array(
				'totalClicks'     => 100,
				'uniqueClicks'    => 80,
				'uniqueClickRate' => 80.0,
			)
		);
		$this->repository->method( 'get_dashboard_link_count' )->willReturn( 15 );
		$this->repository->method( 'group_click_metrics' )->willReturn( array() );
		$this->repository->method( 'query_traffic_series' )->willReturn( array() );

		add_filter(
			'analytics_metrics',
			function ( array $metrics ): array {
				$metrics['totalClicks']     = -50;
				$metrics['devices']         = 'not_an_array';
				$metrics['uniqueClickRate'] = 'not_numeric';
				return $metrics;
			}
		);

		$request = new Request( 'GET', '/api/v1/analytics/summary', array(), array() );
		$summary = $this->service->analytics_summary( $request, 7 );

		$this->assertSame( 100, $summary['totalClicks'] );
		$this->assertIsArray( $summary['devices'] );
		$this->assertSame( 80.0, $summary['uniqueClickRate'] );
	}

	public function test_activity_item_filter_receives_sanitized_public_metadata_only(): void {
		$raw_row = array(
			'id'         => 'act_200',
			'type'       => 'user_created',
			'message'    => 'Created user alice',
			'user_id'    => 'admin_1',
			'metadata'   => json_encode(
				array(
					'user'         => array(
						'id'            => 'u_alice',
						'username'      => 'alice',
						'password_hash' => '$2y$10$secret_hash_not_to_leak',
						'display_name'  => 'Alice Admin',
					),
					'link'         => array(
						'id'             => 'l_10',
						'short_code'     => 'secure',
						'title'          => 'Target Link',
						'destinationUrl' => 'https://example.com/checkout?token=secret123&user=99#fragment-secret',
					),
					'location'     => array(
						'country'     => 'United States',
						'countryCode' => 'US',
						'city'        => 'New York',
						'ip'          => '1.2.3.4',
					),
					'raw_secret'   => 'must_not_be_exposed',
					'server_token' => 'top_secret',
				)
			),
			'created_at' => '2026-10-23 12:00:00',
		);

		$captured_context = null;
		add_filter(
			'activity_item',
			function ( array $item, array $row ) use ( &$captured_context ): array {
				$captured_context = $row;
				return $item;
			},
			10,
			2
		);

		$this->service->format_activity( $raw_row );

		$this->assertNotNull( $captured_context );
		$this->assertSame( 'act_200', $captured_context['id'] );
		$this->assertArrayNotHasKey( 'raw_secret', $captured_context['metadata'] );
		$this->assertArrayNotHasKey( 'server_token', $captured_context['metadata'] );
		$this->assertArrayNotHasKey( 'password_hash', $captured_context['metadata']['user'] );
		$this->assertSame( 'alice', $captured_context['metadata']['user']['username'] );
		$this->assertSame( 'Alice Admin', $captured_context['metadata']['user']['displayName'] );
		// Validated origin only (path, query string, fragment stripped) for privacy.
		$this->assertSame( 'https://example.com', $captured_context['metadata']['link']['destinationUrl'] );
		// Location bounded to country, countryCode, city.
		$this->assertArrayNotHasKey( 'ip', $captured_context['metadata']['location'] );
		$this->assertSame( 'United States', $captured_context['metadata']['location']['country'] );
	}

	public function test_activity_item_filter_omits_malformed_destination_url_with_sensitive_params(): void {
		$raw_row = array(
			'id'         => 'act_201',
			'type'       => 'link_created',
			'message'    => 'Created link with malformed destination',
			'user_id'    => 'admin_1',
			'metadata'   => json_encode(
				array(
					'link' => array(
						'id'             => 'l_malformed',
						'short_code'     => 'badurl',
						'title'          => 'Malformed URL Link',
						'destinationUrl' => 'http:///malformed-host?secret_token=sensitive_token_99999',
					),
				)
			),
			'created_at' => '2026-10-23 12:00:00',
		);

		$captured_context = null;
		add_filter(
			'activity_item',
			function ( array $item, array $row ) use ( &$captured_context ): array {
				$captured_context = $row;
				return $item;
			},
			10,
			2
		);

		$this->service->format_activity( $raw_row );

		$this->assertNotNull( $captured_context );
		$this->assertSame( 'l_malformed', $captured_context['metadata']['link']['id'] );
		$this->assertSame( 'badurl', $captured_context['metadata']['link']['shortCode'] );
		$this->assertSame( 'Malformed URL Link', $captured_context['metadata']['link']['title'] );
		// Malformed raw URL must never be leaked into sanitized metadata.
		$this->assertArrayNotHasKey( 'destinationUrl', $captured_context['metadata']['link'] );
	}

	public function test_analytics_period_filter_rejects_shifting_seven_day_window_forward(): void {
		$now_dt              = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$canonical_start_dt  = $now_dt->setTime( 0, 0, 0 )->modify( '-6 days' );
		$expected_start_date = $canonical_start_dt->format( 'Y-m-d' );
		$expected_start_at   = $canonical_start_dt->format( 'Y-m-d H:i:s' );

		$this->repository->method( 'get_analytics_period' )->with( 7 )->willReturn(
			array(
				'start_at'   => $expected_start_at,
				'start_date' => $expected_start_date,
				'timezone'   => 'UTC',
			)
		);

		// Buggy or malicious filter attempts to shift a 7-day window 3 days forward into the future.
		$future_shifted_start = $canonical_start_dt->modify( '+3 days' )->format( 'Y-m-d' );

		add_filter(
			'analytics_period',
			function ( array $period ) use ( $future_shifted_start ): array {
				$period['start_date'] = $future_shifted_start;
				$period['start_at']   = $future_shifted_start . ' 00:00:00';
				return $period;
			}
		);

		$period = $this->service->get_analytics_period( 7 );

		// Normalization forces the reporting window back to the canonical rolling window ending on today.
		$this->assertSame( 'UTC', $period['timezone'] );
		$this->assertSame( $expected_start_date, $period['start_date'] );
		$this->assertSame( $expected_start_at, $period['start_at'] );
	}

	public function test_analytics_metrics_filter_validates_breakdowns_and_traffic_lengths(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );
		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => '2026-10-17 00:00:00',
				'start_date' => '2026-10-17',
				'timezone'   => 'UTC',
			)
		);
		$this->repository->method( 'get_last_month_period' )->willReturn(
			array(
				'start_at'   => '2026-10-10 00:00:00',
				'end_at'     => '2026-10-16 23:59:59',
				'type'       => 'prior_cycle',
				'days'       => 7,
				'start_date' => '2026-10-10',
				'end_date'   => '2026-10-16',
			)
		);
		$this->repository->method( 'get_dashboard_click_totals' )->willReturn(
			array(
				'totalClicks'     => 50,
				'uniqueClicks'    => 40,
				'uniqueClickRate' => 80.0,
			)
		);
		$this->repository->method( 'get_dashboard_link_count' )->willReturn( 5 );
		$this->repository->method( 'group_click_metrics' )->willReturn(
			array(
				array(
					'name'  => 'Chrome',
					'count' => 30,
				),
			)
		);
		$this->repository->method( 'query_traffic_series_range' )->willReturn(
			array(
				'granularity' => 'day',
				'labels'      => array( '2026-10-17', '2026-10-18' ),
				'clicks'      => array( 20, 30 ),
				'unique'      => array( 15, 25 ),
			)
		);

		add_filter(
			'analytics_metrics',
			function ( array $metrics ): array {
				// Corrupt traffic arrays with incompatible lengths.
				$metrics['traffic']['labels'] = array( '2026-10-17' ); // length 1
				$metrics['traffic']['clicks'] = array( 20, 30 );       // length 2
				$metrics['traffic']['unique'] = array( 15 );           // length 1

				// Corrupt devices with malformed item.
				$metrics['devices'] = array(
					array(
						'invalid_key' => 'Desktop',
					),
				);

				// Provide valid country breakdown with optional code.
				$metrics['countries'] = array(
					array(
						'name'  => 'Canada',
						'count' => 12,
						'code'  => 'CA',
					),
				);

				return $metrics;
			}
		);

		$request = new Request( 'GET', '/api/v1/analytics/summary', array(), array() );
		$summary = $this->service->analytics_summary( $request, 7 );

		// Traffic falls back to canonical because of mismatched array lengths.
		$this->assertCount( 2, $summary['traffic']['labels'] );
		$this->assertCount( 2, $summary['traffic']['clicks'] );
		$this->assertCount( 2, $summary['traffic']['unique'] );

		// Malformed devices falls back to canonical.
		$this->assertSame( 'Chrome', $summary['devices'][0]['name'] );
		$this->assertSame( 30, $summary['devices'][0]['count'] );

		// Valid countries is accepted and preserved.
		$this->assertSame( 'Canada', $summary['countries'][0]['name'] );
		$this->assertSame( 12, $summary['countries'][0]['count'] );
		$this->assertSame( 'CA', $summary['countries'][0]['code'] );
	}

	public function test_analytics_summary_traffic_series_uses_exact_same_filtered_period(): void {
		$admin_user = array(
			'id'   => 'user_admin',
			'role' => 'admin',
		);
		$this->auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$now_utc       = gmdate( 'Y-m-d H:i:s' );
		$canonical_now = gmdate( 'Y-m-d' );

		$this->repository->method( 'get_analytics_period' )->willReturn(
			array(
				'start_at'   => $now_utc,
				'start_date' => $canonical_now,
				'timezone'   => 'UTC',
			)
		);
		$this->repository->method( 'get_last_month_period' )->willReturn(
			array(
				'start_at'   => $now_utc,
				'end_at'     => $now_utc,
				'type'       => 'prior_cycle',
				'days'       => 7,
				'start_date' => $canonical_now,
				'end_date'   => $canonical_now,
			)
		);
		$this->repository->method( 'get_dashboard_click_totals' )->willReturn(
			array(
				'totalClicks'     => 10,
				'uniqueClicks'    => 8,
				'uniqueClickRate' => 80.0,
			)
		);
		$this->repository->method( 'get_dashboard_link_count' )->willReturn( 2 );
		$this->repository->method( 'group_click_metrics' )->willReturn( array() );

		// Verify query_traffic_series_range is called with the exact period arguments.
		$this->repository->expects( $this->once() )
			->method( 'query_traffic_series_range' )
			->with(
				null,
				$canonical_now,
				7,
				$now_utc,
				null,
				'UTC',
				$admin_user,
				'day'
			)
			->willReturn(
				array(
					'granularity' => 'day',
					'labels'      => array( $canonical_now ),
					'clicks'      => array( 10 ),
					'unique'      => array( 8 ),
				)
			);

		$request = new Request( 'GET', '/api/v1/analytics/summary', array(), array() );
		$summary = $this->service->analytics_summary( $request, 7 );

		$this->assertIsArray( $summary['traffic'] );
		$this->assertSame( 10, $summary['traffic']['clicks'][0] );
	}
}
