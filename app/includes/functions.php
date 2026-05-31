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
use PeakURL\Services\Favicon;
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
 * Get the shared PeakURL configuration for the current request.
 *
 * This keeps global helpers from repeating the same config bootstrapping work
 * each time they need settings, URLs, mail, or i18n services.
 *
 * @return array<string, mixed>
 * @since 1.2.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function get_peakurl_config(): array {
	static $config = null;

	if ( null === $config ) {
		$config = RuntimeConfig::bootstrap( ABSPATH . 'app' );
	}

	return $config;
}

/**
 * Build a stable hash from selected runtime config values.
 *
 * Shared request helpers use this to decide when a cached service can be
 * reused without keeping raw secret values in object state.
 *
 * @param array<string, mixed> $config Runtime config map.
 * @param array<int, string>   $keys   Config keys that affect the service.
 * @param array<string, mixed> $extra  Extra identity values, such as object IDs.
 * @return string
 * @since 1.2.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function get_peakurl_config_hash(
	array $config,
	array $keys,
	array $extra = array()
): string {
	$values = array();

	foreach ( $keys as $key ) {
		$values[ $key ] = (string) ( $config[ $key ] ?? '' );
	}

	foreach ( $extra as $key => $value ) {
		$values[ $key ] = is_scalar( $value ) || null === $value
			? (string) $value
			: gettype( $value );
	}

	$encoded = json_encode( $values );

	return md5( is_string( $encoded ) ? $encoded : serialize( $values ) );
}

/**
 * Get the shared database connection for the current request.
 *
 * @param array<string, mixed>|null $config Optional app config.
 * @return Connection
 * @since 1.2.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function get_peakurl_connection( ?array $config = null ): Connection {
	static $connection  = null;
	static $config_hash = null;

	$app_config = $config ?? get_peakurl_config();
	$next_hash  = get_peakurl_config_hash(
		$app_config,
		Constants::DB_KEYS,
	);

	if ( $connection instanceof Connection && $config_hash === $next_hash ) {
		return $connection;
	}

	$connection  = new Connection( $app_config );
	$config_hash = $next_hash;

	return $connection;
}

/**
 * Get the shared settings API for the current request.
 *
 * @param array<string, mixed>|null $config     Optional app config.
 * @param Connection|null           $connection Optional reused connection.
 * @return SettingsApi
 * @since 1.2.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function get_settings_api(
	?array $config = null,
	?Connection $connection = null
): SettingsApi {
	static $settings_api = null;
	static $cache_key    = null;

	$app_config     = $config ?? get_peakurl_config();
	$app_connection = $connection ?? get_peakurl_connection( $app_config );
	$next_cache_key = get_peakurl_config_hash(
		$app_config,
		Constants::DB_KEYS,
		array( 'connection' => spl_object_id( $app_connection ) ),
	);

	if ( $settings_api instanceof SettingsApi && $cache_key === $next_cache_key ) {
		return $settings_api;
	}

	$settings_api = new SettingsApi( new PeakURL_DB( $app_connection ) );
	$cache_key    = $next_cache_key;

	return $settings_api;
}

/**
 * Send an email through the active PeakURL transport.
 *
 * Mirrors the role of WordPress `wp_mail()` while keeping PeakURL's
 * transport settings behind one public helper.
 *
 * @param string                                                           $to_email Recipient email address.
 * @param string                                                           $subject  Email subject line.
 * @param string                                                           $message  Primary message body.
 * @param array{to_name?: string, text_body?: string, html?: bool} $args     Optional send arguments.
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
	$config   = get_peakurl_config();
	$settings = get_settings_api( $config );
	$crypto   = new Crypto( $config );
	$mailer   = new Mailer( $config, $settings, $crypto );
	$to_name  = trim( (string) ( $args['to_name'] ?? '' ) );
	$text     = array_key_exists( 'text_body', $args )
		? (string) $args['text_body']
		: trim( html_entity_decode( strip_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
	$html     = ! empty( $args['html'] )
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
	$config    = get_peakurl_config();
	$settings  = get_settings_api( $config );
	$site_name = trim( (string) $settings->get_option( 'site_name' ) );
	$site_name = '' !== $site_name ? $site_name : 'PeakURL';

	return (string) apply_filters(
		'site_name',
		$site_name,
		$settings,
		$config,
	);
}

/**
 * Remove trailing forward and backslashes from a string.
 *
 * Mirrors WordPress `untrailingslashit()`.
 *
 * @param string $value Raw string value.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

/**
 * Append one trailing forward slash to a string.
 *
 * Mirrors WordPress `trailingslashit()`.
 *
 * @param string $value Raw string value.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function trailingslashit( string $value ): string {
	return untrailingslashit( $value ) . '/';
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
	$config   = get_peakurl_config();
	$settings = get_settings_api( $config );
	$site_url = trim( (string) $settings->get_option( 'site_url' ) );

	if ( '' === $site_url ) {
		$site_url = trim(
			(string) ( $config[ Constants::SITE_URL ] ?? '' ),
		);
	}

	$site_url = untrailingslashit( $site_url );

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
					$site_url .= untrailingslashit( (string) $parts['path'] );
				}
			}
		} elseif ( 'relative' === $normalized_scheme ) {
			$path_only = (string) parse_url( $site_url, PHP_URL_PATH );
			$site_url  = '' !== $path_only ? untrailingslashit( $path_only ) : '';
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
 * Convenience wrapper for get_site_url().
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
	$api_base_url = get_site_url(
		ltrim( Constants::API_BASE_PATH, '/' ),
		$scheme,
	);

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
 * Build the client data object consumed by the dashboard app.
 *
 * This is the one PHP source for `window.__PEAKURL__` values. Packaged HTML
 * rendering and the Vite i18n fallback both use this helper so their payloads
 * cannot drift from each other.
 *
 * @param array<string, mixed> $args Optional dependencies and value overrides.
 * @return array<string, mixed>
 * @since 1.2.2
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function get_peakurl_data( array $args = array() ): array {
	/*
	 * Resolve shared services first. Callers can pass existing dependencies so
	 * this helper does not open extra database connections in normal requests.
	 */
	$app_config = isset( $args['config'] ) && is_array( $args['config'] )
		? $args['config']
		: get_peakurl_config();
	$connection = isset( $args['connection'] ) &&
		$args['connection'] instanceof Connection
		? $args['connection']
		: get_peakurl_connection( $app_config );
	$settings   = isset( $args['settings_api'] ) &&
		$args['settings_api'] instanceof SettingsApi
		? $args['settings_api']
		: get_settings_api( $app_config, $connection );
	$i18n       = isset( $args['i18n_service'] ) &&
		$args['i18n_service'] instanceof I18n
		? $args['i18n_service']
		: get_i18n_service( $app_config, $connection );

	/*
	 * Read payload settings in one query. The local option helper keeps later
	 * value resolution short and consistently trimmed.
	 */
	$options = $settings->get_options(
		array(
			'installed_version',
			'site_name',
			'site_time_format',
			'site_timezone',
			'site_url',
		)
	);
	$option  = static function ( string $name ) use ( $options ): string {
		return trim( (string) ( $options[ $name ] ?? '' ) );
	};

	/*
	 * Resolve public site identity from settings first, with config fallback
	 * for early install and source-checkout development flows.
	 */
	$site_name = array_key_exists( 'site_name', $args )
		? trim( (string) $args['site_name'] )
		: $option( 'site_name' );
	$site_name = '' !== $site_name ? $site_name : 'PeakURL';

	$site_url = array_key_exists( 'site_url', $args )
		? trim( (string) $args['site_url'] )
		: $option( 'site_url' );

	if ( '' === $site_url ) {
		$site_url = trim(
			(string) ( $app_config[ Constants::SITE_URL ] ?? '' ),
		);
	}

	$site_url = untrailingslashit( $site_url );
	$site_url = (string) apply_filters( 'site_url', $site_url, '', null );

	/*
	 * Normalize the mount path. Root installs use an empty string, while
	 * subdirectory installs keep one leading slash and no trailing slash.
	 */
	if ( array_key_exists( 'base_path', $args ) ) {
		$base_path = trim( str_replace( '\\', '/', (string) $args['base_path'] ) );
	} else {
		$parsed_path = parse_url( $site_url, PHP_URL_PATH );
		$base_path   = is_string( $parsed_path ) ? $parsed_path : '';
	}

	$base_path = trim( $base_path );
	$base_path = '' === $base_path || '/' === $base_path
		? ''
		: '/' . trim( $base_path, '/' );

	/*
	 * Resolve locale and dashboard time preferences in the same shape exposed
	 * through `window.__PEAKURL__`.
	 */
	$locale = array_key_exists( 'locale', $args )
		? $i18n->normalize_locale( (string) $args['locale'] )
		: $i18n->get_current_locale();

	$timezone = array_key_exists( 'timezone', $args )
		? trim( (string) $args['timezone'] )
		: $option( 'site_timezone' );

	if (
		'' === $timezone ||
		! in_array( $timezone, \DateTimeZone::listIdentifiers(), true )
	) {
		$timezone = Constants::DEFAULT_TIMEZONE;
	}

	$time_format = array_key_exists( 'time_format', $args )
		? trim( (string) $args['time_format'] )
		: $option( 'site_time_format' );
	$time_format = in_array( $time_format, array( '12', '24' ), true )
		? $time_format
		: Constants::DEFAULT_TIME_FORMAT;

	/*
	 * Prefer the installed version from settings because it reflects the
	 * applied release. Debug can still be forced by callers such as site HTML.
	 */
	$version = array_key_exists( 'version', $args )
		? trim( (string) $args['version'] )
		: $option( 'installed_version' );
	$version = '' !== $version
		? $version
		: (string) ( $app_config[ Constants::VERSION ] ?? Constants::DEFAULT_VERSION );

	$debug_enabled = array_key_exists( 'debug', $args )
		? (bool) $args['debug']
		: ! empty( $app_config[ Constants::DEBUG ] );

	/*
	 * Reuse caller-provided favicon data when available; otherwise ask the
	 * favicon service so HTML and API fallback payloads stay aligned.
	 */
	if (
		array_key_exists( 'favicon', $args ) &&
		( is_array( $args['favicon'] ) || null === $args['favicon'] )
	) {
		$favicon = $args['favicon'];
	} else {
		$favicon_service = isset( $args['favicon_service'] ) &&
			$args['favicon_service'] instanceof Favicon
			? $args['favicon_service']
			: new Favicon( $app_config, $settings );
		$favicon         = $favicon_service->get_settings( $site_name );
	}

	/*
	 * Accept preloaded catalogs from callers that already resolved i18n data,
	 * avoiding duplicate catalog work during HTML rendering and API fallback.
	 */
	if ( isset( $args['i18n'] ) && is_array( $args['i18n'] ) ) {
		$catalog = $args['i18n'];
	} elseif ( isset( $args['catalog'] ) && is_array( $args['catalog'] ) ) {
		$catalog = $args['catalog'];
	} else {
		$catalog = $i18n->get_dashboard_catalog( $locale );
	}

	/*
	 * Keep the public client contract compact: one object, stable camelCase
	 * keys, and one filter for extension code to add fields intentionally.
	 */
	$data     = array(
		'basePath'      => $base_path,
		'apiBase'       => $base_path . Constants::API_BASE_PATH,
		'siteUrl'       => $site_url,
		'siteName'      => $site_name,
		'version'       => $version,
		'debug'         => $debug_enabled,
		'locale'        => $locale,
		'htmlLang'      => $i18n->get_html_lang( $locale ),
		'textDirection' => $i18n->get_text_direction( $locale ),
		'textDomain'    => Constants::I18N_TEXT_DOMAIN,
		'timezone'      => $timezone,
		'timeFormat'    => $time_format,
		'favicon'       => $favicon,
		'i18n'          => $catalog,
	);
	$filtered = apply_filters( 'dashboard_data', $data, $args );

	return is_array( $filtered ) ? $filtered : $data;
}

