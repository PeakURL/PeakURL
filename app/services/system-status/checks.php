<?php
/**
 * Health checks builder for system status.
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
 * Checks — get the health checks and summary for system status.
 *
 * @since 1.0.14
 */
class Checks {

	/**
	 * Get the top-level health check list.
	 *
	 * @param array<string, mixed> $server   Server status section.
	 * @param array<string, mixed> $database Database status section.
	 * @param array<string, mixed> $storage  Storage status section.
	 * @param array<string, mixed> $mail     Mail status section.
	 * @param array<string, mixed> $location Location-data status section.
	 * @param array<string, mixed> $cache    Cache status section.
	 * @return array<int, array<string, string>>
	 * @since 1.0.14
	 */
	public function status_checks(
		array $server,
		array $database,
		array $storage,
		array $mail,
		array $location,
		array $cache = array()
	): array {
		$checks   = array();
		$checks[] = array(
			'id'          => 'database',
			'label'       => __( 'Database connection', 'peakurl' ),
			'status'      => ! empty( $database['connected'] ) ? 'ok' : 'error',
			'description' => ! empty( $database['connected'] )
				? __( 'PeakURL can reach the configured MySQL or MariaDB database.', 'peakurl' )
				: __( 'PeakURL could not reach the configured database.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'database-schema',
			'label'       => __( 'Database schema', 'peakurl' ),
			'status'      => ! empty( $database['schemaUpgradeRequired'] )
				? 'warning'
				: 'ok',
			'description' => ! empty( $database['schemaUpgradeRequired'] )
				? __( 'PeakURL found schema changes or leftovers that still need the database upgrader.', 'peakurl' )
				: __( 'The PeakURL database schema matches the current release.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'cache',
			'label'       => __( 'Object cache', 'peakurl' ),
			'status'      => ( ! empty( $cache['enabled'] ) && 'active' === ( $cache['status'] ?? '' ) )
				? 'ok'
				: 'warning',
			'description' => $this->get_cache_check_description( $cache ),
		);
		$checks[] = array(
			'id'          => 'content',
			'label'       => __( 'Content storage', 'peakurl' ),
			'status'      => ! empty( $storage['contentExists'] ) && ! empty( $storage['contentWritable'] )
				? 'ok'
				: 'error',
			'description' => ! empty( $storage['contentExists'] ) && ! empty( $storage['contentWritable'] )
				? __( 'The persistent content directory is present and writable.', 'peakurl' )
				: __( 'The persistent content directory is missing or not writable.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'languages',
			'label'       => __( 'Language packs', 'peakurl' ),
			'status'      => ! empty( $storage['languagesDirectoryExists'] ) && ! empty( $storage['languagesDirectoryReadable'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $storage['languagesDirectoryExists'] ) && ! empty( $storage['languagesDirectoryReadable'] )
				? __( 'Installed dashboard and PHP translation files can be read from content/languages.', 'peakurl' )
				: __( 'PeakURL cannot read the content/languages directory right now.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'intl',
			'label'       => __( 'PHP intl extension', 'peakurl' ),
			'status'      => ! empty( $server['extensions']['intl'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $server['extensions']['intl'] )
				? __( 'Server-side locale helpers can use native intl metadata.', 'peakurl' )
				: __( 'PeakURL can still run without intl, but server-side locale helpers use fallbacks.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'zip',
			'label'       => __( 'PHP ZipArchive', 'peakurl' ),
			'status'      => ! empty( $server['extensions']['zip'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $server['extensions']['zip'] )
				? __( 'Dashboard updates can use ZipArchive when the install type allows it.', 'peakurl' )
				: __( 'Dashboard-managed updates require the PHP ZipArchive extension.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'mail',
			'label'       => __( 'Email transport', 'peakurl' ),
			'status'      => ! empty( $mail['transportReady'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $mail['transportReady'] )
				? __( 'PeakURL has an active mail transport configuration.', 'peakurl' )
				: __( 'PeakURL is missing part of the active mail transport configuration.', 'peakurl' ),
		);
		$checks[] = array(
			'id'          => 'geoip',
			'label'       => __( 'Location data', 'peakurl' ),
			'status'      => ! empty( $location['locationAnalyticsReady'] )
				? 'ok'
				: 'warning',
			'description' => ! empty( $location['locationAnalyticsReady'] )
				? __( 'The GeoLite2 City database is available for visitor location analytics.', 'peakurl' )
				: __( 'Visitor location analytics is not fully ready yet.', 'peakurl' ),
		);

		return $checks;
	}

	/**
	 * Get the overall status summary from the check list.
	 *
	 * @param array<int, array<string, string>> $checks Health checks list.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function status_summary( array $checks ): array {
		$ok_count      = 0;
		$warning_count = 0;
		$error_count   = 0;

		foreach ( $checks as $check ) {
			$state = (string) ( $check['status'] ?? '' );

			if ( 'error' === $state ) {
				++$error_count;
				continue;
			}

			if ( 'warning' === $state ) {
				++$warning_count;
				continue;
			}

			if ( 'ok' === $state ) {
				++$ok_count;
			}
		}

		return array(
			'overall'      => $error_count > 0
				? 'error'
				: ( $warning_count > 0 ? 'warning' : 'ok' ),
			'okCount'      => $ok_count,
			'warningCount' => $warning_count,
			'errorCount'   => $error_count,
			'totalChecks'  => count( $checks ),
		);
	}

	/**
	 * Build human-readable description for cache health check.
	 *
	 * @param array<string, mixed> $cache Cache status map.
	 * @return string Localised check description.
	 * @since 1.6.0
	 */
	private function get_cache_check_description( array $cache ): string {
		$enabled = ! empty( $cache['enabled'] );
		if ( ! $enabled ) {
			return __( 'Object cache is currently disabled in configuration.', 'peakurl' );
		}

		$status        = (string) ( $cache['status'] ?? '' );
		$active_driver = (string) ( $cache['activeDriver'] ?? '' );

		if ( 'active' === $status ) {
			if ( 'redis' === $active_driver ) {
				$version = ! empty( $cache['redis']['serverVersion'] ) ? ' (v' . $cache['redis']['serverVersion'] . ')' : '';
				/* translators: %s: Redis server version or empty string */
				return sprintf( __( 'High-performance Redis%s in-memory cache is active.', 'peakurl' ), $version );
			}
			if ( 'apcu' === $active_driver ) {
				return __( 'High-performance APCu shared memory cache is active.', 'peakurl' );
			}
			if ( 'file' === $active_driver ) {
				return __( 'Filesystem object cache is active in content/cache.', 'peakurl' );
			}
			return __( 'Object and transient cache is operational.', 'peakurl' );
		}

		if ( 'fallback' === $status ) {
			return __( 'Configured cache driver is unavailable; operating on fallback driver.', 'peakurl' );
		}

		return __( 'No cache backend is currently usable; requests query the database directly.', 'peakurl' );
	}
}
