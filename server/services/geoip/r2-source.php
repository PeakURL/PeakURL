<?php
/**
 * R2 GeoIP distribution source handler.
 *
 * Fetches and validates release manifests, verifies SHA-256 integrity, streams
 * compressed archives directly to disk, performs chunked streaming gzip
 * decompression, validates candidate database integrity, and atomically activates
 * updates under cross-process lock protection.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;
use PeakURL\Core\Config\Constants;
use PeakURL\Http\UserAgent;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * R2Source — download, verify, and activate R2 GeoLite2 City databases.
 *
 * @since 1.7.2
 */
class R2Source {

	/**
	 * Maximum allowed manifest JSON response size in bytes (64 KB).
	 *
	 * @var int
	 */
	public const MAX_MANIFEST_BYTES = 65536;

	/**
	 * Maximum allowed compressed archive size in bytes (100 MB).
	 *
	 * @var int
	 */
	public const MAX_ARCHIVE_BYTES = 104857600;

	/**
	 * Maximum allowed decompressed database size in bytes (200 MB).
	 *
	 * @var int
	 */
	public const MAX_DECOMPRESSED_BYTES = 209715200;

	/**
	 * Decompression chunk size in bytes (64 KB).
	 *
	 * @var int
	 */
	public const DECOMPRESS_CHUNK_SIZE = 65536;

	/**
	 * Maximum pair retries for mutable-object CDN race recovery.
	 *
	 * @var int
	 */
	public const MAX_PAIR_RETRIES = 2;

	/**
	 * Retry backoff in microseconds (500ms).
	 *
	 * @var int
	 */
	public const RETRY_BACKOFF_MICROSECONDS = 500000;

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Filesystem helper.
	 *
	 * @var Filesystem
	 */
	private Filesystem $filesystem;

	/**
	 * Shared HTTP transport.
	 *
	 * @var Transport
	 */
	private Transport $transport;

	/**
	 * Create a new R2Source instance.
	 *
	 * @param Context        $context    Shared GeoIP context.
	 * @param Filesystem     $filesystem Filesystem helper.
	 * @param Transport|null $transport  Optional HTTP transport dependency.
	 */
	public function __construct(
		Context $context,
		Filesystem $filesystem,
		?Transport $transport = null
	) {
		$this->context    = $context;
		$this->filesystem = $filesystem;
		$this->transport  = $transport ?? new Transport( $context );
	}

	/**
	 * Set a loopback test origin for unit test fixtures.
	 *
	 * @param string|null $origin Loopback base URL or null to clear.
	 * @return void
	 */
	public function set_test_origin( ?string $origin ): void {
		$this->transport->set_test_origin( $origin );
	}

