<?php
/**
 * MaxMind GeoLite2 location services.
 *
 * Resolves click locations from a local MaxMind database and manages
 * the optional GeoLite2 City download flow for self-hosted installs.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use MaxMind\Db\Reader;
use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * GeoIP service for local MaxMind lookups and updates.
 *
 * @since 1.0.0
 */
class Geoip {

	/**
	 * MaxMind direct-download permalink for GeoLite2 City binary data.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const DOWNLOAD_URL =
		'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

	/**
	 * Absolute path to the persistent content directory.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $content_dir;

	/**
	 * Absolute path to the configured MaxMind DB file.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $database_path;

	/**
	 * MaxMind account ID used for database downloads.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $account_id;

	/**
	 * MaxMind license key used for database downloads.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $license_key;

	/**
	 * Current PeakURL runtime version string.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $peakurl_version;

	/**
	 * Settings API for database-backed credentials.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Crypto helper for stored credentials.
	 *
	 * @var Crypto
	 * @since 1.0.0
	 */
	private Crypto $crypto_service;

	/**
	 * Cached MaxMind reader instance.
	 *
	 * @var Reader|null
	 * @since 1.0.0
	 */
	private ?Reader $reader = null;

	/**
	 * Whether reader initialization has already been attempted.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private bool $reader_initialized = false;

	/**
	 * Create a GeoIP service instance.
	 *
	 * @param array<string, mixed> $config         Runtime configuration map.
	 * @param SettingsApi         $settings_api   Settings API helper.
	 * @param Crypto       $crypto_service Crypto helper.
	 * @since 1.0.0
	 */
	public function __construct(
		array $config,
		SettingsApi $settings_api,
		Crypto $crypto_service
	) {
		$this->content_dir     = trim(
			(string) ( $config['PEAKURL_CONTENT_DIR'] ?? ABSPATH . 'content' ),
		);
		$this->database_path   = trim(
			(string) ( $config['PEAKURL_GEOIP_DB_PATH'] ?? '' ),
		);
		$this->peakurl_version = trim(
			(string) ( $config[ Constants::CONFIG_VERSION ] ?? Constants::DEFAULT_VERSION ),
		);
		$this->settings_api    = $settings_api;
		$this->crypto_service  = $crypto_service;

		$credentials       = $this->get_runtime_credentials();
		$this->account_id  = $credentials['accountId'];
		$this->license_key = $credentials['licenseKey'];
	}

	/**
	 * Resolve country and city values for the given visitor IP address.
	 *
	 * @param string $ip_address Normalized visitor IP address.
	 * @return array<string, string|null> Country/city payload for clicks.
	 * @since 1.0.0
	 */
	public function resolve_location( string $ip_address = '' ): array {
		if ( ! $this->is_public_ip( $ip_address ) ) {
			return $this->empty_location();
		}

		$reader = $this->get_reader();

		if ( null === $reader ) {
			return $this->empty_location();
		}

		try {
			$record = $reader->get( $ip_address );
		} catch ( \Throwable $exception ) {
			return $this->empty_location();
		}

		if ( ! is_array( $record ) ) {
			return $this->empty_location();
		}

		$country = is_array( $record['country'] ?? null )
			? $record['country']
			: ( is_array( $record['registered_country'] ?? null )
				? $record['registered_country']
				: array() );
		$city    = is_array( $record['city'] ?? null )
			? $record['city']
			: array();

		return array(
			'country_code' => $this->normalize_country_code(
				(string) ( $country['iso_code'] ?? '' ),
			),
			'country_name' => $this->extract_name_field( $country ),
			'city_name'    => $this->extract_name_field( $city ),
		);
	}

