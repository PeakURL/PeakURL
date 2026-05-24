<?php
/**
 * Release installer database configuration service.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Config — database configuration step for the release installer.
 *
 * Validates the submitted database credentials, verifies filesystem access,
 * and writes the generated config.php used by the remaining install flow.
 *
 * @since 1.0.14
 */
class Config {

	/**
	 * Return default field values for the database configuration step.
	 *
	 * @param string $site_url Detected site URL.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public static function get_form_defaults( string $site_url ): array {
		return array(
			'site_url'    => untrailingslashit( $site_url ),
			'db_host'     => self::get_default_value(
				'PEAKURL_INSTALL_DB_HOST_DEFAULT',
				'localhost',
			),
			'db_port'     => self::get_default_value(
				'PEAKURL_INSTALL_DB_PORT_DEFAULT',
				'3306',
			),
			'db_name'     => self::get_default_value(
				'PEAKURL_INSTALL_DB_NAME_DEFAULT',
				'',
			),
			'db_user'     => self::get_default_value(
				'PEAKURL_INSTALL_DB_USER_DEFAULT',
				'',
			),
			'db_password' => self::get_default_value(
				'PEAKURL_INSTALL_DB_PASSWORD_DEFAULT',
				'',
			),
			'db_prefix'   => self::get_default_value(
				'PEAKURL_INSTALL_DB_PREFIX_DEFAULT',
				'peakurl_',
			),
		);
	}

	/**
	 * Execute the database configuration step.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $input    Raw setup form input.
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException When validation or file writes fail.
	 * @since 1.0.14
	 */
	public static function configure(
		string $app_path,
		array $input
	): array {
		$values = self::normalize_input( $app_path, $input );

		self::validate_writable_root( $app_path );
		self::validate_database( $values );
		Writer::write_config_file( $app_path, $values );

		return $values;
	}

	/**
	 * Read an installer-only default from the process environment.
	 *
	 * @param string $name          Environment variable name.
	 * @param string $default_value Fallback value when the variable is unset.
	 * @return string
	 * @since 1.0.14
	 */
	private static function get_default_value(
		string $name,
		string $default_value
	): string {
		$value = getenv( $name );

		if ( false === $value ) {
			return $default_value;
		}

		return trim( (string) $value );
	}

