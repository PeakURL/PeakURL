<?php
/**
 * PeakURL localization and dashboard-catalog helper.
 *
 * @package PeakURL\Services
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services;

use Gettext\Translations as GettextTranslations;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\I18n\BrowserLocale;
use PeakURL\Services\I18n\Languages;
use PeakURL\Services\I18n\Loader;
use PeakURL\Services\I18n\Locale;
use PeakURL\Services\I18n\Paths;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * I18n — WordPress-style i18n helper for PeakURL.
 *
 * Loads PHP gettext catalogs from `content/languages`, exposes the active
 * locale, lists available language packs, and provides the matching
 * dashboard JSON catalog for the React UI.
 *
 * @since 1.0.14
 */
class I18n {

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
	 * Browser locale matching helper.
	 *
	 * @var BrowserLocale
	 * @since 1.0.14
	 */
	private BrowserLocale $browser_locale;

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
	 * Map of registered plugin text domains to directory configurations.
	 *
	 * @var array<string, array{dir: string, plugin_path: ?string}>
	 * @since 1.8.0
	 */
	private array $plugin_domain_dirs = array();

	/**
	 * Map of loaded plugin gettext catalogs keyed by text domain.
	 *
	 * @var array<string, GettextTranslations>
	 * @since 1.8.0
	 */
	private array $plugin_catalogs = array();

