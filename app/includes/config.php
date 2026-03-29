<?php
/**
 * Runtime configuration loader.
 *
 * Merges values from config.php, .env files, environment variables, and
 * PHP constants into a single associative array consumed by the rest of
 * the application.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Immutable configuration reader.
 *
 * Precedence (highest → lowest): runtime environment variables, .env file,
 * config.php constants, built-in defaults.
 *
 * @since 1.0.0
 */
class Config {

	/**
	 * Load the merged runtime configuration for the application.
	 *
	 * @param string $base_path Absolute path to the PHP runtime directory.
	 * @return array<string, mixed> Fully-resolved configuration map.
	 * @since 1.0.0
	 */
	public static function load( string $base_path ): array {
		$root_path   = dirname( $base_path );
		$file_values = array_merge(
			self::parse_config_file( $root_path . '/config.php' ),
			self::parse_env_file( $base_path . '/.env' ),
		);

		return array(
			'PEAKURL_VERSION'             => self::read_version_file(
				$root_path . '/.version',
			),
			'PEAKURL_ENV'                 => self::get_value(
				'PEAKURL_ENV',
				$file_values,
				'development',
			),
			'SITE_URL'                    => self::get_value(
				'SITE_URL',
				$file_values,
				'http://localhost:5173',
			),
			'PEAKURL_UPDATE_MANIFEST_URL' => self::get_value(
				'PEAKURL_UPDATE_MANIFEST_URL',
				$file_values,
				'https://api.peakurl.org/v1/update',
			),
			'PEAKURL_CONTENT_DIR'         => self::resolve_path_value(
				self::get_value(
					'PEAKURL_CONTENT_DIR',
					$file_values,
					'content',
				),
				$root_path,
			),
			'PEAKURL_GEOIP_DB_PATH'       => self::resolve_path_value(
				self::get_value(
					'PEAKURL_GEOIP_DB_PATH',
					$file_values,
					'content/uploads/geoip/GeoLite2-City.mmdb',
				),
				$root_path,
			),
			'PEAKURL_MAXMIND_ACCOUNT_ID'  => self::get_value(
				'PEAKURL_MAXMIND_ACCOUNT_ID',
				$file_values,
				'',
			),
			'PEAKURL_MAXMIND_LICENSE_KEY' => self::get_value(
				'PEAKURL_MAXMIND_LICENSE_KEY',
				$file_values,
				'',
			),
			'PEAKURL_MAIL_DRIVER'         => self::normalize_mail_driver(
				self::get_value(
					'PEAKURL_MAIL_DRIVER',
					$file_values,
					'mail',
				),
			),
			'PEAKURL_SMTP_HOST'           => self::get_value(
				'PEAKURL_SMTP_HOST',
				$file_values,
				'',
			),
			'PEAKURL_SMTP_PORT'           => (int) self::get_value(
				'PEAKURL_SMTP_PORT',
				$file_values,
				'587',
			),
			'PEAKURL_SMTP_ENCRYPTION'     => self::normalize_smtp_encryption(
				self::get_value(
					'PEAKURL_SMTP_ENCRYPTION',
					$file_values,
					'tls',
				),
			),
			'PEAKURL_SMTP_AUTH'           => self::get_bool_value(
				'PEAKURL_SMTP_AUTH',
				$file_values,
				false,
			),
			'PEAKURL_SMTP_USERNAME'       => self::get_value(
				'PEAKURL_SMTP_USERNAME',
				$file_values,
				'',
			),
			'PEAKURL_SMTP_PASSWORD'       => self::get_value(
				'PEAKURL_SMTP_PASSWORD',
				$file_values,
				'',
			),
			'PEAKURL_DEBUG'               => self::get_bool_value(
				'PEAKURL_DEBUG',
				$file_values,
				true,
			),
			'DB_HOST'                     => self::get_value( 'DB_HOST', $file_values, '127.0.0.1' ),
			'DB_PORT'                     => (int) self::get_value( 'DB_PORT', $file_values, '3306' ),
			'DB_DATABASE'                 => self::get_value(
				'DB_DATABASE',
				$file_values,
				'peakurl',
			),
			'DB_USERNAME'                 => self::get_value( 'DB_USERNAME', $file_values, 'root' ),
			'DB_PASSWORD'                 => self::get_value( 'DB_PASSWORD', $file_values, '' ),
			'DB_CHARSET'                  => self::get_value(
				'DB_CHARSET',
				$file_values,
				'utf8mb4',
			),
			'DB_PREFIX'                   => self::normalize_db_prefix(
				self::get_value( 'DB_PREFIX', $file_values, '' ),
			),
			'SESSION_COOKIE_NAME'         => self::get_value(
				'SESSION_COOKIE_NAME',
				$file_values,
				'peakurl_session',
			),
			'SESSION_LIFETIME'            => (int) self::get_value(
				'SESSION_LIFETIME',
				$file_values,
				(string) ( 60 * 60 * 24 * 30 ),
			),
			'SESSION_COOKIE_PATH'         => self::get_value(
				'SESSION_COOKIE_PATH',
				$file_values,
				'/',
			),
			'SESSION_COOKIE_DOMAIN'       => self::sanitize_domain(
				self::get_value( 'SESSION_COOKIE_DOMAIN', $file_values, '' ),
			),
			'SESSION_COOKIE_SAME_SITE'    => self::normalize_same_site(
				self::get_value(
					'SESSION_COOKIE_SAME_SITE',
					$file_values,
					'Strict',
				),
			),
			'SESSION_COOKIE_SECURE'       => self::normalize_secure_mode(
				self::get_value( 'SESSION_COOKIE_SECURE', $file_values, 'auto' ),
			),
			'PEAKURL_OWNER_FALLBACK'      => self::get_bool_value(
				'PEAKURL_OWNER_FALLBACK',
				$file_values,
				false,
			),
			'PEAKURL_OWNER_FIRST_NAME'    => self::get_value(
				'PEAKURL_OWNER_FIRST_NAME',
				$file_values,
				'',
			),
			'PEAKURL_OWNER_LAST_NAME'     => self::get_value(
				'PEAKURL_OWNER_LAST_NAME',
				$file_values,
				'',
			),
			'PEAKURL_OWNER_USERNAME'      => self::get_value(
				'PEAKURL_OWNER_USERNAME',
				$file_values,
				'',
			),
			'PEAKURL_OWNER_EMAIL'         => self::get_value(
				'PEAKURL_OWNER_EMAIL',
				$file_values,
				'',
			),
			'PEAKURL_OWNER_PASSWORD'      => self::get_value(
				'PEAKURL_OWNER_PASSWORD',
				$file_values,
				'',
			),
			'PEAKURL_WORKSPACE_NAME'      => self::get_value(
				'PEAKURL_WORKSPACE_NAME',
				$file_values,
				'',
			),
			'PEAKURL_WORKSPACE_SLUG'      => self::get_value(
				'PEAKURL_WORKSPACE_SLUG',
				$file_values,
				'',
			),
		);
	}

