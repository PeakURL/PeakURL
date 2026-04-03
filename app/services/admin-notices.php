<?php
/**
 * Dashboard admin notice service.
 *
 * @package PeakURL\Services
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Utils\AdminNoticeRegistry;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Build dashboard admin notices from core services and hooks.
 *
 * @since 1.0.3
 */
class AdminNotices {

	/**
	 * Return the current dashboard admin notices.
	 *
	 * Fires the WordPress-style `admin_notices` action with a mutable
	 * registry object so core and future extensions can register
	 * structured notices without rendering HTML directly.
	 *
	 * @param array<string, mixed> $context Notice context.
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.3
	 */
	public function get_notices( array $context = array() ): array {
		$registry = new AdminNoticeRegistry();

		$this->register_core_notices( $registry, $context );

		\do_action( 'admin_notices', $registry, $context );

		$notices = \apply_filters(
			'admin_notice_items',
			$registry->all(),
			$context,
			$registry,
		);

		return is_array( $notices ) ? array_values( $notices ) : $registry->all();
	}

	/**
	 * Register the built-in PeakURL dashboard notices.
	 *
	 * @param AdminNoticeRegistry  $registry Notice registry.
	 * @param array<string, mixed> $context  Notice context.
	 * @return void
	 * @since 1.0.3
	 */
	private function register_core_notices(
		AdminNoticeRegistry $registry,
		array $context
	): void {
		$this->maybe_add_update_notice( $registry, $context );
		$this->maybe_add_location_notice( $registry, $context );
	}

	/**
	 * Register the update-available notice when appropriate.
	 *
	 * @param AdminNoticeRegistry  $registry Notice registry.
	 * @param array<string, mixed> $context  Notice context.
	 * @return void
	 * @since 1.0.3
	 */
	private function maybe_add_update_notice(
		AdminNoticeRegistry $registry,
		array $context
	): void {
		$capabilities  = is_array( $context['capabilities'] ?? null )
			? $context['capabilities']
			: array();
		$update_status = is_array( $context['updateStatus'] ?? null )
			? $context['updateStatus']
			: array();

		if ( empty( $capabilities['manageUpdates'] ) || empty( $update_status['updateAvailable'] ) ) {
			return;
		}

		$latest_version  = trim( (string) ( $update_status['latestVersion'] ?? '' ) );
		$current_version = trim( (string) ( $update_status['currentVersion'] ?? '' ) );
		$title           = '' !== $latest_version
			? sprintf(
				/* translators: %s: latest PeakURL version number. */
				__( 'PeakURL %s is available.', 'peakurl' ),
				$latest_version,
			)
			: __( 'A new PeakURL update is available.', 'peakurl' );
		$message = '' !== $latest_version && '' !== $current_version
			? sprintf(
				/* translators: 1: current version, 2: latest version. */
				__( 'You are running PeakURL %1$s. Review the update details and install %2$s from the Updates screen.', 'peakurl' ),
				$current_version,
				$latest_version,
			)
			: __( 'Review the update details and install the latest release from the Updates screen.', 'peakurl' );

		$registry->add(
			array(
				'id'      => 'update_available',
				'type'    => 'info',
				'title'   => $title,
				'message' => $message,
				'action'  => array(
					'label' => __( 'Open Updates', 'peakurl' ),
					'url'   => '/dashboard/settings/updates',
				),
			),
		);
	}

	/**
	 * Register the location-data notice when GeoIP is not ready.
	 *
	 * @param AdminNoticeRegistry  $registry Notice registry.
	 * @param array<string, mixed> $context  Notice context.
	 * @return void
	 * @since 1.0.3
	 */
	private function maybe_add_location_notice(
		AdminNoticeRegistry $registry,
		array $context
	): void {
		$capabilities = is_array( $context['capabilities'] ?? null )
			? $context['capabilities']
			: array();
		$geoip_status = is_array( $context['geoipStatus'] ?? null )
			? $context['geoipStatus']
			: array();

		if (
			empty( $capabilities['manageLocationData'] ) ||
			empty( $geoip_status ) ||
			! empty( $geoip_status['locationAnalyticsReady'] )
		) {
			return;
		}

		$registry->add(
			array(
				'id'      => 'location_data_setup',
				'type'    => 'warning',
				'title'   => __( 'Location analytics is not ready yet.', 'peakurl' ),
				'message' => __(
					'Add your MaxMind credentials and download the GeoLite2 City database to enable visitor country and city reporting across the dashboard.',
					'peakurl'
				),
				'action'  => array(
					'label' => __( 'Open Location Data', 'peakurl' ),
					'url'   => '/dashboard/settings/location',
				),
			),
		);
	}
}
