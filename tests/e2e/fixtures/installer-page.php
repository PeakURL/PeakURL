<?php
/** Render installer views without reading or writing installation state. */
declare(strict_types=1);
putenv( 'PEAKURL_DEV=true' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require ABSPATH . 'server/vendor/autoload.php';

$step             = (int) ( $argv[1] ?? 0 );
$installer_locale = new \PeakURL\Services\Install\Locale( ABSPATH, (string) ( $argv[2] ?? 'en_US' ) );
set_i18n_service( $installer_locale->get_i18n_service() );
$base_path               = '/installed';
$values                  = 2 === $step
	? \PeakURL\Services\Install\Manager::get_form_defaults( 'https://installer-preview.invalid/installed' )
	: \PeakURL\Services\Install\Config::get_form_defaults( 'https://installer-preview.invalid/installed' );
$values['site_language'] = $installer_locale->get_locale();
$error_message           = (string) ( $argv[3] ?? '' );
ob_start();
require ABSPATH . 'server/services/install/templates/' . ( 2 === $step ? 'administrator' : 'setup' ) . '.php';
$content = (string) ob_get_clean();
$html    = \PeakURL\Services\Install\Page::render( 'Install PeakURL', $content, $step, $installer_locale );
if ( '1' === getenv( 'PEAKURL_TEST_BUILT_ASSETS' ) ) {
	foreach ( array( 'installer.js', 'languagePicker.js' ) as $filename ) {
		$html = str_replace(
			(string) file_get_contents( ABSPATH . 'server/services/install/' . $filename ),
			(string) file_get_contents( ABSPATH . 'build/server/services/install/' . $filename ),
			$html,
		);
	}
}
echo $html;
