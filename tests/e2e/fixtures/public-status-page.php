<?php
/** Render real public page templates without a database or running PHP server. */
declare(strict_types=1);

putenv( 'PEAKURL_DEV=true' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require ABSPATH . 'server/vendor/autoload.php';
set_i18n_service( new \PeakURL\Services\I18n( array() ) );

$state = (string) ( $argv[1] ?? '404' );
$debug = (string) ( $argv[2] ?? '' );

if ( ctype_digit( $state ) ) {
	echo \PeakURL\Services\StatusPage::render_http_error( (int) $state, '/installed', $debug );
	exit;
}
if ( 'maintenance' === $state ) {
	echo render_maintenance_page(
		array(
			'heading' => 'Briefly unavailable',
			'message' => 'An update is in progress. Please try again in a moment.',
		)
	);
	exit;
}
if ( 'database' === $state ) {
	echo \PeakURL\Services\StatusPage::render(
		'Unable to connect to the database',
		'PeakURL couldn’t establish a connection to its database. Please try again.',
		'503 · Database connection error',
		'',
		'/installed/',
		'Retry connection',
		illustration: 'database',
	);
	exit;
}
$controller = ( new ReflectionClass( \PeakURL\Features\Links\Controller::class ) )->newInstanceWithoutConstructor();
if ( 'password' === $state ) {
	$request = new \PeakURL\Http\Request( 'GET', '/protected', array(), array(), array(), array(), array( 'SCRIPT_NAME' => '/installed/index.php' ) );
	echo ( new ReflectionMethod( $controller, 'format_password_page' ) )->invoke( $controller, $request, 'protected', $debug );
	exit;
}
echo ( new ReflectionMethod( $controller, 'format_status_page' ) )->invoke(
	$controller,
	'expired' === $state ? 'Link expired' : 'Link not found',
	'expired' === $state ? 'This short link has expired and can no longer be opened.' : 'This short link doesn’t exist. Check the address and try again.',
	'expired' === $state ? 'expired' : 'unavailable',
);
