<?php
/**
 * PeakURL browser-based database configuration wizard (installer step 1–2).
 *
 * Presents a two-step wizard:
 *   Step 0 – Welcome checklist (prerequisites).
 *   Step 1 – Database credentials form, writes `config.php` via
 *            {@see \PeakURL\Services\Install\Config::configure()}.
 *
 * On success the user is redirected to `install.php` (step 3).
 *
 * @package PeakURL
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Services\Install\Config as InstallConfig;
use PeakURL\Services\Install\Locale as InstallLocale;
use PeakURL\Services\Install\InstallationState;
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
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/setup-config.php' ),
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

if ( InstallationState::NOT_INSTALLED === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/install.php' ) );
	exit();
}

if ( InstallationState::DATABASE_UNAVAILABLE === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/database-error.php' ) );
	exit();
}

$detected_site_url       = InstallScreen::detect_site_url( $base_path, $_SERVER );
$values                  = InstallConfig::get_form_defaults( $detected_site_url );
$values['site_language'] = $installer_locale->get_locale();
$browser_timezone        = trim( (string) ( $_POST['browser_timezone'] ?? $_GET['browser_timezone'] ?? '' ) );
$site_timezone           = trim( (string) ( $_POST['site_timezone'] ?? $_GET['site_timezone'] ?? '' ) );
if ( '' !== $browser_timezone ) {
	$values['browser_timezone'] = $browser_timezone;
}
if ( '' !== $site_timezone ) {
	$values['site_timezone'] = $site_timezone;
}

$error_message = '';
$step          = isset( $_GET['step'] ) ? min( 1, max( 0, (int) $_GET['step'] ) ) : 0;
$page_title    =
	$step >= 1
		/* translators: %s: Application name. */
		? sprintf( __( 'Database Setup - %s', 'peakurl' ), 'PeakURL' )
		/* translators: %s: Application name. */
		: sprintf( __( 'Welcome - %s', 'peakurl' ), 'PeakURL' );

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
	foreach ( array_keys( $values ) as $key ) {
		if ( array_key_exists( $key, $_POST ) ) {
			$values[ $key ] = trim( (string) $_POST[ $key ] );
		}
	}

	try {
		InstallScreen::validate_post_origin( $detected_site_url, $_SERVER );
		InstallConfig::configure( $runtime_path, $_POST );
		$redirect_params = array(
			'site_language' => $values['site_language'],
		);
		if ( ! empty( $values['site_timezone'] ) ) {
			$redirect_params['site_timezone'] = $values['site_timezone'];
		}
		if ( ! empty( $values['browser_timezone'] ) ) {
			$redirect_params['browser_timezone'] = $values['browser_timezone'];
		}
		header(
			'Location: ' . InstallScreen::format_url(
				$base_path,
				'/install.php',
				$redirect_params,
			),
		);
		exit();
	} catch ( \Throwable $exception ) {
		$error_message = $exception->getMessage();
		$step          = 1;
	}
}
ob_start();
require $runtime_path . '/services/install/templates/setup.php';
$content_html = (string) ob_get_clean();
echo \PeakURL\Services\Install\Page::render( $page_title, $content_html, $step, $installer_locale );
