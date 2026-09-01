<?php
/**
 * Data store system trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\AdminNotices;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Geoip;
use PeakURL\Services\Install\Writer as InstallWriter;
use PeakURL\Services\Mailer;
use PeakURL\Services\SystemStatus\Manager as SystemStatusManager;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SystemTrait — updater, GeoIP, and cache performance methods for Store.
 *
 * @property \PeakURL\Includes\PeakURL_DB $db
 * @property array<string, mixed> $config
 * @property \PeakURL\Api\SettingsApi $settings_api
 * @method CacheInterface get_cache()
 * @method CacheInterface refresh_cache_service()
 * @method array<string, mixed> get_current_user(Request $request)
 * @method array<string, mixed> get_admin_user(Request $request)
 * @method string now()
 *
 * @since 1.0.0
 */
trait SystemTrait {

	/**
	 * Return the current dashboard admin notices.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.3
	 */
	public function get_admin_notices( Request $request ): array {
		$user    = $this->get_current_user( $request );
		$service = new AdminNotices();

		return $service->get_notices(
			$this->get_admin_notice_context( $user ),
		);
	}

	/**
	 * Return the dashboard system-status payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function get_system_status( Request $request ): array {
		$this->get_update_user( $request );

		$service = new SystemStatusManager(
			$this->config,
			$this->db,
			$this->settings_api,
			$this->geoip_service,
			$this->mailer_service,
			$this->get_schema_service(),
			$this->i18n_service,
		);

		return $service->get_status();
	}

	/**
	 * Get the shared dashboard notice context for the current user.
	 *
	 * @param array<string, mixed> $user Current authenticated user row.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function get_admin_notice_context( array $user ): array {
		$capabilities = array(
			'manageUpdates'      => $this->roles->has_capability( $user, 'manage_updates' ),
			'manageLocationData' => $this->roles->has_capability( $user, 'manage_location_data' ),
		);
		$context      = array(
			'user'         => $user,
			'capabilities' => $capabilities,
		);

		if ( ! empty( $capabilities['manageUpdates'] ) ) {
			$context['updateStatus'] = $this->load_update_status( false );
		}

		if ( ! empty( $capabilities['manageLocationData'] ) ) {
			$context['geoipStatus'] = $this->format_geoip_status(
				$this->geoip_service->get_status(),
			);
		}

		return $context;
	}

	/**
	 * Return the current update-management user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_update_user( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->validate_capability(
			$user,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current site-settings user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_settings_user( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->validate_capability(
			$user,
			'manage_site_settings',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current mail-management user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_mail_user( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->validate_capability(
			$user,
			'manage_mail_delivery',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current GeoIP-management user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_geoip_user( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->validate_capability(
			$user,
			'manage_location_data',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the dashboard general-settings payload.
	 *
	 * Includes the site language plus the list of installed language packs.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function get_general_settings( Request $request ): array {
		$user         = $this->get_current_user( $request );
		$site_name    = trim( (string) $this->get_option( 'site_name' ) );
		$site_tagline = $this->get_site_tagline();
		$site_url     = \get_site_url();

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		return array(
			'siteName'              => $site_name,
			'siteTagline'           => $site_tagline,
			'siteUrl'               => $site_url,
			'siteLanguage'          => $this->i18n_service->get_site_locale(),
			'siteTimezone'          => $this->get_site_timezone(),
			'siteTimeFormat'        => $this->get_site_time_format(),
			'textDirection'         => $this->i18n_service->get_text_direction(),
			'isRtl'                 => $this->i18n_service->is_locale_rtl(),
			'availableLanguages'    => $this->i18n_service->list_languages(),
			'favicon'               => $this->favicon_service->get_settings( $site_name ),
			'socialPreview'         => $this->social_preview_service->get_settings(),
			'canManageSiteSettings' => $this->roles->has_capability(
				$user,
				'manage_site_settings',
			),
			'landingPageMode'       => $this->get_option( 'landing_page_mode' ) ? $this->get_option( 'landing_page_mode' ) : 'html',
			'landingPageUrl'        => $this->get_option( 'landing_page_url' ) ? $this->get_option( 'landing_page_url' ) : '',
			'trashRetentionDays'    => (int) ( $this->get_option( 'trash_retention_days' ) ?? 30 ),
			'contentDirectory'      => $this->i18n_service->get_content_dir(),
		);
	}

	/**
	 * Return the public dashboard translation payload.
	 *
	 * Used by Vite-served development requests that do not pass through
	 * `site/index.php` before the React app boots.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function get_public_i18n_payload(): array {
		$locale  = $this->i18n_service->get_site_locale();
		$catalog = $this->i18n_service->get_dashboard_catalog( $locale );

		/*
		 * Return the same client data shape used by packaged dashboard HTML,
		 * keeping Vite/dev mode aligned with `window.__PEAKURL__`.
		 */
		return \get_peakurl_data(
			array(
				'config'          => $this->config,
				'connection'      => $this->connection,
				'favicon_service' => $this->favicon_service,
				'i18n'            => $catalog,
				'i18n_service'    => $this->i18n_service,
				'locale'          => $locale,
				'settings_api'    => $this->settings_api,
				'time_format'     => $this->get_site_time_format(),
				'timezone'        => $this->get_site_timezone(),
			)
		);
	}

	/**
	 * Save the site language from the general settings screen.
	 *
	 * @param Request              $request Incoming authenticated request.
	 * @param array<string, mixed> $payload Submitted general-settings payload.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function save_general_settings(
		Request $request,
		array $payload
	): array {
		$this->get_settings_user( $request );

		$site_language = $this->i18n_service->normalize_locale(
			(string) ( $payload['siteLanguage'] ?? '' ),
		);

		if ( ! $this->i18n_service->is_locale_available( $site_language ) ) {
			throw new ApiException(
				__( 'PeakURL could not find that language pack.', 'peakurl' ),
				422,
			);
		}

		$site_timezone    = $this->normalize_site_timezone(
			(string) ( $payload['siteTimezone'] ?? $this->get_site_timezone() ),
		);
		$site_time_format = $this->normalize_site_time_format(
			(string) ( $payload['siteTimeFormat'] ?? $this->get_site_time_format() ),
		);

		$this->update_option( 'site_language', $site_language );
		$this->i18n_service->load_locale( $site_language );
		$this->update_option( 'site_timezone', $site_timezone );
		$this->update_option( 'site_time_format', $site_time_format );

		$current_site_name = trim(
			(string) $this->get_option( 'site_name' ),
		);
		$site_name         = trim(
			(string) ( $payload['siteName'] ?? $current_site_name ),
		);

		if ( '' === $site_name ) {
			$site_name = '' !== $current_site_name ? $current_site_name : 'PeakURL';
		}

		if ( $site_name !== $current_site_name ) {
			$this->update_option( 'site_name', $site_name );
		}

		$site_tagline         = $this->normalize_site_tagline(
			$payload['siteTagline'] ?? $this->get_site_tagline(),
		);
		$current_site_tagline = $this->get_site_tagline();

		if ( $site_tagline !== $current_site_tagline ) {
			$this->update_option( 'site_tagline', $site_tagline );
		}

		$landing_page_mode = trim( (string) ( $payload['landingPageMode'] ?? 'html' ) );
		if ( ! in_array( $landing_page_mode, array( 'login', 'url', 'html' ), true ) ) {
			$landing_page_mode = 'html';
		}
		$this->update_option( 'landing_page_mode', $landing_page_mode );

		$landing_page_url = trim( (string) ( $payload['landingPageUrl'] ?? '' ) );
		if ( $landing_page_url !== (string) $this->get_option( 'landing_page_url' ) ) {
			$this->update_option( 'landing_page_url', $landing_page_url );
		}

		if ( isset( $payload['trashRetentionDays'] ) ) {
			$retention_days = max( 0, (int) $payload['trashRetentionDays'] );
			$this->update_option( 'trash_retention_days', (string) $retention_days );
		}

		try {
			$this->favicon_service->save(
				$request->get_file( 'favicon' ),
				! empty( $payload['removeFavicon'] ),
				$site_name,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		try {
			$this->social_preview_service->save_settings(
				$request->get_file( 'socialPreviewImage' ),
				! empty( $payload['removeSocialPreviewImage'] ),
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$settings          = $this->get_general_settings( $request );
		$settings['saved'] = true;

		return $settings;
	}

	/**
	 * Return the configured site tagline for social previews.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function get_site_tagline(): string {
		$tagline = trim( (string) $this->get_option( 'site_tagline' ) );

		return '' !== $tagline
			? $tagline
			: __( 'Shorten, track, and own every link - PeakURL', 'peakurl' );
	}

	/**
	 * Normalize the site tagline used by default link previews.
	 *
	 * @param mixed $value Submitted tagline value.
	 * @return string
	 * @since 1.2.0
	 */
	private function normalize_site_tagline( $value ): string {
		$tagline = trim( (string) $value );

		if ( '' === $tagline ) {
			return __( 'Shorten, track, and own every link - PeakURL', 'peakurl' );
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $tagline, 'UTF-8' ) > 300 ) {
			return mb_substr( $tagline, 0, 300, 'UTF-8' );
		}

		return strlen( $tagline ) > 300 ? substr( $tagline, 0, 300 ) : $tagline;
	}

	/**
	 * Return the configured site timezone.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function get_site_timezone(): string {
		return $this->normalize_site_timezone(
			(string) $this->get_option( 'site_timezone' ),
			true,
		);
	}

	/**
	 * Return the configured dashboard time format.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function get_site_time_format(): string {
		return $this->normalize_site_time_format(
			(string) $this->get_option( 'site_time_format' ),
		);
	}

	/**
	 * Normalize a dashboard timezone setting.
	 *
	 * @param string $timezone            Submitted timezone identifier.
	 * @param bool   $fallback_on_invalid Whether invalid stored values should fall back.
	 * @return string
	 * @since 1.1.0
	 */
	private function normalize_site_timezone(
		string $timezone,
		bool $fallback_on_invalid = false
	): string {
		$timezone = trim( $timezone );

		if ( '' === $timezone ) {
			return Constants::DEFAULT_TIMEZONE;
		}

		$valid_timezones = \DateTimeZone::listIdentifiers();

		if (
			Constants::DEFAULT_TIMEZONE === $timezone ||
			in_array( $timezone, $valid_timezones, true )
		) {
			return $timezone;
		}

		if ( $fallback_on_invalid ) {
			return Constants::DEFAULT_TIMEZONE;
		}

		throw new ApiException(
			__( 'PeakURL could not find that timezone.', 'peakurl' ),
			422,
		);
	}

	/**
	 * Normalize the dashboard time-format preference.
	 *
	 * @param string $time_format Submitted time format.
	 * @return string
	 * @since 1.1.0
	 */
	private function normalize_site_time_format( string $time_format ): string {
		$time_format = sanitize_key( $time_format );

		if ( in_array( $time_format, array( '12', '24' ), true ) ) {
			return $time_format;
		}

		return Constants::DEFAULT_TIME_FORMAT;
	}

	/**
	 * Return the current mail delivery configuration status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_mail_status( Request $request ): array {
		$this->get_mail_user( $request );

		$status = $this->mailer_service->get_status();
		return $status;
	}

	/**
	 * Save the dashboard mail delivery configuration.
	 *
	 * @param Request              $request Incoming HTTP request (admin-only).
	 * @param array<string, mixed> $payload Submitted mail settings.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function save_mail_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_mail_user( $request );

		try {
			$status = $this->mailer_service->save_settings(
				ABSPATH . 'app',
				$this->config,
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config = RuntimeConfig::load( ABSPATH . 'app' );
		$this->refresh_release_config();
		$this->config         = RuntimeConfig::load( ABSPATH . 'app' );
		$this->crypto_service = new Crypto( $this->config );
		$this->mailer_service = new Mailer(
			$this->config,
			$this->settings_api,
			$this->crypto_service,
		);
		$status['saved']      = true;

		return $status;
	}

	/**
	 * Send a test email through the active mail transport.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	public function send_test_email( Request $request ): array {
		$user = $this->get_mail_user( $request );

		$status = $this->mailer_service->get_status();

		if ( empty( $status['canSendTestEmail'] ) ) {
			throw new ApiException(
				(string) (
					$status['testDisabledReason'] ??
					__( 'Save a complete mail configuration before sending a test email.', 'peakurl' )
				),
				422,
			);
		}

		try {
			$result = $this->notifications_service->send_test_email(
				$user,
				$status,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		return array(
			'sent'      => true,
			'recipient' => $result['recipient'],
			'driver'    => $result['driver'],
		);
	}

	/**
	 * Return the cached update status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current/latest version and availability flag.
	 * @since 1.0.0
	 */
	public function get_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( false );
	}

	/**
	 * Return the current CAPTCHA provider status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current CAPTCHA settings payload.
	 * @since 1.2.0
	 */
	public function get_captcha_status( Request $request ): array {
		$this->get_settings_user( $request );

		return $this->captcha_service->get_status();
	}

	/**
	 * Save CAPTCHA provider credentials into the settings table.
	 *
	 * @param Request              $request Incoming HTTP request (admin-only).
	 * @param array<string, mixed> $payload Submitted CAPTCHA config payload.
	 * @return array<string, mixed> Fresh CAPTCHA settings payload.
	 * @since 1.2.0
	 */
	public function save_captcha_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_settings_user( $request );

		$app_path = ABSPATH . 'app';
		try {
			$status = $this->captcha_service->save_settings(
				$app_path,
				$this->config,
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config          = RuntimeConfig::load( ABSPATH . 'app' );
		$this->crypto_service  = new Crypto( $this->config );
		$this->captcha_service = new Captcha(
			$this->config,
			$this->settings_api,
			$this->crypto_service,
		);
		$status                = $this->captcha_service->get_status();
		$status['saved']       = true;

		return $status;
	}

	/**
	 * Return the CAPTCHA service instance.
	 *
	 * @return Captcha
	 * @since 1.2.0
	 */
	public function get_captcha_service(): Captcha {
		return $this->captcha_service;
	}

	/**
	 * Return the current GeoIP integration status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current GeoIP status payload.
	 * @since 1.0.0
	 */
	public function get_geoip_status( Request $request ): array {
		$this->get_geoip_user( $request );

		return $this->format_geoip_status( $this->geoip_service->get_status() );
	}

	/**
	 * Save MaxMind credentials into the settings table.
	 *
	 * @param Request              $request Incoming HTTP request (admin-only).
	 * @param array<string, mixed> $payload Submitted GeoIP config payload.
	 * @return array<string, mixed> Fresh GeoIP status payload.
	 * @since 1.0.0
	 */
	public function save_geoip_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_geoip_user( $request );
		$this->validate_geoip_admin();

		$app_path = ABSPATH . 'app';
		try {
			$status = $this->geoip_service->save_credentials(
				$app_path,
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config = RuntimeConfig::load( ABSPATH . 'app' );
		$this->refresh_release_config();
		$this->config               = RuntimeConfig::load( ABSPATH . 'app' );
		$this->crypto_service       = new Crypto( $this->config );
		$this->geoip_service        = new Geoip(
			$this->config,
			$this->settings_api,
			$this->crypto_service,
		);
		$status                     = $this->format_geoip_status( $status );
		$status['credentialsSaved'] = true;

		return $status;
	}

	/**
	 * Download or refresh the local GeoLite2 City database.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Fresh GeoIP status payload.
	 * @since 1.0.0
	 */
	public function download_geoip_database( Request $request ): array {
		$this->get_geoip_user( $request );
		$this->validate_geoip_admin();

		try {
			$status = $this->geoip_service->download_database();
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$downloaded_at = $this->now();
		$this->update_option( 'geoip_last_downloaded_at', $downloaded_at, false );
		$status               = $this->format_geoip_status( $status, $downloaded_at );
		$status['downloaded'] = true;

		return $status;
	}

	/**
	 * Format GeoIP status for dashboard responses.
	 *
	 * @param array<string, mixed> $status             Raw GeoIP status payload.
	 * @param string|null          $last_downloaded_at Optional download timestamp.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function format_geoip_status(
		array $status,
		?string $last_downloaded_at = null
	): array {
		if ( null === $last_downloaded_at ) {
			$last_downloaded_at = $this->get_option( 'geoip_last_downloaded_at' );
		}

		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $last_downloaded_at
			? $this->to_iso( (string) $last_downloaded_at )
			: null;

		return $status;
	}

	/**
	 * Refresh the remote update manifest status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Updated status after the remote refresh.
	 * @since 1.0.0
	 */
	public function refresh_update_status( Request $request ): array {
		$this->get_update_user( $request );

		return $this->load_update_status( true );
	}

	/**
	 * Download and apply the latest release archive.
	 *
	 * Only allowed from packaged release installs; blocked in dev.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the update operation.
	 *
	 * @throws ApiException When run from a source checkout or no update is available.
	 * @since 1.0.0
	 */
	public function apply_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( empty( $status['updateAvailable'] ) ) {
			throw new ApiException( __( 'PeakURL is already up to date.', 'peakurl' ), 422 );
		}

		return $this->install_release( $status );
	}

	/**
	 * Reinstall the currently installed release package.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Result of the reinstall operation.
	 *
	 * @throws ApiException When the current release cannot be reinstalled.
	 * @since 1.0.5
	 */
	public function reinstall_update( Request $request ): array {
		$this->get_update_user( $request );

		$status = $this->load_update_status( true );

		if ( ! empty( $status['updateAvailable'] ) ) {
			throw new ApiException(
				__( 'A newer PeakURL release is available. Install the update instead.', 'peakurl' ),
				422,
			);
		}

		if ( empty( $status['reinstallAvailable'] ) ) {
			throw new ApiException(
				__( 'PeakURL cannot reinstall the latest release right now.', 'peakurl' ),
				422,
			);
		}

		return $this->install_release( $status, true );
	}

	/**
	 * Run the managed database upgrade / repair flow on demand.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function upgrade_database_schema( Request $request ): array {
		$this->get_update_user( $request );

		try {
			$service = $this->get_schema_service();
			$status  = $service->inspect();

			if ( empty( $status['upgradeRequired'] ) ) {
				return $status;
			}

			return $service->upgrade();
		} catch ( \Throwable $exception ) {
			throw new ApiException( $exception->getMessage(), 500 );
		}
	}

	/**
	 * Return the current cache and performance status.
	 *
	 * @param Request $request Incoming authenticated request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function get_cache_status( Request $request ): array {
		$this->get_admin_user( $request );

		$service = new \PeakURL\Services\SystemStatus\Cache(
			new \PeakURL\Services\SystemStatus\Context(
				$this->config,
				$this->db,
				$this->settings_api,
				$this->geoip_service,
				$this->mailer_service,
				$this->get_schema_service(),
				$this->i18n_service,
			)
		);

		return $service->cache_status();
	}

	/**
	 * Save cache and performance configuration into settings storage.
	 *
	 * @param Request              $request Incoming HTTP request (admin-only).
	 * @param array<string, mixed> $payload Configuration payload.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function save_cache_configuration( Request $request, array $payload ): array {
		$this->get_admin_user( $request );

		if ( array_key_exists( 'enabled', $payload ) ) {
			$enabled = (bool) $payload['enabled'];
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_ENABLED,
				$enabled ? '1' : '0',
				$this->now(),
				true,
			);
		}

		if ( ! empty( $payload['driver'] ) && is_string( $payload['driver'] ) ) {
			$driver        = strtolower( trim( $payload['driver'] ) );
			$valid_drivers = array( 'auto', 'redis', 'apcu', 'file', 'filesystem', 'null', 'none' );
			if ( in_array( $driver, $valid_drivers, true ) ) {
				$this->settings_api->update_option(
					Constants::SETTING_CACHE_DRIVER,
					$driver,
					$this->now(),
					true,
				);
			}
		}

		if ( isset( $payload['defaultTtl'] ) && is_numeric( $payload['defaultTtl'] ) ) {
			$ttl = max( 0, (int) $payload['defaultTtl'] );
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_DEFAULT_TTL,
				(string) $ttl,
				$this->now(),
				true,
			);
		}

		if ( isset( $payload['negativeTtl'] ) && is_numeric( $payload['negativeTtl'] ) ) {
			$ttl = max( 0, (int) $payload['negativeTtl'] );
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_NEGATIVE_TTL,
				(string) $ttl,
				$this->now(),
				true,
			);
		}

		// Clear active cache driver on setting update and refresh active driver.
		$this->get_cache()->clear();
		$this->refresh_cache_service();

		return $this->get_cache_status( $request );
	}

	/**
	 * Clear all cached objects across the active cache driver and disk storage.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function clear_cache( Request $request ): array {
		$this->get_admin_user( $request );

		// Clear active cache driver.
		$this->get_cache()->clear();

		// Clean disk files if content/cache exists.
		$content_dir = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );
		$custom_path = (string) ( $this->config[ Constants::CACHE_PATH ] ?? '' );
		$cache_dir   = ! empty( $custom_path )
			? rtrim( $custom_path, '/\\' )
			: rtrim( $content_dir, '/\\' ) . '/' . Constants::CACHE_DIRECTORY;

		if ( is_dir( $cache_dir ) ) {
			$file_driver = new \PeakURL\Services\Cache\Drivers\FileCache( $content_dir, $custom_path );
			$file_driver->clear();
		}

		return $this->get_cache_status( $request );
	}

	/**
	 * Rewrite the release config.php from the active runtime config.
	 *
	 * Keeps packaged installs on the new slim config shape while skipping the
	 * source checkout, which persists local overrides in app/.env instead.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function refresh_release_config(): void {
		if ( file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' ) ) {
			return;
		}

		InstallWriter::write_config_file(
			ABSPATH . 'app',
			InstallWriter::prepare_config_values( $this->config ),
		);
	}
}
