<?php
/**
 * Release installer manager.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Features\Settings\Validator;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\I18n;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Manager — final release installer flow for site and admin setup.
 *
 * Validates the install submission, writes the runtime config with
 * temporary install values, creates the schema, bootstraps the site,
 * and then rewrites config.php without those setup-only secrets.
 *
 * @since 1.0.14
 */
class Manager {

	/**
	 * Return default field values for the final install step.
	 *
	 * @param string $site_url Detected site URL.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	public static function get_form_defaults( string $site_url ): array {
		return array(
			'site_url'       => untrailingslashit( $site_url ),
			'site_language'  => Constants::DEFAULT_LOCALE,
			'site_timezone'  => '',
			'workspace_name' => '',
			'owner_username' => '',
			'owner_email'    => '',
			'owner_password' => '',
		);
	}

	/**
	 * Execute the final install step.
	 *
	 * @param string               $app_path Absolute path to the app directory.
	 * @param array<string, mixed> $input    Raw install form input.
	 * @param Request|null         $request  Optional HTTP request (unused).
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException When the install cannot proceed.
	 * @since 1.0.14
	 */
	public static function install(
		string $app_path,
		array $input,
		?Request $request = null
	): array {
		unset( $request );

		if ( InstallationState::is_installed( $app_path ) ) {
			throw new \RuntimeException( __( 'PeakURL is already installed.', 'peakurl' ) );
		}

		if ( ! InstallationState::config_exists( $app_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL still needs database configuration. Run setup-config.php first.', 'peakurl' ),
			);
		}

		$current_config = Configuration::load( $app_path );
		$values         = self::normalize_input( $input, $current_config );

		Writer::write_config_file( $app_path, $values );

		try {
			$app_config = Initializer::prepare_config( $values );
			Initializer::initialize_schema( $app_config, $app_path );

			$connection = new Connection( $app_config );
			Initializer::bootstrap_site( $connection, $app_config );

			Writer::write_config_file(
				$app_path,
				Initializer::prepare_release_values( $values ),
			);
			Initializer::send_install_welcome_once( $connection, $app_config );
		} catch ( \Throwable $exception ) {
			Writer::write_config_file(
				$app_path,
				Initializer::prepare_release_values(
					Writer::prepare_config_values( $current_config ),
				),
			);

			throw $exception;
		}

		return $values;
	}

	/**
	 * Validate and normalize the final install form submission.
	 *
	 * @param array<string, mixed> $input  Raw install form input.
	 * @param array<string, mixed> $config Current runtime configuration.
	 * @return array<string, string>
	 *
	 * @throws \RuntimeException When required fields are missing or invalid.
	 * @since 1.0.14
	 */
	private static function normalize_input( array $input, array $config ): array {
		$site_url       = Site::normalize_url(
			(string) ( $config[ Constants::SITE_URL ] ?? '' ),
		);
		$workspace_name = trim( (string) ( $input['workspace_name'] ?? '' ) );
		$workspace_slug = sanitize_title(
			trim( (string) ( $input['workspace_slug'] ?? $workspace_name ) ),
		);
		$owner_username = trim( (string) ( $input['owner_username'] ?? '' ) );
		$owner_email    = sanitize_email( (string) ( $input['owner_email'] ?? '' ) );
		$owner_password = (string) ( $input['owner_password'] ?? '' );
		$owner_name     = trim( (string) ( $input['owner_name'] ?? '' ) );
		$owner_names    = self::get_owner_names( $owner_name, $owner_username );
		$i18n_service   = new I18n( $config, null );
		$site_language  = $i18n_service->canonicalize_locale(
			(string) ( $input['site_language'] ?? '' ),
		);

		if ( '' === $site_language || ! $i18n_service->is_locale_available( $site_language ) ) {
			$site_language = $i18n_service->get_default_locale();
		}

		$site_timezone    = isset( $input['site_timezone'] )
			? (string) $input['site_timezone']
			: null;
		$browser_timezone = isset( $input['browser_timezone'] )
			? (string) $input['browser_timezone']
			: null;
		$site_timezone    = self::resolve_timezone( $site_timezone, $browser_timezone );

		if ( '' === $workspace_name ) {
			throw new \RuntimeException( __( 'Site title is required.', 'peakurl' ) );
		}

		if ( '' === $workspace_slug ) {
			throw new \RuntimeException(
				__( 'PeakURL could not generate a workspace slug from the site title.', 'peakurl' ),
			);
		}

		$owner_username = strtolower( trim( $owner_username ) );

		if ( ! preg_match( '/^[a-z0-9_-]{3,120}$/', $owner_username ) ) {
			throw new \RuntimeException(
				__( 'Admin username must be 3-120 characters using lowercase letters, numbers, hyphens, and underscores.', 'peakurl' ),
			);
		}

		if ( false === is_email( $owner_email ) ) {
			throw new \RuntimeException(
				__( 'A valid admin email address is required.', 'peakurl' ),
			);
		}

		if ( strlen( $owner_password ) < 8 ) {
			throw new \RuntimeException(
				__( 'Admin password must be at least 8 characters.', 'peakurl' ),
			);
		}

		$values                                   = Writer::prepare_config_values( $config );
		$values[ Constants::SITE_URL ]            = $site_url;
		$values[ Constants::SESSION_COOKIE_PATH ] = Site::get_cookie_path( $site_url );
		$values[ Constants::WORKSPACE_NAME ]      = $workspace_name;
		$values[ Constants::WORKSPACE_SLUG ]      = $workspace_slug;
		$values[ Constants::OWNER_FIRST_NAME ]    = $owner_names['first_name'];
		$values[ Constants::OWNER_LAST_NAME ]     = $owner_names['last_name'];
		$values[ Constants::OWNER_USERNAME ]      = $owner_username;
		$values[ Constants::OWNER_EMAIL ]         = $owner_email;
		$values[ Constants::OWNER_PASSWORD ]      = $owner_password;
		$values[ Constants::SITE_LANGUAGE ]       = $site_language;
		$values[ Constants::SITE_TIMEZONE ]       = $site_timezone;
		$values[ Constants::OWNER_FALLBACK ]      = 'false';

		return $values;
	}

	/**
	 * Resolve the installer site timezone.
	 *
	 * @param string|null $site_timezone    Explicit installer timezone selection.
	 * @param string|null $browser_timezone Detected browser IANA timezone.
	 * @return string Resolved valid IANA timezone identifier.
	 *
	 * @throws \RuntimeException When an explicitly supplied site timezone is invalid.
	 * @since 1.7.2
	 */
	public static function resolve_timezone(
		?string $site_timezone = null,
		?string $browser_timezone = null
	): string {
		$site_timezone = null !== $site_timezone ? trim( $site_timezone ) : '';

		if ( '' !== $site_timezone ) {
			if ( ! Validator::is_valid_timezone( $site_timezone ) ) {
				throw new \RuntimeException(
					__( 'The selected timezone is invalid.', 'peakurl' ),
				);
			}

			return $site_timezone;
		}

		$browser_timezone = null !== $browser_timezone ? trim( $browser_timezone ) : '';

		if ( '' !== $browser_timezone && Validator::is_valid_timezone( $browser_timezone ) ) {
			return $browser_timezone;
		}

		return Constants::DEFAULT_TIMEZONE;
	}

	/**
	 * Derive the owner first and last name from the form submission.
	 *
	 * @param string $owner_name     Full name input.
	 * @param string $owner_username Username fallback.
	 * @return array{first_name: string, last_name: string}
	 * @since 1.0.14
	 */
	private static function get_owner_names(
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
}