	/**
	 * Parse a PHP config.php file that defines constants and $table_prefix.
	 *
	 * The file is included with require, and any defined() constants are
	 * extracted into a key-value map.
	 *
	 * @param string $file_path Absolute path to config.php.
	 * @return array<string, string> Constant name → string value pairs.
	 * @since 1.0.0
	 */
	private static function parse_config_file( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$table_prefix = '';

		require $file_path;

		$keys   = array(
			'PEAKURL_ENV',
			'PEAKURL_DEBUG',
			'SITE_URL',
			'PEAKURL_UPDATE_MANIFEST_URL',
			'PEAKURL_CONTENT_DIR',
			'PEAKURL_GEOIP_DB_PATH',
			'PEAKURL_MAXMIND_ACCOUNT_ID',
			'PEAKURL_MAXMIND_LICENSE_KEY',
			'PEAKURL_MAIL_DRIVER',
			'PEAKURL_SMTP_HOST',
			'PEAKURL_SMTP_PORT',
			'PEAKURL_SMTP_ENCRYPTION',
			'PEAKURL_SMTP_AUTH',
			'PEAKURL_SMTP_USERNAME',
			'PEAKURL_SMTP_PASSWORD',
			'DB_HOST',
			'DB_PORT',
			'DB_DATABASE',
			'DB_USERNAME',
			'DB_PASSWORD',
			'DB_CHARSET',
			'SESSION_COOKIE_NAME',
			'SESSION_LIFETIME',
			'SESSION_COOKIE_PATH',
			'SESSION_COOKIE_DOMAIN',
			'SESSION_COOKIE_SAME_SITE',
			'SESSION_COOKIE_SECURE',
			'PEAKURL_OWNER_FALLBACK',
			'PEAKURL_OWNER_FIRST_NAME',
			'PEAKURL_OWNER_LAST_NAME',
			'PEAKURL_OWNER_USERNAME',
			'PEAKURL_OWNER_EMAIL',
			'PEAKURL_OWNER_PASSWORD',
			'PEAKURL_WORKSPACE_NAME',
			'PEAKURL_WORKSPACE_SLUG',
		);
		$values = array();

		foreach ( $keys as $key ) {
			if ( defined( $key ) ) {
				$values[ $key ] = self::scalar_to_string( constant( $key ) );
			}
		}

		if ( is_string( $table_prefix ) && '' !== trim( $table_prefix ) ) {
			$values['DB_PREFIX'] = trim( $table_prefix );
		}

		return $values;
	}

