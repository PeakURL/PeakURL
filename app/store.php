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

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Data_Store — central persistence facade for the PeakURL dashboard.
 *
 * Public methods and shared internals are split into focused traits so
 * this class stays as the central persistence facade and dependency
 * container. Low-level SQL goes through PeakURL_DB while domain access
 * stays in smaller APIs and traits, similar in spirit to wpdb plus the
 * higher-level WordPress options APIs.
 *
 * @since 1.0.0
 */
class Data_Store {

	/**
	 * Bootstrap methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Bootstrap_Trait;

	/**
	 * Authorization and capability helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Authorization_Trait;

	/**
	 * Session and API token helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Sessions_Trait;

	/**
	 * Accounts and user-management methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Accounts_Trait;

	/**
	 * API key and backup-code helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Credentials_Trait;

	/**
	 * Settings bootstrap and options helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Settings_Trait;

	/**
	 * API hydration helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Hydration_Trait;

	/**
	 * Links CRUD methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Links_Trait;

	/**
	 * Analytics methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Analytics_Trait;

	/**
	 * Analytics recording and grouping helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_Analytics_Support_Trait;

	/**
	 * Webhook methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Webhooks_Trait;

	/**
	 * System methods.
	 *
	 * @since 1.0.0
	 */
	use Store_System_Trait;

	/**
	 * Shared updater and GeoIP support helpers.
	 *
	 * @since 1.0.0
	 */
	use Store_System_Support_Trait;

	/**
	 * Common record lookups.
	 *
	 * @since 1.0.0
	 */
	use Store_Finders_Trait;

	/**
	 * Shared low-level helper methods.
	 *
	 * @since 1.0.0
	 */
	use Store_Helpers_Trait;

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
	 * @var Settings_API
	 * @since 1.0.0
	 */
	private Settings_API $settings_api;

	/**
	 * Users data API.
	 *
	 * @var Users_API
	 * @since 1.0.0
	 */
	private Users_API $users_api;

	/**
	 * Links data API.
	 *
	 * @var Links_API
	 * @since 1.0.0
	 */
	private Links_API $links_api;

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
	 * @var PeakURL_Roles
	 * @since 1.0.0
	 */
	private PeakURL_Roles $roles;

	/**
	 * TOTP service for two-factor authentication.
	 *
	 * @var Totp_Service
	 * @since 1.0.0
	 */
	private Totp_Service $totp_service;

	/**
	 * Request geolocation helper for click analytics.
	 *
	 * @var Geoip_Service
	 * @since 1.0.0
	 */
	private Geoip_Service $geoip_service;

	/**
	 * Mail transport and delivery helper.
	 *
	 * @var Mailer_Service
	 * @since 1.0.0
	 */
	private Mailer_Service $mailer_service;

	/**
	 * Whether the workspace has been bootstrapped in this request.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private bool $bootstrapped = false;

	/**
	 * Create a new Data_Store instance.
	 *
	 * @param Database            $database Initialized Database wrapper.
	 * @param array<string, mixed> $config   Runtime configuration map.
	 * @since 1.0.0
	 */
	public function __construct( Database $database, array $config ) {
		$this->db             = new PeakURL_DB( $database );
		$this->settings_api   = new Settings_API( $this->db );
		$this->users_api      = new Users_API( $this->db );
		$this->links_api      = new Links_API( $this->db );
		$this->config         = $config;
		$this->roles          = new PeakURL_Roles();
		$this->totp_service   = new Totp_Service();
		$this->geoip_service  = new Geoip_Service( $config );
		$this->mailer_service = new Mailer_Service(
			$config,
			$this->settings_api,
		);
	}
}
