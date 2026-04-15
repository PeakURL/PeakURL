<?php
/**
 * Final installation step for the release package.
 *
 * Handles runtime state detection, install form defaults, and the
 * complete install flow: normalise input → write config → create
 * schema → bootstrap workspace → auto-login.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Includes\Connection;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Schema as DatabaseSchema;
use PeakURL\Store;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Install — orchestrates first-run self-hosted installation.
 *
 * Determines the runtime install state (needs_setup / needs_install / ready)
 * and drives the final install step that writes config, seeds the database,
 * and logs the newly-created admin user in.
 *
 * @since 1.0.0
 */
class Install {

	/** Runtime state: config.php is missing. */
	public const STATE_NEEDS_SETUP = 'needs_setup';
	/** Runtime state: config.php exists but tables/data are missing. */
	public const STATE_NEEDS_INSTALL = 'needs_install';
	/** Runtime state: fully installed and ready. */
	public const STATE_READY = 'ready';

	/**
	 * Check whether PeakURL is fully installed and ready.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool True when the runtime state is 'ready'.
	 * @since 1.0.0
	 */
	public static function is_installed( string $app_path ): bool {
		return self::STATE_READY === self::get_runtime_state( $app_path );
	}

	/**
	 * Check whether a runtime config.php file exists.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return bool True when config.php is present.
	 * @since 1.0.0
	 */
	public static function has_runtime_config( string $app_path ): bool {
		return SetupConfig::has_config( $app_path );
	}

	/**
	 * Derive the current runtime install state.
	 *
	 * Returns one of STATE_NEEDS_SETUP, STATE_NEEDS_INSTALL, or STATE_READY
	 * based on config.php presence, required tables, and seed data.
	 *
	 * @param string $app_path Absolute path to the app directory.
	 * @return string One of the STATE_* constants.
	 * @since 1.0.0
	 */
	public static function get_runtime_state( string $app_path ): string {
		if ( ! self::has_runtime_config( $app_path ) ) {
			return self::STATE_NEEDS_SETUP;
		}

		try {
			$config     = RuntimeConfig::load( $app_path );
			$connection = new Connection( $config );

			if ( ! $connection->has_required_tables() ) {
				return self::STATE_NEEDS_INSTALL;
			}

			if ( ! $connection->setting_has_value( 'site_url' ) ) {
				return self::STATE_NEEDS_INSTALL;
			}

			if ( ! $connection->table_has_rows( 'users' ) ) {
				return self::STATE_NEEDS_INSTALL;
			}
		} catch ( \Throwable $exception ) {
			return self::STATE_NEEDS_SETUP;
		}

		return self::STATE_READY;
	}

	/**
	 * Get default form values for the install wizard.
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
			'site_url'       => rtrim( $site_url, '/' ),
			'site_language'  => 'en_US',
			'workspace_name' => '',
			'owner_username' => '',
			'owner_email'    => '',
			'owner_password' => '',
		);
	}

	/**
	 * Execute the full install process.
	 *
	 * Validates input, writes config.php, creates the schema, bootstraps
	 * the workspace (admin user + settings), and logs the user in.
	 *
	 * @param string               $app_path  Absolute path to the app directory.
	 * @param array<string, mixed> $input     Form input data.
	 * @param Request              $request   Current HTTP request (for auto-login).
	 * @return array<string, string> Final config values.
	 *
	 * @throws \RuntimeException When already installed or config is missing.
	 * @since 1.0.0
	 */
	public static function install(
		string $app_path,
		array $input,
		Request $request
	): array {
		if ( self::is_installed( $app_path ) ) {
			throw new \RuntimeException( __( 'PeakURL is already installed.', 'peakurl' ) );
		}

		if ( ! self::has_runtime_config( $app_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL still needs database configuration. Run setup-config.php first.', 'peakurl' ),
			);
		}

		$current_config = RuntimeConfig::load( $app_path );
		$values         = self::normalize_input( $input, $current_config );

		SetupConfig::write_config_file( $app_path, $values );

		try {
			$runtime_config = self::runtime_config_from_values( $values );
			self::initialize_schema_from_config( $runtime_config, $app_path );

			$connection = new Connection( $runtime_config );
			$data_store = new Store( $connection, $runtime_config );
			$data_store->bootstrap_workspace();
			$data_store->login(
				$request,
				array(
					'identifier' => $values['PEAKURL_OWNER_USERNAME'],
					'password'   => $values['PEAKURL_OWNER_PASSWORD'],
				)
			);

			SetupConfig::write_config_file(
				$app_path,
				self::release_runtime_values( $values ),
			);
			$data_store->send_install_welcome_email_once();
		} catch ( \Throwable $exception ) {
			SetupConfig::write_config_file(
				$app_path,
				self::release_runtime_values( self::config_to_values( $current_config ) ),
			);

			throw $exception;
		}

		return $values;
	}