	/**
	 * Validate and normalize the database configuration submission.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $input    Raw setup form input.
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException When required fields are missing or invalid.
	 * @since 1.0.14
	 */
	private static function normalize_input(
		string $app_path,
		array $input
	): array {
		$site_url  = Site::normalize_url(
			(string) ( $input['site_url'] ?? '' ),
		);
		$db_prefix = RuntimeConfig::normalize_db_prefix(
			trim( (string) ( $input['db_prefix'] ?? 'peakurl_' ) ),
		);
		$db_name   = RuntimeConfig::normalize_db_name(
			trim( (string) ( $input['db_name'] ?? '' ) ),
		);
		$db_port   = (string) ( (int) ( $input['db_port'] ?? 3306 ) );

		if ( '' === trim( (string) ( $input['db_host'] ?? '' ) ) ) {
			throw new \RuntimeException( __( 'Database host is required.', 'peakurl' ) );
		}

		if ( '' === trim( (string) ( $input['db_user'] ?? '' ) ) ) {
			throw new \RuntimeException( __( 'Database username is required.', 'peakurl' ) );
		}

		if ( '' === $db_prefix ) {
			throw new \RuntimeException( __( 'Database table prefix is required.', 'peakurl' ) );
		}

		if ( (int) $db_port < 1 || (int) $db_port > 65535 ) {
			throw new \RuntimeException(
				__( 'Database port must be between 1 and 65535.', 'peakurl' ),
			);
		}

		return array(
			Constants::ENV                      => 'production',
			Constants::DEBUG                    => 'false',
			Constants::SITE_URL                 => $site_url,
			Constants::AUTH_KEY                 => self::generate_auth_key(),
			Constants::AUTH_SALT                => self::generate_auth_salt(),
			Constants::UPDATE_MANIFEST_URL      => Constants::DEFAULT_UPDATE_MANIFEST_URL,
			Constants::CONTENT_DIR              => self::get_default_content_dir( $app_path ),
			Constants::GEOIP_DB_PATH            => self::get_default_geoip_path( $app_path ),
			Constants::DB_HOST                  => trim( (string) $input['db_host'] ),
			Constants::DB_PORT                  => $db_port,
			Constants::DB_DATABASE              => $db_name,
			Constants::DB_USERNAME              => trim( (string) $input['db_user'] ),
			Constants::DB_PASSWORD              => (string) ( $input['db_password'] ?? '' ),
			Constants::DB_CHARSET               => 'utf8mb4',
			Constants::DB_PREFIX                => $db_prefix,
			Constants::SESSION_COOKIE_NAME      => Constants::DEFAULT_SESSION_COOKIE_NAME,
			Constants::SESSION_LIFETIME         => (string) Constants::DEFAULT_SESSION_LIFETIME,
			Constants::SESSION_COOKIE_PATH      => Site::get_cookie_path( $site_url ),
			Constants::SESSION_COOKIE_DOMAIN    => '',
			Constants::SESSION_COOKIE_SAME_SITE => Constants::DEFAULT_SESSION_COOKIE_SAME_SITE,
			Constants::SESSION_COOKIE_SECURE    => Constants::DEFAULT_SESSION_COOKIE_SECURE,
			Constants::OWNER_FALLBACK           => 'false',
			Constants::OWNER_FIRST_NAME         => '',
			Constants::OWNER_LAST_NAME          => '',
			Constants::OWNER_USERNAME           => '',
			Constants::OWNER_EMAIL              => '',
			Constants::OWNER_PASSWORD           => '',
			Constants::WORKSPACE_NAME           => '',
			Constants::WORKSPACE_SLUG           => '',
		);
	}

	/**
	 * Verify database connectivity with the submitted credentials.
	 *
	 * @param array<string, string> $values Normalized config values.
	 *
	 * @throws \RuntimeException When the connection fails.
	 * @since 1.0.14
	 */
	private static function validate_database( array $values ): void {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			$values[ Constants::DB_HOST ],
			(int) $values[ Constants::DB_PORT ],
			$values[ Constants::DB_DATABASE ],
			$values[ Constants::DB_CHARSET ],
		);

		try {
			$connection = new \PDO(
				$dsn,
				$values[ Constants::DB_USERNAME ],
				$values[ Constants::DB_PASSWORD ],
				array(
					\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
					\PDO::ATTR_EMULATE_PREPARES   => false,
				),
			);
			$connection->query( 'SELECT 1' );
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException(
				__( 'Unable to connect to the database with those credentials. ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception,
			);
		}
	}

	/**
	 * Validate that the release root and app directories are writable.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 *
	 * @throws \RuntimeException When the release cannot write config.php.
	 * @since 1.0.14
	 */
	private static function validate_writable_root( string $app_path ): void {
		$root_path = Writer::get_release_root_path( $app_path );

		if ( ! is_writable( $root_path ) ) {
			throw new \RuntimeException(
				__( 'The release root directory is not writable. PeakURL could not create config.php.', 'peakurl' ),
			);
		}

		if ( ! is_writable( $app_path ) ) {
			throw new \RuntimeException( __( 'The app directory is not writable.', 'peakurl' ) );
		}
	}

	/**
	 * Return the default absolute content directory path.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	private static function get_default_content_dir( string $app_path ): string {
		return Writer::get_release_root_path( $app_path ) . '/content';
	}

	/**
	 * Return the default absolute GeoIP database path.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	private static function get_default_geoip_path( string $app_path ): string {
		return self::get_default_content_dir( $app_path ) . '/uploads/geoip/GeoLite2-City.mmdb';
	}

	/**
	 * Generate a random authentication key for sessions and stored secrets.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private static function generate_auth_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Generate a random authentication salt for sessions and stored secrets.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private static function generate_auth_salt(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
