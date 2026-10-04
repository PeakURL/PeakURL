<?php
/**
 * Release installation state service.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Configuration;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * InstallationState — installation state detection for the release package.
 *
 * @since 1.0.14
 */
class InstallationState {

	/** Installation state: config.php is missing. */
	public const NOT_CONFIGURED = 'not_configured';

	/** Installation state: config.php exists but tables or initial setup data are missing. */
	public const NOT_INSTALLED = 'not_installed';

	/** Installation state: config.php exists but its database connection or configuration fails. */
	public const DATABASE_UNAVAILABLE = 'database_unavailable';

	/** Installation state: the installation is established and ready. */
	public const READY = 'ready';

	/**
	 * Determine whether the release has an established installation.
	 *
	 * Returns true when the installation identity is confirmed (config exists,
	 * core database tables are present, and site URL is configured). Schema
	 * evolution is decoupled from installation identity and handled
	 * independently.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool
	 * @since 1.0.14
	 */
	public static function is_installed( string $app_path ): bool {
		return self::READY === self::get_state( $app_path );
	}

	/**
	 * Determine whether a release config.php file exists.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool
	 * @since 1.0.14
	 */
	public static function config_exists( string $app_path ): bool {
		return Writer::config_exists( $app_path );
	}

	/**
	 * Return the current installation state for the release.
	 *
	 * Determines installation identity based on the presence of core
	 * database tables, configured site URL, and administrative users.
	 * Schema evolution is strictly separated from installation identity:
	 * this method performs no schema mutations or DDL. Runtime bootstrap
	 * and update workflows handle schema convergence independently through
	 * the shared schema service.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_state( string $app_path ): string {
		if ( ! self::config_exists( $app_path ) ) {
			return self::NOT_CONFIGURED;
		}

		if ( ! Configuration::has_database_configuration( $app_path ) ) {
			return self::DATABASE_UNAVAILABLE;
		}

		try {
			$config     = Configuration::load( $app_path );
			$connection = new Connection( $config );

			if ( ! $connection->table_exists( 'settings' ) || ! $connection->table_exists( 'users' ) ) {
				return self::NOT_INSTALLED;
			}

			$settings_api = new SettingsApi( new PeakURL_DB( $connection ) );
			$site_url     = $settings_api->get_option( 'site_url' );

			if ( ! is_string( $site_url ) || '' === trim( $site_url ) ) {
				return self::NOT_INSTALLED;
			}

			if ( ! $connection->table_has_rows( 'users' ) ) {
				return self::NOT_INSTALLED;
			}
		} catch ( \Throwable $exception ) {
			return self::DATABASE_UNAVAILABLE;
		}

		return self::READY;
	}
}