	/**
	 * Normalise and validate a site URL for the release package.
	 *
	 * @param string $site_url Raw site URL input.
	 * @return string Trimmed URL without trailing slash.
	 *
	 * @throws \RuntimeException When the URL is empty, invalid, or has query/fragment.
	 * @since 1.0.0
	 */
	public static function normalize_site_url_for_release( string $site_url ): string {
		$site_url = trim( $site_url );

		if ( '' === $site_url ) {
			throw new \RuntimeException( __( 'Site URL is required.', 'peakurl' ) );
		}

		if ( ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
			throw new \RuntimeException( __( 'Site URL must be a valid URL.', 'peakurl' ) );
		}

		$parts = parse_url( $site_url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			throw new \RuntimeException( __( 'Site URL must include a valid host.', 'peakurl' ) );
		}

		if ( ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			throw new \RuntimeException(
				__( 'Site URL cannot contain a query string or fragment.', 'peakurl' ),
			);
		}

		return rtrim( $site_url, '/' );
	}

	/**
	 * Derive the session cookie path from a site URL.
	 *
	 * @param string $site_url Validated site URL.
	 * @return string Cookie path (always ends with '/').
	 * @since 1.0.0
	 */
	public static function site_cookie_path_for_release( string $site_url ): string {
		$path = parse_url( $site_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return '/';
		}

		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Convert a runtime config array into flat string values for config.php generation.
	 *
	 * @param array<string, mixed> $config Runtime config.
	 * @return array<string, string> Flat config key-value pairs.
	 * @since 1.0.0
	 */
	private static function config_to_values( array $config ): array {
		return SetupConfig::config_values_from_runtime_config( $config );
	}

	/**
	 * Strip owner-only seed values for the final runtime config.
	 *
	 * @param array<string, string> $values Full install values.
	 * @return array<string, string> Cleaned values for production config.php.
	 * @since 1.0.0
	 */
	private static function release_runtime_values( array $values ): array {
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

	/**
	 * Validate and normalise form input for the install step.
	 *
	 * @param array<string, mixed> $input  Raw form input.
	 * @param array<string, mixed> $config Current runtime config.
	 * @return array<string, string> Normalised config values.
	 *
	 * @throws \RuntimeException When required fields are missing or invalid.
	 * @since 1.0.0
	 */
	private static function normalize_input( array $input, array $config ): array {
		$site_url       = self::normalize_site_url_for_release(
			(string) ( $config['SITE_URL'] ?? '' ),
		);
		$workspace_name = trim( (string) ( $input['workspace_name'] ?? '' ) );
		$workspace_slug = self::slugify(
			trim( (string) ( $input['workspace_slug'] ?? $workspace_name ) ),
		);
		$owner_username = trim( (string) ( $input['owner_username'] ?? '' ) );
		$owner_email    = strtolower( trim( (string) ( $input['owner_email'] ?? '' ) ) );
		$owner_password = (string) ( $input['owner_password'] ?? '' );
		$owner_name     = trim( (string) ( $input['owner_name'] ?? '' ) );
		$owner_names    = self::derive_owner_names( $owner_name, $owner_username );
		$i18n_service   = new I18n( $config, null );
		$site_language  = $i18n_service->normalize_locale(
			(string) ( $input['site_language'] ?? '' ),
		);

		if ( ! $i18n_service->is_locale_available( $site_language ) ) {
			$site_language = $i18n_service->get_default_locale();
		}

		if ( '' === $workspace_name ) {
			throw new \RuntimeException( __( 'Site title is required.', 'peakurl' ) );
		}

		if ( '' === $workspace_slug ) {
			throw new \RuntimeException(
				__( 'PeakURL could not generate a workspace slug from the site title.', 'peakurl' ),
			);
		}

		if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,120}$/', $owner_username ) ) {
			throw new \RuntimeException(
				__( 'Admin username must be 3-120 characters using letters, numbers, dots, dashes, underscores, or @.', 'peakurl' ),
			);
		}

		if ( ! filter_var( $owner_email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \RuntimeException(
				__( 'A valid admin email address is required.', 'peakurl' ),
			);
		}

		if ( strlen( $owner_password ) < 8 ) {
			throw new \RuntimeException(
				__( 'Admin password must be at least 8 characters.', 'peakurl' ),
			);
		}

		$values                             = self::config_to_values( $config );
		$values['SITE_URL']                 = $site_url;
		$values['SESSION_COOKIE_PATH']      = self::site_cookie_path_for_release( $site_url );
		$values['PEAKURL_WORKSPACE_NAME']   = $workspace_name;
		$values['PEAKURL_WORKSPACE_SLUG']   = $workspace_slug;
		$values['PEAKURL_OWNER_FIRST_NAME'] = $owner_names['first_name'];
		$values['PEAKURL_OWNER_LAST_NAME']  = $owner_names['last_name'];
		$values['PEAKURL_OWNER_USERNAME']   = $owner_username;
		$values['PEAKURL_OWNER_EMAIL']      = $owner_email;
		$values['PEAKURL_OWNER_PASSWORD']   = $owner_password;
		$values['PEAKURL_SITE_LANGUAGE']    = $site_language;
		$values['PEAKURL_OWNER_FALLBACK']   = 'false';

		return $values;
	}

	/**
	 * Derive first/last name from owner_name or fallback to username parts.
	 *
	 * @param string $owner_name     Full name input.
	 * @param string $owner_username Username fallback.
	 * @return array{first_name: string, last_name: string}
	 * @since 1.0.0
	 */
	private static function derive_owner_names(
		string $owner_name,
		string $owner_username
	): array {
		$source = '' !== $owner_name ? $owner_name : $owner_username;
		$source = trim( preg_replace( '/[@._-]+/', ' ', $source ) ?? $source );
		$parts  = preg_split( '/\s+/', $source );

		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array(
				'first_name' => 'Site',
				'last_name'  => 'Owner',
			);
		}

		$first_name = ucfirst( strtolower( (string) $parts[0] ) );
		$last_name  = count( $parts ) > 1
			? ucfirst( strtolower( implode( ' ', array_slice( $parts, 1 ) ) ) )
			: 'Owner';

		return array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
	}

	/**
	 * Convert an arbitrary string into a URL-safe slug.
	 *
	 * @param string $value Input string.
	 * @return string Lowercased, hyphen-separated slug.
	 * @since 1.0.0
	 */
	private static function slugify( string $value ): string {
		$value  = strtolower( trim( $value ) );
		$result = preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value  = is_string( $result ) ? $result : '';
		return trim( $value, '-' );
	}

	/**
	 * Create the database schema from the bundled schema.sql file.
	 *
	 * @param array<string, mixed> $config    Runtime config with DB credentials.
	 * @param string               $app_path Absolute path to the app directory.
	 *
	 * @throws \RuntimeException On schema file read failure or SQL execution error.
	 * @since 1.0.0
	 */
	private static function initialize_schema_from_config(
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
	 * Build a runtime config array from flat string values.
	 *
	 * Converts string booleans/integers to their native types for use with
	 * Database and Store constructors.
	 *
	 * @param array<string, string> $values Flat config values.
	 * @return array<string, mixed> Typed runtime config.
	 * @since 1.0.0
	 */
	private static function runtime_config_from_values( array $values ): array {
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
}
