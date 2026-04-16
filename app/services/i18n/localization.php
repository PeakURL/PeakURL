<?php
/**
 * PeakURL localization and dashboard-catalog helper.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

use Gettext\Translations as GettextTranslations;
use PeakURL\Api\SettingsApi;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Localization — WordPress-style i18n helper for PeakURL.
 *
 * Loads PHP gettext catalogs from `content/languages`, exposes the active
 * locale, lists available language packs, and provides the matching
 * dashboard JSON catalog for the React UI.
 *
 * @since 1.0.14
 */
class Localization {

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Settings API for the site-language option.
	 *
	 * @var SettingsApi|null
	 * @since 1.0.14
	 */
	private ?SettingsApi $settings_api;

	/**
	 * Locale formatting helper.
	 *
	 * @var Locale
	 * @since 1.0.14
	 */
	private Locale $locale_helper;

	/**
	 * Filesystem paths helper.
	 *
	 * @var Paths
	 * @since 1.0.14
	 */
	private Paths $paths;

	/**
	 * Installed language metadata helper.
	 *
	 * @var Languages
	 * @since 1.0.14
	 */
	private Languages $languages;

	/**
	 * Catalog loader helper.
	 *
	 * @var Loader
	 * @since 1.0.14
	 */
	private Loader $loader;

	/**
	 * Browser locale sync helper.
	 *
	 * @var Sync
	 * @since 1.0.14
	 */
	private Sync $sync;

	/**
	 * Currently-loaded locale.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $locale = '';

	/**
	 * Loaded gettext catalog for the current locale.
	 *
	 * @var GettextTranslations|null
	 * @since 1.0.14
	 */
	private ?GettextTranslations $catalog = null;

	/**
	 * Create a new localization helper.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi|null     $settings_api Settings API dependency.
	 * @since 1.0.14
	 */
	public function __construct( array $config, ?SettingsApi $settings_api = null ) {
		$this->config        = $config;
		$this->settings_api  = $settings_api;
		$this->locale_helper = new Locale();
		$this->paths         = new Paths( $config );
		$this->languages     = new Languages( $this->locale_helper, $this->paths );
		$this->loader        = new Loader( $this->paths );
		$this->sync          = new Sync( $this->locale_helper );
	}

	/**
	 * Get the active gettext text domain.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_domain(): string {
		return $this->paths->get_text_domain();
	}

	/**
	 * Get the default locale used when no setting exists.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_default_locale(): string {
		return $this->locale_helper->get_default_locale();
	}

	/**
	 * Get the absolute persistent content directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_content_directory(): string {
		return $this->paths->get_content_directory();
	}

	/**
	 * Normalize a locale string to a WordPress-style identifier.
	 *
	 * @param string $locale Raw locale value.
	 * @return string
	 * @since 1.0.14
	 */
	public function normalize_locale( string $locale ): string {
		return $this->locale_helper->normalize_locale( $locale );
	}

