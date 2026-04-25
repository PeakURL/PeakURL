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
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Install\Writer as InstallWriter;
use PeakURL\Services\Mailer;
use PeakURL\Services\SystemStatus\Manager as SystemStatusManager;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SystemTrait — updater and GeoIP methods for Store.
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
			$this->build_admin_notice_context( $user ),
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
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		$service = new SystemStatusManager(
			$this->config,
			$this->db,
			$this->settings_api,
			$this->geoip_service,
			$this->mailer_service,
			$this->get_database_schema_service(),
			$this->i18n_service,
		);

		return $service->get_status();
	}

	/**
	 * Build the shared dashboard notice context for the current user.
	 *
	 * @param array<string, mixed> $user Current authenticated user row.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_admin_notice_context( array $user ): array {
		$capabilities = array(
			'manageUpdates'      => $this->roles->user_can( $user, 'manage_updates' ),
			'manageLocationData' => $this->roles->user_can( $user, 'manage_location_data' ),
		);
		$context      = array(
			'user'         => $user,
			'capabilities' => $capabilities,
		);

		if ( ! empty( $capabilities['manageUpdates'] ) ) {
			$context['updateStatus'] = $this->load_update_status( false );
		}

		if ( ! empty( $capabilities['manageLocationData'] ) ) {
			$geoip_status                     = $this->geoip_service->get_status();
			$last_downloaded_at               = $this->get_option( 'geoip_last_downloaded_at' );
			$geoip_status['installed']        = ! empty( $geoip_status['locationAnalyticsReady'] );
			$geoip_status['lastDownloadedAt'] =
				$last_downloaded_at
					? $this->to_iso( (string) $last_downloaded_at )
					: null;
			$context['geoipStatus']           = $geoip_status;
		}

		return $context;
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
		$user      = $this->get_current_user( $request );
		$site_name = trim( (string) $this->get_option( 'site_name' ) );
		$site_url  = \get_site_url();

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		return array(
			'siteName'              => $site_name,
			'siteUrl'               => $site_url,
			'siteLanguage'          => $this->i18n_service->get_site_locale(),
			'siteTimezone'          => $this->get_site_timezone(),
			'siteTimeFormat'        => $this->get_site_time_format(),
			'textDirection'         => $this->i18n_service->get_text_direction(),
			'isRtl'                 => $this->i18n_service->is_locale_rtl(),
			'availableLanguages'    => $this->i18n_service->list_languages(),
			'favicon'               => $this->favicon_service->get_settings( $site_name ),
			'canManageSiteSettings' => $this->roles->user_can(
				$user,
				'manage_site_settings',
			),
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
		$locale    = $this->i18n_service->get_site_locale();
		$site_name = trim( (string) $this->get_option( 'site_name' ) );

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		return array(
			'locale'        => $locale,
			'htmlLang'      => $this->i18n_service->get_html_lang( $locale ),
			'textDirection' => $this->i18n_service->get_text_direction( $locale ),
			'isRtl'         => $this->i18n_service->is_locale_rtl( $locale ),
			'textDomain'    => Constants::I18N_TEXT_DOMAIN,
			'timezone'      => $this->get_site_timezone(),
			'timeFormat'    => $this->get_site_time_format(),
			'favicon'       => $this->favicon_service->get_settings( $site_name ),
			'defaultLocale' => $this->i18n_service->get_default_locale(),
			'catalog'       => $this->i18n_service->get_dashboard_catalog( $locale ),
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
		$this->assert_request_capability(
			$request,
			'manage_site_settings',
			'Admin access is required.',
		);

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

		try {
			$this->favicon_service->save(
				$request->get_file( 'favicon' ),
				! empty( $payload['removeFavicon'] ),
				$site_name,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$settings          = $this->get_general_settings( $request );
		$settings['saved'] = true;

		return $settings;
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
		$this->assert_request_capability(
			$request,
			'manage_mail_delivery',
			__( 'Admin access is required.', 'peakurl' ),
		);

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
		$this->assert_request_capability(
			$request,
			'manage_mail_delivery',
			__( 'Admin access is required.', 'peakurl' ),
		);

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
		$user = $this->assert_request_capability(
			$request,
			'manage_mail_delivery',
			__( 'Admin access is required.', 'peakurl' ),
		);

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
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $this->load_update_status( false );
	}

	/**
	 * Return the current GeoIP integration status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current GeoIP status payload.
	 * @since 1.0.0
	 */
	public function get_geoip_status( Request $request ): array {
		$this->assert_request_capability(
			$request,
			'manage_location_data',
			__( 'Admin access is required.', 'peakurl' ),
		);

		$status                     = $this->geoip_service->get_status();
		$last_downloaded_at         = $this->get_option( 'geoip_last_downloaded_at' );
		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] =
			$last_downloaded_at
				? $this->to_iso( $last_downloaded_at )
				: null;

		return $status;
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
		$this->assert_request_capability(
			$request,
			'manage_location_data',
			__( 'Admin access is required.', 'peakurl' ),
		);
		$this->assert_geoip_dashboard_management_allowed();

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
		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $this->get_option( 'geoip_last_downloaded_at' );
		$status['lastDownloadedAt'] = $status['lastDownloadedAt']
			? $this->to_iso( (string) $status['lastDownloadedAt'] )
			: null;
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
		$this->assert_request_capability(
			$request,
			'manage_location_data',
			__( 'Admin access is required.', 'peakurl' ),
		);
		$this->assert_geoip_dashboard_management_allowed();

		try {
			$status = $this->geoip_service->download_database();
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->update_option( 'geoip_last_downloaded_at', $this->now(), false );
		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $this->to_iso( $this->now() );
		$status['downloaded']       = true;

		return $status;
	}

	/**
	 * Check the remote manifest for a new release.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Updated status after the remote check.
	 * @since 1.0.0
	 */
	public function check_for_updates( Request $request ): array {
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

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
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		$status = $this->load_update_status( true );

		if ( empty( $status['updateAvailable'] ) ) {
			throw new ApiException( __( 'PeakURL is already up to date.', 'peakurl' ), 422 );
		}

		return $this->install_release_from_status( $status );
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
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

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

		return $this->install_release_from_status( $status, true );
	}

	/**
	 * Run the managed database upgrade / repair flow on demand.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function upgrade_database_schema( Request $request ): array {
		$this->assert_request_capability(
			$request,
			'manage_updates',
			__( 'Admin access is required.', 'peakurl' ),
		);

		try {
			$service = $this->get_database_schema_service();
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
			InstallWriter::build_config_values( $this->config ),
		);
	}
}
