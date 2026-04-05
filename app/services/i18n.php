<?php
/**
 * PeakURL i18n and dashboard-catalog helper.
 *
 * @package PeakURL\Services
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Services;

use Gettext\Languages\Language as GettextLanguage;
use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Translations as GettextTranslations;
use Locale;
use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * WordPress-style i18n helper for PeakURL.
 *
 * Loads PHP gettext catalogs from `content/languages`, exposes the active
 * locale, lists available language packs, and provides the matching
 * dashboard JSON catalog for the React UI.
 *
 * @since 1.0.3
 */
class I18n {

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.3
	 */
	private array $config;

	/**
	 * Settings API for the site-language option.
	 *
	 * @var SettingsApi
	 * @since 1.0.3
	 */
	private SettingsApi $settings_api;

	/**
	 * Currently-loaded locale.
	 *
	 * @var string
	 * @since 1.0.3
	 */
	private string $locale = '';

	/**
	 * Loaded gettext catalog for the current locale.
	 *
	 * @var GettextTranslations|null
	 * @since 1.0.3
	 */
	private ?GettextTranslations $catalog = null;

	/**
	 * Cached installed-language list for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.0.3
	 */
	private ?array $languages_cache = null;

	/**
	 * Cached language-pack scan results for the current request.
	 *
	 * @var array<string, array<string, bool>>|null
	 * @since 1.0.3
	 */
	private ?array $language_packs_cache = null;

	/**
	 * Create a new i18n helper.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi          $settings_api Settings API dependency.
	 * @since 1.0.3
	 */
	public function __construct( array $config, SettingsApi $settings_api ) {
		$this->config       = $config;
		$this->settings_api = $settings_api;
	}

	/**
	 * Get the active gettext text domain.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	public function get_text_domain(): string {
		return Constants::I18N_TEXT_DOMAIN;
	}

	/**
	 * Get the default locale used when no setting exists.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	public function get_default_locale(): string {
		return Constants::DEFAULT_LOCALE;
	}

	/**
	 * Get the absolute persistent content directory path.
	 *
	 * @return string
	 * @since 1.0.4
	 */
	public function get_content_directory(): string {
		return rtrim(
			(string) ( $this->config[ Constants::CONFIG_CONTENT_DIR ] ?? ABSPATH . Constants::DEFAULT_CONTENT_DIR ),
			'/\\',
		);
	}

	/**
	 * Normalize a locale string to a WordPress-style identifier.
	 *
	 * @param string $locale Raw locale value.
	 * @return string Normalized locale or the default locale.
	 * @since 1.0.3
	 */
	public function normalize_locale( string $locale ): string {
		$locale = trim( str_replace( '-', '_', $locale ) );

		if ( '' === $locale ) {
			return $this->get_default_locale();
		}

		if ( ! preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
			return $this->get_default_locale();
		}

		$parts = explode( '_', $locale );

		if ( empty( $parts ) ) {
			return $this->get_default_locale();
		}

		$parts[0] = strtolower( (string) $parts[0] );

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index ) {
				continue;
			}

