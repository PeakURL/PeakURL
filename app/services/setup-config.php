<?php
/**
 * Database configuration step for the release installer.
 *
 * Handles config.php creation, database connectivity verification,
 * and the template-based config file writer.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Setup_Config_Service — manages the database credentials installer step.
 *
 * Validates database connectivity, ensures the release root is writable,
 * and writes the generated config.php from the bundled sample template.
 *
 * @since 1.0.0
 */
class Setup_Config_Service {

	/**
	 * Check whether config.php already exists.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool True when config.php is present.
	 * @since 1.0.0
	 */
	public static function has_config( string $app_path ): bool {
		return file_exists( self::get_config_path( $app_path ) );
	}

	/**
	 * Get the absolute path to the generated config.php.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string Config file path.
	 * @since 1.0.0
	 */
	public static function get_config_path( string $app_path ): string {
		return dirname( rtrim( $app_path, DIRECTORY_SEPARATOR ) ) . '/config.php';
	}

	/**
	 * Get the absolute path to the bundled config-sample.php template.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string Sample config file path.
	 * @since 1.0.0
	 */
	public static function get_sample_path( string $app_path ): string {
		return dirname( rtrim( $app_path, DIRECTORY_SEPARATOR ) ) . '/config-sample.php';
	}

	/**
	 * Get default form values for the database configuration step.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @param string $site_url  Detected site URL.
	 * @return array<string, string> Form field defaults.
	 * @since 1.0.0
	 */
	public static function get_form_defaults(
		string $app_path,
		string $site_url
	): array {
		unset( $app_path );

		return array(
			'site_url'    => rtrim( $site_url, '/' ),
			'db_host'     => self::get_installer_default(
				'PEAKURL_INSTALL_DB_HOST_DEFAULT',
				'localhost',
			),
			'db_port'     => self::get_installer_default(
				'PEAKURL_INSTALL_DB_PORT_DEFAULT',
				'3306',
			),
			'db_name'     => self::get_installer_default(
				'PEAKURL_INSTALL_DB_NAME_DEFAULT',
				'',
			),
			'db_user'     => self::get_installer_default(
				'PEAKURL_INSTALL_DB_USER_DEFAULT',
				'',
			),
			'db_password' => self::get_installer_default(
				'PEAKURL_INSTALL_DB_PASSWORD_DEFAULT',
				'',
			),
			'db_prefix'   => self::get_installer_default(
				'PEAKURL_INSTALL_DB_PREFIX_DEFAULT',
				'peakurl_',
			),
		);
	}

