<?php
/**
 * Global site and dashboard helper functions.
 *
 * Provides WordPress-compatible site metadata resolution, canonical URL
 * generation, public mail dispatch, and dashboard client data bootstrapping.
 *
 * @package PeakURL\Services
 * @since 1.0.0
 */

declare(strict_types=1);

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\RuntimeConfig;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
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

if ( ! function_exists( 'get_site_name' ) ) {
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
}

if ( ! function_exists( 'get_site_url' ) ) {
	/**
	 * Get the canonical site URL for this PeakURL install.
	 *
	 * Checks database settings first, falling back to runtime config.
	 *
	 * @param string      $path   Optional path to append.
	 * @param string|null $scheme Optional scheme override ('http', 'https', 'relative').
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
}

if ( ! function_exists( 'site_url' ) ) {
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
}

if ( ! function_exists( 'get_api_base_url' ) ) {
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
}

if ( ! function_exists( 'api_base_url' ) ) {
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
}

if ( ! function_exists( 'PeakURL_Mail' ) ) {
	/**
	 * Send an email through the active PeakURL transport.
	 *
	 * Mirrors the role of WordPress `wp_mail()` while keeping PeakURL's
	 * transport settings behind one public helper.
	 *
	 * @param string                                                   $to_email Recipient email address.
	 * @param string                                                   $subject  Email subject line.
	 * @param string                                                   $message  Primary message body.
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
}

if ( ! function_exists( 'get_peakurl_data' ) ) {
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
		 * Retrieve CAPTCHA configuration for auth flows.
		 */
		$captcha_service = isset( $args['captcha_service'] ) &&
			$args['captcha_service'] instanceof Captcha
			? $args['captcha_service']
			: new Captcha( $app_config, $settings, new Crypto( $app_config ) );
		$captcha         = $captcha_service->get_challenge();

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
			'captcha'       => $captcha,
			'i18n'          => $catalog,
		);
		$filtered = apply_filters( 'dashboard_data', $data, $args );

		return is_array( $filtered ) ? $filtered : $data;
	}
}

if ( ! function_exists( 'get_generator_tag' ) ) {
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
}

if ( ! function_exists( 'get_user_display_name' ) ) {
	/**
	 * Build a human-readable display name from a user row.
	 *
	 * @param array<string, mixed> $user User database row.
	 * @return string
	 * @since 1.0.2
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Intentional public helper naming.
	function get_user_display_name( array $user ): string {
		$display_name = trim( (string) ( $user['display_name'] ?? '' ) );
		if ( '' === $display_name ) {
			$full_name    = trim(
				(string) ( $user['first_name'] ?? '' ) . ' ' . (string) ( $user['last_name'] ?? '' )
			);
			$display_name = '' !== $full_name
				? $full_name
				: trim( (string) ( $user['username'] ?? '' ) );
		}
		$display_name = '' !== $display_name ? $display_name : 'there';

		return (string) apply_filters(
			'user_display_name',
			$display_name,
			$user,
		);
	}
}
