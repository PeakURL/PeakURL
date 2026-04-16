<?php
/**
 * Location-data status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Location — build the location section of the system-status payload.
 *
 * @since 1.0.14
 */
class Location {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new location status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build location-data status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		$status                = $this->context->get_geoip_service()->get_status();
		$last_downloaded_at    = $this->context->get_settings_api()->get_option(
			'geoip_last_downloaded_at'
		);
		$last_downloaded_stamp = is_string( $last_downloaded_at )
			? strtotime( $last_downloaded_at . ' UTC' )
			: false;

		return array(
			'databasePath'           => (string) ( $status['databasePath'] ?? '' ),
			'databaseExists'         => ! empty( $status['databaseExists'] ),
			'databaseReadable'       => ! empty( $status['databaseReadable'] ),
			'locationAnalyticsReady' => ! empty(
				$status['locationAnalyticsReady']
			),
			'accountIdConfigured'    => ! empty(
				$status['accountIdConfigured']
			),
			'licenseKeyConfigured'   => ! empty(
				$status['licenseKeyConfigured']
			),
			'credentialsConfigured'  => ! empty(
				$status['credentialsConfigured']
			),
			'accountId'              => (string) ( $status['accountId'] ?? '' ),
			'licenseKeyHint'         => (string) ( $status['licenseKeyHint'] ?? '' ),
			'databaseUpdatedAt'      => (string) ( $status['databaseUpdatedAt'] ?? '' ),
			'databaseSizeBytes'      => (int) ( $status['databaseSizeBytes'] ?? 0 ),
			'configurationLabel'     => (string) ( $status['configurationLabel'] ?? '' ),
			'configurationPath'      => (string) ( $status['configurationPath'] ?? '' ),
			'downloadCommand'        => (string) ( $status['downloadCommand'] ?? '' ),
			'lastDownloadedAt'       => false !== $last_downloaded_stamp
				? gmdate( DATE_ATOM, (int) $last_downloaded_stamp )
				: null,
		);
	}
}
