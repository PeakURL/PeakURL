<?php
/**
 * Unit tests for the geoip_location extensibility filter hook.
 *
 * Verifies that:
 * 1. Default MaxMind behavior is preserved when no filter is registered.
 * 2. Successful valid location override works for external provider plugins.
 * 3. Plugins returning the original location unchanged preserve baseline data.
 * 4. Invalid return types and malformed payloads safely fall back to default MaxMind data.
 * 5. Invalid or unsupported country codes safely fall back to default MaxMind data.
 * 6. Empty and null location fields are handled properly without corruption.
 * 7. Exception handling isolates callback failures and falls back safely with sanitized logs.
 * 8. Private, reserved, and invalid IP addresses never trigger external callbacks.
 * 9. Correct propagation of filtered location into recorded click analytics data.
 * 10. Existing analytics recording and webhook dispatching remain completely unchanged.
 * 11. Multiple registered callbacks respect execution priority and documented filter semantics.
 *
 * @package PeakURL\Tests\Unit\Services
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Geoip\Context;
use PeakURL\Services\Geoip\Lookup;
use PeakURL\Tests\Support\GeoipTestFixture;

/**
 * GeoipLocationFilterTest — verify the geoip_location extensibility contract.
 *
 * @since 1.8.0
 */
class GeoipLocationFilterTest extends TestCase {

	/**
	 * Temporary directory for synthetic GeoLite2 City database.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Path to synthetic MMDB file.
	 *
	 * @var string
	 */
	private string $mmdb_path;

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Direct GeoIP lookup instance.
	 *
	 * @var Lookup
	 */
	private Lookup $lookup;

	/**
	 * GeoIP public service facade.
	 *
	 * @var Geoip
	 */
	private Geoip $geoip;

	/**
	 * Set up test environment before each test method.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->temp_dir = sys_get_temp_dir() . '/peakurl_geoip_test_' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temp_dir . '/geoip', 0755, true );
		$this->mmdb_path = $this->temp_dir . '/geoip/GeoLite2-City.mmdb';

		GeoipTestFixture::generate_city_mmdb( $this->mmdb_path );

		$config = array(
			'PEAKURL_GEOIP_DB_PATH' => $this->mmdb_path,
			'PEAKURL_AUTH_KEY'      => 'test_key_01234567890123456789012345678901',
			'PEAKURL_AUTH_SALT'     => 'test_salt_01234567890123456789012345678901',
		);

		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = new SettingsApi( $db );
		$crypto       = new Crypto( $config );

		$this->context = new Context( $config, $settings_api, $crypto );
		$this->lookup  = new Lookup( $this->context );
		$this->geoip   = new Geoip( $config, $settings_api, $crypto );
	}

	/**
	 * Clean up test fixtures and remove temporary files after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();

		if ( isset( $this->context ) ) {
			$this->context->close_reader();
		}

		if ( file_exists( $this->mmdb_path ) ) {
			unlink( $this->mmdb_path );
		}
		if ( is_dir( $this->temp_dir . '/geoip' ) ) {
			rmdir( $this->temp_dir . '/geoip' );
		}
		if ( is_dir( $this->temp_dir ) ) {
			rmdir( $this->temp_dir );
		}

		parent::tearDown();
	}

	/**
	 * 1. Default MaxMind behavior is preserved when no filter is registered.
	 *
	 * @return void
	 */
	public function test_default_maxmind_behavior_with_no_filter_registered(): void {
		$geo = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$geo
		);

		$direct_geo = $this->lookup->lookup_location( '8.8.8.8' );
		$this->assertSame( $geo, $direct_geo );

