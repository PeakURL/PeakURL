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
use PeakURL\Includes\Constants;
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
	 * Get a typed runtime configuration array from flat config values.
	 *
	 * @param array<string, string> $values Flat config values.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public static function prepare_config( array $values ): array {
		return array(
			Constants::ENV                      => $values[ Constants::ENV ],
			Constants::SITE_URL                 => $values[ Constants::SITE_URL ],
			Constants::DEBUG                    => 'true' === $values[ Constants::DEBUG ],
			Constants::AUTH_KEY                 => $values[ Constants::AUTH_KEY ],
			Constants::AUTH_SALT                => $values[ Constants::AUTH_SALT ],
			Constants::UPDATE_MANIFEST_URL      => $values[ Constants::UPDATE_MANIFEST_URL ],
			Constants::CONTENT_DIR              => $values[ Constants::CONTENT_DIR ],
			Constants::GEOIP_DB_PATH            => $values[ Constants::GEOIP_DB_PATH ],
			Constants::DB_HOST                  => $values[ Constants::DB_HOST ],
			Constants::DB_PORT                  => (int) $values[ Constants::DB_PORT ],
			Constants::DB_DATABASE              => $values[ Constants::DB_DATABASE ],
			Constants::DB_USERNAME              => $values[ Constants::DB_USERNAME ],
			Constants::DB_PASSWORD              => $values[ Constants::DB_PASSWORD ],
			Constants::DB_CHARSET               => $values[ Constants::DB_CHARSET ],
			Constants::DB_PREFIX                => $values[ Constants::DB_PREFIX ],
			Constants::SESSION_COOKIE_NAME      => $values[ Constants::SESSION_COOKIE_NAME ],
			Constants::SESSION_LIFETIME         => (int) $values[ Constants::SESSION_LIFETIME ],
			Constants::SESSION_COOKIE_PATH      => $values[ Constants::SESSION_COOKIE_PATH ],
			Constants::SESSION_COOKIE_DOMAIN    => $values[ Constants::SESSION_COOKIE_DOMAIN ],
			Constants::SESSION_COOKIE_SAME_SITE => $values[ Constants::SESSION_COOKIE_SAME_SITE ],
			Constants::SESSION_COOKIE_SECURE    => $values[ Constants::SESSION_COOKIE_SECURE ],
			Constants::OWNER_FALLBACK           => 'true' === $values[ Constants::OWNER_FALLBACK ],
			Constants::OWNER_FIRST_NAME         => $values[ Constants::OWNER_FIRST_NAME ],
			Constants::OWNER_LAST_NAME          => $values[ Constants::OWNER_LAST_NAME ],
			Constants::OWNER_USERNAME           => $values[ Constants::OWNER_USERNAME ],
			Constants::OWNER_EMAIL              => $values[ Constants::OWNER_EMAIL ],
			Constants::OWNER_PASSWORD           => $values[ Constants::OWNER_PASSWORD ],
			Constants::SITE_LANGUAGE            => $values[ Constants::SITE_LANGUAGE ],
			Constants::WORKSPACE_NAME           => $values[ Constants::WORKSPACE_NAME ],
			Constants::WORKSPACE_SLUG           => $values[ Constants::WORKSPACE_SLUG ],
		);
	}

	/**
	 * Remove temporary install values from the final runtime config payload.
	 *
	 * @param array<string, string> $values Full install config values.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public static function prepare_release_values( array $values ): array {
		foreach ( Constants::INSTALL_KEYS as $key ) {
			unset( $values[ $key ] );
		}

		return $values;
	}
}