	/**
	 * Read an installer-only default from the process environment.
	 *
	 * These values are intended for local release-test conveniences such as the
	 * Docker-backed `peakurl.test` site and should not replace the normal
	 * production installer defaults unless explicitly provided.
	 *
	 * @param string $name          Environment variable name.
	 * @param string $default_value Fallback value when the variable is unset.
	 * @return string Installer default value.
	 * @since 1.0.0
	 */
	private static function get_installer_default(
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
	 * Execute the setup-config step.
	 *
	 * Validates input, tests database connectivity, and writes config.php.
	 *
	 * @param string               $app_path  Absolute path to the app directory.
	 * @param array<string, mixed> $input     Form input data.
	 * @return array<string, string> Normalised config values.
	 *
	 * @throws \RuntimeException On validation failure or write error.
	 * @since 1.0.0
	 */
	public static function setup(
		string $app_path,
		array $input
	): array {
		$values = self::normalize_input( $app_path, $input );

		self::ensure_writable_root( $app_path );
		self::assert_database_connection( $values );
		self::write_config_file( $app_path, $values );

		return $values;
	}

	/**
	 * Validate and normalise raw form input.
	 *
	 * @param array<string, mixed> $input Raw form input.
	 * @return array<string, string> Normalised config values.
	 *
	 * @throws \RuntimeException When required fields are missing or invalid.
	 * @since 1.0.0
	 */
	private static function normalize_input( string $app_path, array $input ): array {
		$site_url  = Install_Service::normalize_site_url_for_release(
			(string) ( $input['site_url'] ?? '' ),
		);
		$db_prefix = Config::normalize_db_prefix(
			trim( (string) ( $input['db_prefix'] ?? 'peakurl_' ) ),
		);
		$db_port   = (string) ( (int) ( $input['db_port'] ?? 3306 ) );

		if ( '' === trim( (string) ( $input['db_host'] ?? '' ) ) ) {
			throw new RuntimeException( 'Database host is required.' );
		}

		if ( '' === trim( (string) ( $input['db_name'] ?? '' ) ) ) {
			throw new RuntimeException( 'Database name is required.' );
		}

		if ( '' === trim( (string) ( $input['db_user'] ?? '' ) ) ) {
			throw new RuntimeException( 'Database username is required.' );
		}

		if ( '' === $db_prefix ) {
			throw new RuntimeException( 'Database table prefix is required.' );
		}

		if ( (int) $db_port < 1 || (int) $db_port > 65535 ) {
			throw new RuntimeException(
				'Database port must be between 1 and 65535.',
			);
		}

		return array(
			'PEAKURL_ENV'                 => 'production',
			'PEAKURL_DEBUG'               => 'false',
			'SITE_URL'                    => $site_url,
			'PEAKURL_UPDATE_MANIFEST_URL' =>
				'https://api.peakurl.org/v1/update',
			'PEAKURL_CONTENT_DIR'         => self::default_content_directory( $app_path ),
			'PEAKURL_GEOIP_DB_PATH'       => self::default_geoip_database_path( $app_path ),
			'PEAKURL_MAXMIND_ACCOUNT_ID'  => '',
			'PEAKURL_MAXMIND_LICENSE_KEY' => '',
			'PEAKURL_MAIL_DRIVER'         => 'mail',
			'PEAKURL_SMTP_HOST'           => '',
			'PEAKURL_SMTP_PORT'           => '587',
			'PEAKURL_SMTP_ENCRYPTION'     => 'tls',
			'PEAKURL_SMTP_AUTH'           => 'false',
			'PEAKURL_SMTP_USERNAME'       => '',
			'PEAKURL_SMTP_PASSWORD'       => '',
			'DB_HOST'                     => trim( (string) $input['db_host'] ),
			'DB_PORT'                     => $db_port,
			'DB_DATABASE'                 => trim( (string) $input['db_name'] ),
			'DB_USERNAME'                 => trim( (string) $input['db_user'] ),
			'DB_PASSWORD'                 => (string) ( $input['db_password'] ?? '' ),
			'DB_CHARSET'                  => 'utf8mb4',
			'DB_PREFIX'                   => $db_prefix,
			'SESSION_COOKIE_NAME'         => 'peakurl_session',
			'SESSION_LIFETIME'            => (string) ( 60 * 60 * 24 * 30 ),
			'SESSION_COOKIE_PATH'         => Install_Service::site_cookie_path_for_release(
				$site_url,
			),
			'SESSION_COOKIE_DOMAIN'       => '',
			'SESSION_COOKIE_SAME_SITE'    => 'Strict',
			'SESSION_COOKIE_SECURE'       => 'auto',
			'PEAKURL_OWNER_FALLBACK'      => 'false',
			'PEAKURL_OWNER_FIRST_NAME'    => '',
			'PEAKURL_OWNER_LAST_NAME'     => '',
			'PEAKURL_OWNER_USERNAME'      => '',
			'PEAKURL_OWNER_EMAIL'         => '',
			'PEAKURL_OWNER_PASSWORD'      => '',
			'PEAKURL_WORKSPACE_NAME'      => '',
			'PEAKURL_WORKSPACE_SLUG'      => '',
		);
	}

	/**
	 * Verify database connectivity by opening a PDO connection.
	 *
	 * @param array<string, string> $values Normalised config values.
	 *
	 * @throws \RuntimeException When the connection fails.
	 * @since 1.0.0
	 */
	private static function assert_database_connection( array $values ): void {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			$values['DB_HOST'],
			(int) $values['DB_PORT'],
			$values['DB_DATABASE'],
			$values['DB_CHARSET'],
		);

		try {
			$connection = new PDO(
				$dsn,
				$values['DB_USERNAME'],
				$values['DB_PASSWORD'],
				array(
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				),
			);
			$connection->query( 'SELECT 1' );
		} catch ( Throwable $exception ) {
			throw new RuntimeException(
				'Unable to connect to the database with those credentials. ' . $exception->getMessage(),
				0,
				$exception,
			);
		}
	}

	/**
	 * Ensure the release root and app directories are writable.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 *
	 * @throws \RuntimeException When directories are not writable.
	 * @since 1.0.0
	 */
	private static function ensure_writable_root( string $app_path ): void {
		$root_path = dirname( rtrim( $app_path, DIRECTORY_SEPARATOR ) );

		if ( ! is_writable( $root_path ) ) {
			throw new RuntimeException(
				'The release root directory is not writable. PeakURL could not create config.php.',
			);
		}

		if ( ! is_writable( $app_path ) ) {
			throw new RuntimeException( 'The app directory is not writable.' );
		}
	}

