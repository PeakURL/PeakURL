<?php
/**
 * PeakURL system-status manager.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Api\SettingsApi;
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
 * Manager — build the complete dashboard system-status payload.
 *
 * @since 1.0.14
 */
class Manager {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Site status helper.
	 *
	 * @var Site
	 * @since 1.0.14
	 */
	private Site $site;

	/**
	 * Server status helper.
	 *
	 * @var Server
	 * @since 1.0.14
	 */
	private Server $server;

	/**
	 * Database status helper.
	 *
	 * @var Database
	 * @since 1.0.14
	 */
	private Database $database;

	/**
	 * Storage status helper.
	 *
	 * @var Storage
	 * @since 1.0.14
	 */
	private Storage $storage;

	/**
	 * Mail status helper.
	 *
	 * @var Mail
	 * @since 1.0.14
	 */
	private Mail $mail;

	/**
	 * Location-data status helper.
	 *
	 * @var Location
	 * @since 1.0.14
	 */
	private Location $location;

	/**
	 * Data counts helper.
	 *
	 * @var Data
	 * @since 1.0.14
	 */
	private Data $data;

	/**
	 * Health checks helper.
	 *
	 * @var Checks
	 * @since 1.0.14
	 */
	private Checks $checks;

	/**
	 * Create a new system-status manager instance.
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
		$this->context  = new Context(
			$config,
			$db,
			$settings_api,
			$geoip_service,
			$mailer_service,
			$database_schema,
			$i18n_service,
		);
		$this->site     = new Site( $this->context );
		$this->server   = new Server( $this->context );
		$this->database = new Database( $this->context );
		$this->storage  = new Storage( $this->context );
		$this->mail     = new Mail( $this->context );
		$this->location = new Location( $this->context );
		$this->data     = new Data( $this->context );
		$this->checks   = new Checks();
	}

	/**
	 * Build the complete dashboard system-status payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_status(): array {
		$site     = $this->site->build();
		$server   = $this->server->build();
		$database = $this->database->build();
		$storage  = $this->storage->build();
		$mail     = $this->mail->build();
		$location = $this->location->build();
		$data     = $this->data->build();
		$checks   = $this->checks->build(
			$server,
			$database,
			$storage,
			$mail,
			$location,
		);

		return array(
			'generatedAt' => gmdate( DATE_ATOM ),
			'summary'     => $this->checks->build_summary( $checks ),
			'checks'      => $checks,
			'site'        => $site,
			'server'      => $server,
			'database'    => $database,
			'storage'     => $storage,
			'mail'        => $mail,
			'location'    => $location,
			'data'        => $data,
		);
	}
}
