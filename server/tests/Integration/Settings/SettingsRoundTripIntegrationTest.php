<?php
/**
 * Direct Settings Round-Trip Integration Test.
 *
 * Verifies validation, persistence, and readback for general settings,
 * specifically ensuring analyticsRetentionDays round-trips correctly and
 * treats 0 as indefinite retention.
 *
 * @package PeakURL\Tests\Integration\Settings
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Settings;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Settings\Service as SettingsService;
use PeakURL\Features\Settings\Validator as SettingsValidator;
use PeakURL\Http\Request;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as SchemaService;
use PeakURL\Services\Favicon;
use PeakURL\Services\I18n;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;

class SettingsRoundTripIntegrationTest extends TestCase {

	private array $stored_options;
	private SettingsApi $settings_api;
	private I18n $i18n_service;
	private SettingsValidator $validator;

	protected function setUp(): void {
		parent::setUp();

		$this->stored_options = array(
			'site_name'                => 'Test PeakURL',
			'site_tagline'             => 'Shortener',
			'site_language'            => 'en_US',
			'site_timezone'            => 'UTC',
			'site_time_format'         => '12',
			'landing_page_mode'        => 'html',
			'landing_page_url'         => '',
			'trash_retention_days'     => '30',
			'analytics_retention_days' => '0',
		);

		$this->settings_api = $this->createMock( SettingsApi::class );
		$this->settings_api->method( 'get_option' )
			->willReturnCallback(
				function ( string $key ) {
					return $this->stored_options[ $key ] ?? null;
				}
			);
		$this->settings_api->method( 'update_option' )
			->willReturnCallback(
				function ( string $key, $value ) {
					$this->stored_options[ $key ] = (string) $value;
					return true;
				}
			);

		$this->i18n_service = $this->createMock( I18n::class );
		$this->i18n_service->method( 'normalize_locale' )->willReturn( 'en_US' );
		$this->i18n_service->method( 'is_locale_available' )->willReturn( true );
		$this->i18n_service->method( 'get_site_locale' )->willReturn( 'en_US' );
		$this->i18n_service->method( 'get_text_direction' )->willReturn( 'ltr' );
		$this->i18n_service->method( 'is_locale_rtl' )->willReturn( false );
		$this->i18n_service->method( 'list_languages' )->willReturn( array( 'en_US' => 'English' ) );
		$this->i18n_service->method( 'get_content_dir' )->willReturn( '/tmp/content' );

		$this->validator = new SettingsValidator();
	}

	public function test_validator_normalizes_analytics_retention_days(): void {
		$payload = array(
			'siteLanguage'           => 'en_US',
			'siteTimezone'           => 'UTC',
			'siteTimeFormat'         => '12',
			'analyticsRetentionDays' => 90,
		);

		$validated = $this->validator->validate_general_settings(
			$payload,
			$this->i18n_service,
			'UTC',
			'12'
		);

		$this->assertSame( 90, $validated['analyticsRetentionDays'] );

		// 0 represents indefinite retention
		$payload['analyticsRetentionDays'] = 0;
		$validated_zero                    = $this->validator->validate_general_settings(
			$payload,
			$this->i18n_service,
			'UTC',
			'12'
		);
		$this->assertSame( 0, $validated_zero['analyticsRetentionDays'] );

		// Negative values clamp to 0
		$payload['analyticsRetentionDays'] = -10;
		$validated_neg                     = $this->validator->validate_general_settings(
			$payload,
			$this->i18n_service,
			'UTC',
			'12'
		);
		$this->assertSame( 0, $validated_neg['analyticsRetentionDays'] );

		// Unset returns null to avoid overwriting existing setting
		unset( $payload['analyticsRetentionDays'] );
		$validated_unset = $this->validator->validate_general_settings(
			$payload,
			$this->i18n_service,
			'UTC',
			'12'
		);
		$this->assertNull( $validated_unset['analyticsRetentionDays'] );
	}

	public function test_settings_service_round_trips_analytics_retention_days(): void {
		$db                     = $this->createMock( PeakURL_DB::class );
		$connection             = $this->createMock( Connection::class );
		$schema_service         = $this->createMock( SchemaService::class );
		$cache_service          = $this->createMock( CacheInterface::class );
		$auth_service           = $this->createMock( AuthService::class );
		$roles                  = new Roles();
		$authorization          = new Authorization( $roles );
		$favicon_service        = $this->createMock( Favicon::class );
		$social_preview_service = $this->createMock( SocialPreview::class );
		$captcha_service        = $this->createMock( \PeakURL\Services\Captcha::class );
		$geoip_service          = $this->createMock( \PeakURL\Services\Geoip::class );
		$mailer_service         = $this->createMock( \PeakURL\Services\Mailer::class );
		$notifications_service  = $this->createMock( Notifications::class );

		$admin_user = array(
			'id'       => '1',
			'username' => 'admin',
			'role'     => 'admin',
		);
		$auth_service->method( 'get_current_user' )->willReturn( $admin_user );

		$service = new SettingsService(
			$db,
			$connection,
			$schema_service,
			$cache_service,
			$this->validator,
			$this->settings_api,
			$auth_service,
			$this->i18n_service,
			$favicon_service,
			$social_preview_service,
			$captcha_service,
			$geoip_service,
			$mailer_service,
			$notifications_service,
			$roles,
			$authorization,
			array()
		);

		$request = new Request(
			'POST',
			'/api/v1/system/settings',
			array(),
			array(),
			array()
		);

		// 1. Initial readback defaults to 0 (indefinite)
		$initial = $service->get_general_settings( $request );
		$this->assertSame( 0, $initial['analyticsRetentionDays'] );

		// 2. Save 60 days retention
		$payload = array(
			'siteLanguage'           => 'en_US',
			'siteTimezone'           => 'UTC',
			'siteTimeFormat'         => '12',
			'siteName'               => 'Test PeakURL',
			'siteTagline'            => 'Shortener',
			'landingPageMode'        => 'html',
			'landingPageUrl'         => '',
			'trashRetentionDays'     => 30,
			'analyticsRetentionDays' => 60,
		);

		$service->save_general_settings( $request, $payload );

		// 3. Verify persistence
		$this->assertSame( '60', $this->stored_options['analytics_retention_days'] );

		// 4. Verify readback
		$updated = $service->get_general_settings( $request );
		$this->assertSame( 60, $updated['analyticsRetentionDays'] );

		// 5. Update to 0 (indefinite retention)
		$payload['analyticsRetentionDays'] = 0;
		$service->save_general_settings( $request, $payload );

		$this->assertSame( '0', $this->stored_options['analytics_retention_days'] );
		$updated_indefinite = $service->get_general_settings( $request );
		$this->assertSame( 0, $updated_indefinite['analyticsRetentionDays'] );
	}
}
