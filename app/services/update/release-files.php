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
	public function get_release_paths( string $root_path ): array {
		$scan_results = scandir( $root_path );

		if ( false === $scan_results ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the release package contents.', 'peakurl' ),
			);
		}

		$release_paths = array();

		foreach ( $scan_results as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( in_array( $entry, self::PRESERVED_ROOT_PATHS, true ) ) {
				continue;
			}

			$release_paths[ $entry ] = $entry;
		}

		ksort( $release_paths, SORT_STRING );

		return array_values( $release_paths );
	}

	/**
	 * Merge two release-root path lists into a stable unique list.
	 *
	 * @param array<int, string> $left_paths  First release-root path list.
	 * @param array<int, string> $right_paths Second release-root path list.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	public function merge_release_paths(
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
	 * @param array<int, string> $release_paths Release-managed root paths.
	 * @param string             $backup_root   Backup destination directory.
	 * @return void
	 * @since 1.0.14
	 */
	public function backup_release_paths(
		array $release_paths,
		string $backup_root
	): void {
		foreach ( $release_paths as $relative_path ) {
			$source_path = $this->filesystem->join_path( ABSPATH, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy(
				$source_path,
				$this->filesystem->join_path( $backup_root, $relative_path ),
			);
		}
	}

	/**
	 * Restore the previous release root after a failed update.
	 *
	 * @param array<int, string> $rollback_paths Root paths to remove before restore.
	 * @param string             $backup_root    Backup directory to restore from.
	 * @return void
	 * @since 1.0.14
	 */
	public function restore_release_paths(
		array $rollback_paths,
		string $backup_root
	): void {
		$this->delete_release_paths( $rollback_paths, ABSPATH );
		$this->copy_release_paths(
			$this->get_release_paths( $backup_root ),
			$backup_root,
			ABSPATH,
		);
	}

	/**
	 * Replace the installed release-managed root paths with package contents.
	 *
	 * @param array<int, string> $installed_paths Installed release-root paths.
	 * @param array<int, string> $package_paths   Package release-root paths.
	 * @param string             $source_root     Extracted package root.
	 * @return void
	 * @since 1.0.14
	 */
	public function replace_release_paths(
		array $installed_paths,
		array $package_paths,
		string $source_root
	): void {
		$this->delete_release_paths( $installed_paths, ABSPATH );
		$this->copy_release_paths(
			$package_paths,
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
	public function get_content_paths( string $source_root ): array {
		$package_content_dir = $this->filesystem->join_path(
			$source_root,
			Constants::DEFAULT_CONTENT_DIR,
		);

		if ( ! is_dir( $package_content_dir ) ) {
			return array();
		}

		$scan_results = scandir( $package_content_dir );

		if ( false === $scan_results ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the packaged content directory.', 'peakurl' ),
			);
		}

		$content_paths = array();

		foreach ( $scan_results as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if (
				! $this->has_release_payload(
					$this->filesystem->join_path( $package_content_dir, $entry ),
				)
			) {
				continue;
			}

			$content_paths[ $entry ] = $entry;
		}

		ksort( $content_paths, SORT_STRING );

		return array_values( $content_paths );
	}

	/**
	 * Back up installed content entries that will be replaced by the package.
	 *
	 * @param array<int, string> $content_paths Packaged content root paths.
	 * @param string             $backup_root   Backup destination directory.
	 * @return void
	 * @since 1.0.14
	 */
	public function backup_content_paths(
		array $content_paths,
		string $backup_root
	): void {
		$content_directory = $this->context->get_content_dir();

		foreach ( $content_paths as $relative_path ) {
			$source_path = $this->filesystem->join_path( $content_directory, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy(
				$source_path,
				$this->filesystem->join_path(
					$backup_root,
					Constants::DEFAULT_CONTENT_DIR,
					$relative_path,
				),
			);
		}
	}

	/**
	 * Copy package-provided content entries into the installed content directory.
	 *
	 * @param array<int, string> $content_paths Packaged content root paths.
	 * @param string             $source_root   Extracted release package root.
	 * @return void
	 * @since 1.0.14
	 */
	public function copy_content_paths(
		array $content_paths,
		string $source_root
	): void {
		if ( empty( $content_paths ) ) {
			return;
		}

		$content_directory = $this->context->get_content_dir();

		$this->filesystem->mkdir_p( $content_directory );

		foreach ( $content_paths as $relative_path ) {
			$source_path = $this->filesystem->join_path(
				$source_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy(
				$source_path,
				$this->filesystem->join_path( $content_directory, $relative_path ),
			);
		}
	}

	/**
	 * Restore packaged content entries after a failed update.
	 *
	 * @param array<int, string> $content_paths Packaged content root paths.
	 * @param string             $backup_root   Backup directory to restore from.
	 * @return void
	 * @since 1.0.14
	 */
	public function restore_content_paths(
		array $content_paths,
		string $backup_root
	): void {
		if ( empty( $content_paths ) ) {
			return;
		}

		$content_directory = $this->context->get_content_dir();

		foreach ( $content_paths as $relative_path ) {
			$this->filesystem->delete(
				$this->filesystem->join_path( $content_directory, $relative_path ),
			);

			$backup_path = $this->filesystem->join_path(
				$backup_root,
				Constants::DEFAULT_CONTENT_DIR,
				$relative_path,
			);

			if ( ! file_exists( $backup_path ) ) {
				continue;
			}

			$this->filesystem->copy(
				$backup_path,
				$this->filesystem->join_path( $content_directory, $relative_path ),
			);
		}
	}

	/**
	 * Delete release-managed root paths under a given root directory.
	 *
	 * @param array<int, string> $release_paths Release-managed root paths.
	 * @param string             $root_path     Root directory.
	 * @return void
	 * @since 1.0.14
	 */
	private function delete_release_paths(
		array $release_paths,
		string $root_path
	): void {
		foreach ( $release_paths as $relative_path ) {
			$this->filesystem->delete(
				$this->filesystem->join_path( $root_path, $relative_path ),
			);
		}
	}

	/**
	 * Copy release-managed root paths from a source root to a target root.
	 *
	 * @param array<int, string> $release_paths Release-managed root paths.
	 * @param string             $source_root   Source root.
	 * @param string             $target_root   Target root.
	 * @return void
	 * @since 1.0.14
	 */
	private function copy_release_paths(
		array $release_paths,
		string $source_root,
		string $target_root
	): void {
		foreach ( $release_paths as $relative_path ) {
			$source_path = $this->filesystem->join_path( $source_root, $relative_path );

			if ( ! file_exists( $source_path ) ) {
				continue;
			}

			$this->filesystem->copy(
				$source_path,
				$this->filesystem->join_path( $target_root, $relative_path ),
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
	private function has_release_payload( string $path ): bool {
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
