<?php
/**
 * Release installer bootstrap helpers.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Includes\Connection;
use PeakURL\Services\Database\Schema as DatabaseSchema;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Bootstrap — runtime helpers for schema creation and install-only values.
 *
 * @since 1.0.14
 */
class Bootstrap {

	/**
	 * Create the database schema from the bundled schema.sql file.
	 *
	 * @param array<string, mixed> $config   Runtime configuration with DB credentials.
	 * @param string               $app_path Absolute path to the app directory.
	 *
	 * @throws \RuntimeException When the schema cannot be created.
	 * @since 1.0.14
	 */
	public static function initialize_schema(
		array $config,
		string $app_path
	): void {
		$connection_manager = new Connection( $config );
		$schema_path        =
			rtrim( $app_path, DIRECTORY_SEPARATOR ) . '/database/schema.sql';
		$schema_service     = new DatabaseSchema(
			$connection_manager,
			$schema_path,
		);

		try {
			$schema_service->upgrade();
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException(
				__( 'Unable to connect to the database or create the PeakURL tables. ', 'peakurl' ) . $exception->getMessage(),
				0,
				$exception,
			);
		}
	}

	/**
	 * Build a typed runtime configuration array from flat config values.
	 *
	 * @param array<string, string> $values Flat config values.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public static function build_runtime_config( array $values ): array {
		return array(
			'PEAKURL_ENV'                 => $values['PEAKURL_ENV'],
			'SITE_URL'                    => $values['SITE_URL'],
			'PEAKURL_DEBUG'               => 'true' === $values['PEAKURL_DEBUG'],
			'PEAKURL_AUTH_KEY'            => $values['PEAKURL_AUTH_KEY'],
			'PEAKURL_AUTH_SALT'           => $values['PEAKURL_AUTH_SALT'],
			'PEAKURL_UPDATE_MANIFEST_URL' => $values['PEAKURL_UPDATE_MANIFEST_URL'],
			'PEAKURL_CONTENT_DIR'         => $values['PEAKURL_CONTENT_DIR'],
			'PEAKURL_GEOIP_DB_PATH'       => $values['PEAKURL_GEOIP_DB_PATH'],
			'DB_HOST'                     => $values['DB_HOST'],
			'DB_PORT'                     => (int) $values['DB_PORT'],
			'DB_DATABASE'                 => $values['DB_DATABASE'],
			'DB_USERNAME'                 => $values['DB_USERNAME'],
			'DB_PASSWORD'                 => $values['DB_PASSWORD'],
			'DB_CHARSET'                  => $values['DB_CHARSET'],
			'DB_PREFIX'                   => $values['DB_PREFIX'],
			'SESSION_COOKIE_NAME'         => $values['SESSION_COOKIE_NAME'],
			'SESSION_LIFETIME'            => (int) $values['SESSION_LIFETIME'],
			'SESSION_COOKIE_PATH'         => $values['SESSION_COOKIE_PATH'],
			'SESSION_COOKIE_DOMAIN'       => $values['SESSION_COOKIE_DOMAIN'],
			'SESSION_COOKIE_SAME_SITE'    => $values['SESSION_COOKIE_SAME_SITE'],
			'SESSION_COOKIE_SECURE'       => $values['SESSION_COOKIE_SECURE'],
			'PEAKURL_OWNER_FALLBACK'      => 'true' === $values['PEAKURL_OWNER_FALLBACK'],
			'PEAKURL_OWNER_FIRST_NAME'    => $values['PEAKURL_OWNER_FIRST_NAME'],
			'PEAKURL_OWNER_LAST_NAME'     => $values['PEAKURL_OWNER_LAST_NAME'],
			'PEAKURL_OWNER_USERNAME'      => $values['PEAKURL_OWNER_USERNAME'],
			'PEAKURL_OWNER_EMAIL'         => $values['PEAKURL_OWNER_EMAIL'],
			'PEAKURL_OWNER_PASSWORD'      => $values['PEAKURL_OWNER_PASSWORD'],
			'PEAKURL_SITE_LANGUAGE'       => $values['PEAKURL_SITE_LANGUAGE'],
			'PEAKURL_WORKSPACE_NAME'      => $values['PEAKURL_WORKSPACE_NAME'],
			'PEAKURL_WORKSPACE_SLUG'      => $values['PEAKURL_WORKSPACE_SLUG'],
		);
	}

	/**
	 * Strip install-only seed values from the final runtime config payload.
	 *
	 * @param array<string, string> $values Full install config values.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public static function get_release_values( array $values ): array {
		unset(
			$values['PEAKURL_OWNER_FALLBACK'],
			$values['PEAKURL_OWNER_FIRST_NAME'],
			$values['PEAKURL_OWNER_LAST_NAME'],
			$values['PEAKURL_OWNER_USERNAME'],
			$values['PEAKURL_OWNER_EMAIL'],
			$values['PEAKURL_OWNER_PASSWORD'],
			$values['PEAKURL_SITE_LANGUAGE'],
			$values['PEAKURL_WORKSPACE_NAME'],
			$values['PEAKURL_WORKSPACE_SLUG'],
		);

		return $values;
	}
}