/**
 * Sanitize a string key for internal comparisons.
 *
 * Mirrors WordPress `sanitize_key()`.
 *
 * @param string $key Raw key input.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function sanitize_key( string $key ): string {
	$key = strtolower( trim( $key ) );
	$key = preg_replace( '/[^a-z0-9_-]/', '', $key );

	return is_string( $key ) ? $key : '';
}

/**
 * Sanitize a string into a URL-safe title slug.
 *
 * Mirrors the role of WordPress `sanitize_title()`.
 *
 * @param string $title Raw title input.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function sanitize_title( string $title ): string {
	$title  = strtolower( trim( $title ) );
	$result = preg_replace( '/[^a-z0-9]+/', '-', $title );
	$title  = is_string( $result ) ? $result : '';

	return trim( $title, '-' );
}

/**
 * Sanitize an email address for storage or comparisons.
 *
 * Mirrors the role of WordPress `sanitize_email()`.
 *
 * @param string $email Raw email input.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function sanitize_email( string $email ): string {
	return strtolower( trim( $email ) );
}

/**
 * Validate an email address and return its sanitized value.
 *
 * Mirrors the role of WordPress `is_email()`.
 *
 * @param string $email Raw email input.
 * @return string|false
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function is_email( string $email ): string|false {
	$email = sanitize_email( $email );

	if ( '' === $email ) {
		return false;
	}

	return false === filter_var( $email, FILTER_VALIDATE_EMAIL )
		? false
		: $email;
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
function get_i18n_service(
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

	$app_config = $config ?? get_peakurl_config();
	$next_hash  = get_peakurl_config_hash(
		$app_config,
		array(
			Constants::CONTENT_DIR,
			Constants::SITE_URL,
			Constants::DB_DATABASE,
			Constants::DB_PREFIX,
		),
	);

	if ( $service instanceof I18n && $config_hash === $next_hash ) {
		return $service;
	}

	$app_connection = $connection ?? get_peakurl_connection( $app_config );
	$settings_api   = get_settings_api( $app_config, $app_connection );
	$service        = new I18n( $app_config, $settings_api );
	$config_hash    = $next_hash;

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
function set_i18n_service( ?I18n $service ): void {
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
function load_i18n(
	?array $config = null,
	?Connection $connection = null
): string {
	return get_i18n_service(
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
function get_dashboard_translation_catalog(
	?string $locale = null,
	?array $config = null,
	?Connection $connection = null
): array {
	return get_i18n_service(
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
	return get_i18n_service()->get_current_locale();
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
function get_html_lang_attribute(): string {
	return get_i18n_service()->get_html_lang();
}

/**
 * Get the active locale as an HTML `dir` attribute.
 *
 * @return string
 * @since 1.0.7
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function get_text_direction(): string {
	return get_i18n_service()->get_text_direction();
}

/**
 * Build the translated maintenance page copy and document metadata.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return array<string, string>
 * @since 1.0.8
 */
