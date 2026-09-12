<?php
/**
 * Release installer initialization helpers.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Services\I18n;
use PeakURL\Services\Mailer;
use PeakURL\Services\Notifications;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Initializer — runtime helpers for schema creation and install-only values.
 *
 * @since 1.0.14
 */
class Initializer {

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
		$schema_path        = \PeakURL\Core\Config\Environment::get_instance()->get_database_schema_path();
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
	 * Initialize the site on first request or database setup.
	 *
	 * Ensures the database tables exist, creates the owner admin user
	 * from install-time config values when absent, and synchronizes
	 * setup settings. Runs inside a transaction and is idempotent.
	 *
	 * @param Connection           $connection Connection manager.
	 * @param array<string, mixed> $config     Runtime config map.
	 * @param I18n|null            $i18n       Optional I18n service.
	 * @throws ApiException When tables are missing or install is incomplete.
	 * @throws \RuntimeException When the owner row cannot be created.
	 * @since 1.0.0
	 */
	public static function bootstrap_site(
		Connection $connection,
		array $config,
		?I18n $i18n = null
	): void {
		static $bootstrapped = false;
		if ( $bootstrapped ) {
			return;
		}

		$schema_path = \PeakURL\Core\Config\Environment::get_instance()->get_database_schema_path();
		$schema      = new DatabaseSchema( $connection, $schema_path );

		try {
			$schema->repair_schema();
		} catch ( \Throwable $exception ) {
			throw new ApiException(
				__(
					'PeakURL could not finish the database upgrade. Verify the database user can alter tables, then retry.',
					'peakurl'
				),
				500
			);
		}

		if ( $i18n ) {
			$i18n->prepare_languages_dir();
		}

		$db_prefix = (string) ( $config[ Constants::DB_PREFIX ] ?? '' );
		$db        = new PeakURL_DB( $connection, $db_prefix );

		if ( ! $db->table_exists( 'users' ) ) {
			$setup_command = \PeakURL\Core\Config\Environment::get_instance()->is_development()
				? 'php server/bin/setup-database.php'
				: 'php bin/setup-database.php';

			throw new ApiException(
				sprintf(
					/* translators: %s: CLI setup command */
					__(
						'Database tables are missing. Run the installer or `%s` inside the PHP runtime directory.',
						'peakurl'
					),
					$setup_command
				),
				500
			);
		}

		$db->begin_transaction();

		try {
			$owner = $db->get_row(
				'SELECT * FROM users WHERE role = :role ORDER BY id ASC LIMIT 1',
				array( 'role' => 'admin' )
			);

			if ( ! $owner ) {
				$has_install_data = '' !== trim( (string) ( $config[ Constants::OWNER_USERNAME ] ?? '' ) ) &&
					'' !== trim( (string) ( $config[ Constants::OWNER_EMAIL ] ?? '' ) ) &&
					'' !== trim( (string) ( $config[ Constants::OWNER_PASSWORD ] ?? '' ) );

				if ( ! $has_install_data ) {
					throw new ApiException(
						__(
							'PeakURL is not installed yet. Run install.php to finish setup.',
							'peakurl'
						),
						503
					);
				}

				$now = Date::now();

				$db->insert(
					'users',
					array(
						'username'          => (string) $config[ Constants::OWNER_USERNAME ],
						'email'             => (string) $config[ Constants::OWNER_EMAIL ],
						'first_name'        => (string) $config[ Constants::OWNER_FIRST_NAME ],
						'last_name'         => (string) $config[ Constants::OWNER_LAST_NAME ],
						'password_hash'     => password_hash(
							(string) $config[ Constants::OWNER_PASSWORD ],
							PASSWORD_DEFAULT
						),
						'role'              => 'admin',
						'is_email_verified' => 1,
						'email_verified_at' => $now,
						'company'           => 'PeakURL',
						'bio'               => 'PeakURL site owner.',
						'created_at'        => $now,
						'updated_at'        => $now,
					)
				);

				$owner = $db->get_row(
					'SELECT * FROM users WHERE id = :id',
					array( 'id' => $db->insert_id() )
				);
			}

			if ( ! $owner ) {
				throw new \RuntimeException(
					'Failed to bootstrap the site owner.'
				);
			}

			self::save_install_options( $db, $config );

			$db->commit();
			$bootstrapped = true;
		} catch ( \Throwable $exception ) {
			if ( $db->in_transaction() ) {
				$db->roll_back();
			}

			throw $exception;
		}
	}