	/**
	 * Write the generated config.php from the sample template.
	 *
	 * Replaces placeholder tokens in config-sample.php with real values.
	 *
	 * @param string                $app_path Absolute path to the app directory.
	 * @param array<string, string> $values   Config values to interpolate.
	 *
	 * @throws \RuntimeException On missing sample or write failure.
	 * @since 1.0.0
	 */
	public static function write_config_file(
		string $app_path,
		array $values
	): void {
		$sample_path = self::get_sample_path( $app_path );

		if ( ! file_exists( $sample_path ) ) {
			throw new RuntimeException(
				'config-sample.php is missing from the release package.',
			);
		}

		$template = file_get_contents( $sample_path );

		if ( false === $template ) {
			throw new RuntimeException(
				'Unable to read config-sample.php from the release package.',
			);
		}

		$replacements = array();

		foreach ( self::config_constants_from_values( $values ) as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = $value;
		}

		$config_contents = strtr( $template, $replacements );
		$config_path     = self::get_config_path( $app_path );

		if ( false === file_put_contents( $config_path, $config_contents, LOCK_EX ) ) {
			throw new RuntimeException(
				'Unable to write config.php in the release root.',
			);
		}
	}

	/**
	 * Convert config values into PHP-exportable constant strings.
	 *
	 * @param array<string, string> $values Config values.
	 * @return array<string, string> Values ready for template interpolation.
	 * @since 1.0.0
	 */
	private static function config_constants_from_values( array $values ): array {
		return array(
			'PEAKURL_ENV'                 => var_export( $values['PEAKURL_ENV'], true ),
			'PEAKURL_DEBUG'               => 'true' === $values['PEAKURL_DEBUG'] ? 'true' : 'false',
			'SITE_URL'                    => var_export( $values['SITE_URL'], true ),
			'PEAKURL_UPDATE_MANIFEST_URL' => var_export(
				$values['PEAKURL_UPDATE_MANIFEST_URL'],
				true,
			),
			'PEAKURL_CONTENT_DIR'         => var_export(
				$values['PEAKURL_CONTENT_DIR'],
				true,
			),
			'PEAKURL_GEOIP_DB_PATH'       => var_export(
				$values['PEAKURL_GEOIP_DB_PATH'],
				true,
			),
			'PEAKURL_MAXMIND_ACCOUNT_ID'  => var_export(
				$values['PEAKURL_MAXMIND_ACCOUNT_ID'],
				true,
			),
			'PEAKURL_MAXMIND_LICENSE_KEY' => var_export(
				$values['PEAKURL_MAXMIND_LICENSE_KEY'],
				true,
			),
			'PEAKURL_MAIL_DRIVER'         => var_export(
				$values['PEAKURL_MAIL_DRIVER'],
				true,
			),
			'PEAKURL_SMTP_HOST'           => var_export(
				$values['PEAKURL_SMTP_HOST'],
				true,
			),
			'PEAKURL_SMTP_PORT'           => (string) (int) $values['PEAKURL_SMTP_PORT'],
			'PEAKURL_SMTP_ENCRYPTION'     => var_export(
				$values['PEAKURL_SMTP_ENCRYPTION'],
				true,
			),
			'PEAKURL_SMTP_AUTH'           => 'true' === $values['PEAKURL_SMTP_AUTH'] ? 'true' : 'false',
			'PEAKURL_SMTP_USERNAME'       => var_export(
				$values['PEAKURL_SMTP_USERNAME'],
				true,
			),
			'PEAKURL_SMTP_PASSWORD'       => var_export(
				$values['PEAKURL_SMTP_PASSWORD'],
				true,
			),
			'DB_HOST'                     => var_export( $values['DB_HOST'], true ),
			'DB_PORT'                     => (string) (int) $values['DB_PORT'],
			'DB_DATABASE'                 => var_export( $values['DB_DATABASE'], true ),
			'DB_USERNAME'                 => var_export( $values['DB_USERNAME'], true ),
			'DB_PASSWORD'                 => var_export( $values['DB_PASSWORD'], true ),
			'DB_CHARSET'                  => var_export( $values['DB_CHARSET'], true ),
			'DB_PREFIX'                   => var_export( $values['DB_PREFIX'], true ),
			'SESSION_COOKIE_NAME'         => var_export( $values['SESSION_COOKIE_NAME'], true ),
			'SESSION_LIFETIME'            => (string) (int) $values['SESSION_LIFETIME'],
			'SESSION_COOKIE_PATH'         => var_export( $values['SESSION_COOKIE_PATH'], true ),
			'SESSION_COOKIE_DOMAIN'       => var_export( $values['SESSION_COOKIE_DOMAIN'], true ),
			'SESSION_COOKIE_SAME_SITE'    => var_export( $values['SESSION_COOKIE_SAME_SITE'], true ),
			'SESSION_COOKIE_SECURE'       => var_export( $values['SESSION_COOKIE_SECURE'], true ),
		);
	}

