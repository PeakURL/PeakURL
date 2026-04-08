<?php
/**
 * PeakURL runtime front controller.
 *
 * Serves as the single entry point for every request in a release
 * install.  Responsibilities include:
 *
 *  - Maintenance-mode detection and 503 responses.
 *  - Runtime-state routing (redirect to setup-config / install).
 *  - API pass-through to `app/public/index.php`.
 *  - Dashboard SPA shell injection for `/`, `/login`, `/dashboard*`.
 *
 * @package PeakURL\Site
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Install;
use PeakURL\Utils\Str;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}

require_once __DIR__ . '/app/utils/string.php';

// ────────────────────────────────────────────────────────────────
// Helper functions
// ────────────────────────────────────────────────────────────────

/**
 * Derive the URL base path from the PHP SCRIPT_NAME.
 *
 * Returns an empty string when running at the document root.
 *
 * @param string $script_name Value of $_SERVER['SCRIPT_NAME'].
 * @return string Base path without trailing slash, or ''.
 * @since 1.0.0
 */
function peakurl_runtime_base_path( string $script_name ): string {
	$base_path = str_replace( '\\', '/', dirname( $script_name ) );

	if ( '.' === $base_path || '/' === $base_path ) {
		return '';
	}

	return rtrim( $base_path, '/' );
}

/**
 * Strip the base path prefix to produce a root-relative request path.
 *
 * @param string $request_path Raw request path from parse_url().
 * @param string $base_path    Base path (may be empty).
 * @return string Relative path starting with '/'.
 * @since 1.0.0
 */
function peakurl_relative_request_path(
	string $request_path,
	string $base_path
): string {
	if (
		'' !== $base_path &&
		Str::starts_with( $request_path, $base_path . '/' )
	) {
		$relative_path = substr( $request_path, strlen( $base_path ) );

		return false !== $relative_path && '' !== $relative_path
			? $relative_path
			: '/';
	}

	if ( $request_path === $base_path ) {
		return '/';
	}

	return $request_path;
}

/**
 * Build a full URL path by combining the base path and a suffix.
 *
 * @param string $base_path Base path (may be empty).
 * @param string $suffix    Suffix to append (e.g. '/install.php').
 * @return string Combined URL path.
 * @since 1.0.0
 */
function peakurl_runtime_url( string $base_path, string $suffix ): string {
	$normalized_suffix = '/' . ltrim( $suffix, '/' );

	if ( '' === $base_path ) {
		return $normalized_suffix;
	}

	return $base_path . $normalized_suffix;
}

/** Get the absolute path to the maintenance flag file. */
function peakurl_maintenance_path(): string {
	return __DIR__ . '/.maintenance';
}

/** Check whether the site is in maintenance mode. */
function peakurl_is_under_maintenance(): bool {
	return file_exists( peakurl_maintenance_path() );
}

/**
 * Send a 503 maintenance response and terminate.
 *
 * API requests receive JSON; browser requests receive an HTML page.
 *
 * @param bool $is_api_request Whether the inbound request targets /api/*.
 * @since 1.0.0
 */
