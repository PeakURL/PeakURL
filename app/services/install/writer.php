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

		foreach ( self::template_values( $values ) as $key => $value ) {
			$replacements[ self::template_token( $key ) ] = $value;
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
	public static function prepare_config_values( array $config ): array {
		return array(
			Constants::ENV                      => (string) ( $config[ Constants::ENV ] ?? 'production' ),
			Constants::DEBUG                    => ! empty( $config[ Constants::DEBUG ] ) ? 'true' : 'false',
			Constants::SITE_URL                 => (string) ( $config[ Constants::SITE_URL ] ?? '' ),
			Constants::AUTH_KEY                 => (string) ( $config[ Constants::AUTH_KEY ] ?? '' ),
			Constants::AUTH_SALT                => (string) ( $config[ Constants::AUTH_SALT ] ?? '' ),
			Constants::UPDATE_MANIFEST_URL      => (string) ( $config[ Constants::UPDATE_MANIFEST_URL ] ?? Constants::DEFAULT_UPDATE_MANIFEST_URL ),
			Constants::CONTENT_DIR              => (string) ( $config[ Constants::CONTENT_DIR ] ?? '' ),
			Constants::GEOIP_DB_PATH            => (string) ( $config[ Constants::GEOIP_DB_PATH ] ?? '' ),
			Constants::DB_HOST                  => (string) ( $config[ Constants::DB_HOST ] ?? 'localhost' ),
			Constants::DB_PORT                  => (string) ( $config[ Constants::DB_PORT ] ?? 3306 ),
			Constants::DB_DATABASE              => (string) ( $config[ Constants::DB_DATABASE ] ?? '' ),
			Constants::DB_USERNAME              => (string) ( $config[ Constants::DB_USERNAME ] ?? '' ),
			Constants::DB_PASSWORD              => (string) ( $config[ Constants::DB_PASSWORD ] ?? '' ),
			Constants::DB_CHARSET               => (string) ( $config[ Constants::DB_CHARSET ] ?? 'utf8mb4' ),
			Constants::DB_PREFIX                => (string) ( $config[ Constants::DB_PREFIX ] ?? 'peakurl_' ),
			Constants::SESSION_COOKIE_NAME      => (string) ( $config[ Constants::SESSION_COOKIE_NAME ] ?? Constants::DEFAULT_SESSION_COOKIE_NAME ),
			Constants::SESSION_LIFETIME         => (string) ( $config[ Constants::SESSION_LIFETIME ] ?? Constants::DEFAULT_SESSION_LIFETIME ),
			Constants::SESSION_COOKIE_PATH      => (string) ( $config[ Constants::SESSION_COOKIE_PATH ] ?? '/' ),
			Constants::SESSION_COOKIE_DOMAIN    => (string) ( $config[ Constants::SESSION_COOKIE_DOMAIN ] ?? '' ),
			Constants::SESSION_COOKIE_SAME_SITE => (string) ( $config[ Constants::SESSION_COOKIE_SAME_SITE ] ?? Constants::DEFAULT_SESSION_COOKIE_SAME_SITE ),
			Constants::SESSION_COOKIE_SECURE    => (string) ( $config[ Constants::SESSION_COOKIE_SECURE ] ?? Constants::DEFAULT_SESSION_COOKIE_SECURE ),
			Constants::OWNER_FALLBACK           => ! empty( $config[ Constants::OWNER_FALLBACK ] ) ? 'true' : 'false',
			Constants::OWNER_FIRST_NAME         => (string) ( $config[ Constants::OWNER_FIRST_NAME ] ?? '' ),
			Constants::OWNER_LAST_NAME          => (string) ( $config[ Constants::OWNER_LAST_NAME ] ?? '' ),
			Constants::OWNER_USERNAME           => (string) ( $config[ Constants::OWNER_USERNAME ] ?? '' ),
			Constants::OWNER_EMAIL              => (string) ( $config[ Constants::OWNER_EMAIL ] ?? '' ),
			Constants::OWNER_PASSWORD           => (string) ( $config[ Constants::OWNER_PASSWORD ] ?? '' ),
			Constants::WORKSPACE_NAME           => (string) ( $config[ Constants::WORKSPACE_NAME ] ?? '' ),
			Constants::WORKSPACE_SLUG           => (string) ( $config[ Constants::WORKSPACE_SLUG ] ?? '' ),
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
				$updated_lines[] = self::env_assignment(
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

			$updated_lines[] = self::env_assignment(
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
	private static function template_values( array $values ): array {
		return array(
			Constants::ENV                      => var_export( $values[ Constants::ENV ], true ),
			Constants::DEBUG                    => 'true' === $values[ Constants::DEBUG ] ? 'true' : 'false',
			Constants::SITE_URL                 => var_export( $values[ Constants::SITE_URL ], true ),
			Constants::AUTH_KEY                 => var_export(
				$values[ Constants::AUTH_KEY ],
				true,
			),
			Constants::AUTH_SALT                => var_export(
				$values[ Constants::AUTH_SALT ],
				true,
			),
			Constants::UPDATE_MANIFEST_URL      => var_export(
				$values[ Constants::UPDATE_MANIFEST_URL ],
				true,
			),
			Constants::CONTENT_DIR              => var_export(
				$values[ Constants::CONTENT_DIR ],
				true,
			),
			Constants::GEOIP_DB_PATH            => var_export(
				$values[ Constants::GEOIP_DB_PATH ],
				true,
			),
			Constants::DB_HOST                  => var_export( $values[ Constants::DB_HOST ], true ),
			Constants::DB_PORT                  => (string) (int) $values[ Constants::DB_PORT ],
			Constants::DB_DATABASE              => var_export( $values[ Constants::DB_DATABASE ], true ),
			Constants::DB_USERNAME              => var_export( $values[ Constants::DB_USERNAME ], true ),
			Constants::DB_PASSWORD              => var_export( $values[ Constants::DB_PASSWORD ], true ),
			Constants::DB_CHARSET               => var_export( $values[ Constants::DB_CHARSET ], true ),
			Constants::DB_PREFIX                => var_export( $values[ Constants::DB_PREFIX ], true ),
			Constants::SESSION_COOKIE_NAME      => var_export( $values[ Constants::SESSION_COOKIE_NAME ], true ),
			Constants::SESSION_LIFETIME         => (string) (int) $values[ Constants::SESSION_LIFETIME ],
			Constants::SESSION_COOKIE_PATH      => var_export( $values[ Constants::SESSION_COOKIE_PATH ], true ),
			Constants::SESSION_COOKIE_DOMAIN    => var_export( $values[ Constants::SESSION_COOKIE_DOMAIN ], true ),
			Constants::SESSION_COOKIE_SAME_SITE => var_export( $values[ Constants::SESSION_COOKIE_SAME_SITE ], true ),
			Constants::SESSION_COOKIE_SECURE    => var_export( $values[ Constants::SESSION_COOKIE_SECURE ], true ),
		);
	}

	/**
	 * Return the placeholder token used in config-sample.php.
	 *
	 * @param string $key Config key name.
	 * @return string
	 * @since 1.0.14
	 */
	private static function template_token( string $key ): string {
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
	private static function env_assignment( string $key, string $value ): string {
		return $key . '=' . self::env_value( $value );
	}

	/**
	 * Escape a value for safe .env storage.
	 *
	 * @param string $value Raw environment value.
	 * @return string
	 * @since 1.0.14
	 */
	private static function env_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^[A-Za-z0-9._\\/-]+$/', $value ) ) {
			return $value;
		}

		return '"' . addcslashes( $value, "\"\\\n\r\t\$" ) . '"';
	}
}
