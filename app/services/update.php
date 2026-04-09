<?php
/**
 * Dashboard updater service.
 *
 * Handles update manifest fetching, version comparison, package
 * download / verification / extraction, package-root backup and
 * restore, and automatic rollback on failure.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Update — self-hosted dashboard updater.
 *
 * Downloads release archives, verifies checksums, extracts packages,
 * backs up release-managed paths, applies file replacements, syncs
 * package-provided content files, and rolls back on failure. Only
 * available from installed release packages.
 *
 * @since 1.0.0
 */
class Update {

	/** @var int Cache time-to-live for the update manifest (12 hours). */
	private const CACHE_TTL = 43200;
	/** @var int Lock time-to-live for stale lock cleanup (30 minutes). */
	private const LOCK_TTL = 1800;
	/** @var array<int, string> Root entries that must survive updates. */
	private const PRESERVED_ROOT_PATHS = array(
		'config.php',
		'content',
		'.maintenance',
	);

	/**
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * @param array<string, mixed> $config
	 * @since 1.0.0
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Get the resolved update manifest URL from config.
	 *
	 * @return string Manifest URL (defaults to api.peakurl.org).
	 *
	 * @throws \RuntimeException When the URL is not a valid HTTPS endpoint.
	 * @since 1.0.0
	 */
	public function get_manifest_url(): string {
		$manifest_url = trim(
			(string) ( $this->config[ Constants::CONFIG_UPDATE_MANIFEST_URL ] ?? '' ),
		);

		if ( '' === $manifest_url ) {
			return $this->assert_https_url(
				Constants::DEFAULT_UPDATE_MANIFEST_URL,
				__( 'update manifest URL', 'peakurl' ),
			);
		}

		return $this->assert_https_url(
			$manifest_url,
			__( 'update manifest URL', 'peakurl' ),
		);
	}

	/**
	 * Get the currently installed PeakURL version string.
	 *
	 * @return string Semantic version.
	 * @since 1.0.0
	 */
	public function get_current_version(): string {
		$version = trim(
			(string) ( $this->config[ Constants::CONFIG_VERSION ] ?? '' ),
		);
		return '' !== $version ? $version : Constants::DEFAULT_VERSION;
	}

	/**
	 * Check whether the cached manifest is still fresh.
	 *
	 * @param string|null $last_checked_at ISO/MySQL datetime of last check.
	 * @return bool True when the cache is within the TTL.
	 * @since 1.0.0
	 */
	public function is_cache_fresh( ?string $last_checked_at ): bool {
		if ( empty( $last_checked_at ) ) {
			return false;
		}

		$timestamp = strtotime( $last_checked_at . ' UTC' );

		if ( false === $timestamp ) {
			return false;
		}

		return $timestamp >= ( time() - self::CACHE_TTL );
	}

	/**
	 * Fetch and normalise the remote update manifest.
	 *
	 * @return array<string, string> Normalised manifest data.
	 *
	 * @throws \RuntimeException On HTTP failure or invalid JSON.
	 * @since 1.0.0
	 */
	public function fetch_manifest(): array {
		$body    = $this->http_get( $this->get_manifest_url(), 'application/json' );
		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			throw new \RuntimeException(
				__( 'The update manifest returned invalid JSON.', 'peakurl' ),
			);
		}

