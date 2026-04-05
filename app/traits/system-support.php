<?php
/**
 * Data store system support trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Http\ApiException;
use PeakURL\Services\Update;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SystemSupportTrait — shared updater and GeoIP internals.
 *
 * @since 1.0.0
 */
trait SystemSupportTrait {

	/**
	 * Load (and optionally refresh) the cached update status.
	 *
	 * @param bool $force_check Whether to bypass the cache TTL and fetch fresh.
	 * @return array<string, mixed> Compiled update status payload.
	 * @since 1.0.0
	 */
	private function load_update_status( bool $force_check ): array {
		$update_service  = new Update( $this->config );
		$manifest_url    = $update_service->get_manifest_url();
		$last_checked    = $this->get_setting_value( 'update_last_checked_at' );
		$last_error      = $this->get_setting_value( 'update_last_error' );
		$cached_manifest = $this->decode_update_manifest(
			$this->get_setting_value( 'update_last_result_json' ),
		);

		$this->upsert_setting( 'update_manifest_url', $manifest_url, false );

		if (
			$force_check ||
			empty( $cached_manifest ) ||
			! $update_service->is_cache_fresh( $last_checked )
		) {
			try {
				$cached_manifest = $update_service->fetch_manifest();
				$last_checked    = $this->now();
				$last_error      = null;
				$this->upsert_setting(
					'update_last_result_json',
					$this->encode_json( $cached_manifest ),
					false,
				);
				$this->upsert_setting(
					'update_last_checked_at',
					$last_checked,
					false,
				);
				$this->delete_settings( array( 'update_last_error' ) );
			} catch ( \Throwable $exception ) {
				$last_checked = $this->now();
				$last_error   = $exception->getMessage();
				$this->upsert_setting(
					'update_last_checked_at',
					$last_checked,
					false,
				);
				$this->upsert_setting(
					'update_last_error',
					$last_error,
					false,
				);
			}
		}

		$status = $update_service->build_status(
			$cached_manifest,
			$last_checked,
			$last_error,
		);

		try {
			$status['database'] = $this->get_database_schema_service()->inspect();
		} catch ( \Throwable $exception ) {
			$status['database'] = array(
				'currentVersion'  => 0,
				'targetVersion'   => Constants::DB_SCHEMA_VERSION,
				'compatible'      => false,
				'upgradeRequired' => true,
				'upToDate'        => false,
				'errorCount'      => 1,
				'warningCount'    => 0,
				'issues'          => array(
					array(
						'id'       => 'schema-inspection-failed',
						'severity' => 'error',
						'label'    => __( 'PeakURL could not inspect the database schema.', 'peakurl' ),
					),
				),
				'issuesCount'     => 1,
				'missingTables'   => array(),
				'lastUpgradedAt'  => null,
				'lastError'       => $exception->getMessage(),
				'upgraded'        => false,
				'changes'         => array(),
			);
		}

		return $status;
	}

	/**
	 * Apply a release package from the resolved update status payload.
	 *
	 * @param array<string, mixed> $status Resolved update status.
	 * @param bool                 $reinstall Whether the action is a reinstall.
	 * @return array<string, mixed> Result of the install operation.
	 * @since 1.0.5
	 */
	private function install_release_from_status( array $status, bool $reinstall = false ): array {
		if ( empty( $status['canApply'] ) ) {
			throw new ApiException(
				(string) ( $status['applyDisabledReason'] ?? __( 'PeakURL cannot apply this release.', 'peakurl' ) ),
				422,
			);
		}

		$manifest = is_array( $status['manifest'] ?? null )
			? $status['manifest']
			: array();

		if ( empty( $manifest ) ) {
			throw new ApiException(
				__( 'PeakURL could not load the update manifest.', 'peakurl' ),
				502,
			);
		}

		$update_service = new Update( $this->config );

		try {
			$result = $update_service->apply_update( $manifest );
		} catch ( \Throwable $exception ) {
			$this->upsert_setting( 'update_last_checked_at', $this->now(), false );
			$this->upsert_setting(
				'update_last_error',
				$exception->getMessage(),
				false,
			);

			throw new ApiException( $exception->getMessage(), 500 );
		}

		$installed_version = (string) (
			$result['version']
			?? $this->config[ Constants::CONFIG_VERSION ]
			?? Constants::DEFAULT_VERSION
		);

		$this->upsert_setting(
			'installed_version',
			$installed_version,
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
			'reinstalled'    => $reinstall,
			'currentVersion' => $installed_version,
			'latestVersion'  => (string) ( $manifest['version'] ?? '' ),
			'packageUrl'     => (string) ( $result['packageUrl'] ?? '' ),
			'appliedAt'      => (string) ( $result['appliedAt'] ?? gmdate( DATE_ATOM ) ),
			'reloadRequired' => true,
		);
	}

	/**
	 * Ensure GeoIP dashboard management is allowed in this runtime.
	 *
	 * @throws ApiException When the runtime config target is not writable.
	 * @since 1.0.0
	 */
	private function assert_geoip_dashboard_management_allowed(): void {
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
	 * Decode a cached update manifest JSON string.
	 *
	 * @param string|null $value Raw JSON from the settings table.
	 * @return array<string, string>|null Decoded manifest or null.
	 * @since 1.0.0
	 */
	private function decode_update_manifest( ?string $value ): ?array {
		if ( empty( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Build an SQL condition that matches local and private IP address ranges.
	 *
	 * @param string $column SQL column name containing the IP string.
	 * @return string SQL boolean expression.
	 * @since 1.0.0
	 */
	private function private_network_ip_sql( string $column ): string {
		return sprintf(
			'(
                %1$s LIKE \'10.%%\' OR
                %1$s LIKE \'127.%%\' OR
                %1$s LIKE \'192.168.%%\' OR
                %1$s REGEXP \'^172\\\\.(1[6-9]|2[0-9]|3[0-1])\\\\.\' OR
                %1$s = \'::1\' OR
                LOWER(%1$s) LIKE \'fc%%\' OR
                LOWER(%1$s) LIKE \'fd%%\'
            )',
			$column,
		);
	}
}
