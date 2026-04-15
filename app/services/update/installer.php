<?php
/**
 * Dashboard updater installer helpers.
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
 * Installer — release package download, extraction, and apply flow.
 *
 * Coordinates the full update operation while delegating path, workspace,
 * transport, and file-sync concerns to narrower helpers.
 *
 * @since 1.0.14
 */
class Installer {

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Shared updater filesystem helper.
	 *
	 * @var Filesystem
	 * @since 1.0.14
	 */
	private Filesystem $filesystem;

	/**
	 * Shared updater HTTP client.
	 *
	 * @var Client
	 * @since 1.0.14
	 */
	private Client $client;

	/**
	 * Shared updater workspace helper.
	 *
	 * @var Workspace
	 * @since 1.0.14
	 */
	private Workspace $workspace;

	/**
	 * Shared updater release-file helper.
	 *
	 * @var ReleaseFiles
	 * @since 1.0.14
	 */
	private ReleaseFiles $files;

	/**
	 * Create a new update package helper.
	 *
	 * @param Context     $context     Shared updater context helper.
	 * @param Filesystem  $filesystem  Shared updater filesystem helper.
	 * @param Client      $client      Shared updater HTTP client.
	 * @param Workspace   $workspace   Shared updater workspace helper.
	 * @param ReleaseFiles $files      Shared updater release-file helper.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Filesystem $filesystem,
		Client $client,
		Workspace $workspace,
		ReleaseFiles $files
	) {
		$this->context    = $context;
		$this->filesystem = $filesystem;
		$this->client     = $client;
		$this->workspace  = $workspace;
		$this->files      = $files;
	}

	/**
	 * Apply an update from a normalized manifest payload.
	 *
	 * @param array<string, string> $manifest Normalized update manifest.
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException On any apply failure.
	 * @since 1.0.14
	 */
	public function apply( array $manifest ): array {
		$availability = $this->context->get_apply_availability();

		if ( ! $availability['allowed'] ) {
			throw new \RuntimeException( (string) $availability['reason'] );
		}

		$package_url = trim(
			(string) ( $manifest['packageUrl'] ?? $manifest['downloadUrl'] ?? '' ),
		);
		$version     = trim( (string) ( $manifest['version'] ?? '' ) );

		if ( '' === $package_url || '' === $version ) {
			throw new \RuntimeException(
				__( 'The update manifest is missing a package URL or version.', 'peakurl' ),
			);
		}

		$package_url = $this->client->assert_https_url(
			$package_url,
			__( 'release package URL', 'peakurl' ),
		);

		$lock                       = $this->workspace->acquire_lock();
		$working_dir                = $this->filesystem->build_path(
			$this->context->get_storage_root(),
			'tmp',
			gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ),
		);
		$extract_dir                = $this->filesystem->build_path( $working_dir, 'package' );
		$zip_path                   = $this->filesystem->build_path( $working_dir, 'package.zip' );
		$backup_dir                 = $this->filesystem->build_path(
			$this->context->get_storage_root(),
			'backups',
			gmdate( 'Ymd-His' ) . '-' . preg_replace( '/[^0-9A-Za-z.\-_]/', '-', $version ),
		);
		$backed_up                  = false;
		$rollback_root_paths        = array();
		$package_content_root_paths = array();

		try {
			$this->workspace->remove_legacy_storage_root();
			$this->filesystem->ensure_directory( $working_dir );
			$this->filesystem->ensure_directory( $extract_dir );
			$this->workspace->enable_maintenance_mode( $version );
			$this->download( $package_url, $zip_path );
			$this->verify_checksum(
				$zip_path,
				(string) ( $manifest['checksumSha256'] ?? '' ),
			);
			$this->extract( $zip_path, $extract_dir );

			$source_root                  = $this->resolve_package_root( $extract_dir );
			$installed_release_root_paths = $this->files->get_release_root_paths( ABSPATH );
			$package_release_root_paths   = $this->files->get_release_root_paths( $source_root );
			$rollback_root_paths          = $this->files->merge_release_root_paths(
				$installed_release_root_paths,
				$package_release_root_paths,
			);
			$package_content_root_paths   = $this->files->get_packaged_content_root_paths( $source_root );
			$this->files->backup_release_root_paths(
				$installed_release_root_paths,
				$backup_dir,
			);
			$this->files->backup_packaged_content_root_paths(
				$package_content_root_paths,
				$backup_dir,
			);
			$backed_up = true;

			$this->files->replace_release_root_paths(
				$installed_release_root_paths,
				$package_release_root_paths,
				$source_root,
			);
			$this->files->sync_packaged_content_root_paths(
				$package_content_root_paths,
				$source_root,
			);
			$this->filesystem->delete_path( $backup_dir );

			return array(
				'version'    => $version,
				'packageUrl' => $package_url,
				'backupPath' => $backup_dir,
				'appliedAt'  => gmdate( DATE_ATOM ),
			);
		} catch ( \Throwable $exception ) {
			if ( $backed_up ) {
				try {
					$this->files->restore_release_root_paths(
						$rollback_root_paths,
						$backup_dir,
					);
					$this->files->restore_packaged_content_root_paths(
						$package_content_root_paths,
						$backup_dir,
					);
					$this->filesystem->delete_path( $backup_dir );
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
			$this->workspace->disable_maintenance_mode();
			$this->filesystem->delete_path( $working_dir );
			$this->workspace->release_lock( $lock );
			$this->workspace->cleanup_storage_root();
		}
	}

	/**
	 * Download a release package from a URL.
	 *
	 * @param string $url         Remote package URL.
	 * @param string $target_path Local file path to write the download.
	 * @return void
	 *
	 * @throws \RuntimeException On download or write failure.
	 * @since 1.0.14
	 */
	private function download( string $url, string $target_path ): void {
		$body = $this->client->get( $url, 'application/zip' );

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
	 * @return void
	 *
	 * @throws \RuntimeException On checksum mismatch.
	 * @since 1.0.14
	 */
	private function verify_checksum(
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
	 * @return void
	 *
	 * @throws \RuntimeException On open or extraction failure.
	 * @since 1.0.14
	 */
	private function extract( string $zip_path, string $extract_path ): void {
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
	 * @param string $extract_path Extraction directory.
	 * @return string
	 *
	 * @throws \RuntimeException When the package structure is unrecognized.
	 * @since 1.0.14
	 */
	private function resolve_package_root( string $extract_path ): string {
		if ( $this->has_runtime_directory( $extract_path ) ) {
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

		$nested_root = $this->filesystem->build_path( $extract_path, $entries[0] );

		if ( is_dir( $nested_root ) && $this->has_runtime_directory( $nested_root ) ) {
			return $nested_root;
		}

		throw new \RuntimeException(
			__( 'PeakURL could not validate the extracted package structure.', 'peakurl' ),
		);
	}

	/**
	 * Check whether an extracted package root contains a valid runtime directory.
	 *
	 * @param string $root_path Candidate package root.
	 * @return bool
	 * @since 1.0.14
	 */
	private function has_runtime_directory( string $root_path ): bool {
		if ( ! file_exists( $this->filesystem->build_path( $root_path, 'index.php' ) ) ) {
			return false;
		}

		return is_dir( $this->filesystem->build_path( $root_path, 'app' ) ) ||
			is_dir( $this->filesystem->build_path( $root_path, 'core' ) );
	}
}
