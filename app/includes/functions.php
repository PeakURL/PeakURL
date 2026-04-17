<?php
/**
 * Global runtime helper functions.
 *
 * @package PeakURL\Includes
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Connection;
use PeakURL\Includes\Constants;
use PeakURL\Includes\Hooks;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Services\Crypto;
use PeakURL\Services\I18n;
use PeakURL\Services\Mailer;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

/**
 * Normalize a runtime namespace segment into a kebab-case path segment.
 *
 * @param string $value Raw class or namespace segment.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_runtime_path_segment( string $value ): string {
	if ( 'PeakURL_DB' === $value ) {
		return 'peakurl-db';
	}

	if ( 'Str' === $value ) {
		return 'string';
	}

	$value = str_replace( '_', '-', $value );
	$value = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $value );
	$value = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', (string) $value );

	return strtolower( trim( (string) $value ) );
}

/**
 * Resolve a PeakURL runtime class name to its source file.
 *
 * Composer's classmap is still generated for the PHP runtime, but during
 * development a new file can exist before `composer dump-autoload` has
 * refreshed the map. This keeps WordPress-style filenames working without
 * blocking the whole API on a stale classmap.
 *
 * @param string $type Fully qualified class or trait name.
 * @return string|null Absolute file path when the class follows the runtime layout.
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_runtime_class_file( string $type ): ?string {
	$namespace_prefix = 'PeakURL\\';

	if ( 0 !== strpos( $type, $namespace_prefix ) ) {
		return null;
	}

	$relative_class = substr( $type, strlen( $namespace_prefix ) );

	if ( '' === $relative_class ) {
		return null;
	}

	$app_root      = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
	$segments      = explode( '\\', $relative_class );
	$type_name     = array_pop( $segments );
	$directory_map = array(
		'Api'         => 'api/',
		'Controllers' => 'controllers/',
		'Database'    => 'database/',
		'Http'        => 'http/',
		'Includes'    => 'includes/',
		'Services'    => 'services/',
		'Traits'      => 'traits/',
		'Utils'       => 'utils/',
	);

	if ( empty( $segments ) ) {
		return 'Store' === $type_name ? $app_root . 'store.php' : null;
	}

	$group = array_shift( $segments );

	if ( ! isset( $directory_map[ $group ] ) ) {
		return null;
	}

	if ( 'Controllers' === $group && 0 === substr_compare( $type_name, 'Controller', -10 ) ) {
		$type_name = substr( $type_name, 0, -10 );
	}

	if ( 'Api' === $group && 0 === substr_compare( $type_name, 'Api', -3 ) ) {
		$type_name = substr( $type_name, 0, -3 );
	}

	if ( 'Traits' === $group && 0 === substr_compare( $type_name, 'Trait', -5 ) ) {
		$type_name = substr( $type_name, 0, -5 );
	}

	$sub_path = '';

	foreach ( $segments as $segment ) {
		$segment_name = peakurl_get_runtime_path_segment( $segment );

		if ( '' === $segment_name ) {
			return null;
		}

		$sub_path .= $segment_name . DIRECTORY_SEPARATOR;
	}

	$file_name = peakurl_get_runtime_path_segment( $type_name );

	if ( '' === $file_name ) {
		return null;
	}

	$file = $app_root . $directory_map[ $group ] . $sub_path . $file_name . '.php';

	if ( is_readable( $file ) ) {
		return $file;
	}

	$nested_file = substr( $file, 0, -4 ) . DIRECTORY_SEPARATOR . basename( $file );

	if ( is_readable( $nested_file ) ) {
		return $nested_file;
	}

	$index_name = '';

	if ( ! empty( $segments ) ) {
		$index_name = peakurl_get_runtime_path_segment(
			(string) end( $segments )
		);
	}

	if ( 'base' === $file_name || $file_name === $index_name ) {
		$index_file = $app_root . $directory_map[ $group ] . $sub_path . 'index.php';

		if ( is_readable( $index_file ) ) {
			return $index_file;
		}
	}

	return $file;
}

/**
 * Load PeakURL runtime classes from the source tree when Composer misses them.
 *
 * @param string $type Fully qualified class or trait name.
 * @return void
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_load_runtime_class( string $type ): void {
	$file = peakurl_get_runtime_class_file( $type );

	if ( null === $file || ! is_readable( $file ) ) {
		return;
	}

	require_once $file;
}

spl_autoload_register( 'peakurl_load_runtime_class' );

/**
 * Convert a MySQL datetime string to an RFC 3339 timestamp or null.
 *
 * @param string|null $value Datetime string.
 * @return string|null RFC 3339 datetime or null.
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_mysql_to_rfc3339( ?string $value ): ?string {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return null;
	}

	$timestamp = strtotime( $value . ' UTC' );

	if ( false === $timestamp ) {
		return null;
	}

	return gmdate( DATE_ATOM, $timestamp );
}

/**
 * Send an email through the active PeakURL transport.
 *
 * Mirrors the role of WordPress `wp_mail()` while keeping PeakURL's
 * transport settings behind one public helper.
 *
 * @param string               $to_email Recipient email address.
 * @param string               $subject  Email subject line.
 * @param string               $message  Primary message body.
 * @param array<string, mixed> $args     Optional send arguments.
 * @return bool
 *
 * @throws \RuntimeException When PeakURL cannot deliver the email.
 * @since 1.0.0
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function PeakURL_Mail(
	string $to_email,
	string $subject,
	string $message,
	array $args = array()
): bool {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$crypto     = new Crypto( $config );
	$mailer     = new Mailer( $config, $settings, $crypto );
	$to_name    = trim( (string) ( $args['to_name'] ?? '' ) );
	$text       = array_key_exists( 'text_body', $args )
		? (string) $args['text_body']
		: trim( html_entity_decode( strip_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
	$html       = ! empty( $args['html'] )
		? $message
		: nl2br( htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) );

	$mailer->send( $to_email, $to_name, $subject, $html, $text );

	return true;
}

/**
 * Get the configured PeakURL site name.
 *
 * Mirrors the role of WordPress site helper functions so runtime code can
 * resolve the current site name without instantiating service classes.
 *
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_site_name(): string {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_name  = trim( (string) $settings->get_option( 'site_name' ) );
	$site_name  = '' !== $site_name ? $site_name : 'PeakURL';

	return (string) apply_filters(
		'site_name',
		$site_name,
		$settings,
		$config,
	);
}

/**
 * Get the configured PeakURL site URL.
 *
 * Mirrors the role of WordPress `get_site_url()` and accepts an optional
 * path plus a limited scheme override.
 *
 * @param string      $path   Optional path relative to the site URL.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_site_url( string $path = '', ?string $scheme = null ): string {
	$config     = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$connection = new Connection( $config );
	$settings   = new SettingsApi( new PeakURL_DB( $connection ) );
	$site_url   = trim( (string) $settings->get_option( 'site_url' ) );

	if ( '' === $site_url ) {
		$site_url = trim( (string) ( $config['SITE_URL'] ?? '' ) );
	}

	$site_url = rtrim( $site_url, '/' );

	if ( null !== $scheme ) {
		$normalized_scheme = strtolower( trim( $scheme ) );

		if ( in_array( $normalized_scheme, array( 'http', 'https' ), true ) ) {
			$parts = parse_url( $site_url );

			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$site_url = $normalized_scheme . '://' . $parts['host'];

				if ( ! empty( $parts['port'] ) ) {
					$site_url .= ':' . (int) $parts['port'];
				}

				if ( ! empty( $parts['path'] ) ) {
					$site_url .= rtrim( (string) $parts['path'], '/' );
				}
			}
		} elseif ( 'relative' === $normalized_scheme ) {
			$path_only = (string) parse_url( $site_url, PHP_URL_PATH );
			$site_url  = '' !== $path_only ? rtrim( $path_only, '/' ) : '';
		}
	}

	if ( '' !== $path ) {
		$site_url .= '/' . ltrim( $path, '/' );
	}

	return (string) apply_filters(
		'site_url',
		$site_url,
		$path,
		$scheme,
	);
}

/**
 * Get the configured PeakURL site URL.
 *
 * Mirrors the role of WordPress `site_url()` as a wrapper around
 * `get_site_url()`.
 *
 * @param string      $path   Optional path relative to the site URL.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function site_url( string $path = '', ?string $scheme = null ): string {
	return get_site_url( $path, $scheme );
}

/**
 * Get the canonical public API base URL for this PeakURL install.
 *
 * @param string      $path   Optional path relative to the API base.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_api_base_url( string $path = '', ?string $scheme = null ): string {
	$api_base_url = get_site_url( 'api/v1', $scheme );

	if ( '' !== $path ) {
		$api_base_url .= '/' . ltrim( $path, '/' );
	}

	return (string) apply_filters(
		'api_base_url',
		$api_base_url,
		$path,
		$scheme,
	);
}

/**
 * Get the canonical public API base URL for this PeakURL install.
 *
 * Mirrors the role of WordPress `site_url()` as a wrapper around
 * `get_api_base_url()`.
 *
 * @param string      $path   Optional path relative to the API base.
 * @param string|null $scheme Optional scheme override.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function api_base_url( string $path = '', ?string $scheme = null ): string {
	return get_api_base_url( $path, $scheme );
}

/**
 * Normalize a raw body class value into a sanitized class token.
 *
 * @param string $class_name Raw class token.
 * @return string
 * @since 1.0.11
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function sanitize_body_class( string $class_name ): string {
	$sanitized = strtolower( trim( $class_name ) );
	$sanitized = preg_replace( '/[^a-z0-9-]+/', '-', $sanitized );
	$sanitized = is_string( $sanitized ) ? $sanitized : '';
	$sanitized = preg_replace( '/-{2,}/', '-', $sanitized );
	$sanitized = is_string( $sanitized ) ? $sanitized : '';

	return trim( $sanitized, '-' );
}

/**
 * Normalize body class input into a unique list of sanitized class names.
 *
 * @param array|string $css_class Optional body classes.
 * @return array<int, string>
 * @since 1.0.11
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function sanitize_body_class_list( array|string $css_class = array() ): array {
	$raw_classes = is_array( $css_class )
		? $css_class
		: preg_split( '/\s+/', trim( $css_class ) );
	$raw_classes = is_array( $raw_classes ) ? $raw_classes : array();
	$classes     = array();
	$seen        = array();

	foreach ( $raw_classes as $class_name ) {
		if ( ! is_scalar( $class_name ) ) {
			continue;
		}

		$sanitized = sanitize_body_class( (string) $class_name );

		if ( '' === $sanitized || isset( $seen[ $sanitized ] ) ) {
			continue;
		}

		$seen[ $sanitized ] = true;
		$classes[]          = $sanitized;
	}

	return $classes;
}

/**
 * Get the current document body classes.
 *
 * Mirrors WordPress `get_body_class()` so runtime code and future plugins
 * can extend one shared class list from PHP.
 *
 * @param array|string         $css_class Optional body classes.
 * @param array<string, mixed> $context   Optional runtime context passed to filters.
 * @return array<int, string>
 * @since 1.0.11
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_body_class(
	array|string $css_class = array(),
	array $context = array()
): array {
	$class_names = sanitize_body_class_list(
		array_merge(
			array( 'peakurl-app' ),
			sanitize_body_class_list( $css_class ),
		)
	);
	$filtered    = apply_filters(
		'body_class',
		$class_names,
		$css_class,
		$context,
	);

	if ( ! is_array( $filtered ) && ! is_string( $filtered ) ) {
		return $class_names;
	}

	return sanitize_body_class_list( $filtered );
}

/**
 * Echo the current document body classes as a `class` attribute.
 *
 * Mirrors WordPress `body_class()`.
 *
 * @param array|string         $css_class Optional body classes.
 * @param array<string, mixed> $context   Optional runtime context passed to filters.
 * @return void
 * @since 1.0.11
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function body_class(
	array|string $css_class = array(),
	array $context = array()
): void {
	$class_names = get_body_class( $css_class, $context );

	if ( empty( $class_names ) ) {
		return;
	}

	echo 'class="' .
		htmlspecialchars(
			implode( ' ', $class_names ),
			ENT_QUOTES,
			'UTF-8',
		) .
		'"';
}

/**
 * Get the shared i18n service for the current request.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return I18n
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_i18n_service(
	?array $config = null,
	?Connection $connection = null
): I18n {
	static $service     = null;
	static $config_hash = null;

	if ( isset( $GLOBALS['peakurl_i18n_service_override'] ) ) {
		$override = $GLOBALS['peakurl_i18n_service_override'];

		if ( $override instanceof I18n ) {
			return $override;
		}
	}

	$resolved_config = $config ?? RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$next_hash       = md5(
		(string) json_encode(
			array(
				'content_dir' => (string) ( $resolved_config['PEAKURL_CONTENT_DIR'] ?? '' ),
				'site_url'    => (string) ( $resolved_config['SITE_URL'] ?? '' ),
				'db_name'     => (string) ( $resolved_config['DB_DATABASE'] ?? '' ),
			),
		),
	);

	if ( $service instanceof I18n && $config_hash === $next_hash ) {
		return $service;
	}

	$resolved_connection = $connection ?? new Connection( $resolved_config );
	$settings_api        = new SettingsApi( new PeakURL_DB( $resolved_connection ) );
	$service             = new I18n( $resolved_config, $settings_api );
	$config_hash         = $next_hash;

	return $service;
}

/**
 * Override the shared i18n service for the current request lifecycle.
 *
 * Used by early installer screens that need translations before the normal
 * runtime database-backed locale flow is available.
 *
 * @param I18n|null $service Override service or null to clear it.
 * @return void
 * @since 1.0.8
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_override_i18n_service( ?I18n $service ): void {
	if ( null === $service ) {
		unset( $GLOBALS['peakurl_i18n_service_override'] );
		return;
	}

	$GLOBALS['peakurl_i18n_service_override'] = $service;
}

/**
 * Bootstrap the active locale for the current request.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return string Loaded locale.
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_bootstrap_i18n(
	?array $config = null,
	?Connection $connection = null
): string {
	return peakurl_get_i18n_service(
		$config,
		$connection,
	)->load_locale();
}

/**
 * Get the dashboard JSON catalog for the active locale.
 *
 * @param string|null              $locale     Optional locale override.
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null          $connection Optional reused connection.
 * @return array<string, mixed>
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_dashboard_translation_catalog(
	?string $locale = null,
	?array $config = null,
	?Connection $connection = null
): array {
	return peakurl_get_i18n_service(
		$config,
		$connection,
	)->get_dashboard_catalog( $locale );
}

/**
 * Get the current locale.
 *
 * Mirrors WordPress `get_locale()`.
 *
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_locale(): string {
	return peakurl_get_i18n_service()->get_current_locale();
}

/**
 * Determine the current locale.
 *
 * Mirrors WordPress `determine_locale()`.
 *
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function determine_locale(): string {
	return get_locale();
}

/**
 * Get the active locale as an HTML `lang` attribute.
 *
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_html_lang_attribute(): string {
	return peakurl_get_i18n_service()->get_html_lang();
}

/**
 * Get the active locale as an HTML `dir` attribute.
 *
 * @return string
 * @since 1.0.7
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_text_direction(): string {
	return peakurl_get_i18n_service()->get_text_direction();
}

/**
 * Build the translated maintenance page copy and document metadata.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return array<string, string>
 * @since 1.0.8
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_maintenance_view_data(
	?array $config = null,
	?Connection $connection = null
): array {
	$resolved_config     = $config ?? RuntimeConfig::bootstrap( ABSPATH . 'app' );
	$resolved_connection = $connection;
	$site_name           = 'PeakURL';
	$locale              = Constants::DEFAULT_LOCALE;
	$html_lang           = 'en-US';
	$text_direction      = 'ltr';
	$i18n_service        = null;

	try {
		if (
			null === $resolved_connection &&
			file_exists( ABSPATH . 'config.php' )
		) {
			$resolved_connection = new Connection( $resolved_config );
		}

		$i18n_service   = new I18n(
			$resolved_config,
			null !== $resolved_connection
				? new SettingsApi( new PeakURL_DB( $resolved_connection ) )
				: null,
		);
		$locale         = $i18n_service->load_locale();
		$html_lang      = $i18n_service->get_html_lang( $locale );
		$text_direction = $i18n_service->get_text_direction( $locale );

		if ( null !== $resolved_connection ) {
			$configured_site_name = trim(
				(string) ( $resolved_connection->get_option( 'site_name' ) ?? '' ),
			);

			if ( '' !== $configured_site_name ) {
				$site_name = $configured_site_name;
			}
		}
	} catch ( \Throwable $exception ) {
		$i18n_service   = null;
		$locale         = Constants::DEFAULT_LOCALE;
		$html_lang      = 'en-US';
		$text_direction = 'ltr';
	}

	if ( $i18n_service instanceof I18n ) {
		peakurl_override_i18n_service( $i18n_service );
	}

	$maintenance_title = sprintf(
		/* translators: %s: configured site name. */
		__( '%s is briefly unavailable', 'peakurl' ),
		$site_name,
	);

	$maintenance_api_message = sprintf(
		/* translators: %s: configured site name. */
		__( '%s is briefly unavailable right now. Please try again in a moment.', 'peakurl' ),
		$site_name,
	);

	return array(
		'siteName'          => $site_name,
		'locale'            => $locale,
		'htmlLang'          => $html_lang,
		'textDirection'     => $text_direction,
		'title'             => $maintenance_title,
		'statusLabel'       => __( 'Temporarily unavailable', 'peakurl' ),
		'heading'           => __( 'Briefly unavailable', 'peakurl' ),
		'message'           => __( 'We are making a few improvements right now. Please refresh this page in a moment.', 'peakurl' ),
		'supportingMessage' => __( 'Thanks for your patience.', 'peakurl' ),
		'loadingLabel'      => __( 'Loading', 'peakurl' ),
		'apiMessage'        => $maintenance_api_message,
	);
}