		// When database is not available, default returns all nulls without error.
		$empty_context = new Context(
			array( 'PEAKURL_GEOIP_DB_PATH' => '/nonexistent/path/db.mmdb' ),
			$this->createMock( SettingsApi::class ),
			new Crypto(
				array(
					'PEAKURL_AUTH_KEY'  => 'key_01234567890123456789012345678901',
					'PEAKURL_AUTH_SALT' => 'salt_01234567890123456789012345678901',
				)
			)
		);
		$empty_lookup  = new Lookup( $empty_context );
		$empty_geo     = $empty_lookup->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$empty_geo
		);
	}

	/**
	 * 1b. Registered provider returns valid location data when the local MaxMind database is unavailable.
	 *
	 * Verifies that installations without a downloaded or configured MaxMind database can
	 * still resolve geographic location attributes via the geoip_location filter.
	 *
	 * @return void
	 */
	public function test_registered_provider_returns_valid_location_when_maxmind_database_unavailable(): void {
		$unavailable_config = array(
			'PEAKURL_GEOIP_DB_PATH' => $this->temp_dir . '/nonexistent_geoip_dir/missing.mmdb',
			'PEAKURL_AUTH_KEY'      => 'test_key_01234567890123456789012345678901',
			'PEAKURL_AUTH_SALT'     => 'test_salt_01234567890123456789012345678901',
		);

		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = new SettingsApi( $db );
		$crypto       = new Crypto( $unavailable_config );
		$geoip        = new Geoip( $unavailable_config, $settings_api, $crypto );

		// Before hook: default is all nulls without database.
		$baseline = $geoip->lookup_location( '8.8.8.8' );
		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$baseline
		);

		$captured_input_location = null;
		$captured_input_ip       = null;

		\add_filter(
			'geoip_location',
			function ( array $location, string $ip_address ) use ( &$captured_input_location, &$captured_input_ip ): array {
				$captured_input_location = $location;
				$captured_input_ip       = $ip_address;

				return array(
					'country_code' => 'NL',
					'country_name' => 'Netherlands',
					'city_name'    => 'Amsterdam',
				);
			},
			10,
			2
		);

		$result = $geoip->lookup_location( '8.8.8.8' );

		// Callback receives the unconfigured baseline (all nulls) and public IP.
		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$captured_input_location
		);
		$this->assertSame( '8.8.8.8', $captured_input_ip );

		// Result reflects provider override.
		$this->assertSame(
			array(
				'country_code' => 'NL',
				'country_name' => 'Netherlands',
				'city_name'    => 'Amsterdam',
			),
			$result
		);
	}

	/**
	 * 2. Successful valid location override works for external provider plugins.
	 *
	 * @return void
	 */
	public function test_successful_valid_location_override(): void {
		$captured_location = null;
		$captured_ip       = null;

		\add_filter(
			'geoip_location',
			function ( array $location, string $ip_address ) use ( &$captured_location, &$captured_ip ): array {
				$captured_location = $location;
				$captured_ip       = $ip_address;

				return array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
					'city_name'    => 'Toronto',
				);
			},
			10,
			2
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'CA',
				'country_name' => 'Canada',
				'city_name'    => 'Toronto',
			),
			$result
		);

		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$captured_location
		);
		$this->assertSame( '8.8.8.8', $captured_ip );
	}

	/**
	 * 3. Plugins returning the original location unchanged preserve baseline data.
	 *
	 * @return void
	 */
	public function test_plugin_returning_original_location_unchanged(): void {
		$invoked = false;

		\add_filter(
			'geoip_location',
			function ( array $location, string $ip_address ) use ( &$invoked ): array {
				$invoked = true;
				return $location;
			},
			10,
			2
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertTrue( $invoked );
		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$result
		);
	}

	/**
	 * 3b. Provider failures or unresolved lookups returning original location retain MaxMind fallback.
	 *
	 * Demonstrates that returning the unmodified location argument allows PeakURL to preserve
	 * the default MaxMind GeoLite2 lookup result when a provider cannot resolve an IP or encounters an error.
	 *
	 * @return void
	 */
	public function test_unresolved_lookup_or_provider_failure_returning_original_location_preserves_maxmind(): void {
		$attempts = 0;

		\add_filter(
			'geoip_location',
			function ( array $location, string $ip_address ) use ( &$attempts ): array {
				$attempts++;
				// Simulate provider API error, rate limit, or IP not found in provider database.
				// By returning $location (rather than null or an empty array), MaxMind baseline is retained.
				return $location;
			},
			10,
			2
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame( 1, $attempts );
		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$result
		);
	}

	/**
	 * Data provider for invalid return types and malformed payloads.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_location_payload_provider(): array {
		return array(
			'null return'                    => array( null ),
			'boolean false'                  => array( false ),
			'boolean true'                   => array( true ),
			'integer return'                 => array( 123 ),
			'string return'                  => array( 'US' ),
			'stdClass object'                => array( new \stdClass() ),
			'empty array'                    => array( array() ),
			'missing country_code and name'  => array( array( 'city_name' => 'Toronto' ) ),
			'missing country_name'           => array(
				array(
					'country_code' => 'CA',
					'city_name'    => 'Toronto',
				),
			),
			'missing city_name'              => array(
				array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
				),
			),
			'unrecognized extra key'         => array(
				array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
					'city_name'    => 'Toronto',
					'region_name'  => 'Ontario',
				),
			),
			'integer country_code'           => array(
				array(
					'country_code' => 123,
					'country_name' => 'Canada',
					'city_name'    => 'Toronto',
				),
			),
			'array country_name'             => array(
				array(
					'country_code' => 'CA',
					'country_name' => array( 'Canada' ),
					'city_name'    => 'Toronto',
				),
			),
			'object city_name'               => array(
				array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
					'city_name'    => new \stdClass(),
				),
			),
			'oversized country_name (> 120)' => array(
				array(
					'country_code' => 'CA',
					'country_name' => str_repeat( 'c', 121 ),
					'city_name'    => 'Toronto',
				),
			),
			'oversized city_name (> 120)'    => array(
				array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
					'city_name'    => str_repeat( 't', 121 ),
				),
			),
		);
	}

	/**
	 * 4. Invalid return types and malformed payloads safely fall back to default MaxMind data.
	 *
	 * @param mixed $malformed_payload
	 * @return void
	 */
	#[DataProvider( 'invalid_location_payload_provider' )]
	public function test_invalid_return_types_and_malformed_payloads_fallback_to_default( mixed $malformed_payload ): void {
		\add_filter(
			'geoip_location',
			fn() => $malformed_payload
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$result
		);
	}

	/**
	 * Data provider for invalid or unsupported ISO country codes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_country_codes_provider(): array {
		return array(
			'three letters'        => array( 'USA' ),
			'single letter'        => array( 'U' ),
			'numeric code'         => array( '12' ),
			'symbols'              => array( '??' ),
			'anonymous proxy (A1)' => array( 'A1' ),
			'satellite (A2)'       => array( 'A2' ),
			'other country (O1)'   => array( 'O1' ),
			'tor exit node (T1)'   => array( 'T1' ),
			'unknown pseudo (XX)'  => array( 'XX' ),
			'oversized string'     => array( 'TOOLONG' ),
		);
	}

	/**
	 * 5. Invalid or unsupported country codes safely fall back to default MaxMind data.
	 *
	 * @param string $invalid_code
	 * @return void
	 */
	#[DataProvider( 'invalid_country_codes_provider' )]
	public function test_invalid_or_unsupported_country_codes_fallback_to_default( string $invalid_code ): void {
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => $invalid_code,
				'country_name' => 'Custom Country',
				'city_name'    => 'Custom City',
			)
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$result
		);
	}

	/**
	 * 5b. Valid lowercase ISO country code is normalized to uppercase.
	 *
	 * @return void
	 */
	public function test_lowercase_valid_country_code_normalized(): void {
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'gb',
				'country_name' => 'United Kingdom',
				'city_name'    => 'London',
			)
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'GB',
				'country_name' => 'United Kingdom',
				'city_name'    => 'London',
			),
			$result
		);
	}

	/**
	 * 6. Empty and null location fields are handled properly without corruption.
	 *
	 * @return void
	 */
	public function test_empty_and_null_location_fields(): void {
		// Case A: All nulls represent completely unresolvable location.
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			)
		);

		$result = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$result
		);

		Hooks::remove_all();

		// Case B: Blank/whitespace strings normalize cleanly to null.
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => '   ',
				'country_name' => '',
				'city_name'    => "  \t  ",
			)
		);

		$result_trimmed = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$result_trimmed
		);

		Hooks::remove_all();

		// Case C: Known country with unknown/null city.
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'IS',
				'country_name' => 'Iceland',
				'city_name'    => null,
			)
		);

		$result_country_only = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'IS',
				'country_name' => 'Iceland',
				'city_name'    => null,
			),
			$result_country_only
		);
	}

	/**
	 * 7. Exception handling isolates callback failures and falls back safely with sanitized logs.
	 *
	 * @return void
	 */
	public function test_exception_handling_and_fallback_behavior(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'peakurl_err_' );
		$orig_log = ini_set( 'error_log', $log_file );

		try {
			\add_filter(
				'geoip_location',
				function (): array {
					throw new \RuntimeException( 'Downstream geolocation API timeout' );
				}
			);

			$result = $this->geoip->lookup_location( '8.8.8.8' );

			$this->assertSame(
				array(
					'country_code' => 'US',
					'country_name' => 'United States',
					'city_name'    => 'Mountain View',
				),
				$result
			);

			$log_content = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
			$this->assertStringContainsString( 'PeakURL geoip_location filter error: RuntimeException', $log_content );
			$this->assertStringNotContainsString( '8.8.8.8', $log_content, 'Public IP must not be leaked into error log.' );
			$this->assertStringNotContainsString( 'Downstream geolocation API timeout', $log_content, 'Exception message must not leak sensitive context.' );
		} finally {
			if ( false !== $orig_log ) {
				ini_set( 'error_log', $orig_log );
			}
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	/**
	 * Data provider for non-public (private, loopback, reserved, invalid) IP addresses.
	 *
	 * @return array<string, array{string}>
	 */
	public static function non_public_ips_provider(): array {
		return array(
			'IPv4 loopback'           => array( '127.0.0.1' ),
			'IPv4 private 10/8'       => array( '10.0.0.1' ),
			'IPv4 private 172.16/12'  => array( '172.16.0.1' ),
			'IPv4 private 192.168/16' => array( '192.168.1.1' ),
			'IPv6 loopback'           => array( '::1' ),
			'IPv6 unique local'       => array( 'fc00::1' ),
			'IPv6 link local'         => array( 'fe80::1' ),
			'reserved 0.0.0.0'        => array( '0.0.0.0' ),
			'invalid octet range'     => array( '256.256.256.256' ),
			'empty IP string'         => array( '' ),
			'arbitrary text string'   => array( 'not-an-ip-address' ),
		);
	}

	/**
	 * 8. Private, reserved, and invalid IP addresses never trigger external callbacks.
	 *
	 * @param string $non_public_ip
	 * @return void
	 */
	#[DataProvider( 'non_public_ips_provider' )]
	public function test_private_reserved_and_invalid_ip_handling( string $non_public_ip ): void {
		$callback_invoked = false;

		\add_filter(
			'geoip_location',
			function ( array $location, string $ip ) use ( &$callback_invoked ): array {
				$callback_invoked = true;
				return array(
					'country_code' => 'CA',
					'country_name' => 'Canada',
					'city_name'    => 'Toronto',
				);
			},
			10,
			2
		);

		$result = $this->geoip->lookup_location( $non_public_ip );

		$this->assertFalse(
			$callback_invoked,
			"External callback must never execute for non-public IP: {$non_public_ip}"
		);

		$this->assertSame(
			array(
				'country_code' => null,
				'country_name' => null,
				'city_name'    => null,
			),
			$result
		);
	}

	/**
	 * 9. Correct propagation of filtered location into recorded click analytics data.
	 *
	 * @return void
	 */
	public function test_correct_propagation_of_filtered_location_into_recorded_click_data(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );
		$roles        = new Roles();
		$auth         = new Authorization( $roles );
		$webhooks     = $this->createMock( WebhooksService::class );

		$db->method( 'get_var' )->willReturn( false );

		$inserted_payload = null;
		$db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'clicks',
				$this->callback(
					function ( array $payload ) use ( &$inserted_payload ): bool {
						$inserted_payload = $payload;
						return true;
					}
				)
			);

		$repository = new AnalyticsRepository(
			$db,
			$settings_api,
			$this->geoip,
			$roles,
			$auth,
			$webhooks
		);

		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'DE',
				'country_name' => 'Germany',
				'city_name'    => 'Frankfurt',
			)
		);

		$url = array(
			'id'              => 'url_123',
			'short_code'      => 'promo',
			'alias'           => 'promo',
			'title'           => 'Promo Link',
			'destination_url' => 'https://example.com/target',
			'status'          => 'active',
			'utm_source'      => null,
			'utm_medium'      => null,
			'utm_campaign'    => null,
			'utm_term'        => null,
			'utm_content'     => null,
		);

		$request = new Request(
			'GET',
			'/promo',
			array(),
			array(),
			array(),
			array(),
			array(
				'REMOTE_ADDR'     => '8.8.8.8',
				'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
			)
		);

		$repository->record_click( $url, $request );

		$this->assertNotNull( $inserted_payload );
		$this->assertSame( 'DE', $inserted_payload['country_code'] );
		$this->assertSame( 'Germany', $inserted_payload['country_name'] );
		$this->assertSame( 'Frankfurt', $inserted_payload['city_name'] );
	}

	/**
	 * 10. Existing analytics recording and webhook dispatching remain completely unchanged.
	 *
	 * Explicitly asserts that both the link_clicked action and WebhooksService::dispatch_link_event
	 * receive the exact filtered location payload for overrides, and the exact default MaxMind
	 * location payload for baseline clicks.
	 *
	 * @return void
	 */
	public function test_existing_analytics_and_webhook_behavior_remaining_unchanged(): void {
		$db           = $this->createMock( PeakURL_DB::class );
		$settings_api = $this->createMock( SettingsApi::class );
		$roles        = new Roles();
		$auth         = new Authorization( $roles );
		$webhooks     = $this->createMock( WebhooksService::class );

		$db->method( 'get_var' )->willReturn( false );
		$db->method( 'insert' )->willReturn( 1 );

		$dispatched_webhook_events = array();
		$webhooks->expects( $this->exactly( 2 ) )
			->method( 'dispatch_link_event' )
			->willReturnCallback(
				function (
					string $event_type,
					array $link_payload,
					?array $actor,
					?array $previous,
					?array $click_payload
				) use ( &$dispatched_webhook_events ): array {
					$dispatched_webhook_events[] = array(
						'event_type'    => $event_type,
						'link_payload'  => $link_payload,
						'click_payload' => $click_payload,
					);
					return array();
				}
			);

		$repository = new AnalyticsRepository(
			$db,
			$settings_api,
			$this->geoip,
			$roles,
			$auth,
			$webhooks
		);

		$captured_click_action = null;
		\add_action(
			'link_clicked',
			function ( array $url, array $click_payload, Request $request ) use ( &$captured_click_action ): void {
				$captured_click_action = $click_payload;
			},
			10,
			3
		);

		$url = array(
			'id'              => 'url_default',
			'short_code'      => 'baseline',
			'alias'           => 'baseline',
			'title'           => 'Baseline Link',
			'destination_url' => 'https://example.com',
			'status'          => 'active',
			'utm_source'      => null,
			'utm_medium'      => null,
			'utm_campaign'    => null,
			'utm_term'        => null,
			'utm_content'     => null,
		);

		$request = new Request(
			'GET',
			'/baseline',
			array(),
			array(),
			array(),
			array(),
			array(
				'REMOTE_ADDR'     => '8.8.8.8',
				'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
			)
		);

		// A: Baseline with NO filter registered retains default MaxMind data in both action and webhook dispatch.
		$repository->record_click( $url, $request );

		$this->assertNotNull( $captured_click_action );
		$this->assertSame( 'US', $captured_click_action['country_code'] );
		$this->assertSame( 'United States', $captured_click_action['country_name'] );
		$this->assertSame( 'Mountain View', $captured_click_action['city_name'] );

		$this->assertCount( 1, $dispatched_webhook_events );
		$this->assertSame( 'link.clicked', $dispatched_webhook_events[0]['event_type'] );
		$this->assertSame( 'url_default', $dispatched_webhook_events[0]['link_payload']['id'] );
		$this->assertSame( 'US', $dispatched_webhook_events[0]['click_payload']['country_code'] );
		$this->assertSame( 'United States', $dispatched_webhook_events[0]['click_payload']['country_name'] );
		$this->assertSame( 'Mountain View', $dispatched_webhook_events[0]['click_payload']['city_name'] );

		// B: When geoip_location is filtered, both link_clicked action and webhook event reflect it identically.
		$captured_filtered_action = null;
		\add_action(
			'link_clicked',
			function ( array $url, array $click_payload, Request $request ) use ( &$captured_filtered_action ): void {
				$captured_filtered_action = $click_payload;
			},
			20,
			3
		);

		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'SE',
				'country_name' => 'Sweden',
				'city_name'    => 'Stockholm',
			)
		);

		$url['id'] = 'url_filtered';
		$repository->record_click( $url, $request );

		$this->assertNotNull( $captured_filtered_action );
		$this->assertSame( 'SE', $captured_filtered_action['country_code'] );
		$this->assertSame( 'Sweden', $captured_filtered_action['country_name'] );
		$this->assertSame( 'Stockholm', $captured_filtered_action['city_name'] );

		$this->assertCount( 2, $dispatched_webhook_events );
		$this->assertSame( 'link.clicked', $dispatched_webhook_events[1]['event_type'] );
		$this->assertSame( 'url_filtered', $dispatched_webhook_events[1]['link_payload']['id'] );
		$this->assertSame( 'SE', $dispatched_webhook_events[1]['click_payload']['country_code'] );
		$this->assertSame( 'Sweden', $dispatched_webhook_events[1]['click_payload']['country_name'] );
		$this->assertSame( 'Stockholm', $dispatched_webhook_events[1]['click_payload']['city_name'] );
	}

	/**
	 * 11. Multiple registered callbacks respect execution priority and documented filter semantics.
	 *
	 * @return void
	 */
	public function test_multiple_callbacks_respecting_filter_semantics(): void {
		// Scenario A: Ordered pipeline where passive provider returns incoming data and active provider overrides.
		\add_filter(
			'geoip_location',
			function ( array $location ): array {
				// Passive Provider 1 (priority 10) — disabled, returns untouched.
				return $location;
			},
			10,
			1
		);

		\add_filter(
			'geoip_location',
			function ( array $location ): array {
				// Active Provider 2 (priority 20) — resolves location.
				return array(
					'country_code' => 'JP',
					'country_name' => 'Japan',
					'city_name'    => 'Tokyo',
				);
			},
			20,
			1
		);

		\add_filter(
			'geoip_location',
			function ( array $location ): array {
				// Passive Provider 3 (priority 30) — disabled, returns untouched.
				return $location;
			},
			30,
			1
		);

		$result_pipeline = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'JP',
				'country_name' => 'Japan',
				'city_name'    => 'Tokyo',
			),
			$result_pipeline
		);

		Hooks::remove_all();

		// Scenario B: Higher priority callback overrides earlier callback.
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'CA',
				'country_name' => 'Canada',
				'city_name'    => 'Toronto',
			),
			10
		);

		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'FR',
				'country_name' => 'France',
				'city_name'    => 'Paris',
			),
			20
		);

		$result_precedence = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'FR',
				'country_name' => 'France',
				'city_name'    => 'Paris',
			),
			$result_precedence
		);

		Hooks::remove_all();

		// Scenario C: Exception in a downstream callback triggers safe fallback to default MaxMind.
		\add_filter(
			'geoip_location',
			fn() => array(
				'country_code' => 'CA',
				'country_name' => 'Canada',
				'city_name'    => 'Toronto',
			),
			10
		);

		\add_filter(
			'geoip_location',
			function (): array {
				throw new \RuntimeException( 'Downstream secondary provider failure' );
			},
			20
		);

		$result_fallback = $this->geoip->lookup_location( '8.8.8.8' );

		$this->assertSame(
			array(
				'country_code' => 'US',
				'country_name' => 'United States',
				'city_name'    => 'Mountain View',
			),
			$result_fallback
		);
	}
}