function get_maintenance_view_data(
	?array $config = null,
	?Connection $connection = null
): array {
	$app_config     = $config ?? get_peakurl_config();
	$app_connection = $connection;
	$site_name      = 'PeakURL';
	$locale         = Constants::DEFAULT_LOCALE;
	$html_lang      = 'en-US';
	$text_direction = 'ltr';
	$i18n_service   = null;

	try {
		if (
			null === $app_connection &&
			file_exists( ABSPATH . 'config.php' )
		) {
			$app_connection = get_peakurl_connection( $app_config );
		}

		$i18n_service   = new I18n(
			$app_config,
			null !== $app_connection
				? get_settings_api( $app_config, $app_connection )
				: null,
		);
		$locale         = $i18n_service->load_locale();
		$html_lang      = $i18n_service->get_html_lang( $locale );
		$text_direction = $i18n_service->get_text_direction( $locale );

		if ( null !== $app_connection ) {
			$configured_site_name = trim(
				(string) ( $app_connection->get_option( 'site_name' ) ?? '' ),
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
		set_i18n_service( $i18n_service );
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

	$version = trim( (string) ( $app_config[ Constants::VERSION ] ?? '' ) );

	return array(
		'siteName'          => $site_name,
		'locale'            => $locale,
		'htmlLang'          => $html_lang,
		'textDirection'     => $text_direction,
		'version'           => $version,
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
function get_maintenance_api_payload( array $maintenance_view_data ): array {
	return array(
		'success' => false,
		'message' => (string) ( $maintenance_view_data['apiMessage'] ?? 'PeakURL is updating. Please try again in a moment.' ),
		'data'    => array(
			'maintenance' => true,
		),
	);
}

/**
 * Get the HTML maintenance page.
 *
 * @param array<string, string> $maintenance_view_data Localized maintenance data.
 * @return string
 * @since 1.0.8
 */
function render_maintenance_page( array $maintenance_view_data ): string {
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
	$generator_meta     = get_generator_tag( (string) ( $maintenance_view_data['version'] ?? '' ) );

	return '<!doctype html>' .
		'<html lang="' . $html_lang . '" dir="' . $text_direction . '">' .
		'<head>' .
		'<meta charset="utf-8">' .
		'<title>' . $title . '</title>' .
		'<meta name="viewport" content="width=device-width, initial-scale=1">' .
		( '' !== $generator_meta ? "\n\t\t" . $generator_meta : '' ) .
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
	return get_i18n_service()->is_locale_rtl();
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

	return get_i18n_service()->translate( $text );
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

	return get_i18n_service()->translate( $text, $context );
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

	return get_i18n_service()->translate_plural(
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

	return get_i18n_service()->translate_plural(
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
 * Sanitize a URL for storage or internal validation.
 *
 * Mirrors the role of WordPress `sanitize_url()` with optional protocol
 * allow-listing and support for root-relative paths when requested.
 *
 * @param string             $url            Candidate URL value.
 * @param array<int, string> $protocols      Allowed URL protocols.
 * @param bool               $allow_relative Whether a single-slash relative path is allowed.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function sanitize_url(
	string $url,
	array $protocols = array( 'http', 'https' ),
	bool $allow_relative = false
): string {
	$url = trim( $url );

	if ( '' === $url ) {
		return '';
	}

	if ( $allow_relative && 0 === strpos( $url, '/' ) ) {
		return 0 === strpos( $url, '//' ) ? '' : $url;
	}

	$sanitized = filter_var( $url, FILTER_SANITIZE_URL );

	if ( ! is_string( $sanitized ) || '' === $sanitized ) {
		return '';
	}

	if ( false === filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
		return '';
	}

	$parts = parse_url( $sanitized );

	if (
		! is_array( $parts ) ||
		empty( $parts['scheme'] ) ||
		empty( $parts['host'] )
	) {
		return '';
	}

	$allowed_protocols = array_map( 'strtolower', $protocols );
	$scheme            = strtolower( (string) $parts['scheme'] );

	if ( ! in_array( $scheme, $allowed_protocols, true ) ) {
		return '';
	}

	return $sanitized;
}

/**
 * Escape a URL for HTML output.
 *
 * Mirrors WordPress `esc_url()`.
 *
 * @param string             $url            Candidate URL value.
 * @param array<int, string> $protocols      Allowed URL protocols.
 * @param bool               $allow_relative Whether a single-slash relative path is allowed.
 * @return string
 * @since 1.0.14
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function esc_url(
	string $url,
	array $protocols = array( 'http', 'https' ),
	bool $allow_relative = false
): string {
	return htmlspecialchars(
		sanitize_url( $url, $protocols, $allow_relative ),
		ENT_QUOTES,
		'UTF-8',
	);
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

/**
 * Sanitize HTML to allow only specified tags and attributes.
 *
 * This provides a safe way to output HTML that includes translations or
 * user-provided links, mirroring the role of WordPress `wp_kses()`.
 *
 * @param string               $html        Raw HTML to sanitize.
 * @param array<string, array> $allowed_tags Allowed tags and their attributes.
 * @return string
 * @since 1.2.4
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
function PeakURL_sanitize_html( string $html, array $allowed_tags ): string {
	if ( '' === trim( $html ) ) {
		return '';
	}

	$tags_list = '';
	foreach ( array_keys( $allowed_tags ) as $tag ) {
		$tags_list .= '<' . $tag . '>';
	}

	$sanitized = strip_tags( $html, $tags_list );

	foreach ( $allowed_tags as $tag => $attributes ) {
		$pattern   = '/<' . $tag . '\b([^>]*)>/i';
		$sanitized = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $tag, $attributes ) {
				$attr_string = $matches[1];
				$new_attrs   = '';

				foreach ( $attributes as $attr_name => $true ) {
					if ( preg_match( '/\b' . $attr_name . '=(["\'])(.*?)\1/i', $attr_string, $attr_matches ) ) {
						$val = $attr_matches[2];
						if ( 'href' === $attr_name || 'src' === $attr_name ) {
							$val = sanitize_url( $val );
						}
						$new_attrs .= ' ' . $attr_name . '="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '"';
					}
				}

				return '<' . $tag . $new_attrs . '>';
			},
			$sanitized
		);
	}

	return $sanitized;
}
// phpcs:enable

/**
 * Build the generator meta tag for the site document head.
 *
 * @param string|null $version Optional version override.
 * @return string HTML meta tag string, or empty if version is missing.
 * @since 1.2.3
 */
function get_generator_tag( ?string $version = null ): string {
	if ( null === $version ) {
		$app_config = get_peakurl_config();
		$version    = trim( (string) ( $app_config[ Constants::VERSION ] ?? '' ) );
	}

	$version = htmlspecialchars( $version, ENT_QUOTES, 'UTF-8' );

	if ( '' === $version ) {
		return '';
	}

	return '<meta name="generator" content="PeakURL ' . $version . '">';
}

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
