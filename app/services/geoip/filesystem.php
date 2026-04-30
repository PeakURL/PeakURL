<?php
/**
 * GeoIP filesystem helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use PeakURL\Utils\File;

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
	public function mkdir_p( string $path ): void {
		if ( ! File::mkdir_p( $path, 0755 ) ) {
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
	public function delete( string $path ): void {
		File::delete( $path, true );
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

		$this->delete( $tar_path );

		try {
			$archive = new \PharData( $archive_path );
			$archive->decompress();

			$tar = new \PharData( $tar_path );
			$this->validate_archive( $tar );
			$tar->extractTo( $extract_path, null, true );
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException(
				__( 'PeakURL could not unpack the GeoLite2 archive. ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception,
			);
		} finally {
			$this->delete( $tar_path );
		}
	}

	/**
	 * Validate archive entries before extraction.
	 *
	 * @param \PharData $archive Open tar archive.
	 * @return void
	 *
	 * @throws \RuntimeException When the archive contains an unsafe path.
	 * @since 1.1.1
	 */
	private function validate_archive( \PharData $archive ): void {
		$archive_path = str_replace( '\\', '/', $archive->getPath() );
		$iterator     = new \RecursiveIteratorIterator(
			$archive,
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ( $iterator as $entry ) {
			$entry_name = $this->archive_entry_name( $entry, $archive_path );

			if (
				! File::is_safe_archive_path( $entry_name ) ||
				( method_exists( $entry, 'isLink' ) && $entry->isLink() )
			) {
				throw new \RuntimeException(
					__( 'The GeoLite2 archive contains an unsafe file path.', 'peakurl' ),
				);
			}
		}
	}

	/**
	 * Return the archive-relative entry name from a PharData item.
	 *
	 * @param \SplFileInfo $entry        Archive entry.
	 * @param string       $archive_path Normalized tar path.
	 * @return string
	 * @since 1.1.1
	 */
	private function archive_entry_name(
		\SplFileInfo $entry,
		string $archive_path
	): string {
		$entry_path = str_replace( '\\', '/', $entry->getPathname() );

		if ( 0 === strpos( $entry_path, 'phar://' ) ) {
			$entry_path = substr( $entry_path, strlen( 'phar://' ) );
		}

		$prefix = rtrim( $archive_path, '/' ) . '/';

		if ( 0 === strpos( $entry_path, $prefix ) ) {
			return substr( $entry_path, strlen( $prefix ) );
		}

		return $entry_path;
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

		if ( ! File::copy( $source_path, $temp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not copy the downloaded GeoLite2 database into place.', 'peakurl' ),
			);
		}

		if ( ! File::delete( $target_path, true ) ) {
			$this->delete( $temp_path );
			throw new \RuntimeException(
				__( 'PeakURL could not replace the existing GeoLite2 database file.', 'peakurl' ),
			);
		}

		if ( ! File::move( $temp_path, $target_path ) ) {
			$this->delete( $temp_path );
			throw new \RuntimeException(
				__( 'PeakURL could not activate the downloaded GeoLite2 database file.', 'peakurl' ),
			);
		}
	}
}
