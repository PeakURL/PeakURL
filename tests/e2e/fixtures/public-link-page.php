<?php
/** Render real public access markup for isolated browser tests, without storage. */
declare(strict_types=1);

putenv( 'PEAKURL_DEV=true' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
require ABSPATH . 'server/vendor/autoload.php';
set_i18n_service( new \PeakURL\Services\I18n( array() ) );

$provider   = (string) ( $argv[1] ?? 'turnstile' );
$error      = (string) ( $argv[2] ?? '' );
$base_path  = (string) ( $argv[3] ?? '' );
$controller = ( new ReflectionClass( \PeakURL\Features\Links\Controller::class ) )->newInstanceWithoutConstructor();
$request    = new \PeakURL\Http\Request( 'GET', '/check', array(), array(), array(), array(), array( 'SCRIPT_NAME' => $base_path . '/index.php' ) );
$challenge  = array(
	'provider'      => $provider,
	'siteKey'       => 'public-test-key',
	'action'        => 'peakurl_redirect',
	'responseField' => 'turnstile' === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response',
	'scriptUrl'     => 'turnstile' === $provider ? 'https://challenges.cloudflare.com/turnstile/v0/api.js' : 'https://www.google.com/recaptcha/api.js',
);
$html       = ( new ReflectionMethod( $controller, 'format_captcha_page' ) )->invoke( $controller, $request, $challenge, $error );

if ( '1' === getenv( 'PEAKURL_TEST_BUILT_ASSETS' ) ) {
	if ( ! preg_match( '/\}\)\((\{[^\n]*\})\);/', $html, $matches ) ) {
		throw new RuntimeException( 'Missing public CAPTCHA configuration.' );
	}
	json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
	$compiled = str_replace( '__PEAKURL_CAPTCHA_CONFIG__', $matches[1], (string) file_get_contents( ABSPATH . 'build/server/features/links/publicCaptcha.js' ) );
	$html     = preg_replace_callback(
		'/<script>(.*?)<\/script>/s',
		static fn( array $script_match ): string => str_contains( $script_match[1], 'window.PeakURLRenderCaptcha' ) ? '<script>' . $compiled . '</script>' : $script_match[0],
		$html,
	);
}
echo $html;
