<?php
/**
 * GeoIP release preparation script.
 *
 * Validates upstream GeoLite2 City archives or directory trees, extracts the City MMDB
 * with path traversal and symlink guards, inspects metadata using MaxMind\Db\Reader,
 * compresses raw MMDB to GeoLite2-City.mmdb.gz (streaming/bounded memory), verifies
 * decompression integrity, generates a schema-1 manifest.json, and validates it
 * against PeakURL\Services\Geoip\R2Source.
 *
 * @package PeakURL\Scripts\Geoip
 */

declare(strict_types=1);

namespace PeakURL\Scripts\Geoip;

use MaxMind\Db\Reader;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Geoip\R2Source;
use PeakURL\Utils\File;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

// Direct execution guard / bootstrap.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/server/' );
}

require_once dirname( __DIR__, 2 ) . '/server/vendor/autoload.php';

/**
 * Class GeoipPreparer
 *
 * Encapsulates the complete database preparation, extraction, validation,
 * compression, round-trip verification, and manifest generation workflow.
 */
class GeoipPreparer {

	/**
	 * Maximum allowed uncompressed MMDB size (200 MiB).
	 */
	public const MAX_UNCOMPRESSED_BYTES = 209715200;

	/**
	 * Maximum allowed compressed archive size (100 MiB).
	 */
	public const MAX_COMPRESSED_BYTES = 104857600;

	/**
	 * Maximum allowed upstream archive size (100 MiB).
	 */
	public const MAX_UPSTREAM_ARCHIVE_BYTES = 104857600;

	/**
	 * Maximum allowed total uncompressed bytes across all archive entries (250 MiB).
	 */
	public const MAX_TOTAL_UNCOMPRESSED_BYTES = 262144000;

	/**
	 * Maximum allowed entry count in upstream archive.
	 */
	public const MAX_ENTRY_COUNT = 50;

	/**
	 * Maximum allowed notice file size (1 MiB).
	 */
	public const MAX_NOTICE_BYTES = 1048576;

	/**
	 * Local-only preparation origin marker filename.
	 */
	public const ORIGIN_MARKER_FILENAME = '.release-origin.json';

	/**
	 * Streaming chunk size in bytes (64 KiB).
	 */
	public const CHUNK_SIZE = 65536;

	/**
	 * Canonical City database filename.
	 */
	public const DATABASE_FILENAME = 'GeoLite2-City.mmdb';

	/**
	 * Canonical compressed archive filename.
	 */
	public const ARCHIVE_FILENAME = 'GeoLite2-City.mmdb.gz';

	/**
	 * Canonical manifest filename.
	 */
	public const MANIFEST_FILENAME = 'manifest.json';

	/**
	 * Canonical license filename.
	 */
	public const LICENSE_FILENAME = 'LICENSE.txt';

	/**
	 * Canonical copyright filename.
	 */
	public const COPYRIGHT_FILENAME = 'COPYRIGHT.txt';

	/**
	 * Safely unpack and validate an upstream GeoLite2 tar.gz archive.
	 *
	 * Defends against path traversal, absolute paths, symlinks, missing notices,
	 * zero-size files, decompression bombs, and multiple/ambiguous MMDB matches.
	 *
	 * @param string $archive_path Upstream archive file path.
	 * @param string $extract_dir  Target extraction directory.
	 * @return array{mmdb_path: string, license_path: string, copyright_path: string} Extracted file paths.
	 *
	 * @throws RuntimeException On any structural, security, or validation error.
	 */
	public static function extract_and_validate_tar( string $archive_path, string $extract_dir ): array {
		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
			throw new RuntimeException( "Upstream archive does not exist or is not readable: {$archive_path}" );
		}

		$archive_size = @filesize( $archive_path );
		if ( false === $archive_size || 0 === $archive_size ) {
			throw new RuntimeException( 'Upstream archive is empty (0 bytes).' );
		}

		if ( $archive_size > self::MAX_UPSTREAM_ARCHIVE_BYTES ) {
			throw new RuntimeException(
				sprintf(
					'Upstream archive exceeds maximum permitted size: %d bytes (limit: %d bytes).',
					$archive_size,
					self::MAX_UPSTREAM_ARCHIVE_BYTES
				)
			);
		}

