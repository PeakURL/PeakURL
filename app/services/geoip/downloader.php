<?php
/**
 * GeoIP download orchestration helpers.
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
 * Downloader — refresh the local GeoLite2 City database.
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
	 * Create a new downloader helper.
	 *
	 * @param Context     $context     Shared GeoIP context.
	 * @param Credentials $credentials Credentials helper.
	 * @param Client      $client      Download client helper.
	 * @param Filesystem  $filesystem  Filesystem helper.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Credentials $credentials,
		Client $client,
		Filesystem $filesystem
	) {
		$this->context     = $context;
		$this->credentials = $credentials;
		$this->client      = $client;
		$this->filesystem  = $filesystem;
	}

	/**
	 * Download or refresh the GeoLite2 City database.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When credentials are missing or the download fails.
	 * @since 1.0.14
	 */
	public function download_database(): void {
		if ( ! $this->credentials->is_configured() ) {
			throw new \RuntimeException(
				__( 'MaxMind credentials are required before PeakURL can download the GeoLite2 City database.', 'peakurl' ),
			);
		}

		if ( ! class_exists( '\PharData' ) ) {
			throw new \RuntimeException(
				__( 'The Phar extension is required to unpack the GeoLite2 database archive.', 'peakurl' ),
			);
		}

		$database_directory = dirname( $this->context->get_db_path() );
		$working_directory  = $database_directory . '/.tmp-' . bin2hex( random_bytes( 4 ) );
		$archive_path       = $working_directory . '/GeoLite2-City.tar.gz';
		$extract_path       = $working_directory . '/extract';

		try {
			$this->filesystem->create_directory( $this->context->get_content_dir() );
			$this->filesystem->create_directory( $database_directory );
			$this->filesystem->create_directory( $working_directory );
			$this->filesystem->create_directory( $extract_path );

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
				$this->context->get_db_path(),
			);
			$this->context->reset_reader();
		} finally {
			$this->filesystem->remove_path( $working_directory );
		}
	}
}
