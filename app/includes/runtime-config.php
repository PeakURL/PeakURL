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

namespace PeakURL\Includes;

use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Immutable runtime configuration reader.
 *
 * Precedence (highest → lowest): runtime environment variables, .env file,
 * config.php constants, built-in defaults.
 *
 * @since 1.0.0
 */
class RuntimeConfig {

	/**
	 * Load runtime configuration and apply runtime-side bootstrapping.
	 *
	 * @param string $base_path Absolute path to the PHP runtime directory.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function bootstrap( string $base_path ): array {
		$config = self::load( $base_path );
		self::bootstrap_debug_mode( $config );
		return $config;
	}

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
		$site_url    = self::get_value(
			Constants::SITE_URL,
			$file_values,
			'http://localhost:5173',
		);

		return array(
			Constants::VERSION                  => self::read_version_file(
				$root_path . '/' . Constants::VERSION_FILE,
			),
			Constants::ENV                      => self::get_value(
				Constants::ENV,
				$file_values,
				'development',
			),
			Constants::SITE_URL                 => self::get_value(
				Constants::SITE_URL,
				$file_values,
				$site_url,
			),
			Constants::AUTH_KEY                 => self::get_value(
				Constants::AUTH_KEY,
				$file_values,
				'',
			),
			Constants::AUTH_SALT                => self::get_value(
				Constants::AUTH_SALT,
				$file_values,
				'',
			),
			Constants::UPDATE_MANIFEST_URL      => self::get_value(
				Constants::UPDATE_MANIFEST_URL,
				$file_values,
				Constants::DEFAULT_UPDATE_MANIFEST_URL,
			),
			Constants::CONTENT_DIR              => self::get_path_value(
				self::get_value(
					Constants::CONTENT_DIR,
					$file_values,
					Constants::DEFAULT_CONTENT_DIR,
				),
				$root_path,
			),
			Constants::GEOIP_DB_PATH            => self::get_path_value(
				self::get_value(
					Constants::GEOIP_DB_PATH,
					$file_values,
					Constants::DEFAULT_GEOIP_DB_PATH,
				),
				$root_path,
			),
			Constants::DEBUG                    => self::get_bool_value(
				Constants::DEBUG,
				$file_values,
				false,
			),
			Constants::DB_HOST                  => self::get_value(
				Constants::DB_HOST,
				$file_values,
				'127.0.0.1',
			),
			Constants::DB_PORT                  => (int) self::get_value(
				Constants::DB_PORT,
				$file_values,
				'3306',
			),
			Constants::DB_DATABASE              => self::normalize_db_name(
				self::get_value(
					Constants::DB_DATABASE,
					$file_values,
					'peakurl',
				),
			),
			Constants::DB_USERNAME              => self::get_value(
				Constants::DB_USERNAME,
				$file_values,
				'root',
			),
			Constants::DB_PASSWORD              => self::get_value(
				Constants::DB_PASSWORD,
				$file_values,
				'',
			),
			Constants::DB_CHARSET               => self::get_value(
				Constants::DB_CHARSET,
				$file_values,
				'utf8mb4',
			),
			Constants::DB_PREFIX                => self::normalize_db_prefix(
				self::get_value( Constants::DB_PREFIX, $file_values, '' ),
			),
			Constants::SESSION_COOKIE_NAME      => self::get_value(
				Constants::SESSION_COOKIE_NAME,
				$file_values,
				Constants::DEFAULT_SESSION_COOKIE_NAME,
			),
			Constants::SESSION_LIFETIME         => (int) self::get_value(
				Constants::SESSION_LIFETIME,
				$file_values,
				(string) Constants::DEFAULT_SESSION_LIFETIME,
			),
			Constants::SESSION_COOKIE_PATH      => self::get_value(
				Constants::SESSION_COOKIE_PATH,
				$file_values,
				self::default_cookie_path( $site_url ),
			),
			Constants::SESSION_COOKIE_DOMAIN    => self::sanitize_domain(
				self::get_value( Constants::SESSION_COOKIE_DOMAIN, $file_values, '' ),
			),
			Constants::SESSION_COOKIE_SAME_SITE => self::normalize_same_site(
				self::get_value(
					Constants::SESSION_COOKIE_SAME_SITE,
					$file_values,
					Constants::DEFAULT_SESSION_COOKIE_SAME_SITE,
				),
			),
			Constants::SESSION_COOKIE_SECURE    => self::normalize_secure_mode(
				self::get_value(
					Constants::SESSION_COOKIE_SECURE,
					$file_values,
					Constants::DEFAULT_SESSION_COOKIE_SECURE,
				),
			),
			Constants::OWNER_FALLBACK           => self::get_bool_value(
				Constants::OWNER_FALLBACK,
				$file_values,
				false,
			),
			Constants::OWNER_FIRST_NAME         => self::get_value(
				Constants::OWNER_FIRST_NAME,
				$file_values,
				'',
			),
			Constants::OWNER_LAST_NAME          => self::get_value(
				Constants::OWNER_LAST_NAME,
				$file_values,
				'',
			),
			Constants::OWNER_USERNAME           => self::get_value(
				Constants::OWNER_USERNAME,
				$file_values,
				'',
			),
			Constants::OWNER_EMAIL              => self::get_value(
				Constants::OWNER_EMAIL,
				$file_values,
				'',
			),
			Constants::OWNER_PASSWORD           => self::get_value(
				Constants::OWNER_PASSWORD,
				$file_values,
				'',
			),
			Constants::SITE_LANGUAGE            => self::get_value(
				Constants::SITE_LANGUAGE,
				$file_values,
				'',
			),
			Constants::WORKSPACE_NAME           => self::get_value(
				Constants::WORKSPACE_NAME,
				$file_values,
				'',
			),
			Constants::WORKSPACE_SLUG           => self::get_value(
				Constants::WORKSPACE_SLUG,
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

		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			return array();
		}

		$keys   = array_merge( Constants::RUNTIME_KEYS, Constants::INSTALL_KEYS );
		$values = array();

		foreach ( $keys as $key ) {
			$parsed = self::extract_defined_value( $contents, $key );

			if ( null !== $parsed ) {
				$values[ $key ] = self::scalar_to_string( $parsed );
			}
		}

		$table_prefix = self::extract_assigned_value( $contents, 'table_prefix' );

		if ( is_string( $table_prefix ) && '' !== trim( $table_prefix ) ) {
			$values[ Constants::DB_PREFIX ] = trim( $table_prefix );
		}

		return $values;
	}

	/**
	 * Extract a scalar literal from a define( 'KEY', value ); statement.
	 *
	 * @param string $contents Raw config.php contents.
	 * @param string $key      Constant name to extract.
	 * @return bool|float|int|string|null
	 * @since 1.0.0
	 */
	private static function extract_defined_value( string $contents, string $key ) {
		$pattern = sprintf(
			'/define\s*\(\s*[\'"]%s[\'"]\s*,\s*(.*?)\s*\)\s*;/s',
			preg_quote( $key, '/' ),
		);

		if ( ! preg_match( $pattern, $contents, $matches ) ) {
			return null;
		}

		return self::parse_scalar_literal( trim( (string) $matches[1] ) );
	}

