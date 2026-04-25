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

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Favicon;
use PeakURL\Services\Install\State as InstallState;
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
 * @param bool                       $is_api_request        Whether the inbound request targets /api/*.
 * @param array<string, string>|null $maintenance_view_data Optional localized maintenance data.
 * @since 1.0.0
 */
function peakurl_send_maintenance_response(
	bool $is_api_request,
	?array $maintenance_view_data = null
): void {
	$maintenance_view_data = is_array( $maintenance_view_data )
		? $maintenance_view_data
		: array(
			'htmlLang'      => 'en-US',
			'textDirection' => 'ltr',
			'apiMessage'    => 'PeakURL is briefly unavailable right now. Please try again in a moment.',
		);

	http_response_code( 503 );
	header( 'Retry-After: 60' );
	header(
		'Content-Language: ' .
		(string) ( $maintenance_view_data['htmlLang'] ?? 'en-US' ),
	);

	if ( $is_api_request ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo function_exists( 'peakurl_get_maintenance_api_payload' )
			? json_encode(
				peakurl_get_maintenance_api_payload( $maintenance_view_data ),
				JSON_PRETTY_PRINT,
			)
			: json_encode(
				array(
					'success' => false,
					'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is briefly unavailable right now. Please try again in a moment.' ),
					'data'    => array(
						'maintenance' => true,
					),
				),
				JSON_PRETTY_PRINT,
			);
		exit();
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	echo function_exists( 'peakurl_render_maintenance_page' )
		? peakurl_render_maintenance_page( $maintenance_view_data )
		: '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PeakURL is briefly unavailable</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><p>PeakURL is briefly unavailable right now. Please try again in a moment.</p></body></html>';
	exit();
}

/**
 * Determine whether the current request targets a favicon alias route.
 *
 * @param string $relative_path Root-relative request path.
 * @return bool
 * @since 1.0.14
 */
function peakurl_is_favicon_request( string $relative_path ): bool {
	return in_array(
		$relative_path,
		array(
			'/favicon.ico',
			'/favicon.png',
			'/apple-touch-icon.png',
			'/site.webmanifest',
		),
		true,
	);
}

/**
 * Send a static file response and terminate the request.
 *
 * @param string $file_path    Absolute file path.
 * @param string $content_type Content-Type header value.
 * @param int    $max_age      Cache lifetime in seconds.
 * @return void
 * @since 1.0.14
 */
function peakurl_send_file_response(
	string $file_path,
	string $content_type,
	int $max_age = 3600
): void {
	if ( ! is_readable( $file_path ) ) {
		http_response_code( 404 );
		exit();
	}

	$modified_at = filemtime( $file_path );
	$file_size   = filesize( $file_path );

	header( 'Content-Type: ' . $content_type );
	header(
		'Cache-Control: public, max-age=' . $max_age .
		( $max_age >= 86400 ? ', immutable' : '' )
	);

	if ( false !== $modified_at ) {
		header(
			'Last-Modified: ' .
			gmdate( 'D, d M Y H:i:s', (int) $modified_at ) .
			' GMT',
		);
	}

	if ( false !== $file_size ) {
		header( 'Content-Length: ' . (string) $file_size );
	}

	readfile( $file_path );
	exit();
}

/**
 * Build the favicon and manifest markup for the dashboard shell.
 *
 * @param string               $site_name Configured site name.
 * @param array<string, mixed> $favicon   Favicon settings payload.
 * @return string
 * @since 1.0.14
 */
