<?php
/**
 * Update request metadata builder.
 *
 * @package PeakURL\Services\Update
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Metadata — compiles the canonical 12 query parameters for update checks.
 *
 * Strictly read-only: reads installation metadata and environment details without
 * writing, repairing, or generating persistent metadata during request execution.
 *
 * @since 1.7.0
 */
class Metadata {

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.7.0
	 */
	private Context $context;

	/**
	 * Settings data API helper.
	 *
	 * @var SettingsApi
	 * @since 1.7.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.0
	 */
	private PeakURL_DB $db;

	/**
	 * Cached database server metadata.
	 *
	 * @var array{version: string, version_comment: string}|null
	 * @since 1.7.0
	 */
	private ?array $database_metadata = null;

	/**
	 * Create a new update metadata helper.
	 *
	 * All dependencies are explicit and required.
	 *
	 * @param Context     $context      Shared updater context helper.
	 * @param SettingsApi $settings_api Settings API instance.
	 * @param PeakURL_DB  $db           Shared database wrapper.
	 * @since 1.7.0
	 */
	public function __construct(
		Context $context,
		SettingsApi $settings_api,
		PeakURL_DB $db
	) {
		$this->context      = $context;
		$this->settings_api = $settings_api;
		$this->db           = $db;
	}

	/**
	 * Compile and return the canonical 12 telemetry query parameters.
	 *
	 * @return array<string, string>
	 * @since 1.7.0
	 */
	public function to_array(): array {
		return array(
			'version'          => $this->get_version(),
			'php_version'      => $this->get_php_version(),
			'database'         => $this->get_database(),
			'database_version' => $this->get_database_version(),
			'schema_version'   => $this->get_schema_version(),
			'web_server'       => $this->get_web_server(),
			'os'               => $this->get_os(),
			'install_type'     => $this->get_install_type(),
			'language'         => $this->get_language(),
			'days_active'      => (string) $this->get_days_active(),
			'site_url'         => $this->get_site_url(),
			'installation_id'  => $this->get_installation_id(),
		);
	}

	/**
	 * Get the canonical application version.
	 *
	 * @return string Semantic version.
	 * @since 1.7.0
	 */
	public function get_version(): string {
		return $this->context->get_current_version();
	}

	/**
	 * Get the full runtime PHP version.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * Get the normalized database engine.
	 *
	 * @return string One of: mysql, mariadb, unknown.
	 * @since 1.7.0
	 */
	public function get_database(): string {
		$metadata = $this->query_database_metadata();
		$version  = $metadata['version'];
		$comment  = $metadata['version_comment'];
		$haystack = strtolower( $version . ' ' . $comment );

		if ( false !== strpos( $haystack, 'mariadb' ) ) {
			return 'mariadb';
		}

		if ( '' !== $version || '' !== $comment ) {
			return 'mysql';
		}

		return 'unknown';
	}

	/**
	 * Get the full detected database server version.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_database_version(): string {
		$metadata = $this->query_database_metadata();
		return '' !== $metadata['version'] ? $metadata['version'] : 'unknown';
	}

	/**
	 * Get the canonical database schema version.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_schema_version(): string {
		$stored = $this->settings_api->get_option( Constants::SETTING_DB_SCHEMA_VERSION );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return trim( $stored );
		}

		return (string) Constants::DB_SCHEMA_VERSION;
	}

	/**
	 * Get the normalized web server software.
	 *
	 * @return string One of: nginx, apache, caddy, iis, lighttpd, other, unknown.
	 * @since 1.7.0
	 */
	public function get_web_server(): string {
		return self::normalize_web_server();
	}