		if ( ! is_dir( $extract_dir ) ) {
			mkdir( $extract_dir, 0755, true );
		}

		$entry_count              = 0;
		$total_uncompressed_bytes = 0;
		$mmdb_count               = 0;
		$has_license              = false;
		$has_copyright            = false;
		$mmdb_entry               = null;
		$license_entry            = null;
		$copyright_entry          = null;

		$phar_gz = null;
		try {
			$phar_gz = new PharData( $archive_path );

			$iterator     = new RecursiveIteratorIterator( $phar_gz, RecursiveIteratorIterator::SELF_FIRST );
			$archive_base = rtrim( str_replace( '\\', '/', $phar_gz->getPath() ), '/' ) . '/';

			foreach ( $iterator as $entry ) {
				$filename = $entry->getFilename();

				// Skip AppleDouble resource fork entries on macOS.
				if ( str_starts_with( $filename, '._' ) ) {
					continue;
				}

				$entry_path = str_replace( '\\', '/', $entry->getPathname() );
				if ( 0 === strpos( $entry_path, 'phar://' ) ) {
					$entry_path = substr( $entry_path, strlen( 'phar://' ) );
				}
				if ( 0 === strpos( $entry_path, $archive_base ) ) {
					$entry_path = substr( $entry_path, strlen( $archive_base ) );
				}

				// 1. Guard against path traversal, absolute paths, NUL bytes, and colon separators.
				if ( ! File::is_valid_archive_path( $entry_path ) ) {
					throw new RuntimeException( "Archive contains unsafe path traversal entry: {$entry_path}" );
				}

				// 2. Guard against symlinks and hardlinks.
				if ( ( method_exists( $entry, 'isLink' ) && $entry->isLink() ) || is_link( $entry->getPathname() ) ) {
					throw new RuntimeException( "Archive contains unsafe symlink entry: {$entry_path}" );
				}

				if ( $entry->isDir() ) {
					continue;
				}

				// 3. Bound entry count.
				++$entry_count;
				if ( $entry_count > self::MAX_ENTRY_COUNT ) {
					throw new RuntimeException(
						sprintf(
							'Archive exceeds maximum permitted entry count: %d (limit: %d).',
							$entry_count,
							self::MAX_ENTRY_COUNT
						)
					);
				}

				// 4. Bound individual and cumulative uncompressed bytes.
				$entry_size                = $entry->getSize();
				$total_uncompressed_bytes += $entry_size;
				if ( $total_uncompressed_bytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES ) {
					throw new RuntimeException(
						sprintf(
							'Archive total uncompressed size exceeds maximum permitted limit: %d bytes (limit: %d bytes).',
							$total_uncompressed_bytes,
							self::MAX_TOTAL_UNCOMPRESSED_BYTES
						)
					);
				}

				if ( str_ends_with( strtolower( $filename ), '.mmdb' ) ) {
					if ( $entry_size > self::MAX_UNCOMPRESSED_BYTES ) {
						throw new RuntimeException(
							sprintf(
								'MMDB entry exceeds maximum allowed size: %d bytes (limit: %d bytes).',
								$entry_size,
								self::MAX_UNCOMPRESSED_BYTES
							)
						);
					}
					++$mmdb_count;
					$mmdb_entry = $entry_path;
				}

				if ( 0 === strcasecmp( $filename, 'LICENSE.txt' ) || 0 === strcasecmp( $filename, 'LICENSE' ) ) {
					if ( $entry_size > self::MAX_NOTICE_BYTES ) {
						throw new RuntimeException(
							sprintf(
								'LICENSE entry exceeds maximum allowed size: %d bytes (limit: %d bytes).',
								$entry_size,
								self::MAX_NOTICE_BYTES
							)
						);
					}
					$has_license   = true;
					$license_entry = $entry_path;
				}

				if ( 0 === strcasecmp( $filename, 'COPYRIGHT.txt' ) || 0 === strcasecmp( $filename, 'COPYRIGHT' ) ) {
					if ( $entry_size > self::MAX_NOTICE_BYTES ) {
						throw new RuntimeException(
							sprintf(
								'COPYRIGHT entry exceeds maximum allowed size: %d bytes (limit: %d bytes).',
								$entry_size,
								self::MAX_NOTICE_BYTES
							)
						);
					}
					$has_copyright   = true;
					$copyright_entry = $entry_path;
				}
			}

			// Validate entry counts before extraction.
			if ( 0 === $mmdb_count ) {
				throw new RuntimeException( 'Archive contains no GeoLite2 MMDB database file.' );
			}
			if ( $mmdb_count > 1 ) {
				throw new RuntimeException( "Archive contains multiple database files ({$mmdb_count}); exactly one is expected." );
			}
			if ( ! $has_license || null === $license_entry ) {
				throw new RuntimeException( 'Archive is missing required upstream LICENSE.txt notice.' );
			}
			if ( ! $has_copyright || null === $copyright_entry ) {
				throw new RuntimeException( 'Archive is missing required upstream COPYRIGHT.txt notice.' );
			}

			// Extract ONLY the identified candidate database and notice files.
			$phar_gz->extractTo( $extract_dir, array( $mmdb_entry, $license_entry, $copyright_entry ), true );
		} catch ( Throwable $exception ) {
			throw new RuntimeException(
				'Failed to unpack and validate GeoIP archive: ' . $exception->getMessage(),
				0,
				$exception
			);
		} finally {
			unset( $entry, $iterator, $phar_gz );
		}