	/**
	 * Return the current MaxMind integration status for the dashboard.
	 *
	 * @return array<string, mixed> GeoIP runtime status payload.
	 * @since 1.0.0
	 */
	public function get_status(): array {
		$database_exists = file_exists( $this->database_path );
		$database_ready  = $database_exists && is_readable( $this->database_path );
		$modified_at     = $database_exists ? filemtime( $this->database_path ) : false;
		$size_bytes      = $database_exists ? filesize( $this->database_path ) : false;
		$capability      = $this->get_dashboard_capability();
		$configuration   = $this->get_configuration_target();

		return array(
			'contentDir'             => $this->content_dir,
			'databasePath'           => $this->database_path,
			'databaseExists'         => $database_exists,
			'databaseReadable'       => $database_ready,
			'locationAnalyticsReady' => $database_ready,
			'accountIdConfigured'    => '' !== $this->account_id,
			'licenseKeyConfigured'   => '' !== $this->license_key,
			'credentialsConfigured'  => $this->has_credentials(),
			'accountId'              => $this->account_id,
			'licenseKeyHint'         => $this->mask_license_key(),
			'databaseUpdatedAt'      => false !== $modified_at
				? gmdate( DATE_ATOM, (int) $modified_at )
				: null,
			'databaseSizeBytes'      => false !== $size_bytes
				? (int) $size_bytes
				: 0,
			'canManageFromDashboard' => $capability['allowed'],
			'manageDisabledReason'   => $capability['reason'],
			'configurationLabel'     => $configuration['label'],
			'configurationPath'      => $configuration['path'],
			'downloadCommand'        => 'php app/bin/update-geoip.php',
			'downloadUrl'            => self::DOWNLOAD_URL,
		);
	}

	/**
	 * Persist MaxMind credentials into the settings table.
	 *
	 * @param string               $app_path  Absolute path to the app directory.
	 * @param array<string, mixed> $config    Current runtime configuration.
	 * @param array<string, mixed> $input     Submitted settings payload.
	 * @return array<string, mixed> Fresh GeoIP status after saving.
	 *
	 * @throws \RuntimeException When the credentials are invalid.
	 * @since 1.0.0
	 */
	public function save_configuration(
		string $app_path,
		array $config,
		array $input
	): array {
		$capability = $this->get_dashboard_capability();

		if ( ! $capability['allowed'] ) {
			throw new \RuntimeException( (string) $capability['reason'] );
		}

		$account_id      = trim( (string) ( $input['accountId'] ?? '' ) );
		$license_key     = trim( (string) ( $input['licenseKey'] ?? '' ) );
		$current         = $this->get_runtime_credentials();
		$current_account = $current['accountId'];
		$current_license = $current['licenseKey'];

		if ( '' !== $account_id && ! preg_match( '/^[0-9]{1,32}$/', $account_id ) ) {
			throw new \RuntimeException( __( 'MaxMind account ID must contain digits only.', 'peakurl' ) );
		}

		if ( '' !== $license_key && ! preg_match( '/^[A-Za-z0-9_]{6,255}$/', $license_key ) ) {
			throw new \RuntimeException(
				__( 'MaxMind license key must be 6-255 characters and may only use letters, numbers, and underscores.', 'peakurl' ),
			);
		}

		if (
			( '' === $account_id && '' !== $license_key ) ||
			( '' !== $account_id && '' === $license_key )
		) {
			if (
				'' !== $account_id &&
				'' === $license_key &&
				$account_id === $current_account &&
				'' !== $current_license
			) {
				$license_key = $current_license;
			} else {
				throw new \RuntimeException(
					__( 'Enter both the MaxMind account ID and license key, or leave both blank.', 'peakurl' ),
				);
			}
		}

		if ( '' !== $license_key && ! $this->crypto_service->has_auth_keys() ) {
			$this->crypto_service = new Crypto( $config );
			$this->crypto_service->ensure_persisted_auth_keys( $app_path );
		}

		$updated_at = gmdate( 'Y-m-d H:i:s' );

		$this->settings_api->update_option( 'maxmind_account_id', $account_id, $updated_at, false );
		$this->settings_api->update_option(
			'maxmind_license_key_encrypted',
			'' === $license_key ? '' : $this->crypto_service->encrypt( $license_key ),
			$updated_at,
			false,
		);

		return ( new self(
			$config,
			$this->settings_api,
			$this->crypto_service,
		) )->get_status();
	}

	/**
	 * Download or refresh the local GeoLite2 City database.
	 *
	 * @return array<string, mixed> Fresh GeoIP status after download.
	 *
	 * @throws \RuntimeException When credentials are missing or the download fails.
	 * @since 1.0.0
	 */
	public function download_database(): array {
		if ( ! $this->has_credentials() ) {
			throw new \RuntimeException(
				__( 'MaxMind credentials are required before PeakURL can download the GeoLite2 City database.', 'peakurl' ),
			);
		}

		if ( ! class_exists( '\PharData' ) ) {
			throw new \RuntimeException(
				__( 'The Phar extension is required to unpack the GeoLite2 database archive.', 'peakurl' ),
			);
		}

		$database_directory = dirname( $this->database_path );
		$working_directory  = $database_directory . '/.tmp-' . bin2hex( random_bytes( 4 ) );
		$archive_path       = $working_directory . '/GeoLite2-City.tar.gz';
		$extract_path       = $working_directory . '/extract';

		try {
			$this->ensure_directory( $this->content_dir );
			$this->ensure_directory( $database_directory );
			$this->ensure_directory( $working_directory );
			$this->ensure_directory( $extract_path );

			$this->download_archive( $archive_path );
			$this->extract_archive( $archive_path, $extract_path );

			$source_path = $this->find_database_file( $extract_path );

			if ( null === $source_path ) {
				throw new \RuntimeException(
					__( 'PeakURL downloaded the GeoLite2 archive, but it did not contain GeoLite2-City.mmdb.', 'peakurl' ),
				);
			}

			$this->replace_database_file( $source_path, $this->database_path );
			$this->reader             = null;
			$this->reader_initialized = false;
		} finally {
			$this->delete_path( $working_directory );
		}

		return $this->get_status();
	}