/**
 * Build the maintenance-mode JSON response payload.
 *
 * @param array<string, string> $maintenance_view_data Localized maintenance data.
 * @return array<string, mixed>
 * @since 1.0.8
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_maintenance_api_payload( array $maintenance_view_data ): array {
	return array(
		'success' => false,
		'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is updating. Please try again in a moment.' ),
		'data'    => array(
			'maintenance' => true,
		),
	);
}

/**
 * Render the HTML maintenance page.
 *
 * @param array<string, string> $maintenance_view_data Localized maintenance data.
 * @return string
 * @since 1.0.8
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_render_maintenance_page( array $maintenance_view_data ): string {
	$html_lang          = htmlspecialchars(
		(string) ( $maintenance_view_data['htmlLang'] ?? 'en-US' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$text_direction     = 'rtl' === strtolower( (string) ( $maintenance_view_data['textDirection'] ?? 'ltr' ) )
		? 'rtl'
		: 'ltr';
	$title              = htmlspecialchars(
		(string) ( $maintenance_view_data['title'] ?? 'PeakURL is briefly unavailable' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$status_label       = htmlspecialchars(
		(string) ( $maintenance_view_data['statusLabel'] ?? 'Update in progress' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$heading            = htmlspecialchars(
		(string) ( $maintenance_view_data['heading'] ?? 'Briefly unavailable' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$message            = htmlspecialchars(
		(string) ( $maintenance_view_data['message'] ?? 'We are finishing an update right now. Please refresh this page in a moment.' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$supporting_message = htmlspecialchars(
		(string) ( $maintenance_view_data['supportingMessage'] ?? 'Your dashboard and short links will be back shortly.' ),
		ENT_QUOTES,
		'UTF-8',
	);
	$loading_label      = htmlspecialchars(
		(string) ( $maintenance_view_data['loadingLabel'] ?? 'Loading' ),
		ENT_QUOTES,
		'UTF-8',
	);

	return '<!doctype html>' .
		'<html lang="' . $html_lang . '" dir="' . $text_direction . '">' .
		'<head>' .
		'<meta charset="utf-8">' .
		'<title>' . $title . '</title>' .
		'<meta name="viewport" content="width=device-width, initial-scale=1">' .
		'<style>' .
		'body{margin:0;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(circle at top,#eef2ff 0,#f8fafc 38%,#eef2ff 100%);color:#0f172a;min-height:100vh}' .
		'.shell{min-height:100vh;display:grid;place-items:center;padding:24px}' .
		'.card{width:min(100%,540px);background:rgba(255,255,255,.96);border:1px solid rgba(99,102,241,.14);border-radius:28px;box-shadow:0 28px 90px rgba(15,23,42,.12);padding:36px 32px}' .
		'.status{display:flex;align-items:center;gap:14px;margin-bottom:22px}' .
		'.status-label{font-size:12px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#6366f1}' .
		'.loader{width:18px;height:18px;border-radius:999px;border:2px solid rgba(99,102,241,.18);border-top-color:#6366f1;animation:peakurl-spin .8s linear infinite;flex:none}' .
		'h1{margin:0 0 14px;font-size:clamp(2rem,4vw,2.6rem);line-height:1.04;letter-spacing:-.04em;color:#111827}' .
		'p{margin:0;font-size:1rem;line-height:1.75;color:#475569}' .
		'.supporting{margin-top:14px;color:#64748b}' .
		'@keyframes peakurl-spin{to{transform:rotate(360deg)}}' .
		'@media (max-width:640px){.shell{padding:18px}.card{padding:30px 22px;border-radius:24px}}' .
		'</style>' .
		'</head>' .
		'<body>' .
		'<main class="shell">' .
		'<section class="card">' .
		'<div class="status">' .
		'<div class="loader" aria-hidden="true"></div>' .
		'<span class="status-label">' . $status_label . '</span>' .
		'<span class="screen-reader-text" style="position:absolute;left:-9999px">' . $loading_label . '</span>' .
		'</div>' .
		'<h1>' . $heading . '</h1>' .
		'<p>' . $message . '</p>' .
		'<p class="supporting">' . $supporting_message . '</p>' .
		'</section>' .
		'</main>' .
		'</body>' .
		'</html>';
}

/**
 * Determine whether the active locale uses right-to-left layout.
 *
 * Mirrors WordPress `is_rtl()`.
 *
 * @return bool
 * @since 1.0.7
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function is_rtl(): bool {
	return peakurl_get_i18n_service()->is_locale_rtl();
}

/**
 * Translate a text string.
 *
 * Mirrors WordPress `translate()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:disable WordPress.WP.I18n -- Intentional core translation helpers.
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function translate( string $text, string $domain = 'default' ): string {
	if ( 'default' !== $domain && 'peakurl' !== $domain ) {
		return $text;
	}

	return peakurl_get_i18n_service()->translate( $text );
}

/**
 * Translate a text string with context.
 *
 * Mirrors WordPress `translate_with_gettext_context()`.
 *
 * @param string $text    Source text.
 * @param string $context Gettext context.
 * @param string $domain  Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function translate_with_gettext_context(
	string $text,
	string $context,
	string $domain = 'default'
): string {
	if ( 'default' !== $domain && 'peakurl' !== $domain ) {
		return $text;
	}

	return peakurl_get_i18n_service()->translate( $text, $context );
}

/**
 * Retrieve the translation of a string.
 *
 * Mirrors WordPress `__()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function __( string $text, string $domain = 'default' ): string {
	return translate( $text, $domain );
}

/**
 * Display the translated string.
 *
 * Mirrors WordPress `_e()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return void
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function _e( string $text, string $domain = 'default' ): void {
	echo __( $text, $domain );
}

/**
 * Translate a string with context.
 *
 * Mirrors WordPress `_x()`.
 *
 * @param string $text    Source text.
 * @param string $context Gettext context.
 * @param string $domain  Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function _x(
	string $text,
	string $context,
	string $domain = 'default'
): string {
	return translate_with_gettext_context( $text, $context, $domain );
}

/**
 * Echo a contextual translation.
 *
 * Mirrors WordPress `_ex()`.
 *
 * @param string $text    Source text.
 * @param string $context Gettext context.
 * @param string $domain  Optional text domain.
 * @return void
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function _ex(
	string $text,
	string $context,
	string $domain = 'default'
): void {
	echo _x( $text, $context, $domain );
}

/**
 * Translate plural strings.
 *
 * Mirrors WordPress `_n()`.
 *
 * @param string $single Singular string.
 * @param string $plural Plural string.
 * @param int    $number Count used for plural selection.
 * @param string $domain Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function _n(
	string $single,
	string $plural,
	int $number,
	string $domain = 'default'
): string {
	if ( 'default' !== $domain && 'peakurl' !== $domain ) {
		return 1 === abs( $number ) ? $single : $plural;
	}

	return peakurl_get_i18n_service()->translate_plural(
		$single,
		$plural,
		$number,
	);
}

/**
 * Translate plural strings with context.
 *
 * Mirrors WordPress `_nx()`.
 *
 * @param string $single  Singular string.
 * @param string $plural  Plural string.
 * @param int    $number  Count used for plural selection.
 * @param string $context Gettext context.
 * @param string $domain  Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function _nx(
	string $single,
	string $plural,
	int $number,
	string $context,
	string $domain = 'default'
): string {
	if ( 'default' !== $domain && 'peakurl' !== $domain ) {
		return 1 === abs( $number ) ? $single : $plural;
	}

	return peakurl_get_i18n_service()->translate_plural(
		$single,
		$plural,
		$number,
		$context,
	);
}

/**
 * Translate and escape text for HTML output.
 *
 * Mirrors WordPress `esc_html__()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function esc_html__( string $text, string $domain = 'default' ): string {
	return htmlspecialchars( __( $text, $domain ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Echo translated and escaped HTML text.
 *
 * Mirrors WordPress `esc_html_e()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return void
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html__( $text, $domain );
}

/**
 * Translate and escape text for HTML attributes.
 *
 * Mirrors WordPress `esc_attr__()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return string
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function esc_attr__( string $text, string $domain = 'default' ): string {
	return htmlspecialchars( __( $text, $domain ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Echo translated and escaped attribute text.
 *
 * Mirrors WordPress `esc_attr_e()`.
 *
 * @param string $text   Source text.
 * @param string $domain Optional text domain.
 * @return void
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo esc_attr__( $text, $domain );
}
// phpcs:enable

/**
 * Build a human-readable display name from a user row.
 *
 * @param array<string, mixed> $user User database row.
 * @return string
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_user_display_name( array $user ): string {
	$full_name    = trim(
		(string) ( $user['first_name'] ?? '' ) . ' ' . (string) ( $user['last_name'] ?? '' )
	);
	$display_name = '' !== $full_name
		? $full_name
		: trim( (string) ( $user['username'] ?? '' ) );
	$display_name = '' !== $display_name ? $display_name : 'there';

	return (string) apply_filters(
		'user_display_name',
		$display_name,
		$user,
	);
}

/**
 * Register a filter callback.
 *
 * Mirrors the role of WordPress `add_filter()` for PeakURL runtime hooks.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback to register.
 * @param int      $priority      Execution priority.
 * @param int      $accepted_args Number of accepted callback arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function add_filter(
	string $hook_name,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): void {
	Hooks::add( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Apply filters to a value.
 *
 * Mirrors the role of WordPress `apply_filters()` for PeakURL runtime hooks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Initial filtered value.
 * @param mixed  ...$args   Additional hook arguments.
 * @return mixed
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function apply_filters( string $hook_name, $value, ...$args ) {
	return Hooks::apply_filters( $hook_name, $value, ...$args );
}

/**
 * Register an action callback.
 *
 * Mirrors the role of WordPress `add_action()` for PeakURL runtime hooks.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback to register.
 * @param int      $priority      Execution priority.
 * @param int      $accepted_args Number of accepted callback arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function add_action(
	string $hook_name,
	callable $callback,
	int $priority = 10,
	int $accepted_args = 1
): void {
	Hooks::add( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Execute action callbacks for a hook.
 *
 * Mirrors the role of WordPress `do_action()` for PeakURL runtime hooks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  ...$args   Hook arguments.
 * @return void
 * @since 1.0.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function do_action( string $hook_name, ...$args ): void {
	Hooks::do_action( $hook_name, ...$args );
}