		$found_mmdb      = $extract_dir . '/' . $mmdb_entry;
		$found_license   = $extract_dir . '/' . $license_entry;
		$found_copyright = $extract_dir . '/' . $copyright_entry;

		if ( ! is_file( $found_mmdb ) ) {
			throw new RuntimeException( 'Extracted archive did not contain an MMDB database file on disk.' );
		}
		if ( ! is_file( $found_license ) ) {
			throw new RuntimeException( 'Extracted archive did not contain LICENSE.txt on disk.' );
		}
		if ( ! is_file( $found_copyright ) ) {
			throw new RuntimeException( 'Extracted archive did not contain COPYRIGHT.txt on disk.' );
		}

		return array(
			'mmdb_path'      => $found_mmdb,
			'license_path'   => $found_license,
			'copyright_path' => $found_copyright,
		);
	}

	/**
	 * Inspect and validate MMDB file metadata using MaxMind\Db\Reader.
	 *
	 * @param string $mmdb_path     Path to raw MMDB file.
	 * @param string $expected_type Expected database type substring (default 'City').
	 * @return array{databaseType: string, buildEpoch: int, uncompressedSize: int}
	 *
	 * @throws RuntimeException On any structural, size, or metadata violation.
	 */
	public static function validate_mmdb( string $mmdb_path, string $expected_type = 'City' ): array {
		if ( ! is_file( $mmdb_path ) || ! is_readable( $mmdb_path ) ) {
			throw new RuntimeException( "MMDB file does not exist or is not readable: {$mmdb_path}" );
		}

		$size = @filesize( $mmdb_path );
		if ( false === $size || $size <= 0 ) {
			throw new RuntimeException( "MMDB file is empty or unreadable: {$mmdb_path}" );
		}

		if ( $size > self::MAX_UNCOMPRESSED_BYTES ) {
			throw new RuntimeException(
				sprintf(
					'MMDB file size exceeds maximum permitted uncompressed limit: %d bytes (limit: %d bytes).',
					$size,
					self::MAX_UNCOMPRESSED_BYTES
				)
			);
		}

		try {
			$reader   = new Reader( $mmdb_path );
			$metadata = $reader->metadata();
			$reader->close();
		} catch ( Throwable $exception ) {
			throw new RuntimeException(
				"Failed to read MMDB metadata: {$exception->getMessage()}",
				0,
				$exception
			);
		}

		if ( ! is_object( $metadata ) ) {
			throw new RuntimeException( 'MMDB metadata is not a valid object.' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$database_type = (string) ( $metadata->databaseType ?? '' );
		if ( false === stripos( $database_type, $expected_type ) ) {
			throw new RuntimeException(
				sprintf(
					"MMDB database type '%s' does not match expected edition '%s'.",
					$database_type,
					$expected_type
				)
			);
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$build_epoch = (int) ( $metadata->buildEpoch ?? 0 );
		if ( $build_epoch <= 0 ) {
			throw new RuntimeException( "MMDB contains invalid or non-positive build epoch: {$build_epoch}." );
		}

		return array(
			'databaseType'     => $database_type,
			'buildEpoch'       => $build_epoch,
			'uncompressedSize' => $size,
		);
	}

	/**
	 * Compress raw MMDB to gzip format using bounded chunked streaming.
	 *
	 * Ensures the output contains ONLY the raw MMDB binary (never a tarball).
	 *
	 * @param string $source_path    Path to raw MMDB file.
	 * @param string $target_gz_path Path to write compressed .mmdb.gz file.
	 * @return int Byte count of the resulting compressed gzip file.
	 *
	 * @throws RuntimeException On I/O or compression failure.
	 */
	public static function compress_to_gzip( string $source_path, string $target_gz_path ): int {
		$source = @fopen( $source_path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( "Failed to open source MMDB for compression: {$source_path}" );
		}

		$dir = dirname( $target_gz_path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$target = @gzopen( $target_gz_path, 'wb9' );
		if ( false === $target ) {
			fclose( $source );
			throw new RuntimeException( "Failed to open target gzip file for writing: {$target_gz_path}" );
		}

		$bytes_read = 0;
		while ( ! feof( $source ) ) {
			$chunk = fread( $source, self::CHUNK_SIZE );
			if ( false === $chunk ) {
				fclose( $source );
				gzclose( $target );
				@unlink( $target_gz_path );
				throw new RuntimeException( 'Read error during MMDB compression.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$bytes_read   += strlen( $chunk );
			$chunk_len     = strlen( $chunk );
			$bytes_written = 0;
			while ( $bytes_written < $chunk_len ) {
				$written = gzwrite( $target, substr( $chunk, $bytes_written ) );
				if ( false === $written || 0 === $written ) {
					fclose( $source );
					gzclose( $target );
					@unlink( $target_gz_path );
					throw new RuntimeException( 'Write error or short write during MMDB compression.' );
				}
				$bytes_written += $written;
			}
		}

		$f_closed  = fclose( $source );
		$gz_closed = gzclose( $target );
		if ( ! $gz_closed || ! $f_closed ) {
			@unlink( $target_gz_path );
			throw new RuntimeException( 'Failed to close file handles cleanly after compression.' );
		}

		$compressed_size = @filesize( $target_gz_path );
		if ( false === $compressed_size || 0 === $compressed_size ) {
			@unlink( $target_gz_path );
			throw new RuntimeException( 'Compressed gzip archive is empty (0 bytes).' );
		}

		if ( $compressed_size > self::MAX_COMPRESSED_BYTES ) {
			@unlink( $target_gz_path );
			throw new RuntimeException(
				sprintf(
					'Compressed archive exceeds maximum permitted limit: %d bytes (limit: %d bytes).',
					$compressed_size,
					self::MAX_COMPRESSED_BYTES
				)
			);
		}

		return $compressed_size;
	}

	/**
	 * Verify round-trip decompression integrity of the generated gzip file.
	 *
	 * Asserts that decompression yields exact byte-identical raw MMDB and identical metadata.
	 *
	 * @param string $gz_path            Path to compressed .mmdb.gz file.
	 * @param string $original_mmdb_path Path to original raw MMDB file.
	 * @param int    $expected_epoch     Expected build epoch timestamp.
	 * @return void
	 *
	 * @throws RuntimeException On any verification mismatch or corruption.
	 */
	public static function verify_decompression_integrity(
		string $gz_path,
		string $original_mmdb_path,
		int $expected_epoch
	): void {
		$verify_path = $gz_path . '.verify_' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		$source = @gzopen( $gz_path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( "Failed to open gzip archive for verification: {$gz_path}" );
		}

		$target = @fopen( $verify_path, 'wb' );
		if ( false === $target ) {
			gzclose( $source );
			throw new RuntimeException( "Failed to open temporary verification file: {$verify_path}" );
		}

		while ( ! gzeof( $source ) ) {
			$chunk = gzread( $source, self::CHUNK_SIZE );
			if ( false === $chunk ) {
				gzclose( $source );
				fclose( $target );
				@unlink( $verify_path );
				throw new RuntimeException( 'Decompression error during round-trip integrity verification.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$chunk_len     = strlen( $chunk );
			$bytes_written = 0;
			while ( $bytes_written < $chunk_len ) {
				$written = fwrite( $target, substr( $chunk, $bytes_written ) );
				if ( false === $written || 0 === $written ) {
					gzclose( $source );
					fclose( $target );
					@unlink( $verify_path );
					throw new RuntimeException( 'Write error or short write during round-trip integrity verification.' );
				}
				$bytes_written += $written;
			}
		}

		$gz_closed = gzclose( $source );
		$f_closed  = fclose( $target );
		if ( ! $gz_closed || ! $f_closed ) {
			@unlink( $verify_path );
			throw new RuntimeException( 'Failed to close file handles cleanly during verification.' );
		}

		try {
			$original_size = filesize( $original_mmdb_path );
			$verify_size   = filesize( $verify_path );

			if ( $original_size !== $verify_size ) {
				throw new RuntimeException(
					sprintf(
						'Decompressed file size mismatch: %d bytes (original: %d bytes).',
						$verify_size,
						$original_size
					)
				);
			}

			$original_sha = hash_file( 'sha256', $original_mmdb_path );
			$verify_sha   = hash_file( 'sha256', $verify_path );

			if ( $original_sha !== $verify_sha ) {
				throw new RuntimeException(
					'Decompressed file hash mismatch: raw MMDB bytes were corrupted during round trip.'
				);
			}

			$reader   = new Reader( $verify_path );
			$metadata = $reader->metadata();
			$reader->close();

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$extracted_epoch = (int) ( $metadata->buildEpoch ?? 0 );
			if ( $extracted_epoch !== $expected_epoch ) {
				throw new RuntimeException(
					sprintf(
						'Metadata epoch mismatch in decompressed file: %d (expected: %d).',
						$extracted_epoch,
						$expected_epoch
					)
				);
			}
		} finally {
			@unlink( $verify_path );
		}
	}

	/**
	 * Build schema-1 manifest payload and strictly validate it against R2Source.
	 *
	 * @param string $gz_path      Path to the compressed .mmdb.gz file.
	 * @param int    $build_epoch  MMDB metadata build epoch.
	 * @param string $download_url Canonical public download URL.
	 * @return array{manifest: array<string, mixed>, json: string}
	 *
	 * @throws RuntimeException On schema or validation failure.
	 */
	public static function build_and_validate_manifest(
		string $gz_path,
		int $build_epoch,
		string $download_url = Constants::DEFAULT_GEOIP_ARCHIVE_URL
	): array {
		if ( ! is_file( $gz_path ) || ! is_readable( $gz_path ) ) {
			throw new RuntimeException( "Compressed archive not found on disk: {$gz_path}" );
		}

		$size_bytes = @filesize( $gz_path );
		if ( false === $size_bytes || $size_bytes <= 0 ) {
			throw new RuntimeException( "Invalid compressed archive size: {$gz_path}" );
		}

		$checksum_sha256 = hash_file( 'sha256', $gz_path );
		if ( false === $checksum_sha256 || 64 !== strlen( $checksum_sha256 ) ) {
			throw new RuntimeException( "Failed to compute SHA-256 for archive: {$gz_path}" );
		}
		$checksum_sha256 = strtolower( $checksum_sha256 );

		$manifest = array(
			'schemaVersion'  => 1,
			'edition'        => 'GeoLite2-City',
			'buildEpoch'     => $build_epoch,
			'downloadUrl'    => $download_url,
			'checksumSha256' => $checksum_sha256,
			'sizeBytes'      => $size_bytes,
		);

		$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( false === $json ) {
			throw new RuntimeException( 'Failed to encode manifest array to JSON.' );
		}

		// Strictly validate against PeakURL R2Source schema parser to guarantee contract compliance.
		R2Source::validate_manifest_schema( $json );

		return array(
			'manifest' => $manifest,
			'json'     => $json,
		);
	}

	/**
	 * Execute release preparation workflow.
	 *
	 * @param array<string, mixed> $options Execution options.
	 * @return array<string, mixed> Execution result summary.
	 *
	 * @throws RuntimeException On any failure.
	 */
	public static function prepare( array $options ): array {
		$archive_path = isset( $options['archive'] ) ? (string) $options['archive'] : null;
		$input_dir    = isset( $options['input_dir'] ) ? (string) $options['input_dir'] : null;
		$output_dir   = isset( $options['output_dir'] ) ? (string) $options['output_dir'] : null;
		$dry_run      = ! empty( $options['dry_run'] );
		$custom_epoch = isset( $options['epoch'] ) ? (int) $options['epoch'] : null;
		$download_url = isset( $options['download_url'] ) ? (string) $options['download_url'] : Constants::DEFAULT_GEOIP_ARCHIVE_URL;
		$origin       = isset( $options['origin'] ) ? (string) $options['origin'] : 'official';

		if ( empty( $output_dir ) ) {
			$output_dir = sys_get_temp_dir() . '/peakurl_geoip_dist_' . bin2hex( random_bytes( 4 ) );
		}

		$temp_working_dir = sys_get_temp_dir() . '/peakurl_geoip_prep_' . bin2hex( random_bytes( 4 ) );
		mkdir( $temp_working_dir, 0755, true );

		try {
			$mmdb_path      = null;
			$license_path   = null;
			$copyright_path = null;

			if ( null !== $archive_path && '' !== trim( $archive_path ) ) {
				// Unpack and validate upstream archive.
				$extract_dir    = $temp_working_dir . '/extract';
				$extracted      = self::extract_and_validate_tar( $archive_path, $extract_dir );
				$mmdb_path      = $extracted['mmdb_path'];
				$license_path   = $extracted['license_path'];
				$copyright_path = $extracted['copyright_path'];
			} elseif ( null !== $input_dir && '' !== trim( $input_dir ) && is_dir( $input_dir ) ) {
				// Input directory mode.
				$dir_iterator    = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $input_dir, RecursiveDirectoryIterator::SKIP_DOTS )
				);
				$mmdb_candidates = array();

				foreach ( $dir_iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					$name = $file->getFilename();
					if ( str_ends_with( strtolower( $name ), '.mmdb' ) ) {
						$mmdb_candidates[] = $file->getPathname();
					} elseif ( 0 === strcasecmp( $name, 'LICENSE.txt' ) || 0 === strcasecmp( $name, 'LICENSE' ) ) {
						$license_path = $file->getPathname();
					} elseif ( 0 === strcasecmp( $name, 'COPYRIGHT.txt' ) || 0 === strcasecmp( $name, 'COPYRIGHT' ) ) {
						$copyright_path = $file->getPathname();
					}
				}

				if ( 0 === count( $mmdb_candidates ) ) {
					throw new RuntimeException( "Input directory contains no MMDB files: {$input_dir}" );
				}
				if ( count( $mmdb_candidates ) > 1 ) {
					throw new RuntimeException( "Input directory contains multiple MMDB files: {$input_dir}" );
				}
				$mmdb_path = $mmdb_candidates[0];

				if ( null === $license_path || null === $copyright_path ) {
					throw new RuntimeException( 'Input directory is missing required notice files (LICENSE.txt / COPYRIGHT.txt).' );
				}
			} else {
				throw new RuntimeException(
					'Missing input source: provide --archive=<path> or --input-dir=<path>.'
				);
			}

			// Validate notices are non-empty and bounded in size.
			if ( ! is_file( $license_path ) || filesize( $license_path ) <= 0 || filesize( $license_path ) > self::MAX_NOTICE_BYTES ) {
				throw new RuntimeException( 'Upstream LICENSE.txt is missing, empty, or exceeds maximum allowed size (1 MiB).' );
			}
			if ( ! is_file( $copyright_path ) || filesize( $copyright_path ) <= 0 || filesize( $copyright_path ) > self::MAX_NOTICE_BYTES ) {
				throw new RuntimeException( 'Upstream COPYRIGHT.txt is missing, empty, or exceeds maximum allowed size (1 MiB).' );
			}

			// Validate MMDB metadata and structure.
			$mmdb_info = self::validate_mmdb( $mmdb_path, 'City' );

			// Compress raw MMDB to gzip.
			$staging_gz_path = $temp_working_dir . '/' . self::ARCHIVE_FILENAME;
			self::compress_to_gzip( $mmdb_path, $staging_gz_path );

			// Verify round-trip decompression integrity.
			self::verify_decompression_integrity( $staging_gz_path, $mmdb_path, $mmdb_info['buildEpoch'] );

			// Generate and validate manifest.
			$manifest_info = self::build_and_validate_manifest(
				$staging_gz_path,
				$mmdb_info['buildEpoch'],
				$download_url
			);

			$final_gz_path        = $output_dir . '/' . self::ARCHIVE_FILENAME;
			$final_manifest_path  = $output_dir . '/' . self::MANIFEST_FILENAME;
			$final_license_path   = $output_dir . '/' . self::LICENSE_FILENAME;
			$final_copyright_path = $output_dir . '/' . self::COPYRIGHT_FILENAME;
			$final_origin_path    = $output_dir . '/' . self::ORIGIN_MARKER_FILENAME;

			if ( ! $dry_run ) {
				if ( ! is_dir( $output_dir ) ) {
					if ( ! mkdir( $output_dir, 0755, true ) && ! is_dir( $output_dir ) ) {
						throw new RuntimeException( "Failed to create output directory: {$output_dir}" );
					}
				}

				if ( ! copy( $staging_gz_path, $final_gz_path ) ) {
					throw new RuntimeException( "Failed to copy archive to final path: {$final_gz_path}" );
				}
				if ( false === file_put_contents( $final_manifest_path, $manifest_info['json'] ) ) {
					throw new RuntimeException( "Failed to write manifest to final path: {$final_manifest_path}" );
				}
				if ( ! copy( $license_path, $final_license_path ) ) {
					throw new RuntimeException( "Failed to copy license to final path: {$final_license_path}" );
				}
				if ( ! copy( $copyright_path, $final_copyright_path ) ) {
					throw new RuntimeException( "Failed to copy copyright notice to final path: {$final_copyright_path}" );
				}

				$origin_payload = json_encode(
					array(
						'origin'         => $origin,
						'createdAt'      => gmdate( 'c' ),
						'buildEpoch'     => $manifest_info['manifest']['buildEpoch'],
						'checksumSha256' => $manifest_info['manifest']['checksumSha256'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);
				if ( false === $origin_payload || false === file_put_contents( $final_origin_path, $origin_payload . "\n" ) ) {
					throw new RuntimeException( "Failed to write origin marker to final path: {$final_origin_path}" );
				}
			}

			return array(
				'success'          => true,
				'dryRun'           => $dry_run,
				'outputDir'        => $output_dir,
				'archivePath'      => $final_gz_path,
				'manifestPath'     => $final_manifest_path,
				'licensePath'      => $final_license_path,
				'copyrightPath'    => $final_copyright_path,
				'originMarkerPath' => $final_origin_path,
				'origin'           => $origin,
				'edition'          => $manifest_info['manifest']['edition'],
				'buildEpoch'       => $manifest_info['manifest']['buildEpoch'],
				'checksumSha256'   => $manifest_info['manifest']['checksumSha256'],
				'sizeBytes'        => $manifest_info['manifest']['sizeBytes'],
				'uncompressedSize' => $mmdb_info['uncompressedSize'],
				'manifestJson'     => $manifest_info['json'],
			);
		} finally {
			if ( is_dir( $temp_working_dir ) ) {
				File::delete( $temp_working_dir, true );
			}
		}
	}

	/**
	 * Run release preparation from CLI arguments.
	 *
	 * @param array<int, string> $argv Command line arguments.
	 * @return int Exit code (0 for success, 1 for failure).
	 */
	public static function run_from_cli( array $argv ): int {
		$options = array(
			'synthetic'    => false,
			'dry_run'      => false,
			'archive'      => null,
			'input_dir'    => null,
			'output_dir'   => null,
			'epoch'        => null,
			'download_url' => Constants::DEFAULT_GEOIP_ARCHIVE_URL,
			'json'         => false,
		);

		for ( $i = 1; $i < count( $argv ); $i++ ) {
			$arg = $argv[ $i ];

			if ( '--help' === $arg || '-h' === $arg ) {
				self::print_usage();
				return 0;
			} elseif ( '--dry-run' === $arg ) {
				$options['dry_run'] = true;
			} elseif ( '--json' === $arg ) {
				$options['json'] = true;
			} elseif ( str_starts_with( $arg, '--archive=' ) ) {
				$options['archive'] = substr( $arg, strlen( '--archive=' ) );
			} elseif ( str_starts_with( $arg, '--input-dir=' ) ) {
				$options['input_dir'] = substr( $arg, strlen( '--input-dir=' ) );
			} elseif ( str_starts_with( $arg, '--output-dir=' ) ) {
				$options['output_dir'] = substr( $arg, strlen( '--output-dir=' ) );
			} elseif ( str_starts_with( $arg, '--epoch=' ) ) {
				$options['epoch'] = (int) substr( $arg, strlen( '--epoch=' ) );
			} elseif ( str_starts_with( $arg, '--download-url=' ) ) {
				$options['download_url'] = substr( $arg, strlen( '--download-url=' ) );
			} else {
				fwrite( STDERR, "Unknown option: {$arg}\n" );
				self::print_usage();
				return 1;
			}
		}

		try {
			$result = self::prepare( $options );

			if ( $options['json'] ) {
				echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
			} else {
				printf( "GeoIP Release Preparation Complete\n" );
				printf( "-----------------------------------\n" );
				printf( "Status:           %s\n", $result['dryRun'] ? 'DRY RUN (No files written)' : 'SUCCESS' );
				printf( "Edition:          %s\n", $result['edition'] );
				printf( "Build Epoch:      %d (%s)\n", $result['buildEpoch'], gmdate( 'Y-m-d H:i:s T', $result['buildEpoch'] ) );
				printf( "Uncompressed:     %d bytes (%.2f MiB)\n", $result['uncompressedSize'], $result['uncompressedSize'] / 1048576 );
				printf( "Compressed:       %d bytes (%.2f MiB)\n", $result['sizeBytes'], $result['sizeBytes'] / 1048576 );
				printf( "SHA-256 Checksum: %s\n", $result['checksumSha256'] );
				printf( "Output Directory: %s\n", $result['outputDir'] );
				printf( "Files:\n" );
				printf( "  - Archive:   %s\n", basename( $result['archivePath'] ) );
				printf( "  - Manifest:  %s\n", basename( $result['manifestPath'] ) );
				printf( "  - License:   %s\n", basename( $result['licensePath'] ) );
				printf( "  - Copyright: %s\n", basename( $result['copyrightPath'] ) );
			}

			return 0;
		} catch ( Throwable $exception ) {
			fwrite( STDERR, "GeoIP Release Preparation Error: {$exception->getMessage()}\n" );
			return 1;
		}
	}

	/**
	 * Print usage instructions to standard output.
	 *
	 * @return void
	 */
	public static function print_usage(): void {
		echo "Usage: php scripts/geoip/prepare-geoip.php [options]\n\n";
		echo "Options:\n";
		echo "  --archive=<path>       Path to upstream GeoLite2 City tar.gz archive\n";
		echo "  --input-dir=<path>     Path to directory containing uncompressed MMDB and notices\n";
		echo "  --output-dir=<path>    Target directory for prepared release artifacts\n";
		echo "  --epoch=<int>          Custom build epoch timestamp override\n";
		echo "  --download-url=<url>   Custom download URL override (default: canonical R2)\n";
		echo "  --dry-run              Validate inputs without writing output files\n";
		echo "  --json                 Output result summary as JSON\n";
		echo "  --help, -h             Display this help message\n";
	}
}

// Invoke CLI runner if script executed directly.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	exit( GeoipPreparer::run_from_cli( $argv ) );
}
