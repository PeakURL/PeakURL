<?php
/**
 * Release install runtime state service.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Database\SchemaSpecs;
use PeakURL\Includes\Connection;
use PeakURL\Includes\RuntimeConfig;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * State — runtime state detection for the release installer.
 *
 * @since 1.0.14
 */
class State {

	/** Runtime state: config.php is missing. */
	public const NEEDS_SETUP = 'needs_setup';

	/** Runtime state: config.php exists but tables or seed data are missing. */
	public const NEEDS_INSTALL = 'needs_install';

	/** Runtime state: the release is fully installed and ready. */
	public const READY = 'ready';

	/**
	 * Determine whether the release is fully installed and ready.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool
	 * @since 1.0.14
	 */
	public static function is_installed( string $app_path ): bool {
		return self::READY === self::get_runtime_state( $app_path );
	}

	/**
	 * Determine whether a runtime config.php file exists.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool
	 * @since 1.0.14
	 */
	public static function config_exists( string $app_path ): bool {
		return Writer::config_exists( $app_path );
	}

	/**
	 * Return the current install state for the release.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string
	 * @since 1.0.14
	 */
	public static function get_runtime_state( string $app_path ): string {
		if ( ! self::config_exists( $app_path ) ) {
			return self::NEEDS_SETUP;
		}

		try {
			$config     = RuntimeConfig::load( $app_path );
			$connection = new Connection( $config );

			foreach ( SchemaSpecs::managed_tables() as $table_name ) {
				if ( ! $connection->table_exists( $table_name ) ) {
					return self::NEEDS_INSTALL;
				}
			}

			$site_url = $connection->get_setting_value( 'site_url' );

			if ( ! is_string( $site_url ) || '' === trim( $site_url ) ) {
				return self::NEEDS_INSTALL;
			}

			if ( ! $connection->table_has_rows( 'users' ) ) {
				return self::NEEDS_INSTALL;
			}
		} catch ( \Throwable $exception ) {
			return self::NEEDS_SETUP;
		}

		return self::READY;
	}
}