	/**
	 * Close the MaxMind reader when the service is destroyed.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __destruct() {
		if ( null !== $this->reader ) {
			$this->reader->close();
		}
	}

	/**
	 * Lazily instantiate the MaxMind reader.
	 *
	 * @return Reader|null Reader instance or null when unavailable.
	 * @since 1.0.0
	 */
	private function get_reader(): ?Reader {
		if ( $this->reader_initialized ) {
			return $this->reader;
		}

		$this->reader_initialized = true;

		if (
			'' === $this->database_path ||
			! file_exists( $this->database_path ) ||
			! is_readable( $this->database_path )
		) {
			return null;
		}

		try {
			$this->reader = new Reader( $this->database_path );
		} catch ( \Throwable $exception ) {
			$this->reader = null;
		}

		return $this->reader;
	}

	/**
	 * Check whether both MaxMind credentials are present.
	 *
	 * @return bool True when both account ID and license key are set.
	 * @since 1.0.0
	 */
	private function has_credentials(): bool {
		return '' !== $this->account_id && '' !== $this->license_key;
	}

	/**
	 * Determine whether dashboard GeoIP management is allowed here.
	 *
	 * @return array{allowed: bool, reason: string|null} Capability payload.
	 * @since 1.0.0
	 */
	private function get_dashboard_capability(): array {
		if ( ! $this->settings_api->has_table() ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The settings table is not available yet.', 'peakurl' ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}

	/**
	 * Return the database-backed credentials target.
	 *
	 * @return array{label: string, path: string}
	 * @since 1.0.0
	 */
	private function get_configuration_target(): array {
		return array(
			'label' => 'settings table',
			'path'  => 'settings',
		);
	}

	/**
	 * Download the remote GeoLite2 archive to a local path.
	 *
	 * @param string $archive_path Local archive file path.
	 *
	 * @throws \RuntimeException When the request fails.
	 * @since 1.0.0
	 */
	private function download_archive( string $archive_path ): void {
		if ( function_exists( 'curl_init' ) ) {
			$this->download_archive_with_curl( $archive_path );
			return;
		}

		$this->download_archive_with_streams( $archive_path );
	}

	/**
	 * Download the GeoLite2 archive through cURL.
	 *
	 * cURL handles the MaxMind redirect to the signed R2 URL correctly
	 * without forwarding the Basic auth header to the different host.
	 *
	 * @param string $archive_path Local archive file path.
	 *
	 * @throws \RuntimeException When the request fails.
	 * @return void
	 * @since 1.0.0
	 */
	private function download_archive_with_curl( string $archive_path ): void {
		$handle = curl_init( self::DOWNLOAD_URL );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'PeakURL could not initialize cURL for the GeoLite2 download.', 'peakurl' ) );
		}

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 10,
				CURLOPT_TIMEOUT        => 90,
				CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
				CURLOPT_USERPWD        => $this->account_id . ':' . $this->license_key,
				CURLOPT_HTTPHEADER     => array(
					'Accept: application/gzip, application/octet-stream',
					'User-Agent: PeakURL/' . $this->peakurl_version,
				),
			),
		);

		$body       = curl_exec( $handle );
		$status     = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$curl_error = curl_error( $handle );

		curl_close( $handle );

		if ( false === $body ) {
			throw new \RuntimeException(
				__( 'PeakURL could not download the GeoLite2 City database from MaxMind. ', 'peakurl' ) . $curl_error,
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				$this->build_download_http_error_message( $status ),
			);
		}

		if ( false === file_put_contents( $archive_path, $body, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
			);
		}
	}

	/**
	 * Download the GeoLite2 archive through the PHP stream wrapper.
	 *
	 * This fallback avoids forwarding MaxMind credentials to the signed
	 * redirect host by following redirects manually.
	 *
	 * @param string $archive_path Local archive file path.
	 *
	 * @throws \RuntimeException When the request fails.
	 * @return void
	 * @since 1.0.0
	 */
	private function download_archive_with_streams( string $archive_path ): void {
		$headers = array(
			'Accept: application/gzip, application/octet-stream',
			'Authorization: Basic ' . base64_encode(
				$this->account_id . ':' . $this->license_key,
			),
			'User-Agent: PeakURL/' . $this->peakurl_version,
		);
		$result  = $this->stream_request(
			self::DOWNLOAD_URL,
			$headers,
			false,
		);

		if ( $result['status'] >= 300 && $result['status'] < 400 ) {
			$redirect = $this->extract_location_header( $result['headers'] );

			if ( null === $redirect ) {
				throw new \RuntimeException(
					__( 'PeakURL could not follow the GeoLite2 download redirect from MaxMind.', 'peakurl' ),
				);
			}

			$result = $this->stream_request(
				$redirect,
				array(
					'Accept: application/gzip, application/octet-stream',
					'User-Agent: PeakURL/' . $this->peakurl_version,
				),
				false,
			);
		}

		if ( false === $result['body'] || $result['status'] < 200 || $result['status'] >= 300 ) {
			throw new \RuntimeException(
				$this->build_download_http_error_message( $result['status'] ),
			);
		}

		if ( false === file_put_contents( $archive_path, $result['body'], LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL downloaded the GeoLite2 archive, but could not store it locally.', 'peakurl' ),
			);
		}
	}

	/**
	 * Execute a single HTTP request through the PHP stream wrapper.
	 *
	 * @param string            $url            Remote URL.
	 * @param array<int, string> $headers       Request headers.
	 * @param bool              $follow_location Whether redirects should be followed.
	 * @return array{body: string|false, headers: array<int, string>, status: int}
	 * @since 1.0.0
	 */
	private function stream_request(
		string $url,
		array $headers,
		bool $follow_location
	): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => implode( "\r\n", $headers ) . "\r\n",
					'ignore_errors'   => true,
					'follow_location' => $follow_location ? 1 : 0,
					'max_redirects'   => $follow_location ? 10 : 0,
					'timeout'         => 90,
				),
			),
		);
		$body    = @file_get_contents( $url, false, $context );
		$headers = $http_response_header ?? array();
		$status  = $this->parse_http_status(
			$http_response_header ?? array(),
		);

		return array(
			'body'    => $body,
			'headers' => $headers,
			'status'  => $status,
		);
	}

	/**
	 * Extract the downloaded tar.gz archive into a working directory.
	 *
	 * @param string $archive_path Archive file path.
	 * @param string $extract_path Extraction directory path.
	 *
	 * @throws \RuntimeException When the archive cannot be unpacked.
	 * @since 1.0.0
	 */
	private function extract_archive( string $archive_path, string $extract_path ): void {
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
	 * Find the GeoLite2 City database file inside an extracted archive.
	 *
	 * @param string $extract_path Extraction directory path.
	 * @return string|null Absolute path to the .mmdb file, or null.
	 * @since 1.0.0
	 */
	private function find_database_file( string $extract_path ): ?string {
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
	 * Atomically replace the local database file with a new one.
	 *
	 * @param string $source_path Source .mmdb file path.
	 * @param string $target_path Final target path.
	 *
	 * @throws \RuntimeException When the replacement fails.
	 * @since 1.0.0
	 */
	private function replace_database_file(
		string $source_path,
		string $target_path
	): void {
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

	/**
	 * Parse the final HTTP status code from wrapper response headers.
	 *
	 * @param array<int, string> $headers Wrapper response headers.
	 * @return int HTTP status code, or 0 when unavailable.
	 * @since 1.0.0
	 */
	private function parse_http_status( array $headers ): int {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( preg_match( '/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return 0;
	}

	/**
	 * Extract the final redirect location from HTTP response headers.
	 *
	 * @param array<int, string> $headers Wrapper response headers.
	 * @return string|null Redirect URL or null.
	 * @since 1.0.0
	 */
	private function extract_location_header( array $headers ): ?string {
		foreach ( array_reverse( $headers ) as $header ) {
			if ( 0 !== stripos( $header, 'Location:' ) ) {
				continue;
			}

			return trim( substr( $header, 9 ) );
		}

		return null;
	}

	/**
	 * Build a human-readable HTTP error for MaxMind database downloads.
	 *
	 * @param int $status HTTP status code.
	 * @return string Download error message.
	 * @since 1.0.0
	 */
	private function build_download_http_error_message( int $status ): string {
		if ( 401 === $status || 403 === $status ) {
			return __( 'MaxMind rejected the download request. Check the account ID, license key, and GeoLite download permissions.', 'peakurl' );
		}

		if ( 0 === $status ) {
			return __( 'PeakURL could not download the GeoLite2 City database from MaxMind.', 'peakurl' );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'PeakURL could not download the GeoLite2 City database from MaxMind. HTTP %d.', 'peakurl' ),
			$status,
		);
	}

	/**
	 * Resolve the active MaxMind credentials, preferring the settings table.
	 *
	 * @return array{accountId: string, licenseKey: string}
	 * @since 1.0.0
	 */
	private function get_runtime_credentials(): array {
		$options = $this->settings_api->get_options(
			array(
				'maxmind_account_id',
				'maxmind_license_key_encrypted',
			),
		);

		return array(
			'accountId'  => trim(
				(string) ( $options['maxmind_account_id'] ?? '' )
			),
			'licenseKey' => trim(
				$this->decrypt_secret_value(
					(string) ( $options['maxmind_license_key_encrypted'] ?? '' )
				)
			),
		);
	}

	/**
	 * Create a masked preview of the configured license key.
	 *
	 * @return string|null Masked license key preview or null.
	 * @since 1.0.0
	 */
	private function mask_license_key(): ?string {
		if ( '' === $this->license_key ) {
			return null;
		}

		if ( strlen( $this->license_key ) <= 8 ) {
			return str_repeat( '•', strlen( $this->license_key ) );
		}

		return substr( $this->license_key, 0, 4 ) .
			str_repeat( '•', strlen( $this->license_key ) - 8 ) .
			substr( $this->license_key, -4 );
	}

	/**
	 * Decrypt a stored secret value with plain-text fallback.
	 *
	 * @param string $value Stored value.
	 * @return string
	 * @since 1.0.0
	 */
	private function decrypt_secret_value( string $value ): string {
		try {
			return $this->crypto_service->decrypt( $value );
		} catch ( \RuntimeException $exception ) {
			return '';
		}
	}

	/**
	 * Create a directory recursively when it does not already exist.
	 *
	 * @param string $path Absolute directory path.
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 * @since 1.0.0
	 */
	private function ensure_directory( string $path ): void {
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
	 * @param string $path Absolute path to delete.
	 * @return void
	 * @since 1.0.0
	 */
	private function delete_path( string $path ): void {
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
	 * Extract an English display name from a MaxMind record section.
	 *
	 * @param array<string, mixed> $record MaxMind section data.
	 * @return string|null Extracted display name or null.
	 * @since 1.0.0
	 */
	private function extract_name_field( array $record ): ?string {
		$names = $record['names'] ?? null;

		if ( ! is_array( $names ) ) {
			return null;
		}

		$name = trim(
			(string) ( $names['en'] ?? reset( $names ) ?? '' ),
		);

		return '' !== $name ? $name : null;
	}

	/**
	 * Normalize a country code value.
	 *
	 * @param string $value Raw country code.
	 * @return string|null Two-letter ISO code, or null when unknown.
	 * @since 1.0.0
	 */
	private function normalize_country_code( string $value ): ?string {
		$country_code = strtoupper( trim( $value ) );

		if (
			'' === $country_code ||
			! preg_match( '/^[A-Z]{2}$/', $country_code ) ||
			in_array( $country_code, array( 'A1', 'A2', 'O1', 'T1', 'XX' ), true )
		) {
			return null;
		}

		return $country_code;
	}

	/**
	 * Check whether an IP address is publicly routable.
	 *
	 * @param string $ip_address Candidate IP address.
	 * @return bool True when the address is public.
	 * @since 1.0.0
	 */
	private function is_public_ip( string $ip_address ): bool {
		if ( '' === trim( $ip_address ) ) {
			return false;
		}

		return false !== filter_var(
			$ip_address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		);
	}

	/**
	 * Get an empty location payload.
	 *
	 * @return array<string, string|null> Empty location structure.
	 * @since 1.0.0
	 */
	private function empty_location(): array {
		return array(
			'country_code' => null,
			'country_name' => null,
			'city_name'    => null,
		);
	}
}
