<?php
/**
 * Shared file and path helpers.
 *
 * @package PeakURL\Utils
 * @since 1.1.1
 */

declare(strict_types=1);

namespace PeakURL\Utils;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * File — stateless file and path helpers.
 *
 * @since 1.1.1
 */
class File {

	/**
	 * Create a directory, including missing parent directories.
	 *
	 * @param string $path        Directory path.
	 * @param int    $permissions Directory permissions.
	 * @return bool True when the directory exists after the call.
	 * @since 1.1.1
	 */
	public static function mkdir_p(
		string $path,
		int $permissions = 0755
	): bool {
		if ( is_dir( $path ) ) {
			return true;
		}

		return mkdir( $path, $permissions, true ) || is_dir( $path );
	}

	/**
	 * Build a file path from a base and one or more segments.
	 *
	 * @param string   $base_path Base directory.
	 * @param string[] $segments  Path segments to append.
	 * @return string
	 * @since 1.1.1
	 */
	public static function join_path(
		string $base_path,
		string ...$segments
	): string {
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
	 * Delete a file, symlink, or directory tree.
	 *
	 * @param string $path  File or directory path.
	 * @param bool   $quiet Whether to handle PHP warnings internally.
	 * @return bool True when the path is absent after the call.
	 * @since 1.1.1
	 */
	public static function delete( string $path, bool $quiet = false ): bool {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return self::unlink_path( $path, $quiet );
		}

		$deleted  = true;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$path,
				\FilesystemIterator::SKIP_DOTS,
			),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();

			if ( $item->isDir() ) {
				$deleted = self::rmdir_path( $item_path, $quiet ) && $deleted;
				continue;
			}

			$deleted = self::unlink_path( $item_path, $quiet ) && $deleted;
		}

		return self::rmdir_path( $path, $quiet ) && $deleted;
	}

	/**
	 * Move a file or directory path.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @param bool   $quiet  Whether to handle PHP warnings internally.
	 * @return bool True when the path was moved.
	 * @since 1.1.1
	 */
	public static function move(
		string $source,
		string $target,
		bool $quiet = false
	): bool {
		if ( $quiet ) {
			return self::run_quietly(
				static function () use ( $source, $target ): bool {
					return rename( $source, $target );
				},
			);
		}

		return rename( $source, $target );
	}

	/**
	 * Copy a file path.
	 *
	 * @param string $source Source file path.
	 * @param string $target Target file path.
	 * @param bool   $quiet  Whether to handle PHP warnings internally.
	 * @return bool True when the file was copied.
	 * @since 1.1.1
	 */
	public static function copy(
		string $source,
		string $target,
		bool $quiet = false
	): bool {
		if ( $quiet ) {
			return self::run_quietly(
				static function () use ( $source, $target ): bool {
					return copy( $source, $target );
				},
			);
		}

		return copy( $source, $target );
	}

	/**
	 * Normalize a filesystem path for path-prefix comparisons.
	 *
	 * @param string $path Absolute or relative filesystem path.
	 * @return string
	 * @since 1.1.1
	 */
	public static function normalize_path( string $path ): string {
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
	 * @since 1.1.1
	 */
	public static function is_path_inside(
		string $candidate_path,
		string $base_path
	): bool {
		if ( '' === $base_path ) {
			return false;
		}

		return $candidate_path === $base_path ||
			0 === strpos( $candidate_path, $base_path . '/' );
	}

	/**
	 * Check whether an archive member path is safe to extract.
	 *
	 * @param string $path Archive member path.
	 * @return bool
	 * @since 1.1.1
	 */
	public static function is_safe_archive_path( string $path ): bool {
		$path = self::normalize_path( $path );

		if (
			'' === $path ||
			false !== strpos( $path, "\0" ) ||
			false !== strpos( $path, ':' ) ||
			str_starts_with( $path, '/' ) ||
			preg_match( '/^[A-Za-z]:/', $path )
		) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove a single file path.
	 *
	 * @param string $path  File path.
	 * @param bool   $quiet Whether to handle PHP warnings internally.
	 * @return bool True when the file was removed.
	 * @since 1.1.1
	 */
	private static function unlink_path( string $path, bool $quiet ): bool {
		if ( $quiet ) {
			return self::run_quietly(
				static function () use ( $path ): bool {
					return unlink( $path );
				},
			);
		}

		return unlink( $path );
	}

	/**
	 * Remove a single directory path.
	 *
	 * @param string $path  Directory path.
	 * @param bool   $quiet Whether to handle PHP warnings internally.
	 * @return bool True when the directory was removed.
	 * @since 1.1.1
	 */
	private static function rmdir_path( string $path, bool $quiet ): bool {
		if ( $quiet ) {
			return self::run_quietly(
				static function () use ( $path ): bool {
					return rmdir( $path );
				},
			);
		}

		return rmdir( $path );
	}

	/**
	 * Run a file operation while converting warnings into a false result.
	 *
	 * @param callable $operation File operation callback.
	 * @return bool True when the wrapped operation succeeds.
	 * @since 1.1.1
	 */
	private static function run_quietly( callable $operation ): bool {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			return (bool) $operation();
		} finally {
			restore_error_handler();
		}
	}
}