function peakurl_send_maintenance_response( bool $is_api_request ): void {
	http_response_code( 503 );

	if ( $is_api_request ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode(
			array(
				'success' => false,
				'message' => 'PeakURL is updating. Please try again in a moment.',
				'data'    => array(
					'maintenance' => true,
				),
			),
			JSON_PRETTY_PRINT,
		);
		exit();
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PeakURL is updating</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;color:#111827;display:grid;place-items:center;min-height:100vh;padding:24px}.card{max-width:420px;width:100%;background:#fff;border:1px solid #dcdcde;border-radius:18px;padding:32px;box-shadow:0 12px 36px rgba(17,24,39,.08)}h1{margin:0 0 12px;font-size:28px;line-height:1.1}p{margin:0;color:#4b5563;line-height:1.6}</style></head><body><div class="card"><h1>PeakURL is updating</h1><p>Please wait a moment and refresh the page. The update should finish shortly.</p></div></body></html>';
	exit();
}

/**
 * Decide whether a request path should serve the React dashboard shell.
 *
 * Matches `/`, `/login`, `/forgot-password`, `/reset-password/*`,
 * `/dashboard`, and `/dashboard/*`.
 *
 * @param string $relative_path Root-relative request path.
 * @return bool True when the SPA HTML shell should be returned.
 * @since 1.0.0
 */
function peakurl_should_serve_dashboard_shell( string $relative_path ): bool {
	if (
		'/' === $relative_path ||
		'/login' === $relative_path ||
		'/forgot-password' === $relative_path ||
		'/reset-password' === $relative_path ||
		Str::starts_with( $relative_path, '/reset-password/' )
	) {
		return true;
	}

	return '/dashboard' === $relative_path ||
		Str::starts_with( $relative_path, '/dashboard/' );
}

/**
 * Inject runtime configuration into the dashboard HTML shell.
 *
 * Inserts a `<base>` tag and a `<script>` block carrying the base path,
 * API base, site name, version, locale, and dashboard translation catalog
 * into the `<head>` element.
 *
 * @param string               $html                Raw app.html content.
 * @param string               $base_path           URL base path.
 * @param string               $site_name           Site name from settings.
 * @param string               $version             Installed PeakURL version.
 * @param string               $locale              Active site locale.
 * @param string               $text_direction      Active document text direction.
 * @param array<string, mixed> $translation_catalog Dashboard JSON catalog.
 * @return string Modified HTML.
 * @since 1.0.0
 */
function peakurl_inject_runtime_shell(
	string $html,
	string $base_path,
	string $site_name,
	string $version,
	string $locale,
	string $text_direction,
	array $translation_catalog
): string {
	$base_href = '' === $base_path ? '/' : $base_path . '/';
	$html_lang = htmlspecialchars(
		strtolower( str_replace( '_', '-', $locale ) ),
		ENT_QUOTES,
		'UTF-8',
	);
	$html_dir  = 'rtl' === strtolower( $text_direction ) ? 'rtl' : 'ltr';
	$runtime   =
		'<base href="' .
		htmlspecialchars( $base_href, ENT_QUOTES, 'UTF-8' ) .
		'">' .
		"\n" .
		'<script>window.__PEAKURL_BASE_PATH__=' .
		json_encode( $base_path ) .
		';window.__PEAKURL_API_BASE__=' .
		json_encode( peakurl_runtime_url( $base_path, '/api/v1' ) ) .
		';window.__PEAKURL_URL__=window.location.origin+' .
		json_encode( $base_path ) .
		';window.__PEAKURL_SITE_NAME__=' .
		json_encode( $site_name ) .
		';window.__PEAKURL_VERSION__=' .
		json_encode( $version ) .
		';window.__PEAKURL_LOCALE__=' .
		json_encode( $locale ) .
		';window.__PEAKURL_TEXT_DIRECTION__=' .
		json_encode( $html_dir ) .
		';window.__PEAKURL_TEXT_DOMAIN__=' .
		json_encode( Constants::I18N_TEXT_DOMAIN ) .
		';window.__PEAKURL_I18N__=' .
		json_encode(
			$translation_catalog,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		) .
		';</script>';

	$updated_html   = str_replace( '<head>', "<head>\n\t\t" . $runtime, $html );
	$html_with_lang = preg_replace_callback(
		'/<html\b([^>]*)>/i',
		static function ( array $matches ) use ( $html_lang, $html_dir ): string {
			$attributes = preg_replace(
				'/\s(?:lang|dir)=(["\']).*?\1/i',
				'',
				(string) ( $matches[1] ?? '' ),
			);
			$attributes = is_string( $attributes ) ? trim( $attributes ) : '';

			return '<html' .
				( '' !== $attributes ? ' ' . $attributes : '' ) .
				' lang="' . $html_lang . '" dir="' . $html_dir . '">';
		},
		$updated_html,
		1,
	);

	if ( null !== $html_with_lang ) {
		$updated_html = $html_with_lang;
	}

	return $updated_html !== $html ? $updated_html : $runtime . $html;
}

// ────────────────────────────────────────────────────────────────
// Request routing
// ────────────────────────────────────────────────────────────────

$root_path   = __DIR__;
$app_path    = $root_path . '/app';
$config_path = $root_path . '/config.php';
$autoload    = $app_path . '/vendor/autoload.php';
$uri         = $_SERVER['REQUEST_URI'] ?? '/';
$path        = parse_url( $uri, PHP_URL_PATH );

if ( ! is_string( $path ) || '' === $path ) {
	$path = '/';
}

$base_path     = peakurl_runtime_base_path(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ),
);
$relative_path = peakurl_relative_request_path( $path, $base_path );
$setup_path    = peakurl_runtime_url( $base_path, '/setup-config.php' );
$install_path  = peakurl_runtime_url( $base_path, '/install.php' );

