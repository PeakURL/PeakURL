<?php
/**
 * Data store system support trait.
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
 * Store_System_Support_Trait — shared updater and GeoIP internals.
 *
 * @since 1.0.0
 */
trait Store_System_Support_Trait {

	/**
	 * Load (and optionally refresh) the cached update status.
	 *
	 * @param bool $force_check Whether to bypass the cache TTL and fetch fresh.
	 * @return array<string, mixed> Compiled update status payload.
	 * @since 1.0.0
	 */
	private function load_update_status( bool $force_check ): array {
		$update_service  = new Update_Service( $this->config );
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
			} catch ( Throwable $exception ) {
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

		return $update_service->build_status(
			$cached_manifest,
			$last_checked,
			$last_error,
		);
	}

	/**
	 * Ensure GeoIP dashboard management is allowed in this runtime.
	 *
	 * @throws Api_Exception When the runtime config target is not writable.
	 * @since 1.0.0
	 */
	private function assert_geoip_dashboard_management_allowed(): void {
		$status = $this->geoip_service->get_status();

		if ( ! empty( $status['canManageFromDashboard'] ) ) {
			return;
		}

		throw new Api_Exception(
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