	/**
	 * Parse a .env file into key-value string pairs.
	 *
	 * Lines starting with '#' are treated as comments and ignored. Quoted
	 * values (single or double) are automatically unquoted.
	 *
	 * @param string $file_path Absolute path to the .env file.
	 * @return array<string, string> Environment variable name → value pairs.
	 * @since 1.0.0
	 */
	private static function parse_env_file( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$values = array();
		$lines  = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $lines ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || String_Utils::starts_with( $line, '#' ) ) {
				continue;
			}

			$parts = explode( '=', $line, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$name  = trim( $parts[0] );
			$value = trim( $parts[1] );

			if (
				( String_Utils::starts_with( $value, '"' ) && String_Utils::ends_with( $value, '"' ) ) ||
				( String_Utils::starts_with( $value, '\'' ) && String_Utils::ends_with( $value, '\'' ) )
			) {
				$value = substr( $value, 1, -1 );
			}

			$values[ $name ] = $value;
		}

		return $values;
	}

	/**
	 * Resolve a single configuration value with full precedence chain.
	 *
	 * Checks getenv(), $_ENV, $_SERVER, file values, then falls back.
	 *
	 * @param string               $key         Configuration key name.
	 * @param array<string, string> $file_values Values parsed from config/env files.
	 * @param string               $fallback    Default when no source provides a value.
	 * @return string Resolved value.
	 * @since 1.0.0
	 */
	private static function get_value(
		string $key,
		array $file_values,
		string $fallback = ''
	): string {
		$runtime = getenv( $key );

		if ( false !== $runtime && '' !== $runtime ) {
			return (string) $runtime;
		}

		if ( isset( $_ENV[ $key ] ) && '' !== (string) $_ENV[ $key ] ) {
			return (string) $_ENV[ $key ];
		}

		if ( isset( $_SERVER[ $key ] ) && '' !== (string) $_SERVER[ $key ] ) {
			return (string) $_SERVER[ $key ];
		}

		if ( isset( $file_values[ $key ] ) && '' !== (string) $file_values[ $key ] ) {
			return (string) $file_values[ $key ];
		}

		return $fallback;
	}

	/**
	 * Resolve a boolean configuration value.
	 *
	 * Recognises '1', 'true', 'yes', and 'on' (case-insensitive) as true.
	 *
	 * @param string               $key         Configuration key name.
	 * @param array<string, string> $file_values Values parsed from config/env files.
	 * @param bool                 $fallback    Default when no source provides a value.
	 * @return bool Resolved boolean.
	 * @since 1.0.0
	 */
	private static function get_bool_value(
		string $key,
		array $file_values,
		bool $fallback
	): bool {
		$value = strtolower(
			self::get_value( $key, $file_values, $fallback ? 'true' : 'false' ),
		);

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Read the semver version string from the .version file.
	 *
	 * @param string $file_path Absolute path to the .version file.
	 * @return string Trimmed version string, or '0.0.0' when missing.
	 * @since 1.0.0
	 */
	private static function read_version_file( string $file_path ): string {
		if ( ! file_exists( $file_path ) ) {
			return '0.0.0';
		}

		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			return '0.0.0';
		}

		$version = trim( $contents );

		return '' !== $version ? $version : '0.0.0';
	}

	/**
	 * Strip scheme and path from a domain string for cookie use.
	 *
	 * @param string $value Raw domain or URL value.
	 * @return string Lowercased, bare domain.
	 * @since 1.0.0
	 */
	private static function sanitize_domain( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = self::replace_or_empty( '#^https?://#', '', $value );
		$value = self::replace_or_empty( '#/.*$#', '', $value );
		return $value;
	}

	/**
	 * Validate and normalise a database table prefix.
	 *
	 * Must start with a letter or underscore and contain only alphanumeric
	 * characters and underscores (max 25 characters).
	 *
	 * @param string $value Raw prefix input.
	 * @return string Trimmed, validated prefix (may be empty).
	 *
	 * @throws RuntimeException When the prefix contains invalid characters.
	 * @since 1.0.0
	 */
	public static function normalize_db_prefix( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,24}$/', $value ) ) {
			throw new RuntimeException(
				'DB_PREFIX must start with a letter or underscore and use only letters, numbers, and underscores.',
			);
		}

		return $value;
	}

	/**
	 * Normalise a SameSite cookie attribute value.
	 *
	 * @param string $value Raw SameSite input.
	 * @return string One of 'Lax', 'Strict', or 'None'.
	 * @since 1.0.0
	 */
	private static function normalize_same_site( string $value ): string {
		$value = ucfirst( strtolower( trim( $value ) ) );

		if ( in_array( $value, array( 'Lax', 'Strict', 'None' ), true ) ) {
			return $value;
		}

		return 'Strict';
	}

	/**
	 * Normalise the SESSION_COOKIE_SECURE mode string.
	 *
	 * @param string $value Raw secure-mode input.
	 * @return string One of the accepted mode literals, or 'auto'.
	 * @since 1.0.0
	 */
	private static function normalize_secure_mode( string $value ): string {
		$value = strtolower( trim( $value ) );

		if (
			in_array(
				$value,
				array( 'auto', 'true', 'false', '1', '0', 'yes', 'no', 'on', 'off' ),
				true,
			)
		) {
			return $value;
		}

		return 'auto';
	}

	/**
	 * Normalize the configured mail driver.
	 *
	 * @param string $value Raw mail driver value.
	 * @return string Supported driver value.
	 * @since 1.0.0
	 */
	private static function normalize_mail_driver( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, array( 'mail', 'smtp' ), true ) ) {
			return $value;
		}

		return 'mail';
	}

	/**
	 * Normalize the SMTP encryption mode.
	 *
	 * @param string $value Raw encryption value.
	 * @return string Supported encryption value.
	 * @since 1.0.0
	 */
	private static function normalize_smtp_encryption( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, array( '', 'none', 'ssl', 'tls' ), true ) ) {
			return 'none' === $value ? '' : $value;
		}

		return 'tls';
	}

	/**
	 * Resolve a filesystem path relative to the app directory.
	 *
	 * Absolute paths are returned unchanged. Relative paths are resolved
	 * against the provided base path.
	 *
	 * @param string $value     Raw path value.
	 * @param string $base_path Absolute app directory path.
	 * @return string Absolute or normalized relative path.
	 * @since 1.0.0
	 */
	private static function resolve_path_value(
		string $value,
		string $base_path
	): string {
		$path = trim( $value );

		if ( '' === $path ) {
			return '';
		}

		if (
			String_Utils::starts_with( $path, '/' ) ||
			preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path )
		) {
			return $path;
		}

		return rtrim( $base_path, DIRECTORY_SEPARATOR ) .
			DIRECTORY_SEPARATOR .
			ltrim( $path, DIRECTORY_SEPARATOR );
	}

	/**
	 * Run a regex replacement that returns '' on failure instead of null.
	 *
	 * @param string $pattern     PCRE pattern.
	 * @param string $replacement Replacement string.
	 * @param string $subject     Input string.
	 * @return string Result of the replacement.
	 * @since 1.0.0
	 */
	private static function replace_or_empty(
		string $pattern,
		string $replacement,
		string $subject
	): string {
		$result = preg_replace( $pattern, $replacement, $subject );

		return is_string( $result ) ? $result : '';
	}

	/**
	 * Cast a scalar constant value to its string representation.
	 *
	 * Booleans become 'true'/'false'; ints and floats are cast directly.
	 *
	 * @param mixed $value Value to cast.
	 * @return string String representation.
	 * @since 1.0.0
	 */
	private static function scalar_to_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return is_string( $value ) ? $value : '';
	}
}