	/**
	 * Extract a scalar literal from a variable assignment.
	 *
	 * @param string $contents Raw config.php contents.
	 * @param string $name     Variable name without the leading dollar sign.
	 * @return bool|float|int|string|null
	 * @since 1.0.0
	 */
	private static function extract_assigned_value( string $contents, string $name ) {
		$pattern = sprintf(
			'/\$%s\s*=\s*(.*?)\s*;/s',
			preg_quote( $name, '/' ),
		);

		if ( ! preg_match( $pattern, $contents, $matches ) ) {
			return null;
		}

		return self::parse_scalar_literal( trim( (string) $matches[1] ) );
	}

	/**
	 * Parse a simple PHP scalar literal from generated config.php content.
	 *
	 * Supports booleans, integers, floats, and quoted strings. This keeps
	 * config.php readable without executing it as runtime data input.
	 *
	 * @param string $literal Raw literal expression.
	 * @return bool|float|int|string|null
	 * @since 1.0.0
	 */
	private static function parse_scalar_literal( string $literal ) {
		if ( '' === $literal ) {
			return null;
		}

		$lower_literal = strtolower( $literal );

		if ( 'true' === $lower_literal ) {
			return true;
		}

		if ( 'false' === $lower_literal ) {
			return false;
		}

		if ( preg_match( '/^-?\d+$/', $literal ) ) {
			return (int) $literal;
		}

		if ( preg_match( '/^-?\d+\.\d+$/', $literal ) ) {
			return (float) $literal;
		}

		$quote = substr( $literal, 0, 1 );

		if (
			( '\'' === $quote || '"' === $quote ) &&
			substr( $literal, -1 ) === $quote
		) {
			$value = substr( $literal, 1, -1 );

			if ( '\'' === $quote ) {
				return strtr(
					$value,
					array(
						'\\\\' => '\\',
						'\\\'' => '\'',
					),
				);
			}

			return stripcslashes( $value );
		}

		return null;
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

			if ( '' === $line || Str::starts_with( $line, '#' ) ) {
				continue;
			}

			$parts = explode( '=', $line, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$name  = trim( $parts[0] );
			$value = trim( $parts[1] );

			if (
				( Str::starts_with( $value, '"' ) && Str::ends_with( $value, '"' ) ) ||
				( Str::starts_with( $value, '\'' ) && Str::ends_with( $value, '\'' ) )
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
	 * @return string Trimmed version string, or the default version when missing.
	 * @since 1.0.0
	 */
	private static function read_version_file( string $file_path ): string {
		if ( ! file_exists( $file_path ) ) {
			return Constants::DEFAULT_VERSION;
		}

		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			return Constants::DEFAULT_VERSION;
		}

		$version = trim( $contents );

		return '' !== $version ? $version : Constants::DEFAULT_VERSION;
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
	 * @throws \RuntimeException When the prefix contains invalid characters.
	 * @since 1.0.0
	 */
	public static function normalize_db_prefix( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,24}$/', $value ) ) {
			throw new \RuntimeException(
				__( 'DB_PREFIX must start with a letter or underscore and use only letters, numbers, and underscores.', 'peakurl' ),
			);
		}

		return $value;
	}

	/**
	 * Validate and normalise a database name.
	 *
	 * @param string $value Raw database name.
	 * @return string Valid database name.
	 *
	 * @throws \RuntimeException When the database name is invalid.
	 * @since 1.1.1
	 */
	public static function normalize_db_name( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_]+$/', $value ) ) {
			throw new \RuntimeException(
				__( 'Database name may contain only letters, numbers, and underscores.', 'peakurl' ),
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
		$value = ucfirst( sanitize_key( $value ) );

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
		$value = sanitize_key( $value );

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
	 * Derive the default session cookie path from the configured site URL.
	 *
	 * Root installs use '/', while subdirectory installs keep the install path
	 * so dashboard cookies do not leak to unrelated apps on the same host.
	 *
	 * @param string $site_url Normalized site URL.
	 * @return string
	 * @since 1.0.0
	 */
	private static function default_cookie_path( string $site_url ): string {
		$path = parse_url( $site_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return '/';
		}

		return trailingslashit( $path );
	}

	/**
	 * Enable debug logging into the persistent content directory when requested.
	 *
	 * @param array<string, mixed> $config Merged runtime configuration.
	 * @return void
	 * @since 1.0.0
	 */
	private static function bootstrap_debug_mode( array $config ): void {
		if ( empty( $config[ Constants::DEBUG ] ) ) {
			return;
		}

		error_reporting( E_ALL );
		ini_set( 'display_errors', '0' );
		ini_set( 'log_errors', '1' );

		$content_dir = trim(
			(string) ( $config[ Constants::CONTENT_DIR ] ?? '' ),
		);

		if ( '' === $content_dir ) {
			return;
		}

		if ( ! is_dir( $content_dir ) ) {
			mkdir( $content_dir, 0755, true );
		}

		if ( ! is_dir( $content_dir ) ) {
			return;
		}

		$log_path = rtrim( $content_dir, DIRECTORY_SEPARATOR ) .
			DIRECTORY_SEPARATOR .
			Constants::DEBUG_LOG_FILE;

		ini_set( 'error_log', $log_path );
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
	private static function get_path_value(
		string $value,
		string $base_path
	): string {
		$path = trim( $value );

		if ( '' === $path ) {
			return '';
		}

		if (
			Str::starts_with( $path, '/' ) ||
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
