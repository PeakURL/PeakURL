<?php
/**
 * Dashboard updater filesystem helpers.
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
 * Filesystem — shared updater path and file operations.
 *
 * Centralizes recursive copy, delete, path assembly, and directory
 * preparation so the update helpers can stay focused on updater logic.
 *
 * @since 1.0.14
 */
class Filesystem {

	/**
	 * Ensure a directory exists, creating it recursively if needed.
	 *
	 * @param string $path Directory path.
	 * @return void
	 *
	 * @throws \RuntimeException When directory creation fails.
	 * @since 1.0.14
	 */
	public function ensure_directory( string $path ): void {
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
	 * @return string
	 * @since 1.0.14
	 */
	public function build_path( string $base_path, string ...$segments ): string {
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
	 * Recursively delete a file or directory.
	 *
	 * @param string $path Path to delete.
	 * @return void
	 * @since 1.0.14
	 */
	public function delete_path( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$path,
				\FilesystemIterator::SKIP_DOTS,
			),
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
	 * Recursively remove a directory tree, but only where every directory is empty.
	 *
	 * @param string $path Directory path to inspect.
	 * @return void
	 * @since 1.0.14
	 */
	public function delete_empty_directory_tree( string $path ): void {
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
	 * @return void
	 *
	 * @throws \RuntimeException On copy failure.
	 * @since 1.0.14
	 */
	public function copy_path( string $source_path, string $target_path ): void {
		if ( is_dir( $source_path ) ) {
			$this->ensure_directory( $target_path );

			$source_root                 = $this->normalize_path( $source_path );
			$target_root                 = $this->normalize_path( $target_path );
			$directory_iterator          = new \RecursiveDirectoryIterator(
				$source_path,
				\FilesystemIterator::SKIP_DOTS,
			);
			$recursive_source_iterator   = $directory_iterator;
			$target_nested_inside_source = $this->path_matches_or_is_within(
				$target_root,
				$source_root,
			);

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
	 * @since 1.0.14
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
	 * @since 1.0.14
	 */
	private function path_matches_or_is_within(
		string $candidate_path,
		string $base_path
	): bool {
		if ( '' === $base_path ) {
			return false;
		}

		return $candidate_path === $base_path ||
			0 === strpos( $candidate_path, $base_path . '/' );
	}
}