		return $this->normalize_manifest( $payload );
	}

	/**
	 * Build the full update status payload for the dashboard API.
	 *
	 * Compares versions, checks PHP compatibility, and evaluates
	 * whether the dashboard can apply updates in-place.
	 *
	 * @param array<string, string>|null $manifest        Cached/fetched manifest.
	 * @param string|null                $last_checked_at When the manifest was last fetched.
	 * @param string|null                $last_error      Last update error message.
	 * @return array<string, mixed> Complete status payload.
	 * @since 1.0.0
	 */
	public function build_status(
		?array $manifest,
		?string $last_checked_at,
		?string $last_error
	): array {
		$manifest         = is_array( $manifest ) ? $manifest : array();
		$current_version  = $this->get_current_version();
		$latest_version   = trim( (string) ( $manifest['version'] ?? '' ) );
		$minimum_php      = trim( (string) ( $manifest['minimumPhp'] ?? '' ) );
		$php_compatible   = '' === $minimum_php || version_compare( PHP_VERSION, $minimum_php, '>=' );
		$update_available = '' !== $latest_version && version_compare( $latest_version, $current_version, '>' );
		$apply_capability = $this->get_apply_capability();

		return array(
			'manifestUrl'         => $this->get_manifest_url(),
			'currentVersion'      => $current_version,
			'latestVersion'       => $latest_version,
			'updateAvailable'     => $update_available,
			'reinstallAvailable'  => '' !== $latest_version && 0 === version_compare( $latest_version, $current_version ),
			'downloadUrl'         => (string) ( $manifest['downloadUrl'] ?? '' ),
			'packageUrl'          => (string) ( $manifest['packageUrl'] ?? '' ),
			'checksumSha256'      => (string) ( $manifest['checksumSha256'] ?? '' ),
			'releaseNotesUrl'     => (string) ( $manifest['releaseNotesUrl'] ?? '' ),
			'releasedAt'          => (string) ( $manifest['releasedAt'] ?? '' ),
			'minimumPhp'          => $minimum_php,
			'minimumMysql'        => (string) ( $manifest['minimumMysql'] ?? '' ),
			'minimumMariaDb'      => (string) ( $manifest['minimumMariaDb'] ?? '' ),
			'product'             => (string) ( $manifest['product'] ?? 'peakurl' ),
			'channel'             => (string) ( $manifest['channel'] ?? 'latest' ),
			'lastCheckedAt'       => $this->to_iso_or_null( $last_checked_at ),
			'lastError'           => '' !== trim( (string) $last_error ) ? trim( (string) $last_error ) : null,
			'phpVersion'          => PHP_VERSION,
			'phpCompatible'       => $php_compatible,
			'canApply'            => $apply_capability['allowed'],
			'applyDisabledReason' => $apply_capability['reason'],
			'isLocked'            => $this->is_locked(),
			'manifest'            => $manifest,
		);
	}

	/**
	 * Apply an update from the given manifest.
	 *
	 * Downloads the package, verifies its checksum, extracts it, backs up
	 * managed paths, replaces them, and prunes old backups. On failure,
	 * automatically rolls back to the pre-update state.
	 *
	 * @param array<string, string> $manifest Normalised update manifest.
	 * @return array<string, string> Result with version, packageUrl, backupPath, appliedAt.
	 *
	 * @throws \RuntimeException On any step failure (with auto-rollback).
	 * @since 1.0.0
	 */
	public function apply_update( array $manifest ): array {
		$capability = $this->get_apply_capability();

		if ( ! $capability['allowed'] ) {
			throw new \RuntimeException( $capability['reason'] );
		}

		$package_url = trim( (string) ( $manifest['packageUrl'] ?? $manifest['downloadUrl'] ?? '' ) );
		$version     = trim( (string) ( $manifest['version'] ?? '' ) );

		if ( '' === $package_url || '' === $version ) {
			throw new \RuntimeException(
				__( 'The update manifest is missing a package URL or version.', 'peakurl' ),
			);
		}

		$package_url = $this->assert_https_url(
			$package_url,
			__( 'release package URL', 'peakurl' ),
		);

		$lock                       = $this->acquire_lock();
		$working_dir                = $this->build_path(
			$this->get_storage_root(),
			'tmp',
			gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ),
		);
		$extract_dir                = $this->build_path( $working_dir, 'package' );
		$zip_path                   = $this->build_path( $working_dir, 'package.zip' );
		$backup_dir                 = $this->build_path(
			$this->get_storage_root(),
			'backups',
			gmdate( 'Ymd-His' ) . '-' . preg_replace( '/[^0-9A-Za-z.\-_]/', '-', $version ),
		);
		$backed_up                  = false;
		$rollback_root_paths        = array();
		$package_content_root_paths = array();

		try {
			$this->delete_legacy_storage_root();
			$this->ensure_directory( $working_dir );
			$this->ensure_directory( $extract_dir );
			$this->enable_maintenance_mode( $version );
			$this->download_package( $package_url, $zip_path );
			$this->verify_download_checksum(
				$zip_path,
				(string) ( $manifest['checksumSha256'] ?? '' ),
			);
			$this->extract_package( $zip_path, $extract_dir );

			$source_root                  = $this->resolve_package_root( $extract_dir );
			$installed_release_root_paths = $this->get_release_root_paths( ABSPATH );
			$package_release_root_paths   = $this->get_release_root_paths( $source_root );
			$rollback_root_paths          = $this->merge_release_root_paths(
				$installed_release_root_paths,
				$package_release_root_paths,
			);
			$package_content_root_paths   = $this->get_packaged_content_root_paths( $source_root );
			$this->backup_release_root_paths( $installed_release_root_paths, $backup_dir );
			$this->backup_packaged_content_root_paths( $package_content_root_paths, $backup_dir );
			$backed_up = true;
			$this->replace_release_root_paths(
				$installed_release_root_paths,
				$package_release_root_paths,
				$source_root,
			);
			$this->sync_packaged_content_root_paths(
				$package_content_root_paths,
				$source_root,
			);
			$this->delete_path( $backup_dir );

			return array(
				'version'    => $version,
				'packageUrl' => $package_url,
				'backupPath' => $backup_dir,
				'appliedAt'  => gmdate( DATE_ATOM ),
			);
		} catch ( \Throwable $exception ) {
			if ( $backed_up ) {
				try {
					$this->restore_release_root_paths( $rollback_root_paths, $backup_dir );
					$this->restore_packaged_content_root_paths( $package_content_root_paths, $backup_dir );
					$this->delete_path( $backup_dir );
				} catch ( \Throwable $rollback_exception ) {
					throw new \RuntimeException(
						__( 'PeakURL could not apply the update and the rollback failed. ', 'peakurl' ) .
						$rollback_exception->getMessage(),
						0,
						$rollback_exception,
					);
				}
			}

			throw new \RuntimeException(
				__( 'PeakURL could not apply the update. ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception,
			);
		} finally {
			$this->disable_maintenance_mode();
			$this->delete_path( $working_dir );
			$this->release_lock( $lock );
			$this->delete_storage_root_if_empty();
		}
	}

	/**
	 * Convert a MySQL datetime string to ISO 8601 or null.
	 *
	 * @param string|null $value Datetime string.
	 * @return string|null ISO 8601 datetime or null.
	 * @since 1.0.0
	 */
	private function to_iso_or_null( ?string $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( DATE_ATOM, $timestamp );
	}

	/**
	 * Normalise a raw manifest payload into a consistent structure.
	 *
	 * Supports both flat manifests and nested `offers[0]` formats.
	 *
	 * @param array<string, mixed> $payload Raw manifest JSON.
	 * @return array<string, string> Normalised manifest.
	 *
	 * @throws \RuntimeException When version or package URL is missing.
	 * @since 1.0.0
	 */
	private function normalize_manifest( array $payload ): array {
		$source = $payload;

		if (
			isset( $payload['offers'] ) &&
			is_array( $payload['offers'] ) &&
			isset( $payload['offers'][0] ) &&
			is_array( $payload['offers'][0] )
		) {
			$source = $payload['offers'][0];
		}

		$version           = trim( (string) ( $source['version'] ?? $source['current'] ?? '' ) );
		$package_url       = trim( (string) ( $source['packageUrl'] ?? $source['package'] ?? '' ) );
		$download_url      = trim( (string) ( $source['downloadUrl'] ?? $source['download'] ?? '' ) );
		$checksum          = strtolower(
			trim(
				(string) ( $source['checksumSha256'] ?? $source['checksum_sha256'] ?? '' ),
			),
		);
		$release_notes_url = trim( (string) ( $source['releaseNotesUrl'] ?? '' ) );

		if ( '' === $package_url ) {
			$package_url = $download_url;
		}

		if ( '' === $download_url ) {
			$download_url = $package_url;
		}

		if ( '' === $version ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a version.', 'peakurl' ),
			);
		}

		if ( '' === $package_url ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a package URL.', 'peakurl' ),
			);
		}

		if ( '' === $checksum ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a SHA-256 checksum.', 'peakurl' ),
			);
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			throw new \RuntimeException(
				__( 'The update manifest included an invalid SHA-256 checksum.', 'peakurl' ),
			);
		}

		$package_url  = $this->assert_https_url(
			$package_url,
			__( 'release package URL', 'peakurl' ),
		);
		$download_url = $this->assert_https_url(
			$download_url,
			__( 'release download URL', 'peakurl' ),
		);

		return array(
			'product'         => trim(
				(string) ( $payload['product'] ?? $source['product'] ?? 'peakurl' ),
			),
			'channel'         => trim(
				(string) ( $payload['channel'] ?? $source['channel'] ?? 'latest' ),
			),
			'version'         => $version,
			'downloadUrl'     => $download_url,
			'packageUrl'      => $package_url,
			'checksumSha256'  => $checksum,
			'releaseNotesUrl' => $release_notes_url,
			'releasedAt'      => trim(
				(string) ( $source['releasedAt'] ?? $source['date'] ?? '' ),
			),
			'minimumPhp'      => trim(
				(string) ( $source['minimumPhp'] ?? $source['minimum_php'] ?? '' ),
			),
			'minimumMysql'    => trim(
				(string) ( $source['minimumMysql'] ?? $source['minimum_mysql'] ?? '' ),
			),
			'minimumMariaDb'  => trim(
				(string) ( $source['minimumMariaDb'] ?? $source['minimum_mariadb'] ?? '' ),
			),
		);
	}

	/**
	 * Evaluate whether this installation can apply updates in-place.
	 *
	 * Checks for development markers, \ZipArchive extension, and write permissions.
	 *
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.0.0
	 */
	private function get_apply_capability(): array {
		if ( file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'Dashboard updates can only be applied from an installed release package.', 'peakurl' ),
			);
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The ZipArchive PHP extension is required to apply updates.', 'peakurl' ),
			);
		}

		if ( ! is_writable( ABSPATH ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The release root is not writable.', 'peakurl' ),
			);
		}

		if ( ! is_writable( $this->build_path( ABSPATH, 'app' ) ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The app directory is not writable.', 'peakurl' ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Check whether an update lock is currently held.
	 *
	 * @return bool True when the lock file exists and is not stale.
	 * @since 1.0.0
	 */
	private function is_locked(): bool {
		$lock_path = $this->get_lock_path();

		$this->clear_stale_lock( $lock_path );

		return file_exists( $lock_path );
	}

	/**
	 * Acquire an exclusive file lock for the update process.
	 *
	 * @return array{path: string, handle: resource} Lock metadata.
	 *
	 * @throws \RuntimeException When the lock cannot be acquired.
	 * @since 1.0.0
	 */
	private function acquire_lock(): array {
		$lock_path = $this->get_lock_path();

		$this->ensure_directory( dirname( $lock_path ) );
		$this->clear_stale_lock( $lock_path );

		$handle = fopen( $lock_path, 'c+' );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'PeakURL could not create the update lock.', 'peakurl' ) );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new \RuntimeException( __( 'Another PeakURL update is already running.', 'peakurl' ) );
		}

		ftruncate( $handle, 0 );
		fwrite(
			$handle,
			json_encode(
				array(
					'startedAt' => gmdate( DATE_ATOM ),
					'version'   => $this->get_current_version(),
				),
				JSON_PRETTY_PRINT,
			),
		);
		fflush( $handle );

		return array(
			'path'   => $lock_path,
			'handle' => $handle,
		);
	}

	/**
	 * Release a previously acquired update lock.
	 *
	 * @param array<string, mixed> $lock Lock metadata from acquire_lock().
	 * @since 1.0.0
	 */
	private function release_lock( array $lock ): void {
		if ( isset( $lock['handle'] ) && is_resource( $lock['handle'] ) ) {
			flock( $lock['handle'], LOCK_UN );
			fclose( $lock['handle'] );
		}

		if ( ! empty( $lock['path'] ) && file_exists( (string) $lock['path'] ) ) {
			unlink( (string) $lock['path'] );
		}
	}

	/**
	 * Remove a stale lock file that has exceeded the lock TTL.
	 *
	 * @param string $lock_path Absolute path to the lock file.
	 * @since 1.0.0
	 */
	private function clear_stale_lock( string $lock_path ): void {
		if ( ! file_exists( $lock_path ) ) {
			return;
		}

		$modified_at = filemtime( $lock_path );

		if ( false !== $modified_at && $modified_at >= ( time() - self::LOCK_TTL ) ) {
			return;
		}

		unlink( $lock_path );
	}

	/** Get the base storage directory for update working files. */
	private function get_storage_root(): string {
		return $this->build_path( $this->get_content_directory(), 'updates' );
	}

	/** Get the obsolete pre-1.0.9 update workspace path under uploads/. */
	private function get_legacy_storage_root(): string {
		return $this->build_path( $this->get_content_directory(), 'uploads', 'updates' );
	}

	/**
	 * Remove the obsolete update workspace from older packaged installs.
	 *
	 * @return void
	 * @since 1.0.9
	 */
	private function delete_legacy_storage_root(): void {
		$legacy_storage_root = $this->get_legacy_storage_root();

		if ( $legacy_storage_root === $this->get_storage_root() ) {
			return;
		}

		$this->delete_path( $legacy_storage_root );
	}

	/** Get the configured persistent content directory path. */
	private function get_content_directory(): string {
		$content_dir = trim(
			(string) ( $this->config[ Constants::CONFIG_CONTENT_DIR ] ?? '' ),
		);

		if ( '' === $content_dir ) {
			return $this->build_path( ABSPATH, Constants::DEFAULT_CONTENT_DIR );
		}

		return rtrim( $content_dir, '/\\' );
	}

	/** Get the update lock file path. */
	private function get_lock_path(): string {
		return $this->build_path( $this->get_storage_root(), 'update.lock' );
	}

	/** Get the maintenance-mode flag file path. */
	private function get_maintenance_path(): string {
		return $this->build_path( ABSPATH, '.maintenance' );
	}

	/**
	 * Enable maintenance mode by writing a JSON flag file.
	 *
	 * @param string $version Version being upgraded to.
	 * @since 1.0.0
	 */
	private function enable_maintenance_mode( string $version ): void {
		$body = json_encode(
			array(
				'upgrading' => time(),
				'version'   => $version,
			),
			JSON_PRETTY_PRINT,
		);

		if ( false === $body ) {
			$body = '';
		}

		file_put_contents( $this->get_maintenance_path(), $body, LOCK_EX );
	}

	/** Disable maintenance mode by removing the flag file. */
	private function disable_maintenance_mode(): void {
		$maintenance_path = $this->get_maintenance_path();

		if ( file_exists( $maintenance_path ) ) {
			unlink( $maintenance_path );
		}
	}

	/**
	 * Download a release package from a URL.
	 *
	 * @param string $url         Remote package URL.
	 * @param string $target_path Local file path to write the download.
	 *
	 * @throws \RuntimeException On download or write failure.
	 * @since 1.0.0
	 */
	private function download_package( string $url, string $target_path ): void {
		$body = $this->http_get( $url, 'application/zip' );

		if ( false === file_put_contents( $target_path, $body, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not store the downloaded release package.', 'peakurl' ),
			);
		}
	}

	/**
	 * Verify a downloaded file against an expected SHA-256 checksum.
	 *
	 * @param string $file_path         Local file path.
	 * @param string $expected_checksum Expected hex-encoded SHA-256 hash.
	 *
	 * @throws \RuntimeException On checksum mismatch.
	 * @since 1.0.0
	 */
	private function verify_download_checksum(
		string $file_path,
		string $expected_checksum
	): void {
		$expected_checksum = strtolower( trim( $expected_checksum ) );

		if ( '' === $expected_checksum ) {
			throw new \RuntimeException(
				__( 'The update manifest did not include a SHA-256 checksum.', 'peakurl' ),
			);
		}

		$actual_checksum = hash_file( 'sha256', $file_path );

		if ( false === $actual_checksum ) {
			throw new \RuntimeException(
				__( 'PeakURL could not verify the downloaded release checksum.', 'peakurl' ),
			);
		}

		if ( ! hash_equals( $expected_checksum, strtolower( $actual_checksum ) ) ) {
			throw new \RuntimeException(
				__( 'The downloaded release checksum did not match the manifest.', 'peakurl' ),
			);
		}
	}

	/**
	 * Extract a zip archive to a directory.
	 *
	 * @param string $zip_path     Path to the zip file.
	 * @param string $extract_path Target extraction directory.
	 *
	 * @throws \RuntimeException On open or extraction failure.
	 * @since 1.0.0
	 */
	private function extract_package(
		string $zip_path,
		string $extract_path
	): void {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not open the downloaded release archive.', 'peakurl' ),
			);
		}

		if ( ! $zip->extractTo( $extract_path ) ) {
			$zip->close();
			throw new \RuntimeException(
				__( 'PeakURL could not extract the downloaded release archive.', 'peakurl' ),
			);
		}

		$zip->close();
	}

	/**
	 * Resolve the actual root directory inside an extracted archive.
	 *
	 * Handles both flat and single-subfolder archive layouts.
	 *
	 * @param string $extract_path Extraction directory.
	 * @return string Absolute path to the package root.
	 *
	 * @throws \RuntimeException When the package structure is unrecognised.
	 * @since 1.0.0
	 */
	private function resolve_package_root( string $extract_path ): string {
		if ( $this->package_has_runtime_directory( $extract_path ) ) {
			return $extract_path;
		}

		$scan_results = scandir( $extract_path );

		if ( false === $scan_results ) {
			$scan_results = array();
		}

		$entries = array_values(
			array_filter(
				$scan_results,
				static function ( string $entry ): bool {
					return '.' !== $entry && '..' !== $entry;
				},
			),
		);

		if ( 1 !== count( $entries ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not determine the extracted package root.', 'peakurl' ),
			);
		}

		$nested_root = $this->build_path( $extract_path, $entries[0] );

		if ( is_dir( $nested_root ) && $this->package_has_runtime_directory( $nested_root ) ) {
			return $nested_root;
		}

		throw new \RuntimeException(
			__( 'PeakURL could not validate the extracted package structure.', 'peakurl' ),
		);
	}

	/**
	 * Check whether an extracted package root contains a valid app runtime directory.
	 *
	 * Accepts both the current `app/` layout and the older `core/` layout so
	 * previously-built packages can still be processed safely.
	 *
	 * @param string $root_path Candidate package root.
	 * @return bool True when the package contains index.php and app/ or core/.
	 * @since 1.0.0
	 */
	private function package_has_runtime_directory( string $root_path ): bool {
		if ( ! file_exists( $this->build_path( $root_path, 'index.php' ) ) ) {
			return false;
		}

		return is_dir( $this->build_path( $root_path, 'app' ) ) ||
			is_dir( $this->build_path( $root_path, 'core' ) );
	}

	/**
	 * Get release-managed root paths from a package or installed release root.
	 *
	 * Scans only the first level of the release root and excludes persistent
	 * paths that must survive updates.
	 *
	 * @param string $root_path Release root path.
	 * @return array<int, string>
	 * @since 1.0.8
	 */
	private function get_release_root_paths( string $root_path ): array {
		$scan_results = scandir( $root_path );

		if ( false === $scan_results ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the release package contents.', 'peakurl' ),
			);
		}

		$release_root_paths = array();

		foreach ( $scan_results as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( in_array( $entry, self::PRESERVED_ROOT_PATHS, true ) ) {
				continue;
			}

			$release_root_paths[ $entry ] = $entry;
		}

		ksort( $release_root_paths, SORT_STRING );

		return array_values( $release_root_paths );
	}

	/**
	 * Merge two release root path lists into a stable unique list.
	 *
	 * @param array<int, string> $left_paths  First release root path list.
	 * @param array<int, string> $right_paths Second release root path list.
	 * @return array<int, string>
	 * @since 1.0.8
	 */
	private function merge_release_root_paths(
		array $left_paths,
		array $right_paths
	): array {
		$merged_paths = array();

		foreach ( array_merge( $left_paths, $right_paths ) as $path ) {
			$merged_paths[ $path ] = $path;
		}

		ksort( $merged_paths, SORT_STRING );

		return array_values( $merged_paths );
	}

	/**
	 * Back up the currently installed release-managed root paths.
	 *
	 * @param array<int, string> $release_root_paths Release-managed root paths.
	 * @param string             $backup_root        Backup destination directory.
	 * @return void
	 * @since 1.0.8
	 */
	private function backup_release_root_paths(
		array $release_root_paths,
		string $backup_root
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$source_path = $this->build_path( ABSPATH, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->copy_path(
				$source_path,
				$this->build_path( $backup_root, $relative_path ),
			);
		}
	}

	/**
	 * Restore the previous release root after a failed update.
	 *
	 * @param array<int, string> $rollback_root_paths Root paths to remove before restore.
	 * @param string             $backup_root         Backup directory to restore from.
	 * @return void
	 * @since 1.0.8
	 */
	private function restore_release_root_paths(
		array $rollback_root_paths,
		string $backup_root
	): void {
		$this->delete_release_root_paths( $rollback_root_paths, ABSPATH );
		$this->copy_release_root_paths(
			$this->get_release_root_paths( $backup_root ),
			$backup_root,
			ABSPATH,
		);
	}

	/**
	 * Replace the installed release-managed root paths with the package contents.
	 *
	 * @param array<int, string> $installed_release_root_paths Installed release root paths.
	 * @param array<int, string> $package_release_root_paths   Package release root paths.
	 * @param string             $source_root                  Extracted package root.
	 * @return void
	 * @since 1.0.8
	 */
	private function replace_release_root_paths(
		array $installed_release_root_paths,
		array $package_release_root_paths,
		string $source_root
	): void {
		$this->delete_release_root_paths( $installed_release_root_paths, ABSPATH );
		$this->copy_release_root_paths(
			$package_release_root_paths,
			$source_root,
			ABSPATH,
		);
	}

	/**
	 * Delete release-managed root paths under a given root directory.
	 *
	 * @param array<int, string> $release_root_paths Release-managed root paths.
	 * @param string             $root_path          Root directory.
	 * @return void
	 * @since 1.0.8
	 */
	private function delete_release_root_paths(
		array $release_root_paths,
		string $root_path
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$this->delete_path( $this->build_path( $root_path, $relative_path ) );
		}
	}

	/**
	 * Copy release-managed root paths from a source root to a target root.
	 *
	 * @param array<int, string> $release_root_paths Release-managed root paths.
	 * @param string             $source_root        Source root.
	 * @param string             $target_root        Target root.
	 * @return void
	 * @since 1.0.8
	 */
	private function copy_release_root_paths(
		array $release_root_paths,
		string $source_root,
		string $target_root
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$source_path = $this->build_path( $source_root, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->copy_path(
				$source_path,
				$this->build_path( $target_root, $relative_path ),
			);
		}
	}

	/**
	 * Get the packaged top-level content entries that should sync on update.
	 *
	 * @param string $source_root Extracted release package root.
	 * @return array<int, string>
	 * @since 1.0.8
	 */
	private function get_packaged_content_root_paths( string $source_root ): array {
		$package_content_directory = $this->build_path(
			$source_root,
			Constants::DEFAULT_CONTENT_DIR,
		);

		if ( ! is_dir( $package_content_directory ) ) {
			return array();
		}

		$scan_results = scandir( $package_content_directory );

		if ( false === $scan_results ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the packaged content directory.', 'peakurl' ),
			);
		}

		$content_root_paths = array();

		foreach ( $scan_results as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( ! $this->content_path_contains_release_payload( $this->build_path( $package_content_directory, $entry ) ) ) {
				continue;
			}

			$content_root_paths[ $entry ] = $entry;
		}

		ksort( $content_root_paths, SORT_STRING );

		return array_values( $content_root_paths );
	}

	/**
	 * Check whether a packaged content path contains releasable payload files.
	 *
	 * Placeholder files such as `.gitkeep` should not turn an entire content
	 * root into a managed sync target during updates.
	 *
	 * @param string $path Packaged content path.
	 * @return bool
	 * @since 1.0.8
	 */
	private function content_path_contains_release_payload( string $path ): bool {
		if ( is_file( $path ) ) {
			return ! $this->is_ignored_content_placeholder_file( basename( $path ) );
		}

		if ( ! is_dir( $path ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$path,
				\FilesystemIterator::SKIP_DOTS,
			),
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			if ( $this->is_ignored_content_placeholder_file( $item->getFilename() ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Check whether a file should be ignored as packaged content filler.
	 *
	 * @param string $file_name File name to inspect.
	 * @return bool
	 * @since 1.0.8
	 */
	private function is_ignored_content_placeholder_file( string $file_name ): bool {
		return in_array(
			$file_name,
			array(
				'.gitkeep',
				'.DS_Store',
			),
			true,
		);
	}

	/**
	 * Back up the installed content entries that will be replaced by the package.
	 *
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $backup_root               Backup destination directory.
	 * @return void
	 * @since 1.0.8
	 */
	private function backup_packaged_content_root_paths(
		array $package_content_root_paths,
		string $backup_root
	): void {
		$content_directory = $this->get_content_directory();

		foreach ( $package_content_root_paths as $relative_path ) {
			$source_path = $this->build_path( $content_directory, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->copy_path(
				$source_path,
				$this->build_path(
					$backup_root,
					Constants::DEFAULT_CONTENT_DIR,
					$relative_path,
				),
			);
		}
	}

	/**
	 * Sync package-provided content entries into the installed content directory.
	 *
	 * Packaged files replace matching installed files, but unrelated user content
	 * is left untouched because only the packaged top-level entries are copied.
	 *
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $source_root               Extracted release package root.
	 * @return void
	 * @since 1.0.8
	 */
	private function sync_packaged_content_root_paths(
		array $package_content_root_paths,
		string $source_root
	): void {
		if ( empty( $package_content_root_paths ) ) {
			return;
		}

		$content_directory = $this->get_content_directory();

		$this->ensure_directory( $content_directory );

		foreach ( $package_content_root_paths as $relative_path ) {
			$source_path = $this->build_path(
				$source_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->copy_path(
				$source_path,
				$this->build_path( $content_directory, $relative_path ),
			);
		}
	}

	/**
	 * Restore packaged content entries after a failed update.
	 *
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $backup_root               Backup directory to restore from.
	 * @return void
	 * @since 1.0.8
	 */
	private function restore_packaged_content_root_paths(
		array $package_content_root_paths,
		string $backup_root
	): void {
		if ( empty( $package_content_root_paths ) ) {
			return;
		}

		$content_directory = $this->get_content_directory();

		foreach ( $package_content_root_paths as $relative_path ) {
			$this->delete_path( $this->build_path( $content_directory, $relative_path ) );

			$backup_path = $this->build_path(
				$backup_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $backup_path ) ) {
				continue;
			}

			$this->copy_path(
				$backup_path,
				$this->build_path( $content_directory, $relative_path ),
			);
		}
	}

	/** Remove the update workspace tree when the update run leaves it empty. */
	private function delete_storage_root_if_empty(): void {
		$this->delete_empty_directory_tree( $this->get_storage_root() );
	}

	/**
	 * Recursively remove a directory tree, but only where every directory is empty.
	 *
	 * @param string $path Directory path to inspect.
	 * @return void
	 * @since 1.0.9
	 */
	private function delete_empty_directory_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$scan_results = scandir( $path );

		if ( false === $scan_results ) {
			return;
		}

		$entries = array_values(
			array_filter(
				$scan_results,
				static function ( string $entry ): bool {
					return '.' !== $entry && '..' !== $entry;
				},
			),
		);

		foreach ( $entries as $entry ) {
			$entry_path = $this->build_path( $path, $entry );

			if ( ! is_dir( $entry_path ) ) {
				return;
			}

			$this->delete_empty_directory_tree( $entry_path );
		}

		$remaining_entries = scandir( $path );

		if ( false === $remaining_entries ) {
			return;
		}

		if ( count( $remaining_entries ) > 2 ) {
			return;
		}

		rmdir( $path );
	}

	/**
	 * Recursively copy a file or directory.
	 *
	 * @param string $source_path Source path.
	 * @param string $target_path Destination path.
	 *
	 * @throws \RuntimeException On copy failure.
	 * @since 1.0.0
	 */
	private function copy_path( string $source_path, string $target_path ): void {
		if ( is_dir( $source_path ) ) {
			$this->ensure_directory( $target_path );

			$source_root                 = $this->normalize_path( $source_path );
			$target_root                 = $this->normalize_path( $target_path );
			$directory_iterator          = new \RecursiveDirectoryIterator(
				$source_path,
				\FilesystemIterator::SKIP_DOTS,
			);
			$recursive_source_iterator   = $directory_iterator;
			$target_nested_inside_source = $this->path_matches_or_is_within( $target_root, $source_root );

			if ( $target_nested_inside_source ) {
				$recursive_source_iterator = new \RecursiveCallbackFilterIterator(
					$directory_iterator,
					function ( \SplFileInfo $item ) use ( $target_root ): bool {
						return ! $this->path_matches_or_is_within(
							$this->normalize_path( $item->getPathname() ),
							$target_root,
						);
					},
				);
			}

			$iterator = new \RecursiveIteratorIterator(
				$recursive_source_iterator,
				\RecursiveIteratorIterator::SELF_FIRST,
			);

			foreach ( $iterator as $item ) {
				$relative_path = substr(
					$item->getPathname(),
					strlen( $source_path ) + 1,
				);
				$destination   = $this->build_path( $target_path, $relative_path );

				if ( $item->isDir() ) {
					$this->ensure_directory( $destination );
					continue;
				}

				$this->ensure_directory( dirname( $destination ) );

				if ( ! copy( $item->getPathname(), $destination ) ) {
					throw new \RuntimeException(
						__( 'PeakURL could not copy the updated release files.', 'peakurl' ),
					);
				}
			}

			return;
		}

		$this->ensure_directory( dirname( $target_path ) );

		if ( ! copy( $source_path, $target_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not copy the updated release files.', 'peakurl' ),
			);
		}
	}

	/**
	 * Normalize a filesystem path for safe path-prefix comparisons.
	 *
	 * @param string $path Absolute or relative filesystem path.
	 * @return string
	 * @since 1.0.9
	 */
	private function normalize_path( string $path ): string {
		$normalized_path = str_replace( '\\', '/', $path );
		$normalized_path = preg_replace( '#/+#', '/', $normalized_path );

		if ( null === $normalized_path ) {
			$normalized_path = str_replace( '\\', '/', $path );
		}

		return rtrim( $normalized_path, '/' );
	}

	/**
	 * Check whether a path matches or lives inside another path.
	 *
	 * @param string $candidate_path Candidate path to inspect.
	 * @param string $base_path      Base path that may contain the candidate.
	 * @return bool
	 * @since 1.0.9
	 */
	private function path_matches_or_is_within(
		string $candidate_path,
		string $base_path
	): bool {
		if ( '' === $base_path ) {
			return false;
		}

		return $candidate_path === $base_path ||
			str_starts_with( $candidate_path, $base_path . '/' );
	}

	/**
	 * Recursively delete a file or directory.
	 *
	 * @param string $path Path to delete.
	 * @since 1.0.0
	 */
	private function delete_path( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
				continue;
			}

			unlink( $item->getPathname() );
		}

		rmdir( $path );
	}

	/**
	 * Ensure a directory exists, creating it recursively if needed.
	 *
	 * @param string $path Directory path.
	 *
	 * @throws \RuntimeException When directory creation fails.
	 * @since 1.0.0
	 */
	private function ensure_directory( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not prepare the update workspace.', 'peakurl' ),
			);
		}
	}

	/**
	 * Build a file path from a base and one or more segments.
	 *
	 * @param string   $base_path Base directory.
	 * @param string[] $segments  Path segments to append.
	 * @return string Assembled path.
	 * @since 1.0.0
	 */
	private function build_path( string $base_path, string ...$segments ): string {
		$path = rtrim( $base_path, DIRECTORY_SEPARATOR );

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$path .= DIRECTORY_SEPARATOR . trim( $segment, DIRECTORY_SEPARATOR );
		}

		return $path;
	}

	/**
	 * Perform an HTTP GET request using the best available transport.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 * @since 1.0.0
	 */
	private function http_get( string $url, string $accept ): string {
		$this->assert_https_url(
			$url,
			__( 'remote update URL', 'peakurl' ),
		);

		if ( function_exists( 'curl_init' ) ) {
			return $this->http_get_with_curl( $url, $accept );
		}

		return $this->http_get_with_stream( $url, $accept );
	}

	/**
	 * HTTP GET via cURL.
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 *
	 * @throws \RuntimeException On cURL failure or non-2xx status.
	 * @since 1.0.0
	 */
	private function http_get_with_curl( string $url, string $accept ): string {
		$curl = curl_init( $url );

		if ( false === $curl ) {
			throw new \RuntimeException( __( 'PeakURL could not start the update request.', 'peakurl' ) );
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => array(
					'Accept: ' . $accept,
					'User-Agent: ' . $this->build_user_agent(),
				),
			),
		);

		if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
		}

		if ( defined( 'CURLOPT_REDIR_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS );
		}

		$body       = curl_exec( $curl );
		$status     = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$curl_error = curl_error( $curl );
		unset( $curl );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not contact the update service. ', 'peakurl' ) . $curl_error,
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The update service returned HTTP %d.', 'peakurl' ),
					$status,
				),
			);
		}

		return (string) $body;
	}

	/**
	 * HTTP GET via PHP streams (fallback when cURL is unavailable).
	 *
	 * @param string $url    Remote URL.
	 * @param string $accept Accept header value.
	 * @return string Response body.
	 *
	 * @throws \RuntimeException On stream failure or non-2xx status.
	 * @since 1.0.0
	 */
	private function http_get_with_stream( string $url, string $accept ): string {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 30,
					'ignore_errors' => true,
					'header'        => implode(
						"\r\n",
						array(
							'Accept: ' . $accept,
							'User-Agent: ' . $this->build_user_agent(),
						),
					),
				),
				'ssl'  => array(
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'allow_self_signed' => false,
				),
			),
		);
		$stream  = fopen( $url, 'rb', false, $context );

		if ( false === $stream ) {
			throw new \RuntimeException(
				__( 'PeakURL could not contact the update service.', 'peakurl' ),
			);
		}

		$body            = stream_get_contents( $stream );
		$stream_metadata = stream_get_meta_data( $stream );
		fclose( $stream );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the update service response.', 'peakurl' ),
			);
		}

		$status_line      = '';
		$response_headers = array();

		if (
			isset( $stream_metadata['wrapper_data'] ) &&
			is_array( $stream_metadata['wrapper_data'] )
		) {
			$response_headers = $stream_metadata['wrapper_data'];
		}

		if ( isset( $response_headers[0] ) ) {
			$status_line = (string) $response_headers[0];
		}

		if ( ! preg_match( '/\s(\d{3})\s/', $status_line, $matches ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the update service response.', 'peakurl' ),
			);
		}

		$status = (int) $matches[1];

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The update service returned HTTP %d.', 'peakurl' ),
					$status,
				),
			);
		}

		return (string) $body;
	}

	/**
	 * Validate that an updater URL is a well-formed HTTPS endpoint.
	 *
	 * @param string $url   Candidate absolute URL.
	 * @param string $label Human-readable field label.
	 * @return string
	 *
	 * @throws \RuntimeException When the URL is invalid or not HTTPS.
	 * @since 1.0.3
	 */
	private function assert_https_url( string $url, string $label ): string {
		$url   = trim( $url );
		$parts = parse_url( $url );

		if (
			'' === $url ||
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] )
		) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: manifest field label. */
					__( 'The %s is not a valid absolute URL.', 'peakurl' ),
					$label,
				),
			);
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: manifest field label. */
					__( 'The %s must use HTTPS.', 'peakurl' ),
					$label,
				),
			);
		}

		return $url;
	}

	/**
	 * Build the User-Agent header for update requests.
	 *
	 * @return string User-Agent string (e.g. 'PeakURL/1.0.0; https://example.com').
	 * @since 1.0.0
	 */
	private function build_user_agent(): string {
		return sprintf(
			'PeakURL/%s; %s',
			$this->get_current_version(),
			(string) ( $this->config['SITE_URL'] ?? 'unknown-site' ),
		);
	}
}
