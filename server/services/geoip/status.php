<?php
/**
 * GeoIP status payload helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;
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
		$database_path     = $this->context->get_db_path();
		$database_exists   = file_exists( $database_path );
		$database_readable = $database_exists && is_readable( $database_path );
		$database_ready    = false;
		$modified_at       = $database_exists ? filemtime( $database_path ) : false;
		$size_bytes        = $database_exists ? filesize( $database_path ) : false;
		$capability        = $this->credentials->get_capability();
		$credentials_set   = $this->credentials->is_configured();

		if ( null === $last_downloaded_at ) {
			$last_downloaded_at = $this->context->get_settings_api()->get_option(
				'geoip_last_downloaded_at'
			);
		}

		$last_checked_at = $this->context->get_settings_api()->get_option(
			'geoip_last_checked_at'
		);

		// Read real build epoch from MMDB reader metadata if installed and valid.
		$build_epoch = null;
		if ( $database_readable && class_exists( Reader::class ) ) {
			try {
				$reader   = new Reader( $database_path );
				$metadata = $reader->metadata();
				$reader->close();

				if ( is_object( $metadata ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$raw_epoch = (int) ( $metadata->buildEpoch ?? 0 );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$db_type = (string) ( $metadata->databaseType ?? '' );

					if ( $raw_epoch > 0 && false !== stripos( $db_type, 'City' ) ) {
						$build_epoch    = $raw_epoch;
						$database_ready = true;
					}
				}
			} catch ( \Throwable $e ) {
				$database_ready = false;
			}
		}

		$downloaded_stamp = is_string( $last_downloaded_at ) && '' !== $last_downloaded_at
			? strtotime( $last_downloaded_at . ' UTC' )
			: false;
		$effective_stamp  = false !== $downloaded_stamp
			? $downloaded_stamp
			: ( false !== $modified_at ? (int) $modified_at : false );
		$is_outdated      = ! $database_ready || false === $effective_stamp || ( time() - $effective_stamp ) >= ( 7 * 86400 );

		// Calculate database age in days based on build epoch, falling back to effective stamp.
		$database_age_days = null;
		$age_base_stamp    = $build_epoch ?? ( false !== $effective_stamp ? $effective_stamp : null );
		if ( null !== $age_base_stamp && $database_ready ) {
			$database_age_days = max( 0, (int) floor( ( time() - $age_base_stamp ) / 86400 ) );
		}

		$is_stale = ! $database_ready || ( null !== $database_age_days && $database_age_days >= 30 );

		return array(
			'contentDir'             => $this->context->get_content_dir(),
			'databasePath'           => $database_path,
			'databaseExists'         => $database_exists,
			'databaseReadable'       => $database_readable,
			'locationAnalyticsReady' => $database_ready,
			'installed'              => $database_ready,
			'is_installed'           => $database_ready,
			'is_outdated'            => $is_outdated,
			'lastDownloadedAt'       => $last_downloaded_at
				? Date::to_iso( (string) $last_downloaded_at )
				: null,
			'lastCheckedAt'          => $last_checked_at
				? Date::to_iso( (string) $last_checked_at )
				: null,
			'accountIdConfigured'    => '' !== $this->context->get_account_id(),
			'licenseKeyConfigured'   => '' !== $this->context->get_license_key(),
			'credentialsConfigured'  => $credentials_set,
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
			'buildEpoch'             => $build_epoch,
			'databaseAgeDays'        => $database_age_days,
			'isStale'                => $is_stale,
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
