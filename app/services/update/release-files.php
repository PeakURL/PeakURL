<?php
/**
 * Dashboard updater release files helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * ReleaseFiles — release and content file backup / sync helpers.
 *
 * Keeps release-managed root paths and packaged content sync logic
 * in one place so the public updater service stays focused on state.
 *
 * @since 1.0.14
 */
class ReleaseFiles {

	/**
	 * Root entries that must survive updates.
	 *
	 * @var array<int, string>
	 * @since 1.0.14
	 */
	private const PRESERVED_ROOT_PATHS = array(
		'config.php',
		'content',
		'.maintenance',
	);

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
	 * Create a new release-file helper.
	 *
	 * @param Context     $context     Shared updater context helper.
	 * @param Filesystem  $filesystem  Shared updater filesystem helper.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Filesystem $filesystem
	) {
		$this->context    = $context;
		$this->filesystem = $filesystem;
	}

	/**
	 * Get release-managed root paths from a package or installed release root.
	 *
	 * @param string $root_path Release root path.
	 * @return array<int, string>
	 *
	 * @throws \RuntimeException When the root cannot be read.
	 * @since 1.0.14
	 */
	public function get_release_root_paths( string $root_path ): array {
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
	 * Merge two release-root path lists into a stable unique list.
	 *
	 * @param array<int, string> $left_paths  First release-root path list.
	 * @param array<int, string> $right_paths Second release-root path list.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	public function merge_release_root_paths(
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
	 * @since 1.0.14
	 */
	public function backup_release_root_paths(
		array $release_root_paths,
		string $backup_root
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$source_path = $this->filesystem->build_path( ABSPATH, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy_path(
				$source_path,
				$this->filesystem->build_path( $backup_root, $relative_path ),
			);
		}
	}

	/**
	 * Restore the previous release root after a failed update.
	 *
	 * @param array<int, string> $rollback_root_paths Root paths to remove before restore.
	 * @param string             $backup_root         Backup directory to restore from.
	 * @return void
	 * @since 1.0.14
	 */
	public function restore_release_root_paths(
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
	 * Replace the installed release-managed root paths with package contents.
	 *
	 * @param array<int, string> $installed_release_root_paths Installed release-root paths.
	 * @param array<int, string> $package_release_root_paths   Package release-root paths.
	 * @param string             $source_root                  Extracted package root.
	 * @return void
	 * @since 1.0.14
	 */
	public function replace_release_root_paths(
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
	 * Get packaged top-level content entries that should sync on update.
	 *
	 * @param string $source_root Extracted release package root.
	 * @return array<int, string>
	 *
	 * @throws \RuntimeException When the packaged content directory cannot be read.
	 * @since 1.0.14
	 */
	public function get_packaged_content_root_paths( string $source_root ): array {
		$package_content_directory = $this->filesystem->build_path(
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

			if (
				! $this->content_path_contains_release_payload(
					$this->filesystem->build_path( $package_content_directory, $entry ),
				)
			) {
				continue;
			}

			$content_root_paths[ $entry ] = $entry;
		}

		ksort( $content_root_paths, SORT_STRING );

		return array_values( $content_root_paths );
	}

	/**
	 * Back up installed content entries that will be replaced by the package.
	 *
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $backup_root               Backup destination directory.
	 * @return void
	 * @since 1.0.14
	 */
	public function backup_packaged_content_root_paths(
		array $package_content_root_paths,
		string $backup_root
	): void {
		$content_directory = $this->context->get_content_directory();

		foreach ( $package_content_root_paths as $relative_path ) {
			$source_path = $this->filesystem->build_path( $content_directory, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy_path(
				$source_path,
				$this->filesystem->build_path(
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
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $source_root               Extracted release package root.
	 * @return void
	 * @since 1.0.14
	 */
	public function sync_packaged_content_root_paths(
		array $package_content_root_paths,
		string $source_root
	): void {
		if ( empty( $package_content_root_paths ) ) {
			return;
		}

		$content_directory = $this->context->get_content_directory();

		$this->filesystem->create_directory( $content_directory );

		foreach ( $package_content_root_paths as $relative_path ) {
			$source_path = $this->filesystem->build_path(
				$source_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy_path(
				$source_path,
				$this->filesystem->build_path( $content_directory, $relative_path ),
			);
		}
	}

	/**
	 * Restore packaged content entries after a failed update.
	 *
	 * @param array<int, string> $package_content_root_paths Packaged content root paths.
	 * @param string             $backup_root               Backup directory to restore from.
	 * @return void
	 * @since 1.0.14
	 */
	public function restore_packaged_content_root_paths(
		array $package_content_root_paths,
		string $backup_root
	): void {
		if ( empty( $package_content_root_paths ) ) {
			return;
		}

		$content_directory = $this->context->get_content_directory();

		foreach ( $package_content_root_paths as $relative_path ) {
			$this->filesystem->delete_path(
				$this->filesystem->build_path( $content_directory, $relative_path ),
			);

			$backup_path = $this->filesystem->build_path(
				$backup_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $backup_path ) ) {
				continue;
			}

			$this->filesystem->copy_path(
				$backup_path,
				$this->filesystem->build_path( $content_directory, $relative_path ),
			);
		}
	}

	/**
	 * Delete release-managed root paths under a given root directory.
	 *
	 * @param array<int, string> $release_root_paths Release-managed root paths.
	 * @param string             $root_path          Root directory.
	 * @return void
	 * @since 1.0.14
	 */
	private function delete_release_root_paths(
		array $release_root_paths,
		string $root_path
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$this->filesystem->delete_path(
				$this->filesystem->build_path( $root_path, $relative_path ),
			);
		}
	}

	/**
	 * Copy release-managed root paths from a source root to a target root.
	 *
	 * @param array<int, string> $release_root_paths Release-managed root paths.
	 * @param string             $source_root        Source root.
	 * @param string             $target_root        Target root.
	 * @return void
	 * @since 1.0.14
	 */
	private function copy_release_root_paths(
		array $release_root_paths,
		string $source_root,
		string $target_root
	): void {
		foreach ( $release_root_paths as $relative_path ) {
			$source_path = $this->filesystem->build_path( $source_root, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy_path(
				$source_path,
				$this->filesystem->build_path( $target_root, $relative_path ),
			);
		}
	}

	/**
	 * Check whether a packaged content path contains releasable payload files.
	 *
	 * @param string $path Packaged content path.
	 * @return bool
	 * @since 1.0.14
	 */
	private function content_path_contains_release_payload( string $path ): bool {
		if ( is_file( $path ) ) {
			return true;
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

			return true;
		}

		return false;
	}
}
