<?php
/**
 * PeakURL browser-based site installation form (installer step 3).
 *
 * Collects site name, administrator credentials, and optional
 * settings, then delegates to {@see \PeakURL\Services\Install\Manager::install()} to
 * create the schema, save initial data, and log the admin in.
 *
 * On success the user is redirected to `/dashboard/about?source=install`.
 *
 * @package PeakURL
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Configuration;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Install\InstallationState;
use PeakURL\Services\Install\Locale as InstallLocale;
use PeakURL\Services\Install\Manager as InstallManager;
use PeakURL\Services\Install\Screen as InstallScreen;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}

require_once ABSPATH . 'load.php';

$environment  = \PeakURL\Core\Config\Environment::get_instance();
$root_path    = $environment->get_source_root();
$runtime_path = $environment->get_runtime_root();

$environment->load_autoloader();

$base_path = InstallScreen::get_base_path(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/install.php' ),
);

$requested_locale = trim(
	(string) ( $_POST['site_language'] ?? $_GET['site_language'] ?? '' ),
);
$installer_locale = new InstallLocale(
	$root_path,
	$requested_locale,
	(string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ),
);

set_i18n_service( $installer_locale->get_i18n_service() );

$install_state = InstallationState::get_state( $runtime_path );

if ( InstallationState::READY === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/dashboard' ) );
	exit();
}

if ( InstallationState::NOT_CONFIGURED === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/setup-config.php' ) );
	exit();
}

if ( InstallationState::DATABASE_UNAVAILABLE === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/database-error.php' ) );
	exit();
}

$detected_site_url          = InstallScreen::detect_site_url( $base_path, $_SERVER );
$values                     = InstallManager::get_form_defaults( $detected_site_url );
$values['site_language']    = $installer_locale->get_locale();
$values['browser_timezone'] = trim( (string) ( $_POST['browser_timezone'] ?? $_GET['browser_timezone'] ?? '' ) );
if ( isset( $_POST['site_timezone'] ) || isset( $_GET['site_timezone'] ) ) {
	$values['site_timezone'] = trim( (string) ( $_POST['site_timezone'] ?? $_GET['site_timezone'] ?? '' ) );
}

$error_message = '';
/* translators: %s: Application name. */
$page_title = sprintf( __( 'Administrator Setup - %s', 'peakurl' ), 'PeakURL' );

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
	foreach ( array_keys( $values ) as $key ) {
		if ( ! array_key_exists( $key, $_POST ) ) {
			continue;
		}

		$posted_value   = (string) $_POST[ $key ];
		$values[ $key ] =
			'owner_password' === $key ? $posted_value : trim( $posted_value );
	}

	try {
		InstallScreen::validate_post_origin( $detected_site_url, $_SERVER );
		$request          = Request::from_globals();
		$installed_values = InstallManager::install( $runtime_path, $_POST, $request );

		$installed_config = Configuration::load( $runtime_path );
		$connection       = new Connection( $installed_config );
		$auth_service     = AuthService::create( $installed_config, $connection );
		$auth_service->login(
			$request,
			array(
				'identifier' => $installed_values[ Constants::OWNER_USERNAME ],
				'password'   => $installed_values[ Constants::OWNER_PASSWORD ],
			)
		);

		foreach ( $request->get_response_cookies() as $cookie_header ) {
			header( 'Set-Cookie: ' . $cookie_header, false );
		}

		header( 'Location: ' . InstallScreen::format_url( $base_path, '/dashboard/about?source=install' ) );
		exit();
	} catch ( \Throwable $exception ) {
		$error_message = $exception->getMessage();
	}
}
ob_start();
require $runtime_path . '/services/install/templates/administrator.php';
$content_html = (string) ob_get_clean();
echo \PeakURL\Services\Install\Page::render( $page_title, $content_html, 2, $installer_locale );
