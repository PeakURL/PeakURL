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

	if ( ! isset( $directory_map[ $group ] ) || ! empty( $segments ) ) {
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

	if ( 'PeakURL_DB' === $type_name ) {
		$file_name = 'peakurl-db';
	} elseif ( 'Str' === $type_name ) {
		$file_name = 'string';
	} else {
		$file_name = str_replace( '_', '-', $type_name );
		$file_name = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', $file_name );
		$file_name = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $file_name );
		$file_name = strtolower( (string) $file_name );
	}

	if ( '' === $file_name ) {
		return null;
	}

	return $app_root . $directory_map[ $group ] . $file_name . '.php';
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
 * Back-compat wrapper for the shared i18n service helper.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return I18n
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_get_translations_service(
	?array $config = null,
	?Connection $connection = null
): I18n {
	return peakurl_get_i18n_service( $config, $connection );
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
 * Back-compat wrapper for the i18n bootstrap helper.
 *
 * @param array<string, mixed>|null $config     Optional runtime config.
 * @param Connection|null           $connection Optional reused connection.
 * @return string Loaded locale.
 * @since 1.0.3
 */
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional internal helper naming.
function peakurl_bootstrap_translations(
	?array $config = null,
	?Connection $connection = null
): string {
	return peakurl_bootstrap_i18n( $config, $connection );
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
