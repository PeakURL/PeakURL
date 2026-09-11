<?php
/**
 * Settings domain service.
 *
 * Handles general settings, i18n, caching, CAPTCHA, GeoIP, and mail configuration.
 *
 * @package PeakURL\Features\Settings
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Settings;

use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\RuntimeConfig;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\CacheManager;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as SchemaService;
use PeakURL\Services\Favicon;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Install\Writer as InstallWriter;
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Settings management engine.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Database connection instance.
	 *
	 * @var Connection
	 * @since 1.0.0
	 */
	private Connection $connection;

	/**
	 * Database schema service.
	 *
	 * @var SchemaService
	 * @since 1.0.0
	 */
	private SchemaService $schema_service;

	/**
	 * Cache driver instance.
	 *
	 * @var CacheInterface
	 * @since 1.6.0
	 */
	private CacheInterface $cache_service;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Optional links API for cache synchronization.
	 *
	 * @var LinksApi|null
	 * @since 1.6.0
	 */
	private ?LinksApi $links_api = null;

	/**
	 * Settings validator.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Settings API.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * I18n helper.
	 *
	 * @var I18n
	 * @since 1.0.0
	 */
	private I18n $i18n_service;

	/**
	 * Favicon helper.
	 *
	 * @var Favicon
	 * @since 1.0.0
	 */
	private Favicon $favicon_service;

	/**
	 * Social preview helper.
	 *
	 * @var SocialPreview
	 * @since 1.0.0
	 */
	private SocialPreview $social_preview_service;

	/**
	 * CAPTCHA service.
	 *
	 * @var Captcha
	 * @since 1.0.0
	 */
	private Captcha $captcha_service;

	/**
	 * GeoIP service.
	 *
	 * @var Geoip
	 * @since 1.0.0
	 */
	private Geoip $geoip_service;

	/**
	 * Mailer service.
	 *
	 * @var Mailer
	 * @since 1.0.0
	 */
	private Mailer $mailer_service;

	/**
	 * Transactional notification helper.
	 *
	 * @var Notifications
	 * @since 1.0.0
	 */
	private Notifications $notifications_service;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Create a new Settings Service instance.
	 *
	 * @param PeakURL_DB           $db                     Shared database wrapper.
	 * @param Connection           $connection             Database connection instance.
	 * @param SchemaService        $schema_service         Database schema service.
	 * @param CacheInterface       $cache_service          Cache driver instance.
	 * @param Validator            $validator              Settings validator.
	 * @param SettingsApi          $settings_api           Settings API.
	 * @param AuthService          $auth_service           Authentication domain service.
	 * @param I18n                 $i18n_service           I18n helper.
	 * @param Favicon              $favicon_service        Favicon helper.
	 * @param SocialPreview        $social_preview_service Social preview helper.
	 * @param Captcha              $captcha_service        CAPTCHA service.
	 * @param Geoip                $geoip_service          GeoIP service.
	 * @param Mailer               $mailer_service         Mailer service.
	 * @param Notifications        $notifications_service  Notifications helper.
	 * @param Roles                $roles                  Roles registry.
	 * @param Authorization        $authorization          Authorization helper.
	 * @param array<string, mixed> $config                 Runtime config map.
	 * @param LinksApi|null        $links_api              Optional links API.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		Connection $connection,
		SchemaService $schema_service,
		CacheInterface $cache_service,
		Validator $validator,
		SettingsApi $settings_api,
		AuthService $auth_service,
		I18n $i18n_service,
		Favicon $favicon_service,
		SocialPreview $social_preview_service,
		Captcha $captcha_service,
		Geoip $geoip_service,
		Mailer $mailer_service,
		Notifications $notifications_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		?LinksApi $links_api = null
	) {
		$this->db                     = $db;
		$this->connection             = $connection;
		$this->schema_service         = $schema_service;
		$this->cache_service          = $cache_service;
		$this->validator              = $validator;
		$this->settings_api           = $settings_api;
		$this->auth_service           = $auth_service;
		$this->i18n_service           = $i18n_service;
		$this->favicon_service        = $favicon_service;
		$this->social_preview_service = $social_preview_service;
		$this->captcha_service        = $captcha_service;
		$this->geoip_service          = $geoip_service;
		$this->mailer_service         = $mailer_service;
		$this->notifications_service  = $notifications_service;
		$this->roles                  = $roles;
		$this->authorization          = $authorization;
		$this->config                 = $config;
		$this->links_api              = $links_api;
	}

	/**
	 * Set the links API reference.
	 *
	 * @param LinksApi $links_api Links API.
	 * @return void
	 * @since 1.6.0
	 */
	public function set_links_api( LinksApi $links_api ): void {
		$this->links_api = $links_api;
	}

	/**
	 * Return the current site-settings user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_settings_user( Request $request ): array {
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_location_data',
			__( 'Admin access is required.', 'peakurl' ),
		);

		return $user;
	}

	/**
	 * Return the current admin user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_admin_user( Request $request ): array {
		return $this->auth_service->get_admin_user( $request );
	}

	/**
	 * Retrieve a single option value by its key.
	 *
	 * @param string $option_name Option key to look up.
	 * @return string|null The stored value or null when missing.
	 * @since 1.0.0
	 */
	public function get_option( string $option_name ): ?string {
		return $this->settings_api->get_option( $option_name );
	}

	/**
	 * Insert or update an option row.
	 *
	 * @param string $option_name  Option key.
	 * @param string $option_value Option value to persist.
	 * @param bool   $autoload     Whether the option should autoload.
	 * @since 1.0.0
	 */
	public function update_option(
		string $option_name,
		string $option_value,
		bool $autoload = true
	): void {
		$this->settings_api->update_option(
			$option_name,
			$option_value,
			Date::now(),
			$autoload,
		);
	}

	/**
	 * Delete one or more options.
	 *
	 * @param array<int, string> $option_names Option keys to remove.
	 * @since 1.0.0
	 */
	public function delete_options( array $option_names ): void {
		$this->settings_api->delete_options( $option_names );
	}

	/**
	 * Add an option only when no row exists yet.
	 *
	 * @param string $option_name  Option key.
	 * @param string $option_value Option value.
	 * @param bool   $autoload     Whether the option should autoload.
	 * @return void
	 * @since 1.0.0
	 */
	private function add_option(
		string $option_name,
		string $option_value,
		bool $autoload = true
	): void {
		if ( null !== $this->get_option( $option_name ) ) {
			return;
		}

		$this->update_option( $option_name, $option_value, $autoload );
	}

	/**
	 * Save install-time configuration values into the settings table.
	 *
	 * @since 1.0.0
	 */
	public function save_install_options(): void {
		if ( ! $this->db->table_exists( 'settings' ) ) {
			return;
		}

		$site_name    = trim( (string) ( $this->config[ Constants::WORKSPACE_NAME ] ?? '' ) );
		$site_slug    = trim( (string) ( $this->config[ Constants::WORKSPACE_SLUG ] ?? '' ) );
		$site_url     = trim( (string) ( $this->config[ Constants::SITE_URL ] ?? '' ) );
		$admin_email  = trim( (string) ( $this->config[ Constants::OWNER_EMAIL ] ?? '' ) );
		$version      = trim( (string) ( $this->config[ Constants::VERSION ] ?? '' ) );
		$manifest_url = trim( (string) ( $this->config[ Constants::UPDATE_MANIFEST_URL ] ?? '' ) );

		if ( '' !== $site_name ) {
			$this->add_option( 'site_name', $site_name );
		}

		if ( '' !== $site_slug ) {
			$this->add_option( 'site_slug', $site_slug );
		}

		if ( '' !== $site_url ) {
			$this->update_option( 'site_url', $site_url );
		}

		if ( '' !== $admin_email ) {
			$this->add_option( 'admin_email', $admin_email, false );
		}

		if ( '' !== $version ) {
			$this->update_option( 'installed_version', $version, false );
		}

		if ( '' !== $manifest_url ) {
			$this->update_option( 'update_manifest_url', $manifest_url, false );
		}

		$this->add_default_options();

		if ( null === $this->get_option( 'installed_at' ) ) {
			$this->update_option( 'installed_at', Date::now(), false );
		}

		$this->delete_options(
			array(
				'site_title',
				'workspace_name',
				'workspace_slug',
				'default_team_id',
			),
		);
	}

	/**
	 * Add default database-backed runtime options when they are missing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function add_default_options(): void {
		$site_language = trim( (string) ( $this->config[ Constants::SITE_LANGUAGE ] ?? '' ) );

		if ( '' === $site_language ) {
			$site_language = Constants::DEFAULT_LOCALE;
		} else {
			$site_language = $this->i18n_service->normalize_locale( $site_language );

			if ( ! $this->i18n_service->is_locale_available( $site_language ) ) {
				$site_language = Constants::DEFAULT_LOCALE;
			}
		}

		$this->add_option( 'site_language', $site_language );
		$this->add_option( 'site_timezone', Constants::DEFAULT_TIMEZONE );
		$this->add_option( 'site_time_format', Constants::DEFAULT_TIME_FORMAT );
		$this->add_option(
			'site_tagline',
			__( 'Shorten, track, and own every link - PeakURL', 'peakurl' ),
		);
		$this->add_option( 'mail_driver', 'mail', false );
		$this->add_option( 'mail_from_email', '', false );
		$this->add_option( 'mail_from_name', '', false );
		$this->add_option( 'smtp_host', '', false );
		$this->add_option( 'smtp_port', '587', false );
		$this->add_option( 'smtp_encryption', 'tls', false );
		$this->add_option( 'smtp_auth', 'false', false );
		$this->add_option( 'smtp_username', '', false );
		$this->add_option( 'maxmind_account_id', '', false );
		$this->add_option( 'captcha_provider', 'none', false );
		$this->add_option( 'captcha_site_key', '', false );
		$this->add_option( Constants::SETTING_SOCIAL_PREVIEW_IMAGE, '', false );
	}

	/**
	 * Return the dashboard general-settings payload.
	 *
	 * @param Request $request Incoming authenticated request.
	 * @return array<string, mixed> General settings payload.
	 * @since 1.0.0
	 */
	public function get_general_settings( Request $request ): array {
		$user         = $this->auth_service->get_current_user( $request );
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
	 * @return array<string, mixed> Client data shape.
	 * @since 1.0.0
	 */
	public function get_public_i18n_payload(): array {
		$locale  = $this->i18n_service->get_site_locale();
		$catalog = $this->i18n_service->get_dashboard_catalog( $locale );

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
	 * Save general settings from the settings screen.
	 *
	 * @param Request              $request Incoming authenticated request.
	 * @param array<string, mixed> $payload Submitted general-settings payload.
	 * @return array<string, mixed> Updated settings.
	 *
	 * @throws ApiException On validation or upload failure.
	 * @since 1.0.0
	 */
	public function save_general_settings(
		Request $request,
		array $payload
	): array {
		$this->get_settings_user( $request );

		$validated = $this->validator->validate_general_settings(
			$payload,
			$this->i18n_service,
			$this->get_site_timezone(),
			$this->get_site_time_format(),
		);

		$this->update_option( 'site_language', $validated['siteLanguage'] );
		$this->i18n_service->load_locale( $validated['siteLanguage'] );
		$this->update_option( 'site_timezone', $validated['siteTimezone'] );
		$this->update_option( 'site_time_format', $validated['siteTimeFormat'] );

		$current_site_name = trim( (string) $this->get_option( 'site_name' ) );
		$site_name         = $validated['siteName'];

		if ( '' === $site_name ) {
			$site_name = '' !== $current_site_name ? $current_site_name : 'PeakURL';
		}

		if ( $site_name !== $current_site_name ) {
			$this->update_option( 'site_name', $site_name );
		}

		$current_site_tagline = $this->get_site_tagline();
		if ( $validated['siteTagline'] !== $current_site_tagline ) {
			$this->update_option( 'site_tagline', $validated['siteTagline'] );
		}

		$this->update_option( 'landing_page_mode', $validated['landingPageMode'] );

		if ( $validated['landingPageUrl'] !== (string) $this->get_option( 'landing_page_url' ) ) {
			$this->update_option( 'landing_page_url', $validated['landingPageUrl'] );
		}

		if ( null !== $validated['trashRetentionDays'] ) {
			$this->update_option( 'trash_retention_days', (string) $validated['trashRetentionDays'] );
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
	 * @return string Site tagline.
	 * @since 1.0.0
	 */
	public function get_site_tagline(): string {
		$tagline = trim( (string) $this->get_option( 'site_tagline' ) );

		return '' !== $tagline
			? $tagline
			: __( 'Shorten, track, and own every link - PeakURL', 'peakurl' );
	}

	/**
	 * Return the configured site timezone.
	 *
	 * @return string Timezone identifier.
	 * @since 1.0.0
	 */
	public function get_site_timezone(): string {
		return $this->validator->normalize_timezone(
			(string) $this->get_option( 'site_timezone' ),
			true,
		);
	}

	/**
	 * Return the configured dashboard time format.
	 *
	 * @return string '12' or '24'.
	 * @since 1.0.0
	 */
	public function get_site_time_format(): string {
		return $this->validator->normalize_time_format(
			(string) $this->get_option( 'site_time_format' ),
		);
	}

	/**
	 * Return the current mail delivery configuration status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Mailer status payload.
	 * @since 1.0.0
	 */
	public function get_mail_status( Request $request ): array {
		$this->get_mail_user( $request );

		return $this->mailer_service->get_status();
	}

	/**
	 * Save the dashboard mail delivery configuration.
	 *
	 * @param Request              $request Incoming HTTP request (admin-only).
	 * @param array<string, mixed> $payload Submitted mail settings.
	 * @return array<string, mixed> Fresh mail status payload.
	 *
	 * @throws ApiException On validation or runtime error.
	 * @since 1.0.0
	 */
	public function save_mail_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_mail_user( $request );

		try {
			$status = $this->mailer_service->save_settings(
				ABSPATH . 'server',
				$this->config,
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config = RuntimeConfig::load( ABSPATH . 'server' );
		$this->refresh_release_config();
		$this->config         = RuntimeConfig::load( ABSPATH . 'server' );
		$crypto               = new Crypto( $this->config );
		$this->mailer_service = new Mailer(
			$this->config,
			$this->settings_api,
			$crypto,
		);
		$status['saved']      = true;

		return $status;
	}

	/**
	 * Send a test email through the active mail transport.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Test email result.
	 *
	 * @throws ApiException If test email cannot be sent.
	 * @since 1.0.0
	 */
	public function send_test_email( Request $request ): array {
		$user   = $this->get_mail_user( $request );
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
	 * Return the current CAPTCHA provider status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> CAPTCHA status payload.
	 * @since 1.0.0
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
	 *
	 * @throws ApiException On validation or runtime failure.
	 * @since 1.0.0
	 */
	public function save_captcha_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_settings_user( $request );

		try {
			$status = $this->captcha_service->save_settings(
				ABSPATH . 'server',
				$this->config,
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config          = RuntimeConfig::load( ABSPATH . 'server' );
		$crypto                = new Crypto( $this->config );
		$this->captcha_service = new Captcha(
			$this->config,
			$this->settings_api,
			$crypto,
		);
		$status                = $this->captcha_service->get_status();
		$status['saved']       = true;

		return $status;
	}

	/**
	 * Return the CAPTCHA service instance.
	 *
	 * @return Captcha Captcha service instance.
	 * @since 1.0.0
	 */
	public function get_captcha_service(): Captcha {
		return $this->captcha_service;
	}

	/**
	 * Return the current GeoIP integration status.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> GeoIP status payload.
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
	 *
	 * @throws ApiException On validation or permission error.
	 * @since 1.0.0
	 */
	public function save_geoip_configuration(
		Request $request,
		array $payload
	): array {
		$this->get_geoip_user( $request );
		$this->validate_geoip_admin();

		try {
			$status = $this->geoip_service->save_credentials(
				ABSPATH . 'server',
				$payload,
			);
		} catch ( \RuntimeException $exception ) {
			throw new ApiException( $exception->getMessage(), 422 );
		}

		$this->config = RuntimeConfig::load( ABSPATH . 'server' );
		$this->refresh_release_config();
		$this->config               = RuntimeConfig::load( ABSPATH . 'server' );
		$crypto                     = new Crypto( $this->config );
		$this->geoip_service        = new Geoip(
			$this->config,
			$this->settings_api,
			$crypto,
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
	 *
	 * @throws ApiException On download or file failure.
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

		$downloaded_at = Date::now();
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
	 * @return array<string, mixed> Formatted status.
	 * @since 1.0.0
	 */
	public function format_geoip_status(
		array $status,
		?string $last_downloaded_at = null
	): array {
		if ( null === $last_downloaded_at ) {
			$last_downloaded_at = $this->get_option( 'geoip_last_downloaded_at' );
		}

		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $last_downloaded_at
			? Date::to_iso( (string) $last_downloaded_at )
			: null;

		return $status;
	}

	/**
	 * Validate whether GeoIP dashboard management is allowed.
	 *
	 * @throws ApiException When the runtime config target is not writable.
	 * @since 1.0.0
	 */
	private function validate_geoip_admin(): void {
		$status = $this->geoip_service->get_status();

		if ( ! empty( $status['canManageFromDashboard'] ) ) {
			return;
		}

		throw new ApiException(
			(string) ( $status['manageDisabledReason'] ?? 'Location Data is unavailable in this runtime.' ),
			422,
		);
	}

	/**
	 * Return the current cache and performance status.
	 *
	 * @param Request $request Incoming authenticated request (admin-only).
	 * @return array<string, mixed> Cache status payload.
	 * @since 1.0.0
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
				$this->schema_service,
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
	 * @return array<string, mixed> Updated cache status payload.
	 * @since 1.0.0
	 */
	public function save_cache_configuration( Request $request, array $payload ): array {
		$this->get_admin_user( $request );

		if ( array_key_exists( 'enabled', $payload ) ) {
			$enabled = (bool) $payload['enabled'];
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_ENABLED,
				$enabled ? '1' : '0',
				Date::now(),
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
					Date::now(),
					true,
				);
			}
		}

		if ( isset( $payload['defaultTtl'] ) && is_numeric( $payload['defaultTtl'] ) ) {
			$ttl = max( 0, (int) $payload['defaultTtl'] );
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_DEFAULT_TTL,
				(string) $ttl,
				Date::now(),
				true,
			);
		}

		if ( isset( $payload['negativeTtl'] ) && is_numeric( $payload['negativeTtl'] ) ) {
			$ttl = max( 0, (int) $payload['negativeTtl'] );
			$this->settings_api->update_option(
				Constants::SETTING_CACHE_NEGATIVE_TTL,
				(string) $ttl,
				Date::now(),
				true,
			);
		}

		// Clear active cache driver on setting update and refresh active driver.
		$this->cache_service->clear();
		$this->reload_cache_service();

		return $this->get_cache_status( $request );
	}

	/**
	 * Clear all cached objects across the active cache driver and disk storage.
	 *
	 * @param Request $request Incoming HTTP request (admin-only).
	 * @return array<string, mixed> Current cache status.
	 * @since 1.0.0
	 */
	public function clear_cache( Request $request ): array {
		$this->get_admin_user( $request );

		// Clear active cache driver.
		$this->cache_service->clear();

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
	 * @return void
	 * @since 1.0.0
	 */
	private function refresh_release_config(): void {
		if ( file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' ) ) {
			return;
		}

		InstallWriter::write_config_file(
			ABSPATH . 'server',
			InstallWriter::prepare_config_values( $this->config ),
		);
	}
	/**
	 * Re-initialize the active cache driver instance when settings change.
	 *
	 * @return CacheInterface
	 * @since 1.6.0
	 */
	public function reload_cache_service(): CacheInterface {
		$content_dir         = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );
		$effective_config    = $this->merge_cache_settings( $this->config );
		$this->cache_service = CacheManager::resolve( $effective_config, $content_dir );
		if ( $this->links_api ) {
			$this->links_api->set_cache( $this->cache_service );
		}
		return $this->cache_service;
	}

	/**
	 * Merge settings table overrides into the runtime cache configuration map.
	 *
	 * @param array<string, mixed> $config Configuration map.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	private function merge_cache_settings( array $config ): array {
		$effective = $config;

		$stored_enabled = $this->settings_api->get_option( Constants::SETTING_CACHE_ENABLED );
		if ( null !== $stored_enabled && '' !== trim( $stored_enabled ) ) {
			$effective[ Constants::CACHE_ENABLED ] = filter_var( $stored_enabled, FILTER_VALIDATE_BOOLEAN );
		}

		$stored_driver = $this->settings_api->get_option( Constants::SETTING_CACHE_DRIVER );
		if ( null !== $stored_driver && '' !== trim( $stored_driver ) ) {
			$effective[ Constants::CACHE_DRIVER ] = $stored_driver;
		}

		return $effective;
	}
}
