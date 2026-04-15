<?php
/**
 * Release installer config writer helpers.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Writer — config.php and .env writing helpers for install-related flows.
 *
 * @since 1.0.14
 */
class Writer {

	/**
	 * Return the absolute path to the release root.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_release_root_path( string $app_path ): string {
		return dirname( rtrim( $app_path, DIRECTORY_SEPARATOR ) );
	}

	/**
	 * Determine whether config.php already exists.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool
	 * @since 1.0.14
	 */
	public static function config_exists( string $app_path ): bool {
		return file_exists( self::get_config_path( $app_path ) );
	}

	/**
	 * Return the absolute path to the generated config.php file.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_config_path( string $app_path ): string {
		return self::get_release_root_path( $app_path ) . '/config.php';
	}

	/**
	 * Return the absolute path to the bundled config-sample.php file.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_sample_path( string $app_path ): string {
		return self::get_release_root_path( $app_path ) . '/config-sample.php';
	}

	/**
	 * Write config.php from the sample template and the provided values.
	 *
	 * @param string                $app_path Absolute path to the app directory.
	 * @param array<string, string> $values   Config values to interpolate.
	 *
	 * @throws \RuntimeException On missing templates or write failures.
	 * @since 1.0.14
	 */
	public static function write_config_file(
		string $app_path,
		array $values
	): void {
		$sample_path = self::get_sample_path( $app_path );

		if ( ! file_exists( $sample_path ) ) {
			throw new \RuntimeException(
				__( 'config-sample.php is missing from the release package.', 'peakurl' ),
			);
		}

		$template = file_get_contents( $sample_path );

		if ( false === $template ) {
			throw new \RuntimeException(
				__( 'Unable to read config-sample.php from the release package.', 'peakurl' ),
			);
		}

		$replacements = array();

		foreach ( self::build_template_values( $values ) as $key => $value ) {
			$replacements[ self::get_template_token( $key ) ] = $value;
		}

		$config_contents = strtr( $template, $replacements );
		$config_path     = self::get_config_path( $app_path );

		if ( false === file_put_contents( $config_path, $config_contents, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'Unable to write config.php in the release root.', 'peakurl' ),
			);
		}
	}

	/**
	 * Convert a runtime config array into flat config.php value strings.
	 *
	 * @param array<string, mixed> $config Runtime configuration.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public static function build_config_values( array $config ): array {
		return array(
			'PEAKURL_ENV'                              => (string) ( $config['PEAKURL_ENV'] ?? 'production' ),
			'PEAKURL_DEBUG'                            => ! empty( $config['PEAKURL_DEBUG'] ) ? 'true' : 'false',
			'SITE_URL'                                 => (string) ( $config['SITE_URL'] ?? '' ),
			Constants::CONFIG_AUTH_KEY                 => (string) ( $config[ Constants::CONFIG_AUTH_KEY ] ?? '' ),
			Constants::CONFIG_AUTH_SALT                => (string) ( $config[ Constants::CONFIG_AUTH_SALT ] ?? '' ),
			Constants::CONFIG_UPDATE_MANIFEST_URL      => (string) ( $config[ Constants::CONFIG_UPDATE_MANIFEST_URL ] ?? Constants::DEFAULT_UPDATE_MANIFEST_URL ),
			'PEAKURL_CONTENT_DIR'                      => (string) ( $config['PEAKURL_CONTENT_DIR'] ?? '' ),
			'PEAKURL_GEOIP_DB_PATH'                    => (string) ( $config['PEAKURL_GEOIP_DB_PATH'] ?? '' ),
			'DB_HOST'                                  => (string) ( $config['DB_HOST'] ?? 'localhost' ),
			'DB_PORT'                                  => (string) ( $config['DB_PORT'] ?? 3306 ),
			'DB_DATABASE'                              => (string) ( $config['DB_DATABASE'] ?? '' ),
			'DB_USERNAME'                              => (string) ( $config['DB_USERNAME'] ?? '' ),
			'DB_PASSWORD'                              => (string) ( $config['DB_PASSWORD'] ?? '' ),
			'DB_CHARSET'                               => (string) ( $config['DB_CHARSET'] ?? 'utf8mb4' ),
			'DB_PREFIX'                                => (string) ( $config['DB_PREFIX'] ?? 'peakurl_' ),
			Constants::CONFIG_SESSION_COOKIE_NAME      => (string) ( $config[ Constants::CONFIG_SESSION_COOKIE_NAME ] ?? Constants::DEFAULT_SESSION_COOKIE_NAME ),
			Constants::CONFIG_SESSION_LIFETIME         => (string) ( $config[ Constants::CONFIG_SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			'SESSION_COOKIE_PATH'                      => (string) ( $config['SESSION_COOKIE_PATH'] ?? '/' ),
			'SESSION_COOKIE_DOMAIN'                    => (string) ( $config['SESSION_COOKIE_DOMAIN'] ?? '' ),
			Constants::CONFIG_SESSION_COOKIE_SAME_SITE => (string) ( $config[ Constants::CONFIG_SESSION_COOKIE_SAME_SITE ] ?? Constants::DEFAULT_SESSION_COOKIE_SAME_SITE ),
			Constants::CONFIG_SESSION_COOKIE_SECURE    => (string) ( $config[ Constants::CONFIG_SESSION_COOKIE_SECURE ] ?? Constants::DEFAULT_SESSION_COOKIE_SECURE ),
			'PEAKURL_OWNER_FALLBACK'                   => ! empty( $config['PEAKURL_OWNER_FALLBACK'] ) ? 'true' : 'false',
			'PEAKURL_OWNER_FIRST_NAME'                 => (string) ( $config['PEAKURL_OWNER_FIRST_NAME'] ?? '' ),
			'PEAKURL_OWNER_LAST_NAME'                  => (string) ( $config['PEAKURL_OWNER_LAST_NAME'] ?? '' ),
			'PEAKURL_OWNER_USERNAME'                   => (string) ( $config['PEAKURL_OWNER_USERNAME'] ?? '' ),
			'PEAKURL_OWNER_EMAIL'                      => (string) ( $config['PEAKURL_OWNER_EMAIL'] ?? '' ),
			'PEAKURL_OWNER_PASSWORD'                   => (string) ( $config['PEAKURL_OWNER_PASSWORD'] ?? '' ),
			'PEAKURL_WORKSPACE_NAME'                   => (string) ( $config['PEAKURL_WORKSPACE_NAME'] ?? '' ),
			'PEAKURL_WORKSPACE_SLUG'                   => (string) ( $config['PEAKURL_WORKSPACE_SLUG'] ?? '' ),
		);
	}

	/**
	 * Upsert a managed set of assignments into an .env file.
	 *
	 * @param string                $env_path       Absolute path to the .env file.
	 * @param array<string, string> $managed_values Key-value pairs managed by PeakURL.
	 * @param string                $error_message  Exception message for write failures.
	 * @param string                $header_comment Optional comment added to new files.
	 * @return void
	 * @since 1.0.14
	 */
	public static function write_env_overrides(
		string $env_path,
		array $managed_values,
		string $error_message,
		string $header_comment = ''
	): void {
		$directory = dirname( $env_path );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( $error_message );
		}

		$managed_keys   = array_keys( $managed_values );
		$existing_lines = array();

		if ( file_exists( $env_path ) ) {
			$read_lines = file( $env_path, FILE_IGNORE_NEW_LINES );

			if ( false === $read_lines ) {
				throw new \RuntimeException( $error_message );
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
			throw new \RuntimeException( $error_message );
		}
	}

	/**
	 * Convert config values into PHP-exportable template values.
	 *
	 * @param array<string, string> $values Flat config values.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	private static function build_template_values( array $values ): array {
		return array(
			'PEAKURL_ENV'                              => var_export( $values['PEAKURL_ENV'], true ),
			'PEAKURL_DEBUG'                            => 'true' === $values['PEAKURL_DEBUG'] ? 'true' : 'false',
			'SITE_URL'                                 => var_export( $values['SITE_URL'], true ),
			Constants::CONFIG_AUTH_KEY                 => var_export(
				$values[ Constants::CONFIG_AUTH_KEY ],
				true,
			),
			Constants::CONFIG_AUTH_SALT                => var_export(
				$values[ Constants::CONFIG_AUTH_SALT ],
				true,
			),
			Constants::CONFIG_UPDATE_MANIFEST_URL      => var_export(
				$values[ Constants::CONFIG_UPDATE_MANIFEST_URL ],
				true,
			),
			'PEAKURL_CONTENT_DIR'                      => var_export(
				$values['PEAKURL_CONTENT_DIR'],
				true,
			),
			'PEAKURL_GEOIP_DB_PATH'                    => var_export(
				$values['PEAKURL_GEOIP_DB_PATH'],
				true,
			),
			'DB_HOST'                                  => var_export( $values['DB_HOST'], true ),
			'DB_PORT'                                  => (string) (int) $values['DB_PORT'],
			'DB_DATABASE'                              => var_export( $values['DB_DATABASE'], true ),
			'DB_USERNAME'                              => var_export( $values['DB_USERNAME'], true ),
			'DB_PASSWORD'                              => var_export( $values['DB_PASSWORD'], true ),
			'DB_CHARSET'                               => var_export( $values['DB_CHARSET'], true ),
			'DB_PREFIX'                                => var_export( $values['DB_PREFIX'], true ),
			Constants::CONFIG_SESSION_COOKIE_NAME      => var_export( $values[ Constants::CONFIG_SESSION_COOKIE_NAME ], true ),
			Constants::CONFIG_SESSION_LIFETIME         => (string) (int) $values[ Constants::CONFIG_SESSION_LIFETIME ],
			'SESSION_COOKIE_PATH'                      => var_export( $values['SESSION_COOKIE_PATH'], true ),
			'SESSION_COOKIE_DOMAIN'                    => var_export( $values['SESSION_COOKIE_DOMAIN'], true ),
			Constants::CONFIG_SESSION_COOKIE_SAME_SITE => var_export( $values[ Constants::CONFIG_SESSION_COOKIE_SAME_SITE ], true ),
			Constants::CONFIG_SESSION_COOKIE_SECURE    => var_export( $values[ Constants::CONFIG_SESSION_COOKIE_SECURE ], true ),
		);
	}

	/**
	 * Return the placeholder token used in config-sample.php.
	 *
	 * @param string $key Config key name.
	 * @return string
	 * @since 1.0.14
	 */
	private static function get_template_token( string $key ): string {
		return '__' . $key . '__';
	}

	/**
	 * Format a single .env assignment line.
	 *
	 * @param string $key   Environment key.
	 * @param string $value Environment value.
	 * @return string
	 * @since 1.0.14
	 */
	private static function format_env_assignment( string $key, string $value ): string {
		return $key . '=' . self::format_env_value( $value );
	}

	/**
	 * Escape a value for safe .env storage.
	 *
	 * @param string $value Raw environment value.
	 * @return string
	 * @since 1.0.14
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
