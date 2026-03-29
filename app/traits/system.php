<?php
/**
 * Data store system trait.
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
 * Store_System_Trait — updater and GeoIP methods for Data_Store.
 *
 * @since 1.0.0
 */
trait Store_System_Trait {

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
			'Admin access is required.',
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
			'Admin access is required.',
		);

		try {
			$status = $this->mailer_service->save_configuration(
				ABSPATH . 'app',
				$this->config,
				$payload,
			);
		} catch ( RuntimeException $exception ) {
			throw new Api_Exception( $exception->getMessage(), 422 );
		}

		$this->config         = Config::load( ABSPATH . 'app' );
		$this->mailer_service = new Mailer_Service(
			$this->config,
			$this->settings_api,
		);
		$status['saved']      = true;

		return $status;
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
			'Admin access is required.',
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
			'Admin access is required.',
		);

		$status                     = $this->geoip_service->get_status();
		$last_downloaded_at         = $this->get_setting_value( 'geoip_last_downloaded_at' );
		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] =
			$last_downloaded_at
				? $this->to_iso( $last_downloaded_at )
				: null;

		return $status;
	}

	/**
	 * Save MaxMind credentials into the active runtime config target.
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
			'Admin access is required.',
		);
		$this->assert_geoip_dashboard_management_allowed();

		$app_path = ABSPATH . 'app';
		try {
			$status = $this->geoip_service->save_configuration(
				$app_path,
				$this->config,
				$payload,
			);
		} catch ( RuntimeException $exception ) {
			throw new Api_Exception( $exception->getMessage(), 422 );
		}

		$this->config['PEAKURL_CONTENT_DIR']        = (string) ( $status['contentDir'] ?? '' );
		$this->config['PEAKURL_GEOIP_DB_PATH']      = (string) ( $status['databasePath'] ?? '' );
		$this->config['PEAKURL_MAXMIND_ACCOUNT_ID'] = (string) ( $status['accountId'] ?? '' );

		if ( ! empty( $payload['licenseKey'] ) ) {
			$this->config['PEAKURL_MAXMIND_LICENSE_KEY'] =
				(string) $payload['licenseKey'];
		} elseif ( empty( $status['licenseKeyConfigured'] ) ) {
			$this->config['PEAKURL_MAXMIND_LICENSE_KEY'] = '';
		}

		$this->geoip_service        = new Geoip_Service( $this->config );
		$status['installed']        = ! empty( $status['locationAnalyticsReady'] );
		$status['lastDownloadedAt'] = $this->get_setting_value( 'geoip_last_downloaded_at' );
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
			'Admin access is required.',
		);
		$this->assert_geoip_dashboard_management_allowed();

		try {
			$status = $this->geoip_service->download_database();
		} catch ( RuntimeException $exception ) {
			throw new Api_Exception( $exception->getMessage(), 422 );
		}

		$this->upsert_setting( 'geoip_last_downloaded_at', $this->now(), false );
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
			'Admin access is required.',
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
	 * @throws Api_Exception When run from a source checkout or no update is available.
	 * @since 1.0.0
	 */
	public function apply_update( Request $request ): array {
		$this->assert_request_capability(
			$request,
			'manage_updates',
			'Admin access is required.',
		);

		$status = $this->load_update_status( true );

		if ( empty( $status['updateAvailable'] ) ) {
			throw new Api_Exception( 'PeakURL is already up to date.', 422 );
		}

		if ( empty( $status['canApply'] ) ) {
			throw new Api_Exception(
				(string) ( $status['applyDisabledReason'] ?? 'PeakURL cannot apply this update.' ),
				422,
			);
		}

		$manifest = is_array( $status['manifest'] ?? null )
			? $status['manifest']
			: array();

		if ( empty( $manifest ) ) {
			throw new Api_Exception(
				'PeakURL could not load the update manifest.',
				502,
			);
		}

		$update_service = new Update_Service( $this->config );
		$result         = $update_service->apply_update( $manifest );

		$this->upsert_setting(
			'installed_version',
			(string) ( $result['version'] ?? $this->config['PEAKURL_VERSION'] ?? '0.0.0' ),
			false,
		);
		$this->upsert_setting( 'update_last_applied_at', $this->now(), false );
		$this->upsert_setting( 'update_last_checked_at', $this->now(), false );
		$this->upsert_setting(
			'update_last_result_json',
			$this->encode_json( $manifest ),
			false,
		);
		$this->delete_settings( array( 'update_last_error' ) );

		return array(
			'applied'        => true,
			'currentVersion' => (string) ( $result['version'] ?? '' ),
			'latestVersion'  => (string) ( $manifest['version'] ?? '' ),
			'packageUrl'     => (string) ( $result['packageUrl'] ?? '' ),
			'appliedAt'      => (string) ( $result['appliedAt'] ?? gmdate( DATE_ATOM ) ),
			'reloadRequired' => true,
		);
	}
}