	/**
	 * Convert a runtime config array into flat values for config.php.
	 *
	 * @param array<string, mixed> $config Runtime configuration.
	 * @return array<string, string> Flat config value map.
	 * @since 1.0.0
	 */
	public static function config_values_from_runtime_config( array $config ): array {
		return array(
			'PEAKURL_ENV'                 => (string) ( $config['PEAKURL_ENV'] ?? 'production' ),
			'PEAKURL_DEBUG'               => ! empty( $config['PEAKURL_DEBUG'] ) ? 'true' : 'false',
			'SITE_URL'                    => (string) ( $config['SITE_URL'] ?? '' ),
			'PEAKURL_UPDATE_MANIFEST_URL' => (string) ( $config['PEAKURL_UPDATE_MANIFEST_URL'] ?? 'https://api.peakurl.org/v1/update' ),
			'PEAKURL_CONTENT_DIR'         => (string) ( $config['PEAKURL_CONTENT_DIR'] ?? '' ),
			'PEAKURL_GEOIP_DB_PATH'       => (string) ( $config['PEAKURL_GEOIP_DB_PATH'] ?? '' ),
			'PEAKURL_MAXMIND_ACCOUNT_ID'  => (string) ( $config['PEAKURL_MAXMIND_ACCOUNT_ID'] ?? '' ),
			'PEAKURL_MAXMIND_LICENSE_KEY' => (string) ( $config['PEAKURL_MAXMIND_LICENSE_KEY'] ?? '' ),
			'PEAKURL_MAIL_DRIVER'         => (string) ( $config['PEAKURL_MAIL_DRIVER'] ?? 'mail' ),
			'PEAKURL_SMTP_HOST'           => (string) ( $config['PEAKURL_SMTP_HOST'] ?? '' ),
			'PEAKURL_SMTP_PORT'           => (string) ( $config['PEAKURL_SMTP_PORT'] ?? 587 ),
			'PEAKURL_SMTP_ENCRYPTION'     => (string) ( $config['PEAKURL_SMTP_ENCRYPTION'] ?? 'tls' ),
			'PEAKURL_SMTP_AUTH'           => ! empty( $config['PEAKURL_SMTP_AUTH'] ) ? 'true' : 'false',
			'PEAKURL_SMTP_USERNAME'       => (string) ( $config['PEAKURL_SMTP_USERNAME'] ?? '' ),
			'PEAKURL_SMTP_PASSWORD'       => (string) ( $config['PEAKURL_SMTP_PASSWORD'] ?? '' ),
			'DB_HOST'                     => (string) ( $config['DB_HOST'] ?? 'localhost' ),
			'DB_PORT'                     => (string) ( $config['DB_PORT'] ?? 3306 ),
			'DB_DATABASE'                 => (string) ( $config['DB_DATABASE'] ?? '' ),
			'DB_USERNAME'                 => (string) ( $config['DB_USERNAME'] ?? '' ),
			'DB_PASSWORD'                 => (string) ( $config['DB_PASSWORD'] ?? '' ),
			'DB_CHARSET'                  => (string) ( $config['DB_CHARSET'] ?? 'utf8mb4' ),
			'DB_PREFIX'                   => (string) ( $config['DB_PREFIX'] ?? 'peakurl_' ),
			'SESSION_COOKIE_NAME'         => (string) ( $config['SESSION_COOKIE_NAME'] ?? 'peakurl_session' ),
			'SESSION_LIFETIME'            => (string) ( $config['SESSION_LIFETIME'] ?? ( 60 * 60 * 24 * 30 ) ),
			'SESSION_COOKIE_PATH'         => (string) ( $config['SESSION_COOKIE_PATH'] ?? '/' ),
			'SESSION_COOKIE_DOMAIN'       => (string) ( $config['SESSION_COOKIE_DOMAIN'] ?? '' ),
			'SESSION_COOKIE_SAME_SITE'    => (string) ( $config['SESSION_COOKIE_SAME_SITE'] ?? 'Strict' ),
			'SESSION_COOKIE_SECURE'       => (string) ( $config['SESSION_COOKIE_SECURE'] ?? 'auto' ),
			'PEAKURL_OWNER_FALLBACK'      => ! empty( $config['PEAKURL_OWNER_FALLBACK'] ) ? 'true' : 'false',
			'PEAKURL_OWNER_FIRST_NAME'    => (string) ( $config['PEAKURL_OWNER_FIRST_NAME'] ?? '' ),
			'PEAKURL_OWNER_LAST_NAME'     => (string) ( $config['PEAKURL_OWNER_LAST_NAME'] ?? '' ),
			'PEAKURL_OWNER_USERNAME'      => (string) ( $config['PEAKURL_OWNER_USERNAME'] ?? '' ),
			'PEAKURL_OWNER_EMAIL'         => (string) ( $config['PEAKURL_OWNER_EMAIL'] ?? '' ),
			'PEAKURL_OWNER_PASSWORD'      => (string) ( $config['PEAKURL_OWNER_PASSWORD'] ?? '' ),
			'PEAKURL_WORKSPACE_NAME'      => (string) ( $config['PEAKURL_WORKSPACE_NAME'] ?? '' ),
			'PEAKURL_WORKSPACE_SLUG'      => (string) ( $config['PEAKURL_WORKSPACE_SLUG'] ?? '' ),
		);
	}

