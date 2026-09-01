<?php
/**
 * MySQL-backed data store for the self-hosted dashboard.
 *
 * Houses every database interaction for users, authentication,
 * short URLs, analytics, webhooks, and the update system.
 * Controllers delegate directly to public methods here while
 * focused traits and utility classes provide the internal
 * session, formatting, analytics, and query support layers.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL;

use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\Roles;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\CacheManager;
use PeakURL\Services\Crypto;
use PeakURL\Services\Captcha;
use PeakURL\Services\Favicon;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n;
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Traits\Accounts\AccountsTrait;
use PeakURL\Traits\AnalyticsSupportTrait;
use PeakURL\Traits\AnalyticsTrait;
use PeakURL\Traits\AuthorizationTrait;
use PeakURL\Traits\BootstrapTrait;
use PeakURL\Traits\CredentialsTrait;
use PeakURL\Traits\FindersTrait;
use PeakURL\Traits\HelpersTrait;
use PeakURL\Traits\FormattingTrait;
use PeakURL\Traits\LinksTrait;
use PeakURL\Traits\SessionsTrait;
use PeakURL\Traits\SettingsTrait;
use PeakURL\Traits\SystemSupportTrait;
use PeakURL\Traits\SystemTrait;
use PeakURL\Traits\WebhooksTrait;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Store — central persistence facade for the PeakURL dashboard.
 *
 * Public methods and shared internals are split into focused traits so
 * this class stays as the central persistence facade and dependency
 * container. Low-level SQL goes through PeakURL_DB while domain access
 * stays in smaller APIs and traits, similar in spirit to wpdb plus the
 * higher-level WordPress options APIs.
 *
 * @since 1.0.0
 */
class Store {

	/**
	 * Bootstrap methods.
	 *
	 * @since 1.0.0
	 */
	use BootstrapTrait;

	/**
	 * Authorization and capability helpers.
	 *
	 * @since 1.0.0
	 */
	use AuthorizationTrait;

	/**
	 * Session and API token helpers.
	 *
	 * @since 1.0.0
	 */
	use SessionsTrait;

	/**
	 * Accounts and user-management methods.
	 *
	 * @since 1.0.0
	 */
	use AccountsTrait;

	/**
	 * API key and backup-code helpers.
	 *
	 * @since 1.0.0
	 */
	use CredentialsTrait;

	/**
	 * Settings bootstrap and options helpers.
	 *
	 * @since 1.0.0
	 */
	use SettingsTrait;

	/**
	 * API formatting helpers.
	 *
	 * @since 1.0.0
	 */
	use FormattingTrait;

	/**
	 * Links CRUD methods.
	 *
	 * @since 1.0.0
	 */
	use LinksTrait;

	/**
	 * Analytics methods.
	 *
	 * @since 1.0.0
	 */
	use AnalyticsTrait;

	/**
	 * Analytics recording and grouping helpers.
	 *
	 * @since 1.0.0
	 */
	use AnalyticsSupportTrait;

	/**
	 * Webhook methods.
	 *
	 * @since 1.0.0
	 */
	use WebhooksTrait;

	/**
	 * System methods.
	 *
	 * @since 1.0.0
	 */
	use SystemTrait;

	/**
	 * Shared updater and GeoIP support helpers.
	 *
	 * @since 1.0.0
	 */
	use SystemSupportTrait;

	/**
	 * Common record lookups.
	 *
	 * @since 1.0.0
	 */
	use FindersTrait;

	/**
	 * Shared low-level helper methods.
	 *
	 * @since 1.0.0
	 */
	use HelpersTrait;

	/**
	 * Shared connection manager.
	 *
	 * @var Connection
	 * @since 1.0.3
	 */
	private Connection $connection;

	/**
	 * WordPress-style database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Settings/options API.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Users data API.
	 *
	 * @var UsersApi
	 * @since 1.0.0
	 */
	private UsersApi $users_api;

	/**
	 * Links data API.
	 *
	 * @var LinksApi
	 * @since 1.0.0
	 */
	private LinksApi $links_api;

	/**
	 * Runtime configuration values merged from config.php and env.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * WordPress-style role and capability registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * TOTP service for two-factor authentication.
	 *
	 * @var Totp
	 * @since 1.0.0
	 */
	private Totp $totp_service;