			$parts[ $index ] = strlen( $part ) <= 3
				? strtoupper( $part )
				: ucfirst( strtolower( $part ) );
		}

		return implode( '_', $parts );
	}

	/**
	 * Resolve the current site locale from the settings table.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	public function get_site_locale(): string {
		return $this->normalize_locale(
			(string) $this->settings_api->get_option( 'site_language' ),
		);
	}

	/**
	 * Load the gettext catalog for the requested locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string Loaded locale.
	 * @since 1.0.3
	 */
	public function load_locale( ?string $locale = null ): string {
		$resolved_locale = null === $locale
			? $this->get_site_locale()
			: $this->normalize_locale( $locale );

		if ( $resolved_locale === $this->locale && null !== $this->catalog ) {
			return $resolved_locale;
		}

		$this->locale  = $resolved_locale;
		$this->catalog = $this->load_catalog( $resolved_locale );

		return $resolved_locale;
	}

	/**
	 * Get the current locale, loading it on first access.
	 *
	 * @return string
	 * @since 1.0.3
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
	 * @since 1.0.3
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
	 * @since 1.0.3
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

		$index = $this->resolve_plural_index( $resolved_locale, $number );
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
	 * @since 1.0.3
	 */
	public function list_languages(): array {
		if ( null !== $this->languages_cache ) {
			return $this->languages_cache;
		}

		$available = $this->scan_language_packs();
		$default   = $this->get_default_locale();

		if ( ! isset( $available[ $default ] ) ) {
			$available[ $default ] = array(
				'php' => true,
				'js'  => true,
			);
		}

		ksort( $available, SORT_NATURAL | SORT_FLAG_CASE );

		$languages      = array();
		$variant_counts = $this->count_base_language_variants( array_keys( $available ) );

		foreach ( $available as $locale => $flags ) {
			$languages[] = $this->build_language_entry(
				$locale,
				$flags,
				(int) ( $variant_counts[ $this->get_base_locale( $locale ) ] ?? 1 ),
			);
		}

		usort(
			$languages,
			static function ( array $left, array $right ): int {
				return strcmp(
					(string) $left['label'],
					(string) $right['label'],
				);
			},
		);

		$this->languages_cache = $languages;

		return $this->languages_cache;
	}

	/**
	 * Check whether a locale can be selected from the installed packs.
	 *
	 * @param string $locale Locale to validate.
	 * @return bool
	 * @since 1.0.3
	 */
	public function is_locale_available( string $locale ): bool {
		$locale = $this->normalize_locale( $locale );

		foreach ( $this->list_languages() as $language ) {
			if ( (string) ( $language['locale'] ?? '' ) === $locale ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the dashboard translation catalog for the active locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	public function get_dashboard_catalog( ?string $locale = null ): array {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );
		$catalog_path    = $this->build_dashboard_catalog_path( $resolved_locale );

		if ( file_exists( $catalog_path ) ) {
			$contents = file_get_contents( $catalog_path );

			if ( false !== $contents ) {
				$decoded = json_decode( $contents, true );

				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array(
			'translation-revision-date' => gmdate( 'Y-m-d H:iO' ),
			'generator'                 => 'PeakURL',
			'domain'                    => $this->get_text_domain(),
			'locale_data'               => array(
				'messages' => array(
					'' => array(
						'domain'       => $this->get_text_domain(),
						'lang'         => $resolved_locale,
						'plural-forms' => $this->get_plural_forms_header( $resolved_locale ),
					),
				),
			),
		);
	}

	/**
	 * Convert the locale to an HTML lang attribute value.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 * @since 1.0.3
	 */
	public function get_html_lang( ?string $locale = null ): string {
		$resolved_locale = null === $locale
			? $this->get_current_locale()
			: $this->normalize_locale( $locale );

		$parts = explode( '_', $resolved_locale );

		foreach ( $parts as $index => $part ) {
			$parts[ $index ] = 0 === $index
				? strtolower( $part )
				: strtoupper( $part );
		}

		return implode( '-', $parts );
	}

	/**
	 * Ensure the persistent languages directory exists when the install can create it.
	 *
	 * @return bool True when the languages directory exists after the repair attempt.
	 * @since 1.0.4
	 */
	public function ensure_languages_directory(): bool {
		$content_directory   = $this->get_content_directory();
		$languages_directory = $this->get_languages_directory();
		$created_directory   = false;

		if ( ! is_dir( $content_directory ) ) {
			$created_directory = $this->create_directory_if_missing( $content_directory ) || $created_directory;
		}

		if ( is_dir( $content_directory ) && ! is_dir( $languages_directory ) ) {
			$created_directory = $this->create_directory_if_missing( $languages_directory ) || $created_directory;
		}

		if ( $created_directory ) {
			clearstatcache();
			$this->catalog              = null;
			$this->languages_cache      = null;
			$this->language_packs_cache = null;
		}

		return is_dir( $languages_directory );
	}

	/**
	 * Get the absolute languages directory path.
	 *
	 * @return string
	 * @since 1.0.3
	 */
	public function get_languages_directory(): string {
		return $this->get_content_directory() .
			DIRECTORY_SEPARATOR .
			Constants::LANGUAGES_DIRECTORY;
	}

	/**
	 * Load the PHP catalog for a locale from `.mo` or `.po`.
	 *
	 * @param string $locale Locale to load.
	 * @return GettextTranslations|null
	 * @since 1.0.3
	 */
	private function load_catalog( string $locale ): ?GettextTranslations {
		$mo_path = $this->build_php_catalog_path( $locale, 'mo' );
		$po_path = $this->build_php_catalog_path( $locale, 'po' );

		try {
			if ( file_exists( $mo_path ) ) {
				$catalog = ( new MoLoader() )->loadFile( $mo_path );
				$catalog->setDomain( $this->get_text_domain() );

				return $catalog;
			}

			if ( file_exists( $po_path ) ) {
				$catalog = ( new PoLoader() )->loadFile( $po_path );
				$catalog->setDomain( $this->get_text_domain() );

				return $catalog;
			}
		} catch ( \Throwable $exception ) {
			return null;
		}

		return null;
	}

	/**
	 * Resolve a plural index for the requested locale.
	 *
	 * Uses the trusted gettext/languages locale registry instead of the
	 * translation-file header so the runtime does not execute arbitrary
	 * formulas from uploaded language packs.
	 *
	 * @param string $locale Locale to evaluate.
	 * @param int    $number Count used for plural selection.
	 * @return int
	 * @since 1.0.3
	 */
	private function resolve_plural_index( string $locale, int $number ): int {
		$language = $this->resolve_language_info( $locale );
		$formula  = $language ? $language->formula : '(n != 1)';
		$n        = abs( $number );

		if (
			! is_string( $formula ) ||
			'' === trim( $formula ) ||
			! preg_match( '/^[\\s0-9n<>=!&|?:()%+\\-*\\/]+$/', $formula )
		) {
			return 1 === $n ? 0 : 1;
		}

		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Formula is restricted to the trusted gettext/languages registry.
			$index = eval( 'return (int) (' . $formula . ');' );
		} catch ( \Throwable $exception ) {
			$index = 1 === $n ? 0 : 1;
		}

		return max( 0, (int) $index );
	}

	/**
	 * Build a language entry for the dashboard dropdown.
	 *
	 * @param string               $locale Locale identifier.
	 * @param array<string, bool> $flags  Installed-catalog flags.
	 * @return array<string, mixed>
	 * @since 1.0.3
	 */
	private function build_language_entry(
		string $locale,
		array $flags,
		int $variant_count = 1
	): array {
		$language         = $this->resolve_language_info( $locale );
		$base_language    = $this->resolve_base_language_info( $locale );
		$territory        = $this->resolve_territory_label( $locale, $language );
		$english_name     = $base_language
			? (string) $base_language->name
			: ( $language ? (string) $language->name : str_replace( '_', ' ', $locale ) );
		$native_name      = $this->resolve_native_language_label( $locale, $english_name );
		$native_territory = $this->resolve_native_territory_label( $locale, $territory );
		$show_territory   = $variant_count > 1 || $locale === $this->get_default_locale();
		$label            = $show_territory && $native_territory
			? sprintf( '%s (%s)', $native_name, $native_territory )
			: $native_name;
		$english_label    = $show_territory && $territory
			? sprintf( '%s (%s)', $english_name, $territory )
			: $english_name;

		return array(
			'locale'             => $locale,
			'label'              => $label,
			'nativeName'         => $native_name,
			'nativeTerritory'    => $native_territory,
			'englishName'        => $english_name,
			'englishLabel'       => $english_label,
			'territory'          => $territory,
			'hasPhpTranslations' => ! empty( $flags['php'] ),
			'hasJsTranslations'  => ! empty( $flags['js'] ),
			'isDefault'          => $locale === $this->get_default_locale(),
		);
	}

	/**
	 * Count installed locale variants per base language.
	 *
	 * @param array<int, string> $locales Installed locale identifiers.
	 * @return array<string, int>
	 * @since 1.0.3
	 */
	private function count_base_language_variants( array $locales ): array {
		$counts = array();

		foreach ( $locales as $locale ) {
			$base_locale = $this->get_base_locale( $locale );

			if ( ! isset( $counts[ $base_locale ] ) ) {
				$counts[ $base_locale ] = 0;
			}

			++$counts[ $base_locale ];
		}

		return $counts;
	}

	/**
	 * Resolve the base language code for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.3
	 */
	private function get_base_locale( string $locale ): string {
		$base_locale = strstr( $locale, '_', true );

		if ( false === $base_locale || '' === $base_locale ) {
			return strtolower( $locale );
		}

		return strtolower( $base_locale );
	}

	/**
	 * Resolve locale metadata from the gettext/languages registry.
	 *
	 * @param string $locale Locale identifier.
	 * @return GettextLanguage|null
	 * @since 1.0.3
	 */
	private function resolve_language_info( string $locale ): ?GettextLanguage {
		$language = GettextLanguage::getById( $locale );

		if ( null !== $language ) {
			return $language;
		}

		if ( false !== strpos( $locale, '_' ) ) {
			$base_locale = strstr( $locale, '_', true );

			if ( false !== $base_locale ) {
				return GettextLanguage::getById( $base_locale );
			}
		}

		return null;
	}

	/**
	 * Resolve base-language metadata for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return GettextLanguage|null
	 * @since 1.0.3
	 */
	private function resolve_base_language_info( string $locale ): ?GettextLanguage {
		$base_locale = strstr( $locale, '_', true );

		if ( false === $base_locale || '' === $base_locale ) {
			return $this->resolve_language_info( $locale );
		}

		$base_language = GettextLanguage::getById( $base_locale );

		if ( null !== $base_language ) {
			return $base_language;
		}

		return $this->resolve_language_info( $locale );
	}

	/**
	 * Resolve territory metadata for a locale.
	 *
	 * @param string               $locale   Locale identifier.
	 * @param GettextLanguage|null $language Full locale metadata.
	 * @return string|null
	 * @since 1.0.3
	 */
	private function resolve_territory_label(
		string $locale,
		?GettextLanguage $language
	): ?string {
		if ( $language && ! empty( $language->territory ) ) {
			return (string) $language->territory;
		}

		$parts = explode( '_', $locale );

		if ( count( $parts ) < 2 ) {
			return null;
		}

		$territory_locale = strtolower( (string) $parts[0] ) .
			'_' .
			strtoupper( (string) $parts[1] );
		$territory_info   = GettextLanguage::getById( $territory_locale );

		if ( $territory_info && ! empty( $territory_info->territory ) ) {
			return (string) $territory_info->territory;
		}

		return null;
	}

	/**
	 * Resolve the native language name for a locale.
	 *
	 * @param string $locale   Locale identifier.
	 * @param string $fallback English fallback label.
	 * @return string
	 * @since 1.0.3
	 */
	private function resolve_native_language_label(
		string $locale,
		string $fallback
	): string {
		if ( class_exists( Locale::class ) ) {
			$native_name = Locale::getDisplayLanguage( $locale, $locale );

			if ( is_string( $native_name ) && '' !== trim( $native_name ) ) {
				return $this->normalize_native_label( $native_name );
			}
		}

		return $fallback;
	}

	/**
	 * Resolve the native territory name for a locale.
	 *
	 * @param string      $locale   Locale identifier.
	 * @param string|null $fallback English fallback label.
	 * @return string|null
	 * @since 1.0.3
	 */
	private function resolve_native_territory_label(
		string $locale,
		?string $fallback
	): ?string {
		if ( class_exists( Locale::class ) ) {
			$native_territory = Locale::getDisplayRegion( $locale, $locale );

			if ( is_string( $native_territory ) && '' !== trim( $native_territory ) ) {
				return $this->normalize_native_label( $native_territory );
			}
		}

		return $fallback;
	}

	/**
	 * Normalize native locale labels for display in the settings dropdown.
	 *
	 * @param string $label Raw locale label.
	 * @return string
	 * @since 1.0.3
	 */
	private function normalize_native_label( string $label ): string {
		$normalized_label = preg_replace( '/\s+/u', ' ', trim( $label ) );

		if ( ! is_string( $normalized_label ) || '' === $normalized_label ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			$first_character = mb_substr( $normalized_label, 0, 1, 'UTF-8' );
			$remaining       = mb_substr( $normalized_label, 1, null, 'UTF-8' );

			return mb_strtoupper( $first_character, 'UTF-8' ) . $remaining;
		}

		return ucfirst( $normalized_label );
	}

	/**
	 * Scan the languages directory for installed PHP and JS catalogs.
	 *
	 * @return array<string, array<string, bool>>
	 * @since 1.0.3
	 */
	private function scan_language_packs(): array {
		if ( null !== $this->language_packs_cache ) {
			return $this->language_packs_cache;
		}

		$directory = $this->get_languages_directory();
		$available = array();

		if ( ! is_dir( $directory ) ) {
			$this->language_packs_cache = $available;

			return $this->language_packs_cache;
		}

		$entries = scandir( $directory );

		if ( false === $entries ) {
			$this->language_packs_cache = $available;

			return $this->language_packs_cache;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) || '.' === $entry || '..' === $entry ) {
				continue;
			}

			if (
				preg_match(
					'/^' . preg_quote( $this->get_text_domain(), '/' ) . '-([A-Za-z0-9_]+)\.(po|mo)$/',
					$entry,
					$matches,
				)
			) {
				$locale = $this->normalize_locale( (string) $matches[1] );

				if ( ! isset( $available[ $locale ] ) ) {
					$available[ $locale ] = array(
						'php' => false,
						'js'  => false,
					);
				}

				$available[ $locale ]['php'] = true;
				continue;
			}

			if (
				preg_match(
					'/^' . preg_quote( $this->get_text_domain(), '/' ) . '-([A-Za-z0-9_]+)\.json$/',
					$entry,
					$matches,
				)
			) {
				$locale = $this->normalize_locale( (string) $matches[1] );

				if ( ! isset( $available[ $locale ] ) ) {
					$available[ $locale ] = array(
						'php' => false,
						'js'  => false,
					);
				}

				$available[ $locale ]['js'] = true;
			}
		}

		$this->language_packs_cache = $available;

		return $this->language_packs_cache;
	}

	/**
	 * Build the absolute PHP catalog path for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @param string $type   Either `po` or `mo`.
	 * @return string
	 * @since 1.0.3
	 */
	private function build_php_catalog_path( string $locale, string $type ): string {
		return $this->get_languages_directory() .
			DIRECTORY_SEPARATOR .
			sprintf(
				'%s-%s.%s',
				$this->get_text_domain(),
				$locale,
				$type,
			);
	}

	/**
	 * Build the absolute dashboard JSON catalog path for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.3
	 */
	private function build_dashboard_catalog_path( string $locale ): string {
		return $this->get_languages_directory() .
			DIRECTORY_SEPARATOR .
			sprintf(
				'%s-%s.json',
				$this->get_text_domain(),
				$locale,
			);
	}

	/**
	 * Build the default gettext plural-forms header for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.3
	 */
	private function get_plural_forms_header( string $locale ): string {
		$language = $this->resolve_language_info( $locale );

		if ( null === $language ) {
			return 'nplurals=2; plural=(n != 1);';
		}

		return sprintf(
			'nplurals=%d; plural=%s;',
			count( $language->categories ),
			$language->buildFormula( true ),
		);
	}

	/**
	 * Create a directory when its immediate parent already exists and is writable.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True when the directory was created.
	 * @since 1.0.4
	 */
	private function create_directory_if_missing( string $path ): bool {
		if ( '' === trim( $path ) || is_dir( $path ) ) {
			return false;
		}

		$parent_directory = dirname( $path );
		$last_parent      = '';

		while ( '' !== $parent_directory && ! is_dir( $parent_directory ) && $parent_directory !== $last_parent ) {
			$last_parent      = $parent_directory;
			$parent_directory = dirname( $parent_directory );
		}

		if ( '' === $parent_directory || ! is_dir( $parent_directory ) || ! is_writable( $parent_directory ) ) {
			return false;
		}

		if ( ! mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
			return false;
		}

		return true;
	}
}