function peakurl_get_favicon_head_markup(
	string $site_name,
	array $favicon
): string {
	if ( empty( $favicon['configured'] ) ) {
		return '';
	}

	$icon_url        = trim( (string) ( $favicon['url'] ?? '' ) );
	$shortcut_url    = trim( (string) ( $favicon['iconUrl'] ?? $icon_url ) );
	$apple_touch_url = trim( (string) ( $favicon['appleTouchUrl'] ?? $icon_url ) );
	$manifest_url    = trim( (string) ( $favicon['manifestUrl'] ?? '' ) );
	$sizes           = trim( (string) ( $favicon['sizes'] ?? '' ) );

	if ( '' === $icon_url ) {
		return '';
	}

	$icon_url       = htmlspecialchars( $icon_url, ENT_QUOTES, 'UTF-8' );
	$shortcut_url   = htmlspecialchars( $shortcut_url, ENT_QUOTES, 'UTF-8' );
	$site_name_attr = htmlspecialchars( $site_name, ENT_QUOTES, 'UTF-8' );
	$sizes_attr     = '' !== $sizes
		? ' sizes="' . htmlspecialchars( $sizes, ENT_QUOTES, 'UTF-8' ) . '"'
		: '';

	$markup = '<link rel="icon" type="image/png" href="' . $icon_url . '"' . $sizes_attr . '>' .
		"\n" .
		'<link rel="shortcut icon" type="image/png" href="' . $shortcut_url . '">';

	if ( '' !== $apple_touch_url ) {
		$markup .=
			"\n" .
			'<link rel="apple-touch-icon" href="' .
			htmlspecialchars( $apple_touch_url, ENT_QUOTES, 'UTF-8' ) .
			'">';
	}

	if ( '' !== $manifest_url ) {
		$markup .=
			"\n" .
			'<link rel="manifest" href="' .
			htmlspecialchars( $manifest_url, ENT_QUOTES, 'UTF-8' ) .
			'">';
	}

	return $markup .
		"\n" .
		'<meta name="apple-mobile-web-app-title" content="' . $site_name_attr . '">';
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
 * API base, site name, version, debug flag, backend body classes, locale, and
 * dashboard translation catalog into the `<head>` element.
 *
 * @param string               $html                Raw app.html content.
 * @param string               $base_path           URL base path.
 * @param string               $site_name           Site name from settings.
 * @param string               $version             Installed PeakURL version.
 * @param array<int, string>   $body_classes        Initial body classes from PHP runtime hooks.
 * @param string               $locale              Active site locale.
 * @param string               $text_direction      Active document text direction.
 * @param array<string, mixed> $translation_catalog Dashboard JSON catalog.
 * @param array<string, mixed> $favicon             Public favicon settings payload.
 * @param bool                 $debug_enabled       Whether runtime debug mode is enabled.
 * @return string Modified HTML.
 * @since 1.0.0
 */
function peakurl_inject_runtime_shell(
	string $html,
	string $base_path,
	string $site_name,
	string $version,
	array $body_classes,
	string $locale,
	string $text_direction,
	array $translation_catalog,
	array $favicon,
	bool $debug_enabled
): string {
	$base_href    = '' === $base_path ? '/' : $base_path . '/';
	$html_lang    = htmlspecialchars(
		strtolower( str_replace( '_', '-', $locale ) ),
		ENT_QUOTES,
		'UTF-8',
	);
	$html_dir     = 'rtl' === strtolower( $text_direction ) ? 'rtl' : 'ltr';
	$favicon_head = peakurl_get_favicon_head_markup(
		$site_name,
		$favicon,
	);
	$runtime      =
		'<base href="' .
		htmlspecialchars( $base_href, ENT_QUOTES, 'UTF-8' ) .
		'">' .
		"\n" .
		( '' !== $favicon_head ? $favicon_head . "\n" : '' ) .
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
		';window.__PEAKURL_DEBUG__=' .
		json_encode( $debug_enabled ) .
		';window.__PEAKURL_FAVICON__=' .
		json_encode(
			$favicon,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		) .
		';window.__PEAKURL_BODY_CLASSES__=' .
		json_encode( $body_classes ) .
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

	if ( ! empty( $body_classes ) ) {
		$body_class_string = implode( ' ', $body_classes );
		$html_with_body    = preg_replace_callback(
			'/<body\b([^>]*)>/i',
			static function ( array $matches ) use ( $body_class_string ): string {
				$attributes         = (string) ( $matches[1] ?? '' );
				$existing_classes   = '';
				$updated_attributes = preg_replace_callback(
					'/\sclass=(["\'])(.*?)\1/i',
					static function ( array $class_matches ) use ( &$existing_classes ): string {
						$existing_classes = (string) ( $class_matches[2] ?? '' );
						return '';
					},
					$attributes,
					1,
				);
				$updated_attributes = is_string( $updated_attributes )
					? trim( $updated_attributes )
					: trim( $attributes );
				$combined_classes   = trim(
					$existing_classes . ' ' . $body_class_string,
				);

				return '<body' .
					( '' !== $updated_attributes ? ' ' . $updated_attributes : '' ) .
					( '' !== $combined_classes
						? ' class="' .
							htmlspecialchars(
								$combined_classes,
								ENT_QUOTES,
								'UTF-8',
							) .
							'"'
						: '' ) .
					'>';
			},
			$updated_html,
			1,
		);

		if ( null !== $html_with_body ) {
			$updated_html = $html_with_body;
		}
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
	$maintenance_view_data = null;

	if ( file_exists( $autoload ) ) {
		require_once $autoload;

		if ( function_exists( 'peakurl_get_maintenance_view_data' ) ) {
			try {
				$maintenance_view_data = peakurl_get_maintenance_view_data();
			} catch ( \Throwable $exception ) {
				$maintenance_view_data = null;
			}
		}
	}

	peakurl_send_maintenance_response(
		Str::starts_with( $relative_path, '/api/' ),
		$maintenance_view_data,
	);
}

if ( ! file_exists( $autoload ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL dependencies are missing. Upload the complete release package before continuing.\n";
	exit();
}

require_once $autoload;

$runtime_state = InstallState::get_runtime_state( $app_path );

if ( peakurl_is_favicon_request( $relative_path ) ) {
	if ( InstallState::READY !== $runtime_state ) {
		http_response_code( 404 );
		exit();
	}

	$runtime_config  = RuntimeConfig::bootstrap( $app_path );
	$connection      = new Connection( $runtime_config );
	$settings_api    = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_name       = trim(
		(string) ( $connection->get_option( 'site_name' ) ?? 'PeakURL' ),
	);
	$favicon_service = new Favicon( $runtime_config, $settings_api );
	$favicon_assets  = $favicon_service->get_assets(
		'' !== $site_name ? $site_name : 'PeakURL'
	);

	if ( empty( $favicon_assets['configured'] ) ) {
		http_response_code( 404 );
		exit();
	}

	if ( '/site.webmanifest' === $relative_path ) {
		peakurl_send_file_response(
			(string) $favicon_assets['manifestPath'],
			'application/manifest+json; charset=utf-8',
			3600,
		);
	}

	peakurl_send_file_response(
		(string) $favicon_assets['iconPath'],
		'image/png',
		31536000,
	);
}

if ( Str::starts_with( $relative_path, '/api/' ) ) {
	if ( InstallState::READY !== $runtime_state ) {
		http_response_code( 503 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode(
			array(
				'success' => false,
				'message' => InstallState::NEEDS_INSTALL === $runtime_state
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

if ( InstallState::NEEDS_SETUP === $runtime_state ) {
	header( 'Location: ' . $setup_path );
	exit();
}

if ( InstallState::NEEDS_INSTALL === $runtime_state ) {
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
	(string) ( $connection->get_option( 'site_name' ) ?? 'PeakURL' ),
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
		$connection->get_option( 'installed_version' ) ??
		$runtime_config[ Constants::CONFIG_VERSION ] ??
		Constants::DEFAULT_VERSION
	),
);
$favicon        = ( new Favicon(
	$runtime_config,
	new SettingsApi( new PeakURL_DB( $connection ) ),
) )->get_settings( $site_name );
$body_classes   = get_body_class(
	array(),
	array(
		'base_path'     => $base_path,
		'relative_path' => $relative_path,
		'request_path'  => $path,
		'is_spa_shell'  => true,
	),
);
$runtime_env    = strtolower(
	(string) ( $runtime_config[ Constants::CONFIG_ENV ] ?? 'production' ),
);
$debug_enabled  =
	! empty( $runtime_config[ Constants::CONFIG_DEBUG ] ) ||
	'development' === $runtime_env;

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
	$body_classes,
	$locale,
	$text_direction,
	$catalog,
	$favicon,
	$debug_enabled,
);