	/**
	 * Get the default absolute content directory path.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string Absolute content directory path.
	 * @since 1.0.0
	 */
	private static function default_content_directory( string $app_path ): string {
		return dirname( rtrim( $app_path, DIRECTORY_SEPARATOR ) ) . '/content';
	}

	/**
	 * Get the default absolute GeoIP database path.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string Absolute GeoIP database path.
	 * @since 1.0.0
	 */
	private static function default_geoip_database_path( string $app_path ): string {
		return self::default_content_directory( $app_path ) . '/uploads/geoip/GeoLite2-City.mmdb';
	}

	/**
	 * Upsert a managed set of assignments into an .env file.
	 *
	 * @param string                $env_path       Absolute path to the .env file.
	 * @param array<string, string> $managed_values Key-value pairs managed by PeakURL.
	 * @param string                $error_message  Exception message for write failures.
	 * @param string                $header_comment Optional comment added to new files.
	 * @return void
	 * @since 1.0.0
	 */
	public static function write_env_overrides(
		string $env_path,
		array $managed_values,
		string $error_message,
		string $header_comment = ''
	): void {
		$directory = dirname( $env_path );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( $error_message );
		}

		$managed_keys   = array_keys( $managed_values );
		$existing_lines = array();

		if ( file_exists( $env_path ) ) {
			$read_lines = file( $env_path, FILE_IGNORE_NEW_LINES );

			if ( false === $read_lines ) {
				throw new RuntimeException( $error_message );
			}

			$existing_lines = $read_lines;
		}

		$updated_lines = array();
		$handled_keys  = array();

		foreach ( $existing_lines as $line ) {
			if (
				preg_match( '/^\s*([A-Z0-9_]+)\s*=/', $line, $matches ) &&
				in_array( $matches[1], $managed_keys, true )
			) {
				$key             = $matches[1];
				$updated_lines[] = self::format_env_assignment(
					$key,
					(string) $managed_values[ $key ],
				);
				$handled_keys[]  = $key;
				continue;
			}

			$updated_lines[] = $line;
		}

		if ( empty( $existing_lines ) && '' !== trim( $header_comment ) ) {
			$updated_lines[] = trim( $header_comment );
		}

		foreach ( $managed_keys as $key ) {
			if ( in_array( $key, $handled_keys, true ) ) {
				continue;
			}

			$updated_lines[] = self::format_env_assignment(
				$key,
				(string) $managed_values[ $key ],
			);
		}

		$env_body = implode( PHP_EOL, $updated_lines );

		if ( '' !== $env_body ) {
			$env_body .= PHP_EOL;
		}

		if ( false === file_put_contents( $env_path, $env_body, LOCK_EX ) ) {
			throw new RuntimeException( $error_message );
		}
	}

	/**
	 * Format a single .env assignment line.
	 *
	 * @param string $key   Environment key.
	 * @param string $value Environment value.
	 * @return string
	 * @since 1.0.0
	 */
	private static function format_env_assignment( string $key, string $value ): string {
		return $key . '=' . self::format_env_value( $value );
	}

	/**
	 * Escape a value for safe .env storage.
	 *
	 * @param string $value Raw environment value.
	 * @return string
	 * @since 1.0.0
	 */
	private static function format_env_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^[A-Za-z0-9._\\/-]+$/', $value ) ) {
			return $value;
		}

		return '"' . addcslashes( $value, "\"\\\n\r\t\$" ) . '"';
	}
}
