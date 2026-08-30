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
 *  - Dashboard app HTML injection for `/`, `/login`, `/dashboard*`.
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
$base_path_from = static function ( string $script_name ): string {
	$base_path = str_replace( '\\', '/', dirname( $script_name ) );

	if ( '.' === $base_path || '/' === $base_path ) {
		return '';
	}

	return rtrim( $base_path, '/' );
};

/**
 * Strip the base path prefix to produce a root-relative request path.
 *
 * @param string $request_path Raw request path from parse_url().
 * @param string $base_path    Base path (may be empty).
 * @return string Relative path starting with '/'.
 * @since 1.0.0
 */
$request_path_from = static function (
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
};

/**
 * Build a full URL path by combining the base path and a suffix.
 *
 * @param string $base_path Base path (may be empty).
 * @param string $suffix    Suffix to append (e.g. '/install.php').
 * @return string Combined URL path.
 * @since 1.0.0
 */
$app_url = static function ( string $base_path, string $suffix ): string {
	$normalized_suffix = '/' . ltrim( $suffix, '/' );

	if ( '' === $base_path ) {
		return $normalized_suffix;
	}

	return $base_path . $normalized_suffix;
};

/** Get the absolute path to the maintenance flag file. */
$maintenance_file = static function (): string {
	return __DIR__ . '/.maintenance';
};

/** Check whether the site is in maintenance mode. */
$is_maintenance = static fn(): bool => file_exists( $maintenance_file() );

/**
 * Send a 503 maintenance response and terminate.
 *
 * API requests receive JSON; browser requests receive an HTML page.
 *
 * @param bool                       $is_api_request        Whether the inbound request targets /api/*.
 * @param array<string, string>|null $maintenance_view_data Optional localized maintenance data.
 * @since 1.0.0
 */