	/**
	 * Save install-time options into the settings table.
	 *
	 * @param PeakURL_DB           $db     Database wrapper.
	 * @param array<string, mixed> $config Runtime config map.
	 * @return void
	 * @since 1.0.0
	 */
	public static function save_install_options( PeakURL_DB $db, array $config ): void {
		if ( ! $db->table_exists( 'settings' ) ) {
			return;
		}

		$settings_api = new SettingsApi( $db );
		$site_name    = trim( (string) ( $config[ Constants::WORKSPACE_NAME ] ?? '' ) );
		$site_slug    = trim( (string) ( $config[ Constants::WORKSPACE_SLUG ] ?? '' ) );
		$site_url     = trim( (string) ( $config[ Constants::SITE_URL ] ?? '' ) );
		$admin_email  = trim( (string) ( $config[ Constants::OWNER_EMAIL ] ?? '' ) );
		$version      = trim( (string) ( $config[ Constants::VERSION ] ?? '' ) );
		$manifest_url = trim( (string) ( $config[ Constants::UPDATE_MANIFEST_URL ] ?? '' ) );
		$now          = Date::now();

		if ( '' !== $site_name && null === $settings_api->get_option( 'site_name' ) ) {
			$settings_api->update_option( 'site_name', $site_name, $now );
		}
		if ( '' !== $site_slug && null === $settings_api->get_option( 'site_slug' ) ) {
			$settings_api->update_option( 'site_slug', $site_slug, $now );
		}
		if ( '' !== $site_url && null === $settings_api->get_option( 'site_url' ) ) {
			$settings_api->update_option( 'site_url', $site_url, $now );
		}
		if ( '' !== $admin_email && null === $settings_api->get_option( 'admin_email' ) ) {
			$settings_api->update_option( 'admin_email', $admin_email, $now );
		}
		if ( '' !== $version && null === $settings_api->get_option( 'installed_version' ) ) {
			$settings_api->update_option( 'installed_version', $version, $now );
		}
		if ( '' !== $manifest_url && null === $settings_api->get_option( 'update_manifest_url' ) ) {
			$settings_api->update_option( 'update_manifest_url', $manifest_url, $now );
		}
		if ( null === $settings_api->get_option( 'installed_at' ) ) {
			$settings_api->update_option( 'installed_at', $now, $now, false );
		}
	}

	/**
	 * Send the install welcome email once for the site owner.
	 *
	 * @param Connection           $connection    Database connection.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @param Notifications|null   $notifications Optional notification service.
	 * @return void
	 * @since 1.0.2
	 */
	public static function send_install_welcome_once(
		Connection $connection,
		array $config,
		?Notifications $notifications = null
	): void {
		$db = new PeakURL_DB( $connection, (string) ( $config[ Constants::DB_PREFIX ] ?? '' ) );
		if ( ! $db->table_exists( 'settings' ) ) {
			return;
		}

		$settings_api = new SettingsApi( $db );
		if ( null !== $settings_api->get_option( 'install_welcome_email_sent_at' ) ) {
			return;
		}

		$owner = $db->get_row(
			'SELECT * FROM users WHERE role = :role ORDER BY id ASC LIMIT 1',
			array( 'role' => 'admin' )
		);

		if ( ! is_array( $owner ) ) {
			return;
		}

		try {
			if ( ! $notifications ) {
				$notifications = new Notifications();
			}
			$notifications->send_welcome( $owner );
			$settings_api->update_option(
				'install_welcome_email_sent_at',
				Date::now(),
				Date::now(),
				false
			);
		} catch ( \Throwable $exception ) {
			error_log(
				sprintf(
					'PeakURL mail error for install welcome (%s): %s',
					(string) ( $owner['email'] ?? 'unknown-email' ),
					$exception->getMessage()
				)
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
