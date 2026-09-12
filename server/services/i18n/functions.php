<?php
/**
 * Global internationalization and gettext translation helper functions.
 *
 * Exposes WordPress-style translation functions (__(), _e(), _x(), etc.)
 * backed by the active I18n service in the application.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.3
 */

declare(strict_types=1);

use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\I18n;

// If this file is called directly, abort.
if (
	! defined( 'ABSPATH' ) &&
	realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__
) {
	exit( 'Direct access forbidden.' );
}

if ( ! function_exists( 'get_i18n_service' ) ) {
	/**
	 * Get the shared i18n service instance for the current request.
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

		try {
			$app_connection = $connection ?? get_peakurl_connection( $app_config );
			$settings_api   = get_settings_api( $app_config, $app_connection );
			$service        = new I18n( $app_config, $settings_api );
			$config_hash    = $next_hash;

			return $service;
		} catch ( \Throwable ) {
			return new I18n( $app_config );
		}
	}
}

if ( ! function_exists( 'set_i18n_service' ) ) {
	/**
	 * Override the shared i18n service for the current request lifecycle.
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
}

if ( ! function_exists( 'load_i18n' ) ) {
	/**
	 * Initialize the active locale for the current request.
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
}

if ( ! function_exists( 'get_dashboard_translation_catalog' ) ) {
	/**
	 * Get the dashboard JSON catalog for the active locale.
	 *
	 * @param string|null               $locale     Optional locale override.
	 * @param array<string, mixed>|null $config     Optional runtime config.
	 * @param Connection|null           $connection Optional reused connection.
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
}

if ( ! function_exists( 'get_locale' ) ) {
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
}

if ( ! function_exists( 'determine_locale' ) ) {
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
}

if ( ! function_exists( 'get_html_lang_attribute' ) ) {
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
}

if ( ! function_exists( 'get_text_direction' ) ) {
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
}

if ( ! function_exists( 'is_rtl' ) ) {
	/**
	 * Determine whether the active locale is right-to-left.
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
}

if ( ! function_exists( 'translate' ) ) {
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
}

if ( ! function_exists( 'translate_with_gettext_context' ) ) {
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
}

if ( ! function_exists( '__' ) ) {
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
}

if ( ! function_exists( '_e' ) ) {
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
}

if ( ! function_exists( '_x' ) ) {
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
}

if ( ! function_exists( '_ex' ) ) {
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
}

if ( ! function_exists( '_n' ) ) {
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
}

if ( ! function_exists( '_nx' ) ) {
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
}

if ( ! function_exists( 'esc_html__' ) ) {
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
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Translate, escape, and display text.
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
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Translate and escape text for attribute output.
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
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Translate, escape, and display attribute text.
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
}
// phpcs:enable
