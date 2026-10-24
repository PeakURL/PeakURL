<?php
/**
 * Unit tests for click tracking options, click attribution, and analytics retention hooks.
 *
 * Verifies that:
 * - click_tracking_options filters deduplication settings and bounds windows
 * - click_attribution validates and customizes supported attribution fields before persistence
 * - analytics_retention_completed notifies observers after confirmed click retention purges
 *
 * @package PeakURL\Tests\Unit\Analytics
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Features\Analytics\Jobs\AnalyticsRetentionJob;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use RuntimeException;
use PDOException;

class ClickTrackingAndRetentionHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&SettingsApi $settings_api;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks;
	private Roles $roles;
	private Authorization $authorization;
	private AnalyticsRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db            = $this->createMock( PeakURL_DB::class );
		$this->settings_api  = $this->createMock( SettingsApi::class );
		$this->geoip         = $this->createMock( Geoip::class );
		$this->webhooks      = $this->createMock( WebhooksService::class );
		$this->roles         = new Roles();
		$this->authorization = new Authorization( $this->roles );

		$this->geoip->method( 'lookup_location' )->willReturn(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'San Francisco',
			)
		);

		$this->repository = new AnalyticsRepository(
			$this->db,
			$this->settings_api,
			$this->geoip,
			$this->roles,
			$this->authorization,
			$this->webhooks
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	private function sample_url(): array {
		return array(
			'id'              => 'url_test_123',
			'short_code'      => 'sample',
			'alias'           => 'sample',
			'title'           => 'Sample Link',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'utm_source'      => 'newsletter',
			'utm_medium'      => 'email',
			'utm_campaign'    => 'spring_sale',
			'utm_term'        => 'discount',
			'utm_content'     => 'header_banner',
		);
	}

	private function sample_request(
		string $method = 'GET',
		array $headers = array(),
		string $ip = '198.51.100.42'
	): Request {
		$server = array(
			'REQUEST_METHOD'  => $method,
			'REMOTE_ADDR'     => $ip,
			'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
		);

		foreach ( $headers as $k => $v ) {
			$header_key            = 'HTTP_' . strtoupper( str_replace( '-', '_', $k ) );
			$server[ $header_key ] = $v;
		}

		return new Request(
			$method,
			'/sample',
			array(),
			array(),
			array(),
			array(),
			$server
		);
	}

	public function test_click_tracking_options_applies_defaults_when_no_filter_registered(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		$this->db->method( 'get_var' )->willReturn( false );
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'clicks', $this->isType( 'array' ) );

		$this->repository->record_click( $url, $request );
	}

	public function test_click_tracking_options_receives_bounded_link_and_context(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		$received_options = null;
		$received_link    = null;
		$received_context = null;

		\add_filter(
			'click_tracking_options',
			function ( array $options, array $link, array $context ) use ( &$received_options, &$received_link, &$received_context ): array {
				$received_options = $options;
				$received_link    = $link;
				$received_context = $context;
				return $options;
			},
			10,
			3
		);

		$this->db->method( 'get_var' )->willReturn( false );
		$this->repository->record_click( $url, $request );

		$this->assertSame(
			array(
				'deduplicate'          => true,
				'deduplication_window' => 2,
			),
			$received_options
		);

		$this->assertSame(
			array( 'id', 'shortCode', 'alias', 'title', 'status' ),
			array_keys( $received_link )
		);
		$this->assertSame( 'url_test_123', $received_link['id'] );
		$this->assertArrayNotHasKey( 'destinationUrl', $received_link );

		$this->assertSame(
			array( 'ip', 'userAgent', 'method' ),
			array_keys( $received_context )
		);
		$this->assertSame( '198.51.100.42', $received_context['ip'] );
		$this->assertSame( 'GET', $received_context['method'] );
	}

	public function test_click_tracking_options_can_disable_deduplication(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_tracking_options',
			fn( array $options ) => array( 'deduplicate' => false )
		);

		// With deduplicate=false, get_var (duplicate query) must NOT be called, and insert must happen.
		$this->db->expects( $this->never() )->method( 'get_var' );
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'clicks', $this->isType( 'array' ) );

		$this->repository->record_click( $url, $request );
	}

	public function test_click_tracking_options_can_configure_custom_deduplication_window(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_tracking_options',
			fn( array $options ) => array(
				'deduplicate'          => true,
				'deduplication_window' => 60,
			)
		);

		$captured_params = null;
		$this->db->expects( $this->once() )
			->method( 'get_var' )
			->willReturnCallback(
				function ( string $sql, array $params ) use ( &$captured_params ) {
					$captured_params = $params;
					return 'clk_existing_duplicate';
				}
			);

		// Because a duplicate was found, insert must NOT be called.
		$this->db->expects( $this->never() )->method( 'insert' );

		$this->repository->record_click( $url, $request );

		$this->assertNotNull( $captured_params );
		$this->assertArrayHasKey( 'threshold', $captured_params );

		$threshold_time = ( new \DateTimeImmutable(
			$captured_params['threshold'],
			new \DateTimeZone( 'UTC' )
		) )->getTimestamp();
		$now_time       = ( new \DateTimeImmutable(
			'now',
			new \DateTimeZone( 'UTC' )
		) )->getTimestamp();
		$diff           = $now_time - $threshold_time;
		$this->assertGreaterThanOrEqual( 59, $diff );
		$this->assertLessThanOrEqual( 61, $diff );
	}

	public function test_click_tracking_options_sanitizes_invalid_types_and_unsupported_keys(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_tracking_options',
			fn() => array(
				'deduplicate'          => 'invalid_not_boolean',
				'deduplication_window' => 'invalid_not_integer',
				'skip_bots'            => false,
				'unknown_setting'      => 'forbidden',
			)
		);

		// With invalid types, defaults (deduplicate=true, window=2) are retained.
		$this->db->expects( $this->once() )
			->method( 'get_var' )
			->willReturn( false );
		$this->db->expects( $this->once() )
			->method( 'insert' );

		$this->repository->record_click( $url, $request );
	}

	public function test_click_tracking_options_bounds_window_range(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_tracking_options',
			fn() => array(
				'deduplicate'          => true,
				'deduplication_window' => 1000000,
			)
		);

		$captured_params = null;
		$this->db->expects( $this->once() )
			->method( 'get_var' )
			->willReturnCallback(
				function ( string $sql, array $params ) use ( &$captured_params ) {
					$captured_params = $params;
					return false;
				}
			);

		$this->repository->record_click( $url, $request );

		$this->assertNotNull( $captured_params );
		$threshold_time = ( new \DateTimeImmutable(
			$captured_params['threshold'],
			new \DateTimeZone( 'UTC' )
		) )->getTimestamp();
		$now_time       = ( new \DateTimeImmutable(
			'now',
			new \DateTimeZone( 'UTC' )
		) )->getTimestamp();
		$diff           = $now_time - $threshold_time;
		// 1,000,000 should be bounded to 86,400 seconds (24 hours).
		$this->assertGreaterThanOrEqual( 86399, $diff );
		$this->assertLessThanOrEqual( 86401, $diff );
	}

	public function test_click_tracking_options_isolates_observer_exceptions(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		$called = false;
		\add_filter(
			'click_tracking_options',
			function () use ( &$called ): array {
				$called = true;
				throw new RuntimeException( 'Tracking options listener crashed' );
			}
		);

		$this->db->method( 'get_var' )->willReturn( false );
		$this->db->expects( $this->once() )->method( 'insert' );

		$this->repository->record_click( $url, $request );
		$this->assertTrue( $called );
	}

	public function test_click_tracking_options_never_runs_for_ineligible_non_get_requests(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request( 'POST' );

		$called = false;
		\add_filter(
			'click_tracking_options',
			function ( array $options ) use ( &$called ): array {
				$called = true;
				return $options;
			}
		);

		$this->db->expects( $this->never() )->method( 'insert' );
		$this->repository->record_click( $url, $request, false );

		$this->assertFalse( $called );
	}

	public function test_click_tracking_options_never_runs_for_prefetch_requests(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request( 'GET', array( 'Sec-Purpose' => 'prefetch' ) );

		$called = false;
		\add_filter(
			'click_tracking_options',
			function ( array $options ) use ( &$called ): array {
				$called = true;
				return $options;
			}
		);

		$this->db->expects( $this->never() )->method( 'insert' );
		$this->repository->record_click( $url, $request );

		$this->assertFalse( $called );
	}

	public function test_click_attribution_persists_calculated_attribution_when_no_filter_registered(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request( 'GET', array( 'Referer' => 'https://www.google.com/search?q=peakurl' ) );

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertNotNull( $inserted );
		$this->assertSame( 'Google.com', $inserted['referrer_name'] );
		$this->assertSame( 'google.com', $inserted['referrer_domain'] );
		$this->assertSame( 'Search', $inserted['referrer_category'] );
		$this->assertSame( 'newsletter', $inserted['utm_source'] );
		$this->assertSame( 'email', $inserted['utm_medium'] );
		$this->assertSame( 'spring_sale', $inserted['utm_campaign'] );
		$this->assertSame( 'discount', $inserted['utm_term'] );
		$this->assertSame( 'header_banner', $inserted['utm_content'] );
	}

	public function test_click_attribution_receives_bounded_link_and_context(): void {
		$url     = $this->sample_url();
		$referer = 'https://example.com/private/landing?token=super_secret_auth_token_999&session=admin_active';
		$request = $this->sample_request( 'GET', array( 'Referer' => $referer ) );

		$received_attribution = null;
		$received_link        = null;
		$received_context     = null;

		\add_filter(
			'click_attribution',
			function ( array $attr, array $link, array $ctx ) use ( &$received_attribution, &$received_link, &$received_context ): array {
				$received_attribution = $attr;
				$received_link        = $link;
				$received_context     = $ctx;
				return $attr;
			},
			10,
			3
		);

		$this->db->method( 'get_var' )->willReturn( false );
		$this->repository->record_click( $url, $request );

		$this->assertSame(
			array(
				'referrer_name',
				'referrer_domain',
				'referrer_category',
				'utm_source',
				'utm_medium',
				'utm_campaign',
				'utm_term',
				'utm_content',
			),
			array_keys( $received_attribution )
		);
		$this->assertSame( 'Example.com', $received_attribution['referrer_name'] );
		$this->assertSame( 'example.com', $received_attribution['referrer_domain'] );
		$this->assertSame( 'Website', $received_attribution['referrer_category'] );

		$this->assertSame(
			array( 'id', 'shortCode', 'alias', 'title', 'status' ),
			array_keys( $received_link )
		);
		$this->assertArrayNotHasKey( 'destinationUrl', $received_link );

		// Strictly verify exact context keys (raw referrer URL and sensitive parameters must be excluded)
		$this->assertSame(
			array( 'ip', 'userAgent', 'method' ),
			array_keys( $received_context )
		);
		$this->assertArrayNotHasKey( 'referrer', $received_context );
		$this->assertSame( '198.51.100.42', $received_context['ip'] );
		$this->assertSame( 'GET', $received_context['method'] );
	}

	public function test_click_attribution_allows_customizing_supported_fields(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'referrer_name'     => 'Affiliate Network',
					'referrer_category' => 'Affiliate',
					'utm_source'        => 'affiliate_partner',
					'utm_campaign'      => 'q4_push',
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertSame( 'Affiliate Network', $inserted['referrer_name'] );
		$this->assertSame( 'Affiliate', $inserted['referrer_category'] );
		$this->assertSame( 'affiliate_partner', $inserted['utm_source'] );
		$this->assertSame( 'q4_push', $inserted['utm_campaign'] );
		// Unmodified fields retained from original
		$this->assertSame( 'email', $inserted['utm_medium'] );
	}

	public function test_click_attribution_preserves_immutable_identity_fields(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'id'            => 'hacked_click_id',
					'url_id'        => 'hacked_url_id',
					'clicked_at'    => '1999-01-01 00:00:00',
					'visitor_hash'  => 'fake_visitor_hash',
					'ip_address'    => '10.0.0.1',
					'user_agent'    => 'Malicious Agent/1.0',
					'custom_column' => 'arbitrary_injection',
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		// Identity fields cannot be overridden by callbacks
		$this->assertNotSame( 'hacked_click_id', $inserted['id'] );
		$this->assertSame( 'url_test_123', $inserted['url_id'] );
		$this->assertNotSame( '1999-01-01 00:00:00', $inserted['clicked_at'] );
		$this->assertNotSame( 'fake_visitor_hash', $inserted['visitor_hash'] );
		$this->assertSame( '198.51.100.42', $inserted['ip_address'] );
		$this->assertStringContainsString( 'Mozilla/5.0', $inserted['user_agent'] );
		$this->assertArrayNotHasKey( 'custom_column', $inserted );
	}

	public function test_click_attribution_rejects_oversized_strings_and_preserves_valid_values(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'referrer_category' => str_repeat( 'X', 65 ),  // limit is 64
					'utm_source'        => str_repeat( 'Y', 121 ), // limit is 120
					'referrer_name'     => str_repeat( 'Z', 192 ), // limit is 191
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		// Reverts to original valid values when length exceeds schema limit
		$this->assertSame( 'newsletter', $inserted['utm_source'] );
		$this->assertNull( $inserted['referrer_category'] );
		$this->assertNull( $inserted['referrer_name'] );
	}

	public function test_click_attribution_handles_null_and_empty_string_properly(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'utm_source'   => null,
					'utm_medium'   => '   ',
					'utm_campaign' => '',
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertNull( $inserted['utm_source'] );
		$this->assertNull( $inserted['utm_medium'] );
		$this->assertNull( $inserted['utm_campaign'] );
	}

	public function test_click_attribution_rejects_invalid_types_and_preserves_valid_values(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'utm_source'   => array( 'nested' => 'array' ),
					'utm_campaign' => 12345,
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertSame( 'newsletter', $inserted['utm_source'] );
		$this->assertSame( 'spring_sale', $inserted['utm_campaign'] );
	}

	public function test_click_attribution_isolates_observer_exceptions(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		$called = false;
		\add_filter(
			'click_attribution',
			function () use ( &$called ): array {
				$called = true;
				throw new RuntimeException( 'Attribution filter crashed' );
			}
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$inserted = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted ): bool {
						$inserted = $payload;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertTrue( $called );
		// Preserves original valid calculated attribution
		$this->assertSame( 'newsletter', $inserted['utm_source'] );
	}

	public function test_click_attribution_maintains_consistency_with_link_clicked_action_and_webhooks(): void {
		$url     = $this->sample_url();
		$request = $this->sample_request();

		\add_filter(
			'click_attribution',
			fn( array $attr ) => array_merge(
				$attr,
				array(
					'utm_campaign' => 'black_friday_exclusive',
				)
			)
		);

		$this->db->method( 'get_var' )->willReturn( false );

		$action_payload  = null;
		$webhook_payload = null;

		\add_action(
			'link_clicked',
			function ( array $u, array $click, Request $r ) use ( &$action_payload ): void {
				$action_payload = $click;
			},
			10,
			3
		);

		$this->webhooks->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with(
				'link.clicked',
				$this->isType( 'array' ),
				null,
				null,
				$this->callback(
					function ( array $click ) use ( &$webhook_payload ): bool {
						$webhook_payload = $click;
						return true;
					}
				)
			);

		$this->repository->record_click( $url, $request );

		$this->assertNotNull( $action_payload );
		$this->assertNotNull( $webhook_payload );
		$this->assertSame( 'black_friday_exclusive', $action_payload['utm_campaign'] );
		$this->assertSame( 'black_friday_exclusive', $webhook_payload['utm_campaign'] );
	}

	private function create_analytics_service(): AnalyticsService {
		$auth_service = $this->createMock( AuthService::class );
		$links_api    = $this->createMock( LinksApi::class );

		return new AnalyticsService(
			$this->repository,
			$this->db,
			$auth_service,
			$this->roles,
			$this->authorization,
			array( 'site_url' => 'https://peakurl.dev' ),
			$links_api
		);
	}

	public function test_analytics_retention_completed_fires_on_service_purge(): void {
		$service = $this->create_analytics_service();

		// Repository query deletes 42 records
		$this->db->expects( $this->once() )
			->method( 'query' )
			->willReturn( 42 );

		$captured_summary = null;
		\add_action(
			'analytics_retention_completed',
			function ( array $summary ) use ( &$captured_summary ): void {
				$captured_summary = $summary;
			}
		);

		$deleted = $service->purge_old_clicks( '2026-09-01 00:00:00', 500 );

		$this->assertSame( 42, $deleted );
		$this->assertSame(
			array(
				'purgedClicks' => 42,
				'cutoff'       => '2026-09-01 00:00:00',
				'limit'        => 500,
			),
			$captured_summary
		);
	}

	public function test_analytics_retention_completed_fires_on_zero_affected_rows(): void {
		$service = $this->create_analytics_service();

		$this->db->expects( $this->once() )
			->method( 'query' )
			->willReturn( 0 );

		$captured_summary = null;
		\add_action(
			'analytics_retention_completed',
			function ( array $summary ) use ( &$captured_summary ): void {
				$captured_summary = $summary;
			}
		);

		$deleted = $service->purge_old_clicks( '2026-09-01 00:00:00', 1000 );

		$this->assertSame( 0, $deleted );
		$this->assertSame(
			array(
				'purgedClicks' => 0,
				'cutoff'       => '2026-09-01 00:00:00',
				'limit'        => 1000,
			),
			$captured_summary
		);
	}

	public function test_analytics_retention_completed_does_not_fire_on_database_exception(): void {
		$service = $this->create_analytics_service();

		$this->db->expects( $this->once() )
			->method( 'query' )
			->willThrowException( new PDOException( 'Database connection lost' ) );

		$called = false;
		\add_action(
			'analytics_retention_completed',
			function () use ( &$called ): void {
				$called = true;
			}
		);

		$this->expectException( PDOException::class );
		try {
			$service->purge_old_clicks( '2026-09-01 00:00:00', 1000 );
		} finally {
			$this->assertFalse( $called );
		}
	}

	public function test_analytics_retention_completed_isolates_observer_exception_in_service(): void {
		$service = $this->create_analytics_service();

		$this->db->method( 'query' )->willReturn( 15 );

		$called = false;
		\add_action(
			'analytics_retention_completed',
			function () use ( &$called ): void {
				$called = true;
				throw new RuntimeException( 'Retention observer crashed' );
			}
		);

		$deleted = $service->purge_old_clicks( '2026-09-01 00:00:00', 1000 );

		$this->assertTrue( $called );
		$this->assertSame( 15, $deleted );
	}

	public function test_analytics_retention_job_fires_hook_via_service_exactly_once(): void {
		$service = $this->create_analytics_service();

		$this->settings_api->method( 'get_option' )->willReturnCallback(
			function ( string $key ): ?string {
				if ( 'analytics_retention_days' === $key ) {
					return '30';
				}
				if ( 'trash_retention_days' === $key ) {
					return '0'; // Skip trash purging in this test
				}
				return null;
			}
		);

		$this->db->method( 'query' )->willReturn( 25 );

		$dispatches = array();
		\add_action(
			'analytics_retention_completed',
			function ( array $summary ) use ( &$dispatches ): void {
				$dispatches[] = $summary;
			}
		);

		$job     = new AnalyticsRetentionJob( $this->db, $this->settings_api, null, $service );
		$context = new ExecutionContext( 'peakurl_analytics_retention', 'run_test_1', 1, false, gmdate( 'Y-m-d H:i:s' ) );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $dispatches );
		$this->assertSame( 25, $dispatches[0]['purgedClicks'] );
		$this->assertSame( 1000, $dispatches[0]['limit'] );
	}

	public function test_analytics_retention_job_fires_hook_via_database_fallback_exactly_once(): void {
		$this->settings_api->method( 'get_option' )->willReturnCallback(
			function ( string $key ): ?string {
				if ( 'analytics_retention_days' === $key ) {
					return '60';
				}
				if ( 'trash_retention_days' === $key ) {
					return '0';
				}
				return null;
			}
		);

		$this->db->expects( $this->once() )
			->method( 'query' )
			->willReturn( 80 );

		$dispatches = array();
		\add_action(
			'analytics_retention_completed',
			function ( array $summary ) use ( &$dispatches ): void {
				$dispatches[] = $summary;
			}
		);

		// Service is null; job uses direct DB fallback
		$job     = new AnalyticsRetentionJob( $this->db, $this->settings_api, null, null );
		$context = new ExecutionContext( 'peakurl_analytics_retention', 'run_test_2', 1, false, gmdate( 'Y-m-d H:i:s' ) );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $dispatches );
		$this->assertSame( 80, $dispatches[0]['purgedClicks'] );
		$this->assertSame( 1000, $dispatches[0]['limit'] );
	}

	public function test_analytics_retention_completed_does_not_fire_when_retention_disabled(): void {
		$this->settings_api->method( 'get_option' )->willReturnCallback(
			function ( string $key ): ?string {
				if ( 'analytics_retention_days' === $key ) {
					return '0'; // Disabled
				}
				return '0';
			}
		);

		$called = false;
		\add_action(
			'analytics_retention_completed',
			function () use ( &$called ): void {
				$called = true;
			}
		);

		$job     = new AnalyticsRetentionJob( $this->db, $this->settings_api, null, null );
		$context = new ExecutionContext( 'peakurl_analytics_retention', 'run_test_3', 1, false, gmdate( 'Y-m-d H:i:s' ) );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $called );
	}

	public function test_analytics_retention_job_fallback_isolates_observer_exception(): void {
		$this->settings_api->method( 'get_option' )->willReturnCallback(
			function ( string $key ): ?string {
				if ( 'analytics_retention_days' === $key ) {
					return '15';
				}
				return '0';
			}
		);

		$this->db->method( 'query' )->willReturn( 10 );

		$called = false;
		\add_action(
			'analytics_retention_completed',
			function () use ( &$called ): void {
				$called = true;
				throw new RuntimeException( 'Fallback retention observer crashed' );
			}
		);

		$job     = new AnalyticsRetentionJob( $this->db, $this->settings_api, null, null );
		$context = new ExecutionContext( 'peakurl_analytics_retention', 'run_test_4', 1, false, gmdate( 'Y-m-d H:i:s' ) );
		$result  = $job->execute( $context );

		$this->assertTrue( $called );
		$this->assertTrue( $result->is_success() );
	}
}
