<?php
/**
 * Dashboard updater filesystem helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

use PeakURL\Utils\File;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Filesystem — shared updater path and file operations.
 *
 * Centralizes recursive copy, delete, path assembly, and directory
 * preparation so the update helpers can stay focused on updater logic.
 *
 * @since 1.0.14
 */
class Filesystem {

	/**
	 * Create a directory, including any missing parent directories.
	 *
	 * @param string $path Directory path.
	 * @return void
	 *
	 * @throws \RuntimeException When directory creation fails.
	 * @since 1.0.14
	 */
	public function mkdir_p( string $path ): void {
		if ( ! File::mkdir_p( $path, 0775 ) ) {
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
	 * @return string
	 * @since 1.0.14
	 */
	public function join_path( string $base_path, string ...$segments ): string {
		return File::join_path( $base_path, ...$segments );
	}

	/**
	 * Recursively delete a file or directory.
	 *
	 * @param string $path Path to delete.
	 * @return void
	 * @since 1.0.14
	 */
	public function delete( string $path ): void {
		File::delete( $path );
	}

	/**
	 * Recursively remove a directory tree, but only where every directory is empty.
	 *
	 * @param string $path Directory path to inspect.
	 * @return void
	 * @since 1.0.14
	 */
	public function delete_empty_dirs( string $path ): void {
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
			$entry_path = $this->join_path( $path, $entry );

			if ( ! is_dir( $entry_path ) ) {
				return;
			}

			$this->delete_empty_dirs( $entry_path );
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
	 * @return void
	 *
	 * @throws \RuntimeException On copy failure.
	 * @since 1.0.14
	 */
	public function copy( string $source_path, string $target_path ): void {
		if ( is_dir( $source_path ) ) {
			$this->mkdir_p( $target_path );

			$source_root                 = File::normalize_path( $source_path );
			$target_root                 = File::normalize_path( $target_path );
			$directory_iterator          = new \RecursiveDirectoryIterator(
				$source_path,
				\FilesystemIterator::SKIP_DOTS,
			);
			$recursive_source_iterator   = $directory_iterator;
			$target_nested_inside_source = File::is_path_inside(
				$target_root,
				$source_root,
			);

			if ( $target_nested_inside_source ) {
				$recursive_source_iterator = new \RecursiveCallbackFilterIterator(
					$directory_iterator,
					function ( \SplFileInfo $item ) use ( $target_root ): bool {
						return ! File::is_path_inside(
							File::normalize_path( $item->getPathname() ),
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
				$destination   = $this->join_path( $target_path, $relative_path );

				if ( $item->isDir() ) {
					$this->mkdir_p( $destination );
					continue;
				}

				$this->mkdir_p( dirname( $destination ) );

				if ( ! File::copy( $item->getPathname(), $destination ) ) {
					throw new \RuntimeException(
						__( 'PeakURL could not copy the updated release files.', 'peakurl' ),
					);
				}
			}

			return;
		}

		$this->mkdir_p( dirname( $target_path ) );

		if ( ! File::copy( $source_path, $target_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not copy the updated release files.', 'peakurl' ),
			);
		}
	}
}
