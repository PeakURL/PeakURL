<?php
/**
 * Shared system-status context helpers.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\Geoip;
use PeakURL\Services\I18n\Manager as I18nManager;
use PeakURL\Services\Mailer;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Context — shared dependencies and runtime helpers for system status.
 *
 * @since 1.0.14
 */
class Context {

	/**
	 * Runtime configuration.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.14
	 */
	private PeakURL_DB $db;

	/**
	 * Settings API dependency.
	 *
	 * @var SettingsApi
	 * @since 1.0.14
	 */
	private SettingsApi $settings_api;

	/**
	 * GeoIP service dependency.
	 *
	 * @var Geoip
	 * @since 1.0.14
	 */
	private Geoip $geoip_service;

	/**
	 * Mail transport dependency.
	 *
	 * @var Mailer
	 * @since 1.0.14
	 */
	private Mailer $mailer_service;

	/**
	 * Database schema dependency.
	 *
	 * @var DatabaseSchema
	 * @since 1.0.14
	 */
	private DatabaseSchema $database_schema;

	/**
	 * I18n service dependency.
	 *
	 * @var I18nManager
	 * @since 1.0.14
	 */
	private I18nManager $i18n_service;

	/**
	 * Create a new system-status context.
	 *
	 * @param array<string, mixed> $config          Runtime configuration.
	 * @param PeakURL_DB           $db              Shared database wrapper.
	 * @param SettingsApi          $settings_api    Settings API dependency.
	 * @param Geoip                $geoip_service   GeoIP service dependency.
	 * @param Mailer               $mailer_service  Mail transport dependency.
	 * @param DatabaseSchema       $database_schema Database schema dependency.
	 * @param I18nManager          $i18n_service    I18n service dependency.
	 * @since 1.0.14
	 */
	public function __construct(
		array $config,
		PeakURL_DB $db,
		SettingsApi $settings_api,
		Geoip $geoip_service,
		Mailer $mailer_service,
		DatabaseSchema $database_schema,
		I18nManager $i18n_service
	) {
		$this->config          = $config;
		$this->db              = $db;
		$this->settings_api    = $settings_api;
		$this->geoip_service   = $geoip_service;
		$this->mailer_service  = $mailer_service;
		$this->database_schema = $database_schema;
		$this->i18n_service    = $i18n_service;
	}

	/**
	 * Return the runtime configuration map.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Return the shared database wrapper.
	 *
	 * @return PeakURL_DB
	 * @since 1.0.14
	 */
	public function get_db(): PeakURL_DB {
		return $this->db;
	}

	/**
	 * Return the settings API dependency.
	 *
	 * @return SettingsApi
	 * @since 1.0.14
	 */
	public function get_settings_api(): SettingsApi {
		return $this->settings_api;
	}

	/**
	 * Return the GeoIP service dependency.
	 *
	 * @return Geoip
	 * @since 1.0.14
	 */
	public function get_geoip_service(): Geoip {
		return $this->geoip_service;
	}

	/**
	 * Return the mail transport dependency.
	 *
	 * @return Mailer
	 * @since 1.0.14
	 */
	public function get_mailer_service(): Mailer {
		return $this->mailer_service;
	}

	/**
	 * Return the database schema dependency.
	 *
	 * @return DatabaseSchema
	 * @since 1.0.14
	 */
	public function get_database_schema(): DatabaseSchema {
		return $this->database_schema;
	}

	/**
	 * Return the i18n service dependency.
	 *
	 * @return I18nManager
	 * @since 1.0.14
	 */
	public function get_i18n_service(): I18nManager {
		return $this->i18n_service;
	}

	/**
	 * Return the configured PeakURL version.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_peakurl_version(): string {
		$version = trim(
			(string) ( $this->config[ Constants::CONFIG_VERSION ] ?? '' ),
		);

		return '' !== $version ? $version : Constants::DEFAULT_VERSION;
	}

	/**
	 * Determine whether this runtime is the source checkout.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_source_checkout(): bool {
		return file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' );
	}
}