	/**
	 * Return the installed database build epoch from MMDB metadata, or null if missing/unreadable.
	 *
	 * @return int|null Build epoch timestamp in seconds, or null.
	 */
	public function get_installed_build_epoch(): ?int {
		$database_path = $this->context->get_db_path();

		if ( ! is_file( $database_path ) || ! is_readable( $database_path ) ) {
			return null;
		}

		if ( ! class_exists( Reader::class ) ) {
			return null;
		}

		try {
			$reader   = new Reader( $database_path );
			$metadata = $reader->metadata();
			$reader->close();

			if ( ! is_object( $metadata ) ) {
				return null;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$build_epoch = (int) ( $metadata->buildEpoch ?? 0 );
			return $build_epoch > 0 ? $build_epoch : null;
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Fetch and strictly validate the GeoIP release manifest.
	 *
	 * @param string|null $url Optional manifest URL override (for tests).
	 * @return array{schemaVersion: int, edition: string, buildEpoch: int, downloadUrl: string, checksumSha256: string, sizeBytes: int} Validated manifest dictionary.
	 *
	 * @throws \RuntimeException On network failure, HTTP error, or schema validation failure.
	 */
	public function fetch_and_validate_manifest( ?string $url = null ): array {
		$manifest_url = ( null !== $url && '' !== trim( $url ) )
			? trim( $url )
			: Constants::DEFAULT_GEOIP_MANIFEST_URL;

		$validated_url = $this->validate_manifest_url( $manifest_url );

		$headers = array(
			'Accept: application/json',
			'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
		);

		$raw_json = $this->transport->fetch_string(
			$validated_url,
			array(
				'max_bytes'       => self::MAX_MANIFEST_BYTES,
				'timeout'         => 15,
				'connect_timeout' => 10,
				'headers'         => $headers,
				'validate_url'    => array( $this, 'validate_manifest_url' ),
			)
		);

		return $this->validate_manifest_payload( $raw_json );
	}

	/**
	 * Validate untrusted manifest JSON string against locked schema 1 specifications.
	 *
	 * @param string $raw_json Raw response payload.
	 * @return array{schemaVersion: int, edition: string, buildEpoch: int, downloadUrl: string, checksumSha256: string, sizeBytes: int}
	 *
	 * @throws \RuntimeException When JSON is malformed or violates schema contracts.
	 */
	/**
	 * Validate untrusted manifest JSON string against locked schema 1 specifications.
	 *
	 * @param string $raw_json Raw response payload.
	 * @return array{schemaVersion: int, edition: string, buildEpoch: int, downloadUrl: string, checksumSha256: string, sizeBytes: int}
	 *
	 * @throws \RuntimeException When JSON is malformed or violates schema contracts.
	 */
	public function validate_manifest_payload( string $raw_json ): array {
		return self::validate_manifest_schema( $raw_json, $this->transport->get_test_origin() );
	}

	/**
	 * Strictly validate raw manifest JSON string against locked schema 1 specifications.
	 *
	 * Static helper to permit decoupled contract validation without requiring
	 * instantiated Context/Transport dependencies.
	 *
	 * @param string      $raw_json    Raw manifest JSON string.
	 * @param string|null $test_origin Optional loopback test origin seam.
	 * @return array{schemaVersion: int, edition: string, buildEpoch: int, downloadUrl: string, checksumSha256: string, sizeBytes: int}
	 *
	 * @throws \RuntimeException When JSON is malformed or violates schema contracts.
	 */
	public static function validate_manifest_schema( string $raw_json, ?string $test_origin = null ): array {
		if ( '' === trim( $raw_json ) ) {
			throw new \RuntimeException( __( 'Received empty GeoIP release manifest.', 'peakurl' ) );
		}

		try {
			$data = json_decode( $raw_json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException(
				__( 'GeoIP release manifest contains invalid JSON: ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception
			);
		}

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( __( 'GeoIP release manifest must be a JSON object.', 'peakurl' ) );
		}

		// 1. Schema version must be integer 1.
		if ( ! isset( $data['schemaVersion'] ) || 1 !== $data['schemaVersion'] ) {
			throw new \RuntimeException( __( 'Unsupported GeoIP manifest schema version.', 'peakurl' ) );
		}

		// 2. Edition must match GeoLite2-City exactly.
		if ( ! isset( $data['edition'] ) || 'GeoLite2-City' !== $data['edition'] ) {
			throw new \RuntimeException( __( 'Unexpected GeoIP database edition in manifest.', 'peakurl' ) );
		}

		// 3. buildEpoch must be a positive integer.
		if ( ! isset( $data['buildEpoch'] ) || ! is_int( $data['buildEpoch'] ) || $data['buildEpoch'] <= 0 ) {
			throw new \RuntimeException( __( 'Invalid or non-positive build epoch in manifest.', 'peakurl' ) );
		}

		// 4. sizeBytes must be a positive integer within bounds.
		if (
			! isset( $data['sizeBytes'] ) ||
			! is_int( $data['sizeBytes'] ) ||
			$data['sizeBytes'] <= 0 ||
			$data['sizeBytes'] > self::MAX_ARCHIVE_BYTES
		) {
			throw new \RuntimeException( __( 'Invalid or out-of-bounds archive size in manifest.', 'peakurl' ) );
		}

		// 5. checksumSha256 must be a 64-character lowercase hex string.
		if (
			! isset( $data['checksumSha256'] ) ||
			! is_string( $data['checksumSha256'] ) ||
			1 !== preg_match( '/^[0-9a-f]{64}$/', $data['checksumSha256'] )
		) {
			throw new \RuntimeException( __( 'Invalid SHA-256 checksum format in manifest.', 'peakurl' ) );
		}

		// 6. downloadUrl must match exact fixed canonical archive URL or test seam.
		if ( ! isset( $data['downloadUrl'] ) || ! is_string( $data['downloadUrl'] ) ) {
			throw new \RuntimeException( __( 'Missing download URL in manifest.', 'peakurl' ) );
		}

		self::validate_endpoint_url_static( $data['downloadUrl'], 'GeoLite2-City.mmdb.gz', $test_origin );

		return array(
			'schemaVersion'  => 1,
			'edition'        => 'GeoLite2-City',
			'buildEpoch'     => $data['buildEpoch'],
			'downloadUrl'    => $data['downloadUrl'],
			'checksumSha256' => $data['checksumSha256'],
			'sizeBytes'      => $data['sizeBytes'],
		);
	}

	/**
	 * Validate a manifest URL against origin and protocol security rules.
	 *
	 * @param string $url Manifest URL.
	 * @return string Validated URL.
	 *
	 * @throws \RuntimeException On security policy failure.
	 */
	public function validate_manifest_url( string $url ): string {
		return $this->validate_endpoint_url( $url, 'manifest.json' );
	}

	/**
	 * Validate an archive URL against origin and protocol security rules.
	 *
	 * @param string $url Archive URL.
	 * @return string Validated URL.
	 *
	 * @throws \RuntimeException On security policy failure.
	 */
	public function validate_archive_url( string $url ): string {
		return $this->validate_endpoint_url( $url, 'GeoLite2-City.mmdb.gz' );
	}

	/**
	 * Validate an endpoint URL targeting releases.peakurl.org or loopback test origin.
	 *
	 * @param string $url           Endpoint URL.
	 * @param string $expected_file Expected filename ('manifest.json' or 'GeoLite2-City.mmdb.gz').
	 * @return string Validated URL.
	 *
	 * @throws \RuntimeException On invalid protocol, port, origin, userinfo, or path.
	 */
	private function validate_endpoint_url( string $url, string $expected_file ): string {
		return self::validate_endpoint_url_static( $url, $expected_file, $this->transport->get_test_origin() );
	}

	/**
	 * Static endpoint URL validator targeting releases.peakurl.org or loopback test origin.
	 *
	 * @param string      $url           Endpoint URL.
	 * @param string      $expected_file Expected filename ('manifest.json' or 'GeoLite2-City.mmdb.gz').
	 * @param string|null $test_origin   Optional test origin seam.
	 * @return string Validated URL.
	 *
	 * @throws \RuntimeException On invalid protocol, port, origin, userinfo, or path.
	 */
	public static function validate_endpoint_url_static( string $url, string $expected_file, ?string $test_origin = null ): string {
		$parts = parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new \RuntimeException( __( 'Invalid URL encountered during GeoIP check.', 'peakurl' ) );
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			throw new \RuntimeException( __( 'URLs containing credentials are forbidden.', 'peakurl' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path   = (string) ( $parts['path'] ?? '' );

		if ( null !== $test_origin ) {
			$test_parts = parse_url( $test_origin );
			$test_host  = strtolower( (string) ( $test_parts['host'] ?? '' ) );
			$test_port  = isset( $test_parts['port'] ) ? (int) $test_parts['port'] : null;

			if ( $host === $test_host && $port === $test_port ) {
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					throw new \RuntimeException( __( 'GeoIP test downloads require HTTP or HTTPS.', 'peakurl' ) );
				}
				if ( ! str_ends_with( $path, '/' . $expected_file ) ) {
					throw new \RuntimeException( __( 'GeoIP URL path mismatch.', 'peakurl' ) );
				}
				return $url;
			}
		}

		if ( 'https' !== $scheme ) {
			throw new \RuntimeException( __( 'GeoIP requests require an encrypted HTTPS connection.', 'peakurl' ) );
		}

		if ( null !== $port && 443 !== $port ) {
			throw new \RuntimeException( __( 'GeoIP requests require standard HTTPS port 443.', 'peakurl' ) );
		}

		if ( 'releases.peakurl.org' !== $host ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: unauthorized host */
					__( 'Untrusted download origin rejected: %s.', 'peakurl' ),
					$host
				)
			);
		}

		if ( '/geoip/' . $expected_file !== $path ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: unexpected path */
					__( 'Unexpected endpoint path: %s.', 'peakurl' ),
					$path
				)
			);
		}

		return $url;
	}

	/**
	 * Synchronize the local database with the R2 release.
	 *
	 * Performs manifest freshness check, handles mutable-object CDN race recovery
	 * with bounded retries, streaming decompression, validation, and atomic activation.
	 *
	 * @param resource    $lock_handle  Active cross-process update lock.
	 * @param bool        $force        True to download even if on-disk build epoch matches manifest.
	 * @param string|null $manifest_url Optional manifest URL override (for tests).
	 * @param string|null $archive_url  Optional archive URL override (for tests).
	 * @return array<string, mixed> Outcome summary dictionary.
	 *
	 * @throws \RuntimeException When update fails terminally after retries.
	 */
	public function sync_database(
		$lock_handle,
		bool $force = false,
		?string $manifest_url = null,
		?string $archive_url = null
	): array {
		$target_db_path = $this->context->get_db_path();
		$attempt        = 0;
		$last_exception = null;

		while ( $attempt <= self::MAX_PAIR_RETRIES ) {
			try {
				// 1. Fetch and validate manifest.
				$manifest = $this->fetch_and_validate_manifest( $manifest_url );

				// 2. Check local database build epoch against manifest.
				$installed_epoch = $this->get_installed_build_epoch();

				if ( ! $force && null !== $installed_epoch && $installed_epoch >= $manifest['buildEpoch'] ) {
					// Local DB is already up to date. Record successful manifest check without overwriting lastDownloadedAt.
					$now = Date::now();
					try {
						$this->context->get_settings_api()->update_option(
							'geoip_last_checked_at',
							$now,
							$now,
							false
						);
					} catch ( \Throwable $e ) {
						// Non-fatal if timestamp persistence fails.
						error_log( 'PeakURL GeoIP: Failed to persist geoip_last_checked_at setting: ' . $e->getMessage() );
					}

					return array(
						'activated'     => false,
						'source'        => 'r2',
						'status'        => 'current',
						'buildEpoch'    => $installed_epoch,
						'manifestEpoch' => $manifest['buildEpoch'],
						'databasePath'  => $target_db_path,
					);
				}

				// 3. Download, verify, decompress, and activate candidate database.
				$effective_archive_url = ( null !== $archive_url && '' !== trim( $archive_url ) )
					? trim( $archive_url )
					: $manifest['downloadUrl'];

				$this->download_and_activate( $manifest, $lock_handle, $effective_archive_url );

				$now = Date::now();
				try {
					$this->context->get_settings_api()->update_option(
						'geoip_last_checked_at',
						$now,
						$now,
						false
					);
				} catch ( \Throwable $e ) {
					// Non-fatal if timestamp persistence fails after successful activation.
					error_log( 'PeakURL GeoIP: Failed to persist geoip_last_checked_at setting: ' . $e->getMessage() );
				}

				return array(
					'activated'    => true,
					'source'       => 'r2',
					'status'       => 'installed',
					'buildEpoch'   => $manifest['buildEpoch'],
					'sizeBytes'    => $manifest['sizeBytes'],
					'databasePath' => $target_db_path,
				);
			} catch ( \Throwable $exception ) {
				$last_exception = $exception;
				++$attempt;

				if ( $attempt <= self::MAX_PAIR_RETRIES ) {
					usleep( self::RETRY_BACKOFF_MICROSECONDS );
					continue;
				}

				break;
			}
		}

		throw new \RuntimeException(
			sprintf(
				/* translators: %s: underlying error */
				__( 'GeoIP update failed: %s', 'peakurl' ),
				null !== $last_exception ? $last_exception->getMessage() : 'unknown error'
			),
			0,
			$last_exception
		);
	}

	/**
	 * Download compressed archive, verify checksum and size, stream-decompress, and activate.
	 *
	 * @param array<string, mixed> $manifest       Validated manifest dictionary.
	 * @param resource             $lock_handle     Active cross-process update lock.
	 * @param string               $archive_url     Validated archive URL.
	 * @return void
	 *
	 * @throws \RuntimeException On verification failure or decompress failure.
	 */
	public function download_and_activate(
		array $manifest,
		$lock_handle,
		string $archive_url
	): void {
		$target_db_path = $this->context->get_db_path();
		$target_dir     = dirname( $target_db_path );
		$this->filesystem->mkdir_p( $target_dir );

		$working_dir = $target_dir . '/.tmp-r2-' . bin2hex( random_bytes( 4 ) );
		$this->filesystem->mkdir_p( $working_dir );

		$gz_file   = $working_dir . '/GeoLite2-City.mmdb.gz';
		$mmdb_file = $working_dir . '/GeoLite2-City.mmdb';

		try {
			// 1. Stream archive to disk.
			$expected_size = (int) $manifest['sizeBytes'];
			$headers       = array(
				'Accept: application/gzip, application/octet-stream',
				'User-Agent: ' . UserAgent::format( $this->context->get_version() ),
			);

			$written = $this->transport->download_to_file(
				$archive_url,
				$gz_file,
				array(
					'max_bytes'       => $expected_size,
					'timeout'         => 90,
					'connect_timeout' => 15,
					'headers'         => $headers,
					'validate_url'    => array( $this, 'validate_archive_url' ),
				)
			);

			// 2. Exact byte count verification.
			if ( $written !== $expected_size || filesize( $gz_file ) !== $expected_size ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: received bytes, 2: expected bytes */
						__( 'Archive size mismatch: received %1$d bytes of expected %2$d bytes.', 'peakurl' ),
						$written,
						$expected_size
					)
				);
			}

			// 3. SHA-256 digest verification on compressed bytes.
			$actual_sha256 = hash_file( 'sha256', $gz_file );
			if ( strtolower( (string) $actual_sha256 ) !== strtolower( (string) $manifest['checksumSha256'] ) ) {
				throw new \RuntimeException(
					__( 'Archive SHA-256 integrity check failed. Downloaded archive does not match manifest.', 'peakurl' )
				);
			}

			// 4. Bounded streaming gzip decompression chunk by chunk to staging MMDB file.
			$this->decompress_archive_streaming( $gz_file, $mmdb_file );

			// 5. Verify decompressed candidate with Reader: valid City database & exact buildEpoch match.
			if ( ! $this->filesystem->validate_database_file( $mmdb_file ) ) {
				throw new \RuntimeException(
					__( 'Decompressed GeoLite2 database failed structural verification.', 'peakurl' )
				);
			}

			$reader   = new Reader( $mmdb_file );
			$metadata = $reader->metadata();
			$reader->close();

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$actual_epoch = (int) ( $metadata->buildEpoch ?? 0 );
			if ( $actual_epoch !== (int) $manifest['buildEpoch'] ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: actual build epoch, 2: expected build epoch */
						__( 'Database build epoch mismatch: archive contains build %1$d, but manifest announced %2$d.', 'peakurl' ),
						$actual_epoch,
						(int) $manifest['buildEpoch']
					)
				);
			}

			// 6. Rollback-safe atomic activation using caller's lock.
			$this->filesystem->replace_database_file( $mmdb_file, $target_db_path, $lock_handle );
			$this->context->reset_reader();
		} finally {
			$this->filesystem->delete( $working_dir );
		}
	}

	/**
	 * Decompress a gzip archive directly to disk chunk by chunk with bounded memory and size limit.
	 *
	 * @param string $source_gz Path to compressed .gz file.
	 * @param string $dest_file Path to write decompressed database.
	 * @return void
	 *
	 * @throws \RuntimeException On corrupted stream, write error, or size limit breach.
	 */
	private function decompress_archive_streaming( string $source_gz, string $dest_file ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing gzip stream.
		$gz = @gzopen( $source_gz, 'rb' );
		if ( false === $gz ) {
			throw new \RuntimeException( __( 'Could not open GeoLite2 gzip archive for decompression.', 'peakurl' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Initializing staging file.
		$dest_fp = @fopen( $dest_file, 'wb' );
		if ( false === $dest_fp ) {
			gzclose( $gz );
			throw new \RuntimeException( __( 'Could not initialize staging file for GeoLite2 decompression.', 'peakurl' ) );
		}

		$bytes_written = 0;

		try {
			while ( ! gzeof( $gz ) ) {
				$chunk = gzread( $gz, self::DECOMPRESS_CHUNK_SIZE );

				if ( false === $chunk ) {
					throw new \RuntimeException( __( 'Error reading GeoLite2 compressed stream.', 'peakurl' ) );
				}

				if ( '' === $chunk ) {
					break;
				}

				$chunk_len = strlen( $chunk );
				if ( ( $bytes_written + $chunk_len ) > self::MAX_DECOMPRESSED_BYTES ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %d: maximum megabytes */
							__( 'Decompressed GeoLite2 database exceeded maximum size limit (%d MB).', 'peakurl' ),
							(int) ( self::MAX_DECOMPRESSED_BYTES / ( 1024 * 1024 ) )
						)
					);
				}

				if ( ! $this->transport->write_chunk( $dest_fp, $chunk ) ) {
					throw new \RuntimeException( __( 'Could not write decompressed GeoLite2 chunk to disk.', 'peakurl' ) );
				}

				$bytes_written += $chunk_len;
			}
		} finally {
			gzclose( $gz );
			fclose( $dest_fp );
		}

		if ( $bytes_written < Filesystem::MIN_DATABASE_BYTES ) {
			throw new \RuntimeException( __( 'Decompressed GeoLite2 database is too small or invalid.', 'peakurl' ) );
		}
	}
}
