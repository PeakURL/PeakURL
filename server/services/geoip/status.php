<?php
/**
 * GeoIP status payload helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Services\Geoip as GeoipService;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Status — build the current MaxMind integration status payload.
 *
 * @since 1.0.14
 */
class Status {

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Credentials helper.
	 *
	 * @var Credentials
	 * @since 1.0.14
	 */
	private Credentials $credentials;

	/**
	 * Create a new status helper.
	 *
	 * @param Context     $context     Shared GeoIP context.
	 * @param Credentials $credentials Credentials helper.
	 * @since 1.0.14
	 */
	public function __construct( Context $context, Credentials $credentials ) {
		$this->context     = $context;
		$this->credentials = $credentials;
	}

	/**
	 * Build the current GeoIP status payload.
	 *
	 * @param string|null $last_downloaded_at Optional download timestamp.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_status( ?string $last_downloaded_at = null ): array {
		$database_path   = $this->context->get_db_path();
		$database_exists = file_exists( $database_path );
		$database_ready  = $database_exists && is_readable( $database_path );
		$modified_at     = $database_exists ? filemtime( $database_path ) : false;
		$size_bytes      = $database_exists ? filesize( $database_path ) : false;
		$capability      = $this->credentials->get_capability();

		if ( null === $last_downloaded_at ) {
			$last_downloaded_at = $this->context->get_settings_api()->get_option(
				'geoip_last_downloaded_at'
			);
		}

		return array(
			'contentDir'             => $this->context->get_content_dir(),
			'databasePath'           => $database_path,
			'databaseExists'         => $database_exists,
			'databaseReadable'       => $database_ready,
			'locationAnalyticsReady' => $database_ready,
			'installed'              => $database_ready,
			'lastDownloadedAt'       => $last_downloaded_at
				? Date::to_iso( (string) $last_downloaded_at )
				: null,
			'accountIdConfigured'    => '' !== $this->context->get_account_id(),
			'licenseKeyConfigured'   => '' !== $this->context->get_license_key(),
			'credentialsConfigured'  => $this->credentials->is_configured(),
			'accountId'              => $this->context->get_account_id(),
			'licenseKeyHint'         => $this->credentials->get_license_key_hint(),
			'databaseUpdatedAt'      => false !== $modified_at
				? gmdate( DATE_ATOM, (int) $modified_at )
				: null,
			'databaseSizeBytes'      => false !== $size_bytes
				? (int) $size_bytes
				: 0,
			'canManageFromDashboard' => $capability['allowed'],
			'manageDisabledReason'   => $capability['reason'],
			'configurationLabel'     => 'settings table',
			'downloadCommand'        => \PeakURL\Core\Config\Environment::get_instance()->get_geoip_command(),
			'downloadUrl'            => GeoipService::DOWNLOAD_URL,
		);
	}

	/**
	 * Format an existing GeoIP status payload with download metadata.
	 *
	 * @param array<string, mixed> $status             Raw GeoIP status payload.
	 * @param string|null          $last_downloaded_at Optional download timestamp.
	 * @return array<string, mixed> Formatted status.
	 * @since 1.2.3
	 */
	public function format_status(
		array $status,
		?string $last_downloaded_at = null
	): array {
		if ( null === $last_downloaded_at ) {
			$last_downloaded_at = $this->context->get_settings_api()->get_option(
				'geoip_last_downloaded_at'
			);
		}

		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $last_downloaded_at
			? Date::to_iso( (string) $last_downloaded_at )
			: null;

		return $status;
	}
}
