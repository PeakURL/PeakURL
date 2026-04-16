<?php
/**
 * GeoIP filesystem helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Filesystem — manage temporary files and local database replacement.
 *
 * @since 1.0.14
 */
class Filesystem {

	/**
	 * Create a directory recursively when needed.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 * @since 1.0.14
	 */
	public function create_directory( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the required directory: ', 'peakurl' ) . $path,
			);
		}
	}

	/**
	 * Recursively delete a file or directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 * @since 1.0.14
	 */
	public function remove_path( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$path,
				\RecursiveDirectoryIterator::SKIP_DOTS,
			),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
				continue;
			}

			@unlink( $item->getPathname() );
		}

		@rmdir( $path );
	}

	/**
	 * Extract the downloaded archive into a working directory.
	 *
	 * @param string $archive_path Archive file path.
	 * @param string $extract_path Extraction directory path.
	 * @return void
	 *
	 * @throws \RuntimeException When the archive cannot be unpacked.
	 * @since 1.0.14
	 */
	public function extract_archive( string $archive_path, string $extract_path ): void {
		$tar_path = preg_replace( '/\.gz$/', '', $archive_path );

		if ( ! is_string( $tar_path ) || '' === $tar_path ) {
			throw new \RuntimeException( __( 'PeakURL could not prepare the GeoLite2 archive for extraction.', 'peakurl' ) );
		}

		if ( file_exists( $tar_path ) ) {
			unlink( $tar_path );
		}

		try {
			$archive = new \PharData( $archive_path );
			$archive->decompress();

			$tar = new \PharData( $tar_path );
			$tar->extractTo( $extract_path, null, true );
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException(
				__( 'PeakURL could not unpack the GeoLite2 archive. ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception,
			);
		} finally {
			if ( file_exists( $tar_path ) ) {
				unlink( $tar_path );
			}
		}
	}

	/**
	 * Find the GeoLite2 database file inside the extracted archive.
	 *
	 * @param string $extract_path Extraction directory path.
	 * @return string|null
	 * @since 1.0.14
	 */
	public function find_database_file( string $extract_path ): ?string {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$extract_path,
				\RecursiveDirectoryIterator::SKIP_DOTS,
			),
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( 'GeoLite2-City.mmdb' !== $file->getFilename() ) {
				continue;
			}

			return $file->getPathname();
		}

		return null;
	}

	/**
	 * Atomically replace the local database file.
	 *
	 * @param string $source_path Source database file path.
	 * @param string $target_path Final database file path.
	 * @return void
	 *
	 * @throws \RuntimeException When the replacement fails.
	 * @since 1.0.14
	 */
	public function replace_database_file( string $source_path, string $target_path ): void {
		$temp_path = dirname( $target_path ) . '/.' . basename( $target_path ) . '.tmp';

		if ( ! copy( $source_path, $temp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not copy the downloaded GeoLite2 database into place.', 'peakurl' ),
			);
		}

		if ( file_exists( $target_path ) && ! unlink( $target_path ) ) {
			@unlink( $temp_path );
			throw new \RuntimeException(
				__( 'PeakURL could not replace the existing GeoLite2 database file.', 'peakurl' ),
			);
		}

		if ( ! rename( $temp_path, $target_path ) ) {
			@unlink( $temp_path );
			throw new \RuntimeException(
				__( 'PeakURL could not activate the downloaded GeoLite2 database file.', 'peakurl' ),
			);
		}
	}
}
