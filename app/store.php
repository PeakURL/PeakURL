<?php
/**
 * MySQL-backed data store for the self-hosted dashboard.
 *
 * Houses every database interaction for users, authentication,
 * short URLs, analytics, webhooks, and the update system.
 * Controllers delegate directly to public methods here while
 * focused traits and utility classes provide the internal
 * session, hydration, analytics, and query support layers.
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
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\Roles;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use PeakURL\Services\I18n;
use PeakURL\Traits\AccountsTrait;
use PeakURL\Traits\AnalyticsSupportTrait;
use PeakURL\Traits\AnalyticsTrait;
use PeakURL\Traits\AuthorizationTrait;
use PeakURL\Traits\BootstrapTrait;
use PeakURL\Traits\CredentialsTrait;
use PeakURL\Traits\FindersTrait;
use PeakURL\Traits\HelpersTrait;
use PeakURL\Traits\HydrationTrait;
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
	 * API hydration helpers.
	 *
	 * @since 1.0.0
	 */
	use HydrationTrait;

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
	 * Whether the workspace has been bootstrapped in this request.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private bool $bootstrapped = false;

	/**
	 * Create a new Store instance.
	 *
	 * @param Connection          $connection Initialized connection manager.
	 * @param array<string, mixed> $config   Runtime configuration map.
	 * @since 1.0.0
	 */
	public function __construct( Connection $connection, array $config ) {
		$this->connection            = $connection;
		$this->db                    = new PeakURL_DB( $connection );
		$this->settings_api          = new SettingsApi( $this->db );
		$this->users_api             = new UsersApi( $this->db );
		$this->links_api             = new LinksApi( $this->db );
		$this->config                = $config;
		$this->roles                 = new Roles();
		$this->totp_service          = new Totp();
		$this->crypto_service        = new Crypto( $config );
		$this->geoip_service         = new Geoip(
			$config,
			$this->settings_api,
			$this->crypto_service,
		);
		$this->mailer_service        = new Mailer(
			$config,
			$this->settings_api,
			$this->crypto_service,
		);
		$this->notifications_service = new Notifications();
		$this->i18n_service          = new I18n(
			$config,
			$this->settings_api,
		);
	}
}
