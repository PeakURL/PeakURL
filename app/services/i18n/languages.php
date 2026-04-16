<?php
/**
 * Installed language discovery and locale metadata helpers.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

use Gettext\Languages\Language as GettextLanguage;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Languages — scan installed language packs and build locale metadata.
 *
 * @since 1.0.14
 */
class Languages {

	/**
	 * Locale normalization and display helper.
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
	 * Cached installed-language list for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.0.14
	 */
	private ?array $languages_cache = null;

	/**
	 * Cached language-pack scan results for the current request.
	 *
	 * @var array<string, array<string, bool>>|null
	 * @since 1.0.14
	 */
	private ?array $language_packs_cache = null;

	/**
	 * Create a new language metadata helper.
	 *
	 * @param Locale $locale_helper Locale normalization helper.
	 * @param Paths  $paths         Filesystem paths helper.
	 * @since 1.0.14
	 */
	public function __construct( Locale $locale_helper, Paths $paths ) {
		$this->locale_helper = $locale_helper;
		$this->paths         = $paths;
	}

	/**
	 * Reset the in-request language-pack caches.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function reset(): void {
		$this->languages_cache      = null;
		$this->language_packs_cache = null;
	}

	/**
	 * Return the list of installed language packs plus the default locale.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.14
	 */
	public function list_languages(): array {
		if ( null !== $this->languages_cache ) {
			return $this->languages_cache;
		}

		$available = $this->scan_language_packs();
		$default   = $this->locale_helper->get_default_locale();

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
				(int) ( $variant_counts[ $this->locale_helper->get_base_locale( $locale ) ] ?? 1 ),
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
	 * @since 1.0.14
	 */
	public function is_locale_available( string $locale ): bool {
		$locale = $this->locale_helper->normalize_locale( $locale );

		foreach ( $this->list_languages() as $language ) {
			if ( (string) ( $language['locale'] ?? '' ) === $locale ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the default gettext plural-forms header for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_plural_forms_header( string $locale ): string {
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
	 * Resolve a plural index for the requested locale.
	 *
	 * Uses the trusted gettext/languages locale registry instead of the
	 * translation-file header so the runtime does not execute arbitrary
	 * formulas from uploaded language packs.
	 *
	 * @param string $locale Locale to evaluate.
	 * @param int    $number Count used for plural selection.
	 * @return int
	 * @since 1.0.14
	 */
	public function resolve_plural_index( string $locale, int $number ): int {
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
	 * @param string              $locale        Locale identifier.
	 * @param array<string, bool> $flags         Installed-catalog flags.
	 * @param int                 $variant_count Number of locale variants for the base language.
	 * @return array<string, mixed>
	 * @since 1.0.14
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
		$show_territory   = $variant_count > 1 || $locale === $this->locale_helper->get_default_locale();
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
			'textDirection'      => $this->locale_helper->get_text_direction( $locale ),
			'isRtl'              => $this->locale_helper->is_locale_rtl( $locale ),
			'hasPhpTranslations' => ! empty( $flags['php'] ),
			'hasJsTranslations'  => ! empty( $flags['js'] ),
			'isDefault'          => $locale === $this->locale_helper->get_default_locale(),
		);
	}

	/**
	 * Count installed locale variants per base language.
	 *
	 * @param array<int, string> $locales Installed locale identifiers.
	 * @return array<string, int>
	 * @since 1.0.14
	 */
	private function count_base_language_variants( array $locales ): array {
		$counts = array();

		foreach ( $locales as $locale ) {
			$base_locale = $this->locale_helper->get_base_locale( $locale );

			if ( ! isset( $counts[ $base_locale ] ) ) {
				$counts[ $base_locale ] = 0;
			}

			++$counts[ $base_locale ];
		}

		return $counts;
	}

	/**
	 * Resolve locale metadata from the gettext/languages registry.
	 *
	 * @param string $locale Locale identifier.
	 * @return GettextLanguage|null
	 * @since 1.0.14
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
	 * @since 1.0.14
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
	 * @since 1.0.14
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
	 * @since 1.0.14
	 */
	private function resolve_native_language_label(
		string $locale,
		string $fallback
	): string {
		if ( class_exists( \Locale::class ) ) {
			$native_name = \Locale::getDisplayLanguage( $locale, $locale );

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
	 * @since 1.0.14
	 */
	private function resolve_native_territory_label(
		string $locale,
		?string $fallback
	): ?string {
		if ( class_exists( \Locale::class ) ) {
			$native_territory = \Locale::getDisplayRegion( $locale, $locale );

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
	 * @since 1.0.14
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
	 * @since 1.0.14
	 */
	private function scan_language_packs(): array {
		if ( null !== $this->language_packs_cache ) {
			return $this->language_packs_cache;
		}

		$directory = $this->paths->get_languages_dir();
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
					'/^' . preg_quote( $this->paths->get_text_domain(), '/' ) . '-([A-Za-z0-9_]+)\.(po|mo)$/',
					$entry,
					$matches,
				)
			) {
				$locale = $this->locale_helper->normalize_locale( (string) $matches[1] );

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
					'/^' . preg_quote( $this->paths->get_text_domain(), '/' ) . '-([A-Za-z0-9_]+)\.json$/',
					$entry,
					$matches,
				)
			) {
				$locale = $this->locale_helper->normalize_locale( (string) $matches[1] );

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
}