if ( peakurl_is_under_maintenance() ) {
	peakurl_send_maintenance_response(
		Str::starts_with( $relative_path, '/api/' ),
	);
}

if ( ! file_exists( $autoload ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL dependencies are missing. Upload the complete release package before continuing.\n";
	exit();
}

require_once $autoload;

$runtime_state = Install::get_runtime_state( $app_path );

if ( Str::starts_with( $relative_path, '/api/' ) ) {
	if ( Install::STATE_READY !== $runtime_state ) {
		http_response_code( 503 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode(
			array(
				'success' => false,
				'message' => Install::STATE_NEEDS_INSTALL === $runtime_state
					? 'PeakURL needs installation.'
					: 'PeakURL needs database configuration.',
				'data'    => array(
					'setupConfigUrl' => $setup_path,
					'installUrl'     => $install_path,
					'isConfigured'   => file_exists( $config_path ),
					'recoveryState'  => $runtime_state,
				),
			),
			JSON_PRETTY_PRINT,
		);
		exit();
	}

	require $app_path . '/public/index.php';
	exit();
}

if ( Install::STATE_NEEDS_SETUP === $runtime_state ) {
	header( 'Location: ' . $setup_path );
	exit();
}

if ( Install::STATE_NEEDS_INSTALL === $runtime_state ) {
	header( 'Location: ' . $install_path );
	exit();
}

if ( ! peakurl_should_serve_dashboard_shell( $relative_path ) ) {
	require $app_path . '/public/index.php';
	exit();
}

$runtime_config = RuntimeConfig::bootstrap( $app_path );
$connection     = new Connection( $runtime_config );
peakurl_bootstrap_i18n( $runtime_config, $connection );
$site_name      = trim(
	(string) ( $connection->get_setting_value( 'site_name' ) ?? 'PeakURL' ),
);
$locale         = get_locale();
$text_direction = peakurl_get_text_direction();
$catalog        = peakurl_get_dashboard_translation_catalog(
	$locale,
	$runtime_config,
	$connection,
);
$version        = trim(
	(string) (
		$connection->get_setting_value( 'installed_version' ) ??
		$runtime_config[ Constants::CONFIG_VERSION ] ??
		Constants::DEFAULT_VERSION
	),
);

$dashboard_shell_path = $root_path . '/app.html';

if ( ! file_exists( $dashboard_shell_path ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL build output is missing. Upload the full release package and try again.\n";
	exit();
}

header( 'Content-Type: text/html; charset=utf-8' );
header( 'Content-Language: ' . peakurl_get_html_lang_attribute() );
$dashboard_shell = file_get_contents( $dashboard_shell_path );

if ( false === $dashboard_shell ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL build output could not be read.\n";
	exit();
}

echo peakurl_inject_runtime_shell(
	$dashboard_shell,
	$base_path,
	$site_name,
	$version,
	$locale,
	$text_direction,
	$catalog,
);