$send_maintenance = static function (
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
		echo function_exists( 'get_maintenance_api_payload' )
			? json_encode(
				get_maintenance_api_payload( $maintenance_view_data ),
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
	echo function_exists( 'render_maintenance_page' )
		? render_maintenance_page( $maintenance_view_data )
		: '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PeakURL is briefly unavailable</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><p>PeakURL is briefly unavailable right now. Please try again in a moment.</p></body></html>';
	exit();
};

/**
 * Determine whether the current request targets a favicon alias route.
 *
 * @param string $relative_path Root-relative request path.
 * @return bool
 * @since 1.0.14
 */
$is_favicon = static function ( string $relative_path ): bool {
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
};

/**
 * Send a static file response and terminate the request.
 *
 * @param string $file_path    Absolute file path.
 * @param string $content_type Content-Type header value.
 * @param int    $max_age      Cache lifetime in seconds.
 * @return void
 * @since 1.0.14
 */
$send_file = static function (
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
};

/**
 * Build the favicon and manifest markup for the dashboard app.
 *
 * @param string               $site_name Configured site name.
 * @param array<string, mixed> $favicon   Favicon settings payload.
 * @return string
 * @since 1.0.14
 */
$favicon_markup = static function (
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

	$markup = '<link data-peakurl-favicon="1" rel="icon" type="image/png" href="' . $icon_url . '"' . $sizes_attr . '>' .
		"\n" .
		'<link data-peakurl-favicon="1" rel="shortcut icon" type="image/png" href="' . $shortcut_url . '">';

	if ( '' !== $apple_touch_url ) {
		$markup .=
			"\n" .
			'<link data-peakurl-favicon="1" rel="apple-touch-icon" href="' .
			htmlspecialchars( $apple_touch_url, ENT_QUOTES, 'UTF-8' ) .
			'">';
	}

	if ( '' !== $manifest_url ) {
		$markup .=
			"\n" .
			'<link data-peakurl-favicon="1" rel="manifest" href="' .
			htmlspecialchars( $manifest_url, ENT_QUOTES, 'UTF-8' ) .
			'">';
	}

	return $markup .
		"\n" .
		'<meta data-peakurl-favicon="1" name="apple-mobile-web-app-title" content="' . $site_name_attr . '">';
};

/**
 * Decide whether a request path should serve the React dashboard app.
 *
 * Matches `/`, `/login`, `/forgot-password`, `/reset-password/*`,
 * `/dashboard`, and `/dashboard/*`.
 *
 * @param string $relative_path Root-relative request path.
 * @return bool True when the dashboard app HTML should be returned.
 * @since 1.0.0
 */
$is_dashboard_path = static function ( string $relative_path ): bool {
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
};

/**
 * Inject PHP-provided app data into the dashboard app HTML.
 *
 * Inserts a `<base>` tag and a single `window.__PEAKURL__` object carrying
 * the dashboard values the React app needs before it renders.
 *
 * @param string               $html         Raw app.html content.
 * @param array<int, string>   $body_classes Initial body classes from PHP hooks.
 * @param array<string, mixed> $peakurl_data Dashboard data from get_peakurl_data().
 * @return string Modified HTML.
 * @since 1.0.0
 */
$prepare_html = static function (
	string $html,
	array $body_classes,
	array $peakurl_data
) use ( $favicon_markup ): string {
	$base_path      = trim( (string) ( $peakurl_data['basePath'] ?? '' ) );
	$base_href      = '' === $base_path ? '/' : $base_path . '/';
	$site_name      = trim( (string) ( $peakurl_data['siteName'] ?? 'PeakURL' ) );
	$html_lang      = htmlspecialchars(
		(string) ( $peakurl_data['htmlLang'] ?? 'en-US' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$html_dir       = 'rtl' === strtolower(
		(string) ( $peakurl_data['textDirection'] ?? 'ltr' )
	) ? 'rtl' : 'ltr';
	$favicon        = is_array( $peakurl_data['favicon'] ?? null )
		? $peakurl_data['favicon']
		: array();
	$favicon_head   = $favicon_markup(
		$site_name,
		$favicon,
	);
	$peakurl_json   = json_encode(
		$peakurl_data,
		JSON_HEX_TAG |
			JSON_HEX_AMP |
			JSON_HEX_APOS |
			JSON_HEX_QUOT |
			JSON_UNESCAPED_SLASHES |
			JSON_UNESCAPED_UNICODE,
	);
	$peakurl_json   = is_string( $peakurl_json ) ? $peakurl_json : '{}';
	$generator_meta = get_generator_tag( (string) ( $peakurl_data['version'] ?? '' ) );

	$dashboard_data_script =
		'<base href="' .
		htmlspecialchars( $base_href, ENT_QUOTES, 'UTF-8' ) .
		'">' .
		"\n" .
		( '' !== $generator_meta ? $generator_meta . "\n\t\t" : '' ) .
		( '' !== $favicon_head ? $favicon_head . "\n" : '' ) .
		'<script>window.__PEAKURL__=' .
		$peakurl_json .
		';</script>';

	$updated_html   = str_replace( '<head>', "<head>\n\t\t" . $dashboard_data_script, $html );
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

	if ( $updated_html !== $html ) {
		return $updated_html;
	}

	$fallback_after_body_open = preg_replace_callback(
		'/<body\b[^>]*>/i',
		static function ( array $matches ) use ( $dashboard_data_script ): string {
			return (string) ( $matches[0] ?? '' ) . $dashboard_data_script;
		},
		$html,
		1,
	);

	if (
		is_string( $fallback_after_body_open ) &&
		$fallback_after_body_open !== $html
	) {
		return $fallback_after_body_open;
	}

	$fallback_before_body_close = preg_replace_callback(
		'/<\/body>/i',
		static function ( array $matches ) use ( $dashboard_data_script ): string {
			return $dashboard_data_script . (string) ( $matches[0] ?? '' );
		},
		$html,
		1,
	);

	if (
		is_string( $fallback_before_body_close ) &&
		$fallback_before_body_close !== $html
	) {
		return $fallback_before_body_close;
	}

	return $html;
};

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

$base_path                      = $base_path_from(
	(string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ),
);
$relative_path                  = $request_path_from( $path, $base_path );
$setup_path                     = $app_url( $base_path, '/setup-config.php' );
$install_path                   = $app_url( $base_path, '/install.php' );
$database_connection_error_path = $app_url( $base_path, '/database-connection-error.php' );

if ( $is_maintenance() ) {
	$maintenance_view_data = null;

	if ( file_exists( $autoload ) ) {
		require_once $autoload;

		if ( function_exists( 'get_maintenance_view_data' ) ) {
			try {
				$maintenance_view_data = get_maintenance_view_data();
			} catch ( \Throwable $exception ) {
				$maintenance_view_data = null;
			}
		}
	}

	$send_maintenance(
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

$install_state = InstallState::get_state( $app_path );

if ( $is_favicon( $relative_path ) ) {
	if ( InstallState::READY !== $install_state ) {
		http_response_code( 404 );
		exit();
	}

	$app_config      = RuntimeConfig::bootstrap( $app_path );
	$connection      = new Connection( $app_config );
	$settings_api    = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_name       = trim(
		(string) ( $connection->get_option( 'site_name' ) ?? 'PeakURL' ),
	);
	$favicon_service = new Favicon( $app_config, $settings_api );
	$favicon_assets  = $favicon_service->get_assets(
		'' !== $site_name ? $site_name : 'PeakURL'
	);

	if ( empty( $favicon_assets['configured'] ) ) {
		http_response_code( 404 );
		exit();
	}

	if ( '/site.webmanifest' === $relative_path ) {
		$send_file(
			(string) $favicon_assets['manifestPath'],
			'application/manifest+json; charset=utf-8',
			3600,
		);
	}

	$send_file(
		(string) $favicon_assets['iconPath'],
		'image/png',
		31536000,
	);
}

if ( Str::starts_with( $relative_path, '/api/' ) ) {
	if ( InstallState::READY !== $install_state ) {
		http_response_code( 503 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode(
			array(
				'success' => false,
				'message' => InstallState::NEEDS_INSTALL === $install_state
					? 'PeakURL needs installation.'
					: ( InstallState::DATABASE_CONNECTION_ERROR === $install_state
						? 'PeakURL could not connect to the configured database.'
						: 'PeakURL needs database configuration.' ),
				'data'    => array(
					'setupConfigUrl'             => $setup_path,
					'installUrl'                 => $install_path,
					'databaseConnectionErrorUrl' => $database_connection_error_path,
					'isConfigured'               => file_exists( $config_path ),
					'recoveryState'              => $install_state,
				),
			),
			JSON_PRETTY_PRINT,
		);
		exit();
	}

	require $app_path . '/public/index.php';
	exit();
}

if ( InstallState::DATABASE_CONNECTION_ERROR === $install_state ) {
	$safe_database_connection_error_path = str_replace(
		array( "\r", "\n" ),
		'',
		(string) $database_connection_error_path,
	);

	if (
		'' === $safe_database_connection_error_path ||
		'/' !== $safe_database_connection_error_path[0]
	) {
		$safe_database_connection_error_path = '/';
	}

	header( 'Location: ' . $safe_database_connection_error_path, true, 302 );
	exit();
}

if ( InstallState::NEEDS_SETUP === $install_state ) {
	$safe_setup_path = str_replace( array( "\r", "\n" ), '', (string) $setup_path );

	if ( '' === $safe_setup_path || '/' !== $safe_setup_path[0] ) {
		$safe_setup_path = '/';
	}

	header( 'Location: ' . $safe_setup_path, true, 302 );
	exit();
}

if ( InstallState::NEEDS_INSTALL === $install_state ) {
	$safe_install_path = str_replace( array( "\r", "\n" ), '', (string) $install_path );

	if ( '' === $safe_install_path || '/' !== $safe_install_path[0] ) {
		$safe_install_path = '/';
	}

	header( 'Location: ' . $safe_install_path, true, 302 );
	exit();
}

if ( '/' === $relative_path && InstallState::READY === $install_state ) {
	$app_config   = RuntimeConfig::bootstrap( $app_path );
	$connection   = new Connection( $app_config );
	$settings_api = new SettingsApi( new PeakURL_DB( $connection ) );

	$landing_page_mode = $settings_api->get_option( 'landing_page_mode' );
	if ( empty( $landing_page_mode ) ) {
		$landing_page_mode = 'html';
	}

	if ( 'url' === $landing_page_mode ) {
		$landing_page_url = $settings_api->get_option( 'landing_page_url' );

		if ( is_string( $landing_page_url ) && '' !== trim( $landing_page_url ) ) {
			header( 'Location: ' . trim( $landing_page_url ), true, 302 );
			exit();
		}
	} elseif ( 'html' === $landing_page_mode ) {
		$landing_page_file = ABSPATH . 'content/landing-page.html';
		if ( file_exists( $landing_page_file ) ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			if ( function_exists( 'get_landing_page_html' ) ) {
				echo get_landing_page_html( $landing_page_file, $app_config, $connection );
			} else {
				readfile( $landing_page_file );
			}
			exit();
		}
	}
}

if ( ! $is_dashboard_path( $relative_path ) ) {
	require $app_path . '/public/index.php';
	exit();
}

$app_config = RuntimeConfig::bootstrap( $app_path );
$connection = new Connection( $app_config );
load_i18n( $app_config, $connection );

/**
 * Fires after PeakURL has loaded configuration, translations, and shared helpers.
 *
 * This is the main request-level initialization hook for custom PHP code.
 *
 * @since 1.2.2
 */
do_action( 'init' );

$body_classes  = get_body_class(
	array(),
	array(
		'base_path'        => $base_path,
		'relative_path'    => $relative_path,
		'request_path'     => $path,
		'is_dashboard_app' => true,
	),
);
$app_env       = strtolower(
	(string) ( $app_config[ Constants::ENV ] ?? 'production' ),
);
$debug_enabled =
	! empty( $app_config[ Constants::DEBUG ] ) ||
	'development' === $app_env;

/**
 * Fires after the dashboard page context has been prepared.
 *
 * Custom PHP can use this hook for dashboard-only setup. Public
 * short-link redirects do not run this action.
 *
 * @since 1.2.2
 */
do_action( 'admin_init' );

/*
 * Build the PHP-provided client data after dashboard hooks run so filters can
 * adjust the payload before it is serialized into the app HTML.
 */
$peakurl_data = get_peakurl_data(
	array(
		'base_path'  => $base_path,
		'config'     => $app_config,
		'connection' => $connection,
		'debug'      => $debug_enabled,
	)
);

$dashboard_html_path = $root_path . '/app.html';

if ( ! file_exists( $dashboard_html_path ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL build output is missing. Upload the full release package and try again.\n";
	exit();
}

header( 'Content-Type: text/html; charset=utf-8' );
header(
	'Content-Language: ' .
	(string) ( $peakurl_data['htmlLang'] ?? get_html_lang_attribute() )
);
$dashboard_html = file_get_contents( $dashboard_html_path );

if ( false === $dashboard_html ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "PeakURL build output could not be read.\n";
	exit();
}

echo $prepare_html(
	$dashboard_html,
	$body_classes,
	$peakurl_data,
);
