<?php
/**
 * GeoIP filesystem helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;
use PeakURL\Utils\File;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Filesystem — manage temporary files, validation, and rollback-safe database replacement.
 *
 * @since 1.0.14
 */
class Filesystem {

	/**
	 * Minimum valid database file size in bytes (100 B).
	 *
	 * A valid binary MaxMind database requires at minimum a search tree,
	 * data section separator, 14-byte metadata marker, and metadata map.
	 * Structural verification is performed by Reader::metadata().
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const MIN_DATABASE_BYTES = 100;

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
				! File::is_valid_archive_path( $entry_name ) ||
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
	 * Validate that a candidate database file is non-empty, readable, and structurally sound.
	 *
	 * @param string $file_path Candidate database file path.
	 * @return bool True when the database can be opened and contains City metadata.
	 * @since 1.7.2
	 */
	public function validate_database_file( string $file_path ): bool {
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$size = @filesize( $file_path );
		if ( false === $size || $size < self::MIN_DATABASE_BYTES ) {
			return false;
		}

		if ( ! class_exists( Reader::class ) ) {
			return false;
		}

		try {
			$reader   = new Reader( $file_path );
			$metadata = $reader->metadata();
			$reader->close();

			if ( ! is_object( $metadata ) ) {
				return false;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$database_type = (string) ( $metadata->databaseType ?? '' );
			if ( false === stripos( $database_type, 'City' ) ) {
				return false;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$build_epoch = (int) ( $metadata->buildEpoch ?? 0 );
			if ( $build_epoch <= 0 ) {
				return false;
			}

			return true;
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Acquire the cross-process GeoIP update lock.
	 *
	 * Uses a non-blocking exclusive lock on .geoip_update.lock in the destination
	 * directory. The lock file inode is preserved across invocations.
	 *
	 * @param string $database_path Active database file path.
	 * @return resource Locked file pointer.
	 *
	 * @throws \RuntimeException When another update is already in progress.
	 * @since 1.7.2
	 */
	public function acquire_lock( string $database_path ) {
		$target_dir = dirname( $database_path );
		$this->mkdir_p( $target_dir );

		$lock_file = $target_dir . '/.geoip_update.lock';
		$lock_fp   = @fopen( $lock_file, 'c+' );

		if ( false === $lock_fp || ! flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock_fp ) ) {
				fclose( $lock_fp );
			}
			throw new \RuntimeException(
				__( 'Another GeoIP database update is currently in progress.', 'peakurl' ),
			);
		}

		return $lock_fp;
	}

	/**
	 * Release a previously acquired cross-process GeoIP update lock.
	 *
	 * @param resource|null $lock_handle Open locked file handle.
	 * @return void
	 * @since 1.7.2
	 */
	public function release_lock( $lock_handle ): void {
		if ( is_resource( $lock_handle ) ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle );
		}
	}

	/**
	 * Replace the active database file atomically and safely with rollback protection.
	 *
	 * Stages the replacement, validates the staged candidate before activation, and uses
	 * cross-process file locking. If replacement fails, the existing database remains
	 * completely intact.
	 *
	 * @param string        $source_path Candidate database file path.
	 * @param string        $target_path Final active database file path.
	 * @param resource|null $lock_handle Optional active lock handle already held by caller.
	 * @return void
	 *
	 * @throws \RuntimeException When validation, locking, or atomic swap fails.
	 * @since 1.0.14
	 */
	public function replace_database_file(
		string $source_path,
		string $target_path,
		$lock_handle = null
	): void {
		if ( is_link( $target_path ) ) {
			throw new \RuntimeException(
				__( 'Target database path cannot be a symbolic link.', 'peakurl' ),
			);
		}

		$target_dir = dirname( $target_path );
		$this->mkdir_p( $target_dir );

		// If caller already holds the lock, reuse it; otherwise acquire lock locally.
		$own_lock = null;
		if ( ! is_resource( $lock_handle ) ) {
			$own_lock = $this->acquire_lock( $target_path );
		}

		$staging_path = $target_dir . '/.' . basename( $target_path ) . '.staged-' . bin2hex( random_bytes( 4 ) );

		try {
			// Stage file into destination directory to ensure same filesystem for atomic rename.
			if ( ! File::copy( $source_path, $staging_path ) ) {
				throw new \RuntimeException(
					__( 'PeakURL could not stage the downloaded GeoLite2 database into place.', 'peakurl' ),
				);
			}

			chmod( $staging_path, 0644 );

			// Validate the staged file before activation.
			if ( ! $this->validate_database_file( $staging_path ) ) {
				throw new \RuntimeException(
					__( 'The downloaded GeoLite2 database file failed integrity verification.', 'peakurl' ),
				);
			}

			// Atomic replacement: replace active target directly.
			// If rename fails, the existing target database is completely preserved.
			if ( ! @rename( $staging_path, $target_path ) ) {
				throw new \RuntimeException(
					__( 'PeakURL could not activate the downloaded GeoLite2 database file.', 'peakurl' ),
				);
			}
		} finally {
			if ( file_exists( $staging_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning temporary staging file.
				@unlink( $staging_path );
			}
			if ( null !== $own_lock ) {
				$this->release_lock( $own_lock );
			}
		}
	}
}