	/**
	 * Resolve the current site locale from the settings table.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_site_locale(): string {
		if ( null !== $this->settings_api ) {
			return $this->normalize_locale(
				(string) $this->settings_api->get_option( 'site_language' ),
			);
		}

		$configured_locale = trim(
			(string) ( $this->config['PEAKURL_SITE_LANGUAGE'] ?? '' ),
		);

		if ( '' === $configured_locale ) {
			return $this->get_default_locale();
		}

		$configured_locale = $this->normalize_locale( $configured_locale );

		if ( ! $this->is_locale_available( $configured_locale ) ) {
			return $this->get_default_locale();
		}

		return $configured_locale;
	}

	/**
	 * Load the gettext catalog for the requested locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function load_locale( ?string $locale = null ): string {
		$resolved_locale = null === $locale
			? $this->get_site_locale()
			: $this->normalize_locale( $locale );

		if ( $resolved_locale === $this->locale && null !== $this->catalog ) {
			return $resolved_locale;
		}

		$this->locale  = $resolved_locale;
		$this->catalog = $this->loader->load_php_catalog( $resolved_locale );

		return $resolved_locale;
	}

	/**
	 * Get the current locale, loading it on first access.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_current_locale(): string {
		if ( '' === $this->locale ) {
			$this->load_locale();
		}

		return $this->locale;
	}

	/**
	 * Translate a singular string.
	 *
	 * @param string      $text    Source text.
	 * @param string|null $context Optional gettext context.
	 * @param string|null $locale  Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function translate(
		string $text,
		?string $context = null,
		?string $locale = null
	): string {
		$this->load_locale( $locale );

		if ( null === $this->catalog ) {
			return $text;
		}

		$translation = $this->catalog->find( $context, $text );

		if ( null === $translation ) {
			return $text;
		}

		$value = (string) $translation->getTranslation();

		return '' !== $value ? $value : $text;
	}

	/**
	 * Translate a plural string.
	 *
	 * @param string      $single  Singular source string.
	 * @param string      $plural  Plural source string.
	 * @param int         $number  Count used for plural selection.
	 * @param string|null $context Optional gettext context.
	 * @param string|null $locale  Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function translate_plural(
		string $single,
		string $plural,
		int $number,
		?string $context = null,
		?string $locale = null
	): string {
		$resolved_locale = $this->load_locale( $locale );

		if ( null === $this->catalog ) {
			return 1 === abs( $number ) ? $single : $plural;
		}

		$translation = $this->catalog->find( $context, $single );

		if ( null === $translation ) {
			return 1 === abs( $number ) ? $single : $plural;
		}

		$plural_translations = array_values(
			array_map(
				static fn( $value ): string => (string) $value,
				$translation->getPluralTranslations(),
			),
		);

		if ( empty( $plural_translations ) ) {
			$value = (string) $translation->getTranslation();

			if ( '' !== $value ) {
				return $value;
			}

			return 1 === abs( $number ) ? $single : $plural;
		}

		$index = $this->languages->resolve_plural_index(
			$resolved_locale,
			$number,
		);
		$value = (string) ( $plural_translations[ $index ] ?? '' );

		if ( '' !== $value ) {
			return $value;
		}

		return 1 === abs( $number ) ? $single : $plural;
	}

	/**
	 * Return the list of installed language packs plus the default locale.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.14
	 */
	public function list_languages(): array {
		return $this->languages->list_languages();
	}

	/**
	 * Check whether a locale can be selected from the installed packs.
	 *
	 * @param string $locale Locale to validate.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_locale_available( string $locale ): bool {
		return $this->languages->is_locale_available( $locale );
	}

	/**
	 * Match the browser language preferences against installed locales.
	 *
	 * @param string $accept_language_header Browser language header.
	 * @return string
	 * @since 1.0.14
	 */
	public function match_browser_locale( string $accept_language_header ): string {
		$installed_locales = array_values(
			array_filter(
				array_map(
					static function ( array $language ): string {
						return (string) ( $language['locale'] ?? '' );
					},
					$this->list_languages(),
				),
			),
		);

		return $this->sync->match_browser_locale(
			$accept_language_header,
			$installed_locales,
		);
	}

	/**
	 * Return the dashboard translation catalog for the active locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_dashboard_catalog( ?string $locale = null ): array {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );

		return $this->loader->get_dashboard_catalog(
			$resolved_locale,
			$this->languages->get_plural_forms_header( $resolved_locale ),
		);
	}

	/**
	 * Convert the locale to an HTML lang attribute value.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_html_lang( ?string $locale = null ): string {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );

		return $this->locale_helper->get_html_lang( $resolved_locale );
	}

	/**
	 * Determine whether a locale should render right-to-left.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_locale_rtl( ?string $locale = null ): bool {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );

		return $this->locale_helper->is_locale_rtl( $resolved_locale );
	}

	/**
	 * Get the document text direction for a locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_direction( ?string $locale = null ): string {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );

		return $this->locale_helper->get_text_direction( $resolved_locale );
	}

	/**
	 * Prepare the persistent languages directory when the install can create it.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function prepare_languages_directory(): bool {
		$directory_status = $this->paths->prepare_languages_directory();

		if ( ! empty( $directory_status['created'] ) ) {
			$this->catalog = null;
			$this->languages->reset();
		}

		return ! empty( $directory_status['exists'] );
	}

	/**
	 * Get the absolute languages directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_languages_directory(): string {
		return $this->paths->get_languages_directory();
	}
}
