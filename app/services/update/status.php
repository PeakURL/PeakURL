<?php
/**
 * Dashboard updater status helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Status — updater status payload assembly.
 *
 * @since 1.0.14
 */
class Status {

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Shared updater manifest helper.
	 *
	 * @var Manifest
	 * @since 1.0.14
	 */
	private Manifest $manifest;

	/**
	 * Shared updater workspace helper.
	 *
	 * @var Workspace
	 * @since 1.0.14
	 */
	private Workspace $workspace;

	/**
	 * Create a new updater status helper.
	 *
	 * @param Context   $context   Shared updater context helper.
	 * @param Manifest  $manifest  Shared updater manifest helper.
	 * @param Workspace $workspace Shared updater workspace helper.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Manifest $manifest,
		Workspace $workspace
	) {
		$this->context   = $context;
		$this->manifest  = $manifest;
		$this->workspace = $workspace;
	}

	/**
	 * Build the full update status payload for the dashboard API.
	 *
	 * @param array<string, string>|null $manifest_data   Cached or fetched manifest.
	 * @param string|null                $last_checked_at When the manifest was last fetched.
	 * @param string|null                $last_error      Last update error message.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build_status(
		?array $manifest_data,
		?string $last_checked_at,
		?string $last_error
	): array {
		$manifest_data    = is_array( $manifest_data ) ? $manifest_data : array();
		$current_version  = $this->context->get_current_version();
		$latest_version   = trim( (string) ( $manifest_data['version'] ?? '' ) );
		$minimum_php      = trim( (string) ( $manifest_data['minimumPhp'] ?? '' ) );
		$php_compatible   = '' === $minimum_php || version_compare( PHP_VERSION, $minimum_php, '>=' );
		$update_available = '' !== $latest_version && version_compare( $latest_version, $current_version, '>' );
		$availability     = $this->context->get_apply_availability();

		return array(
			'manifestUrl'         => $this->manifest->get_manifest_url(),
			'currentVersion'      => $current_version,
			'latestVersion'       => $latest_version,
			'updateAvailable'     => $update_available,
			'reinstallAvailable'  => '' !== $latest_version && 0 === version_compare( $latest_version, $current_version ),
			'downloadUrl'         => (string) ( $manifest_data['downloadUrl'] ?? '' ),
			'packageUrl'          => (string) ( $manifest_data['packageUrl'] ?? '' ),
			'checksumSha256'      => (string) ( $manifest_data['checksumSha256'] ?? '' ),
			'releaseNotesUrl'     => (string) ( $manifest_data['releaseNotesUrl'] ?? '' ),
			'releasedAt'          => (string) ( $manifest_data['releasedAt'] ?? '' ),
			'minimumPhp'          => $minimum_php,
			'minimumMysql'        => (string) ( $manifest_data['minimumMysql'] ?? '' ),
			'minimumMariaDb'      => (string) ( $manifest_data['minimumMariaDb'] ?? '' ),
			'product'             => (string) ( $manifest_data['product'] ?? 'peakurl' ),
			'channel'             => (string) ( $manifest_data['channel'] ?? 'latest' ),
			'lastCheckedAt'       => \peakurl_mysql_to_rfc3339( $last_checked_at ),
			'lastError'           => '' !== trim( (string) $last_error ) ? trim( (string) $last_error ) : null,
			'phpVersion'          => PHP_VERSION,
			'phpCompatible'       => $php_compatible,
			'canApply'            => $availability['allowed'],
			'applyDisabledReason' => $availability['reason'],
			'isLocked'            => $this->workspace->has_active_lock(),
			'manifest'            => $manifest_data,
		);
	}
}