	/**
	 * Create a new localization helper.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi|null     $settings_api Settings API dependency.
	 * @since 1.0.14
	 */
	public function __construct( array $config, ?SettingsApi $settings_api = null ) {
		$this->config         = $config;
		$this->settings_api   = $settings_api;
		$this->locale_helper  = new Locale();
		$this->paths          = new Paths( $config );
		$this->languages      = new Languages( $this->locale_helper, $this->paths );
		$this->loader         = new Loader( $this->paths );
		$this->browser_locale = new BrowserLocale( $this->locale_helper );
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
	public function get_content_dir(): string {
		return $this->paths->get_content_dir();
	}

	/**
	 * Canonicalize a raw locale string to a standard identifier.
	 *
	 * @param string $locale Raw locale value.
	 * @return string Canonical locale identifier or empty string when invalid.
	 * @since 1.0.14
	 */
	public function canonicalize_locale( string $locale ): string {
		return $this->locale_helper->canonicalize_locale( $locale );
	}

	/**
	 * Resolve the current site locale from the settings table.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_site_locale(): string {
		if ( null !== $this->settings_api ) {
			$configured = (string) $this->settings_api->get_option( 'site_language' );
			$locale     = $this->locale_helper->canonicalize_locale( $configured );

			if ( '' !== $locale && $this->is_locale_available( $locale ) ) {
				return $locale;
			}

			return $this->get_default_locale();
		}

		$configured_locale = trim(
			(string) ( $this->config[ Constants::SITE_LANGUAGE ] ?? '' ),
		);

		if ( '' === $configured_locale ) {
			return $this->get_default_locale();
		}

		$configured_locale = $this->locale_helper->canonicalize_locale( $configured_locale );

		if ( '' === $configured_locale || ! $this->is_locale_available( $configured_locale ) ) {
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
		$locale_name = null === $locale
			? ( '' !== $this->locale ? $this->locale : $this->get_site_locale() )
			: $this->locale_helper->canonicalize_locale( $locale );

		if ( '' === $locale_name || ! $this->is_locale_available( $locale_name ) ) {
			$locale_name = $this->get_site_locale();
		}

		if ( $locale_name === $this->locale && ( null !== $this->catalog || $this->get_default_locale() === $locale_name ) ) {
			return $locale_name;
		}

		$this->locale  = $locale_name;
		$this->catalog = $this->loader->load_php_catalog( $locale_name );

		$this->reload_plugin_catalogs( $locale_name );

		return $locale_name;
	}

	/**
	 * Register and load a plugin translation domain.
	 *
	 * @param string      $domain        Plugin text domain.
	 * @param string      $languages_dir Directory path containing translation files.
	 * @param string|null $plugin_path   Optional root plugin directory path for boundary verification.
	 * @return bool True if directory is valid and registered.
	 * @since 1.8.0
	 */
	public function load_plugin_textdomain(
		string $domain,
		string $languages_dir,
		?string $plugin_path = null
	): bool {
		$clean_domain     = trim( $domain );
		$reserved_domains = array( 'peakurl', 'default', 'core', 'system', 'messages', 'admin', 'api' );
		if ( '' === $clean_domain || in_array( strtolower( $clean_domain ), $reserved_domains, true ) ) {
			return false;
		}

		if ( is_link( $languages_dir ) ) {
			return false;
		}

		$real_dir = realpath( $languages_dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) || is_link( $real_dir ) ) {
			return false;
		}

		// Prevent path traversal outside plugin boundary or content directory.
		// Enforce strict directory boundary to prevent sibling collisions (e.g. plugin vs plugin-sibling).
		if ( null !== $plugin_path && '' !== trim( $plugin_path ) ) {
			if ( is_link( $plugin_path ) ) {
				return false;
			}
			$real_plugin = realpath( $plugin_path );
			if ( false === $real_plugin || ! is_dir( $real_plugin ) || is_link( $real_plugin ) ) {
				return false;
			}
			$clean_plugin = rtrim( $real_plugin, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( $real_dir !== $real_plugin && ! str_starts_with( $real_dir, $clean_plugin ) ) {
				return false;
			}
		} else {
			$content_dir = realpath( $this->get_content_dir() );
			if ( false === $content_dir || ! is_dir( $content_dir ) || is_link( $content_dir ) ) {
				return false;
			}
			$clean_content = rtrim( $content_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( $real_dir !== $content_dir && ! str_starts_with( $real_dir, $clean_content ) ) {
				return false;
			}
		}

		// Reject domain collision if domain is already registered to a different directory.
		if ( isset( $this->plugin_domain_dirs[ $clean_domain ] ) ) {
			if ( $this->plugin_domain_dirs[ $clean_domain ]['dir'] !== $real_dir ) {
				return false;
			}
		}

		$this->plugin_domain_dirs[ $clean_domain ] = array(
			'dir'         => $real_dir,
			'plugin_path' => $plugin_path,
		);

		$current_locale = $this->get_current_locale();
		$catalog        = $this->loader->load_domain_catalog( $clean_domain, $real_dir, $current_locale );

		if ( null !== $catalog ) {
			$this->plugin_catalogs[ $clean_domain ] = $catalog;
		} else {
			unset( $this->plugin_catalogs[ $clean_domain ] );
		}

		return true;
	}

	/**
	 * Reload all registered plugin catalogs for the given locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return void
	 */
	private function reload_plugin_catalogs( string $locale ): void {
		$this->plugin_catalogs = array();

		foreach ( $this->plugin_domain_dirs as $domain => $info ) {
			$catalog = $this->loader->load_domain_catalog( $domain, $info['dir'], $locale );
			if ( null !== $catalog ) {
				$this->plugin_catalogs[ $domain ] = $catalog;
			}
		}
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
	 * @param string      $domain  Optional text domain.
	 * @return string
	 * @since 1.0.14
	 */
	public function translate(
		string $text,
		?string $context = null,
		?string $locale = null,
		string $domain = 'default'
	): string {
		if ( null !== $locale || '' === $this->locale ) {
			$this->load_locale( $locale );
		}

		$clean_domain = trim( $domain );
		if ( 'default' === $clean_domain || 'peakurl' === $clean_domain || '' === $clean_domain ) {
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

		$plugin_catalog = $this->plugin_catalogs[ $clean_domain ] ?? null;
		if ( null === $plugin_catalog ) {
			return $text;
		}

		$translation = $plugin_catalog->find( $context, $text );
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
	 * @param string      $domain  Optional text domain.
	 * @return string
	 * @since 1.0.14
	 */
	public function translate_plural(
		string $single,
		string $plural,
		int $number,
		?string $context = null,
		?string $locale = null,
		string $domain = 'default'
	): string {
		$locale_name = null !== $locale || '' === $this->locale
			? $this->load_locale( $locale )
			: $this->get_current_locale();

		$clean_domain = trim( $domain );
		$target_cat   = ( 'default' === $clean_domain || 'peakurl' === $clean_domain || '' === $clean_domain )
			? $this->catalog
			: ( $this->plugin_catalogs[ $clean_domain ] ?? null );

		if ( null === $target_cat ) {
			return 1 === abs( $number ) ? $single : $plural;
		}

		$translation = $target_cat->find( $context, $single );

		if ( null === $translation ) {
			return 1 === abs( $number ) ? $single : $plural;
		}

		$plural_translations = array_merge(
			array( (string) $translation->getTranslation() ),
			array_values(
				array_map(
					static fn( $value ): string => (string) $value,
					$translation->getPluralTranslations(),
				),
			),
		);

		if ( empty( $plural_translations ) ) {
			$value = (string) $translation->getTranslation();

			if ( '' !== $value ) {
				return $value;
			}

			return 1 === abs( $number ) ? $single : $plural;
		}

		$index = $this->languages->get_plural_index(
			$locale_name,
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

		return $this->browser_locale->match_browser_locale(
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
		$locale_name = null === $locale
			? $this->get_current_locale()
			: $this->locale_helper->canonicalize_locale( $locale );

		if ( '' === $locale_name || ! $this->is_locale_available( $locale_name ) ) {
			$locale_name = $this->get_site_locale();
		}

		$dashboard_catalog = $this->loader->get_dashboard_catalog(
			$locale_name,
			$this->languages->get_plural_forms_header( $locale_name ),
		);

		// Merge registered plugin translation catalogs into dashboard locale_data.
		foreach ( $this->plugin_domain_dirs as $domain => $info ) {
			$plugin_json = $this->loader->get_domain_dashboard_catalog( $domain, $info['dir'], $locale_name );

			if ( null !== $plugin_json && isset( $plugin_json['locale_data'] ) && is_array( $plugin_json['locale_data'] ) ) {
				// Translation domain isolation: only extract the domain's messages or poedit default 'messages'.
				// Strictly map to $domain, and discard any attempts to override 'peakurl' or other domains.
				$messages = null;
				if ( isset( $plugin_json['locale_data'][ $domain ] ) && is_array( $plugin_json['locale_data'][ $domain ] ) ) {
					$messages = $plugin_json['locale_data'][ $domain ];
				} elseif ( isset( $plugin_json['locale_data']['messages'] ) && is_array( $plugin_json['locale_data']['messages'] ) ) {
					$messages = $plugin_json['locale_data']['messages'];
				}

				if ( null !== $messages ) {
					// Force sanitize header '' with the target domain, current language, and plural forms.
					$messages['']                                = array(
						'domain'       => $domain,
						'lang'         => $locale_name,
						'plural-forms' => $this->languages->get_plural_forms_header( $locale_name ),
					);
					$dashboard_catalog['locale_data'][ $domain ] = $messages;
				}
			} elseif ( isset( $this->plugin_catalogs[ $domain ] ) ) {
				$cat             = $this->plugin_catalogs[ $domain ];
				$domain_messages = array(
					'' => array(
						'domain'       => $domain,
						'lang'         => $locale_name,
						'plural-forms' => $this->languages->get_plural_forms_header( $locale_name ),
					),
				);

				foreach ( $cat as $entry ) {
					$orig  = $entry->getOriginal();
					$trans = $entry->getTranslation();
					$ctx   = $entry->getContext();
					$key   = $ctx ? "{$ctx}\x04{$orig}" : $orig;

					$domain_messages[ $key ] = array( $trans );
					$plurals                 = $entry->getPluralTranslations();
					if ( ! empty( $plurals ) ) {
						$domain_messages[ $key ] = array_merge( array( $trans ), $plurals );
					}
				}

				$dashboard_catalog['locale_data'][ $domain ] = $domain_messages;
			}
		}

		return $dashboard_catalog;
	}

	/**
	 * Convert the locale to an HTML lang attribute value.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_html_lang( ?string $locale = null ): string {
		$locale_name = null === $locale
			? $this->get_current_locale()
			: $this->locale_helper->canonicalize_locale( $locale );

		if ( '' === $locale_name ) {
			$locale_name = $this->get_site_locale();
		}

		return $this->locale_helper->get_html_lang( $locale_name );
	}

	/**
	 * Determine whether a locale should render right-to-left.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_locale_rtl( ?string $locale = null ): bool {
		$locale_name = null === $locale
			? $this->get_current_locale()
			: $this->locale_helper->canonicalize_locale( $locale );

		if ( '' === $locale_name ) {
			$locale_name = $this->get_site_locale();
		}

		return $this->locale_helper->is_locale_rtl( $locale_name );
	}

	/**
	 * Get the document text direction for a locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_direction( ?string $locale = null ): string {
		$locale_name = null === $locale
			? $this->get_current_locale()
			: $this->locale_helper->canonicalize_locale( $locale );

		if ( '' === $locale_name ) {
			$locale_name = $this->get_site_locale();
		}

		return $this->locale_helper->get_text_direction( $locale_name );
	}

	/**
	 * Prepare the persistent languages directory when the install can create it.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function prepare_languages_dir(): bool {
		$directory_status = $this->paths->prepare_languages_dir();

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
	public function get_languages_dir(): string {
		return $this->paths->get_languages_dir();
	}
}
