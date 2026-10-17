<?php
/**
 * Database connection recovery page.
 *
 * Shown when config.php exists but PeakURL cannot establish a connection with
 * its configured database settings.
 *
 * @package PeakURL
 * @since 1.3.0
 */

declare(strict_types=1);

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

$base_path     = InstallScreen::get_base_path(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/database-error.php' ),
);
$install_state = InstallationState::get_state( $runtime_path );

if ( InstallationState::READY === $install_state ) {
	$target = InstallScreen::sanitize_redirect_target( (string) ( $_GET['redirect_to'] ?? '' ), '/dashboard' );
	header( 'Location: ' . InstallScreen::format_url( $base_path, $target ) );
	exit();
}

if ( InstallationState::NOT_CONFIGURED === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/setup-config.php' ) );
	exit();
}

if ( InstallationState::NOT_INSTALLED === $install_state ) {
	header( 'Location: ' . InstallScreen::format_url( $base_path, '/install.php' ) );
	exit();
}

// Rendering recovery must not attempt another database connection for translations.
set_i18n_service( new \PeakURL\Services\I18n( get_peakurl_config() ) );

$retry_url = InstallScreen::format_url( $base_path, '/' );

http_response_code( 503 );
header( 'Content-Type: text/html; charset=utf-8' );

echo \PeakURL\Services\StatusPage::render(
	'Unable to connect to the database',
	'PeakURL couldn’t establish a connection to its database. Please try again. If the problem persists, contact your site administrator.',
	'503 · Database connection error',
	'For your security, database credentials and connection details are never displayed here.',
	$retry_url,
	'Retry connection',
	illustration: 'database',
);