	/**
	 * Crypto helper for database-backed settings and session signing.
	 *
	 * @var Crypto
	 * @since 1.0.0
	 */
	private Crypto $crypto_service;

	/**
	 * CAPTCHA provider helper for public link protection.
	 *
	 * @var Captcha
	 * @since 1.2.0
	 */
	private Captcha $captcha_service;

	/**
	 * Site favicon management helper.
	 *
	 * @var Favicon
	 * @since 1.0.14
	 */
	private Favicon $favicon_service;

	/**
	 * Social preview metadata helper.
	 *
	 * @var SocialPreview
	 * @since 1.2.0
	 */
	private SocialPreview $social_preview_service;

	/**
	 * Request geolocation helper for click analytics.
	 *
	 * @var Geoip
	 * @since 1.0.0
	 */
	private Geoip $geoip_service;

	/**
	 * Mail transport and delivery helper.
	 *
	 * @var Mailer
	 * @since 1.0.0
	 */
	private Mailer $mailer_service;

	/**
	 * Transactional notification helper.
	 *
	 * @var Notifications
	 * @since 1.0.2
	 */
	private Notifications $notifications_service;

	/**
	 * Site locale and catalog helper.
	 *
	 * @var I18n
	 * @since 1.0.3
	 */
	private I18n $i18n_service;

	/**
	 * Active transient/object cache driver.
	 *
	 * @var CacheInterface
	 * @since 1.6.0
	 */
	private CacheInterface $cache_service;

	/**
	 * Whether the site has been bootstrapped in this request.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private bool $bootstrapped = false;

	/**
	 * Create a new Store instance.
	 *
	 * @param Connection           $connection Initialized connection manager.
	 * @param array<string, mixed> $config     Runtime configuration map.
	 * @since 1.0.0
	 */
	public function __construct( Connection $connection, array $config ) {
		$this->connection             = $connection;
		$this->db                     = new PeakURL_DB( $connection );
		$this->config                 = $config;
		$this->settings_api           = new SettingsApi( $this->db );
		$content_dir                  = (string) ( $config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );
		$effective_config             = $this->resolve_effective_cache_config( $config );
		$this->cache_service          = CacheManager::resolve( $effective_config, $content_dir );
		$this->users_api              = new UsersApi( $this->db );
		$this->links_api              = new LinksApi( $this->db, $this->cache_service, $this->settings_api );
		$this->roles                  = new Roles();
		$this->totp_service           = new Totp();
		$this->crypto_service         = new Crypto( $config );
		$this->captcha_service        = new Captcha(
			$config,
			$this->settings_api,
			$this->crypto_service,
		);
		$this->favicon_service        = new Favicon(
			$config,
			$this->settings_api,
		);
		$this->social_preview_service = new SocialPreview(
			$config,
			$this->settings_api,
		);
		$this->geoip_service          = new Geoip(
			$config,
			$this->settings_api,
			$this->crypto_service,
		);
		$this->mailer_service         = new Mailer(
			$config,
			$this->settings_api,
			$this->crypto_service,
		);
		$this->notifications_service  = new Notifications();
		$this->i18n_service           = new I18n(
			$config,
			$this->settings_api,
		);
	}

	/**
	 * Get the active cache driver instance.
	 *
	 * @return CacheInterface
	 * @since 1.6.0
	 */
	public function get_cache(): CacheInterface {
		return $this->cache_service;
	}

	/**
	 * Re-resolve the active cache driver instance when settings change.
	 *
	 * @return CacheInterface
	 * @since 1.6.0
	 */
	public function refresh_cache_service(): CacheInterface {
		$content_dir         = (string) ( $this->config[ Constants::CONTENT_DIR ] ?? ( ABSPATH . Constants::DEFAULT_CONTENT_DIR ) );
		$effective_config    = $this->resolve_effective_cache_config( $this->config );
		$this->cache_service = CacheManager::resolve( $effective_config, $content_dir );
		$this->links_api->set_cache( $this->cache_service );
		return $this->cache_service;
	}

	/**
	 * Merge settings table overrides into the runtime cache configuration map.
	 *
	 * @param array<string, mixed> $config Merged configuration map.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	private function resolve_effective_cache_config( array $config ): array {
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