	/**
	 * Normalize a server software string.
	 *
	 * @param string|null $server_software Raw SERVER_SOFTWARE string or null to read from environment.
	 * @return string One of: nginx, apache, caddy, iis, lighttpd, other, unknown.
	 * @since 1.7.0
	 */
	public static function normalize_web_server( ?string $server_software = null ): string {
		$software = strtolower( trim( (string) ( $server_software ?? ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) ) );

		if ( '' === $software ) {
			return 'unknown';
		}

		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}

		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}

		if ( false !== strpos( $software, 'caddy' ) ) {
			return 'caddy';
		}

		if ( false !== strpos( $software, 'iis' ) || false !== strpos( $software, 'microsoft-iis' ) ) {
			return 'iis';
		}

		if ( false !== strpos( $software, 'lighttpd' ) ) {
			return 'lighttpd';
		}

		return 'other';
	}

	/**
	 * Get the normalized operating system family.
	 *
	 * @return string One of: linux, windows, darwin, freebsd, other, unknown.
	 * @since 1.7.0
	 */
	public function get_os(): string {
		return self::normalize_os();
	}

	/**
	 * Normalize an operating system family string.
	 *
	 * @param string|null $os_family Raw OS family string or null to read from environment.
	 * @return string One of: linux, windows, darwin, freebsd, other, unknown.
	 * @since 1.7.0
	 */
	public static function normalize_os( ?string $os_family = null ): string {
		if ( null === $os_family ) {
			$raw_family = defined( 'PHP_OS_FAMILY' ) ? PHP_OS_FAMILY : PHP_OS;
			$family     = strtolower( trim( (string) $raw_family ) );
			$is_freebsd = false !== strpos( strtolower( PHP_OS ), 'freebsd' );
		} else {
			$family     = strtolower( trim( $os_family ) );
			$is_freebsd = false !== strpos( $family, 'freebsd' );
		}

		if ( 'linux' === $family ) {
			return 'linux';
		}

		if ( 'windows' === $family ) {
			return 'windows';
		}

		if ( 'darwin' === $family ) {
			return 'darwin';
		}

		if ( 'bsd' === $family || $is_freebsd ) {
			return 'freebsd';
		}

		if ( '' === $family || 'unknown' === $family ) {
			return 'unknown';
		}

		return 'other';
	}

	/**
	 * Get the installation type based on environment configuration.
	 *
	 * @return string One of: development, release.
	 * @since 1.7.0
	 */
	public function get_install_type(): string {
		return Environment::get_instance()->is_development() ? 'development' : 'release';
	}

	/**
	 * Get the configured site locale.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_language(): string {
		$stored = $this->settings_api->get_option( 'site_language' );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return trim( $stored );
		}

		$configured = trim( (string) $this->context->get_config_value( Constants::SITE_LANGUAGE, '' ) );

		return '' !== $configured ? $configured : Constants::DEFAULT_LOCALE;
	}

	/**
	 * Calculate completed whole UTC days active from installed_at.
	 *
	 * Strictly read-only: never creates or repairs installed_at.
	 *
	 * @return int Non-negative integer number of days.
	 * @since 1.7.0
	 */
	public function get_days_active(): int {
		$installed_at = $this->settings_api->get_option( 'installed_at' );

		if ( ! is_string( $installed_at ) || '' === trim( $installed_at ) ) {
			return 0;
		}

		try {
			$installed = new \DateTimeImmutable( trim( $installed_at ), new \DateTimeZone( 'UTC' ) );
			$now       = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$diff      = $now->getTimestamp() - $installed->getTimestamp();

			return max( 0, (int) floor( $diff / 86400 ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return 0;
		}
	}

	/**
	 * Get the normalized canonical site origin.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function get_site_url(): string {
		return self::normalize_site_url( $this->context->get_site_url() );
	}

	/**
	 * Normalize a raw site URL into a canonical origin.
	 *
	 * @param string $raw_url Raw URL.
	 * @return string Normalized origin (e.g. https://example.com) or empty string on invalid URL.
	 * @since 1.7.0
	 */
	public static function normalize_site_url( string $raw_url ): string {
		$raw_url = trim( $raw_url );

		if ( '' === $raw_url ) {
			return '';
		}

		$parts = parse_url( $raw_url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host   = strtolower( (string) $parts['host'] );
		$origin = $scheme . '://' . $host;

		if ( ! empty( $parts['port'] ) ) {
			$port       = (int) $parts['port'];
			$is_default = ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port );

			if ( ! $is_default ) {
				$origin .= ':' . $port;
			}
		}

		return $origin;
	}

	/**
	 * Read the persisted canonical installation ID.
	 *
	 * Strictly read-only: never creates, regenerates, or writes an installation ID.
	 *
	 * @return string Persistent UUID v4 or empty string if uninitialized.
	 * @since 1.7.0
	 */
	public function get_installation_id(): string {
		$stored = $this->settings_api->get_option( 'installation_id' );

		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/**
	 * Query database engine metadata.
	 *
	 * @return array{version: string, version_comment: string}
	 * @since 1.7.0
	 */
	private function query_database_metadata(): array {
		if ( null !== $this->database_metadata ) {
			return $this->database_metadata;
		}

		try {
			$row = $this->db->get_row(
				'SELECT VERSION() AS version, @@version_comment AS version_comment'
			);

			if ( is_array( $row ) ) {
				$this->database_metadata = array(
					'version'         => trim( (string) ( $row['version'] ?? '' ) ),
					'version_comment' => trim( (string) ( $row['version_comment'] ?? '' ) ),
				);

				return $this->database_metadata;
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		$this->database_metadata = array(
			'version'         => '',
			'version_comment' => '',
		);

		return $this->database_metadata;
	}
}
