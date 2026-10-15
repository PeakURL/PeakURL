<?php
/**
 * GeoIP download orchestration helpers.
 *
 * @package PeakURL\Services\Geoip
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Geoip;

use MaxMind\Db\Reader;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Downloader — refresh the local GeoLite2 City database.
 *
 * Orchestrates source selection between direct MaxMind credentials and
 * automated R2 downloads, holding a single cross-process lock across
 * freshness checks, streaming download, and atomic activation.
 *
 * @since 1.0.14
 */
class Downloader {

	/**
	 * Shared GeoIP context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Credentials helper.
	 *
	 * @var Credentials
	 * @since 1.0.14
	 */
	private Credentials $credentials;

	/**
	 * Download client helper.
	 *
	 * @var Client
	 * @since 1.0.14
	 */
	private Client $client;

	/**
	 * Filesystem helper.
	 *
	 * @var Filesystem
	 * @since 1.0.14
	 */
	private Filesystem $filesystem;

	/**
	 * R2 source helper.
	 *
	 * @var R2Source
	 * @since 1.7.2
	 */
	private R2Source $r2_source;

	/**
	 * Create a new downloader helper.
	 *
	 * @param Context       $context     Shared GeoIP context.
	 * @param Credentials   $credentials Credentials helper.
	 * @param Client        $client      Download client helper.
	 * @param Filesystem    $filesystem  Filesystem helper.
	 * @param R2Source|null $r2_source   Optional R2 source dependency.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Credentials $credentials,
		Client $client,
		Filesystem $filesystem,
		?R2Source $r2_source = null
	) {
		$this->context     = $context;
		$this->credentials = $credentials;
		$this->client      = $client;
		$this->filesystem  = $filesystem;
		$this->r2_source   = $r2_source ?? new R2Source( $context, $filesystem );
	}

	/**
	 * Download or refresh the GeoLite2 City database.
	 *
	 * Selects direct MaxMind when credentials are configured; selects R2
	 * when credentials are absent. Holds a single cross-process lock across the
	 * entire operation.
	 *
	 * @param bool $force True to force download even if local database is current.
	 * @return array<string, mixed> Download outcome summary.
	 *
	 * @throws \RuntimeException When the download fails.
	 * @since 1.0.14
	 */
	public function download_database( bool $force = false ): array {
		if ( $this->credentials->is_configured() ) {
			return $this->download_from_maxmind();
		}

		return $this->download_from_r2( $force );
	}

	/**
	 * Execute authenticated direct MaxMind download and atomic activation.
	 *
	 * @return array<string, mixed> Outcome summary.
	 *
	 * @throws \RuntimeException On Phar missing, network failure, or corrupt archive.
	 * @since 1.7.2
	 */
	private function download_from_maxmind(): array {
		if ( ! class_exists( '\PharData' ) ) {
			throw new \RuntimeException(
				__( 'The Phar extension is required to unpack the GeoLite2 database archive. Enable the PHP Phar extension on the server, restart PHP, and try again. See https://www.php.net/manual/en/phar.setup.php for setup guidance.', 'peakurl' ),
			);
		}

		$target_path = $this->context->get_db_path();
		$lock_handle = $this->filesystem->acquire_lock( $target_path );

		$database_directory = dirname( $target_path );
		$working_directory  = $database_directory . '/.tmp-' . bin2hex( random_bytes( 4 ) );
		$archive_path       = $working_directory . '/GeoLite2-City.tar.gz';
		$extract_path       = $working_directory . '/extract';

		try {
			$this->filesystem->mkdir_p( $this->context->get_content_dir() );
			$this->filesystem->mkdir_p( $database_directory );
			$this->filesystem->mkdir_p( $working_directory );
			$this->filesystem->mkdir_p( $extract_path );

			$this->client->download_archive( $archive_path );
			$this->filesystem->extract_archive( $archive_path, $extract_path );

			$source_path = $this->filesystem->find_database_file( $extract_path );

			if ( null === $source_path ) {
				throw new \RuntimeException(
					__( 'PeakURL downloaded the GeoLite2 archive, but it did not contain GeoLite2-City.mmdb.', 'peakurl' ),
				);
			}

			$this->filesystem->replace_database_file(
				$source_path,
				$target_path,
				$lock_handle
			);
			$this->context->reset_reader();

			$epoch = 0;
			try {
				$reader = new Reader( $target_path );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$epoch = (int) ( $reader->metadata()->buildEpoch ?? 0 );
				$reader->close();
			} catch ( \Throwable $e ) {
				// Non-fatal if metadata reader fails after activation.
			}

			return array(
				'activated'    => true,
				'status'       => 'installed',
				'databasePath' => $target_path,
				'buildEpoch'   => $epoch,
			);
		} finally {
			$this->filesystem->delete( $working_directory );
			$this->filesystem->release_lock( $lock_handle );
		}
	}

	/**
	 * Execute R2 release check, streaming download, and atomic activation.
	 *
	 * @param bool $force True to force download even if local database is current.
	 * @return array<string, mixed> Outcome summary.
	 *
	 * @throws \RuntimeException On network failure, hash mismatch, or corruption.
	 * @since 1.7.2
	 */
	private function download_from_r2( bool $force ): array {
		$target_path = $this->context->get_db_path();
		$lock_handle = $this->filesystem->acquire_lock( $target_path );

		try {
			return $this->r2_source->sync_database( $lock_handle, $force );
		} finally {
			$this->filesystem->release_lock( $lock_handle );
		}
	}
}
