<?php
/**
 * Browser locale matching helpers.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BrowserLocale - match browser language preferences against installed locales.
 *
 * @since 1.0.14
 */
class BrowserLocale {

	/**
	 * Locale normalization helper.
	 *
	 * @var Locale
	 * @since 1.0.14
	 */
	private Locale $locale_helper;

	/**
	 * Create a new browser locale helper.
	 *
	 * @param Locale $locale_helper Locale normalization helper.
	 * @since 1.0.14
	 */
	public function __construct( Locale $locale_helper ) {
		$this->locale_helper = $locale_helper;
	}

	/**
	 * Match the browser language preferences against installed locales.
	 *
	 * @param string            $accept_language_header Browser language header.
	 * @param array<int, string> $installed_locales     Installed locale codes.
	 * @return string
	 * @since 1.0.14
	 */
	public function match_browser_locale(
		string $accept_language_header,
		array $installed_locales
	): string {
		$accept_language_header = trim( $accept_language_header );

		if ( '' === $accept_language_header ) {
			return '';
		}

		foreach ( $this->parse_browser_language_preferences( $accept_language_header ) as $preferred_locale ) {
			$matched_locale = $this->find_available_locale(
				$installed_locales,
				$preferred_locale,
			);

			if ( '' !== $matched_locale ) {
				return $matched_locale;
			}
		}

		return '';
	}

	/**
	 * Parse the browser Accept-Language header into normalized locale codes.
	 *
	 * @param string $accept_language_header Browser language header.
	 * @return array<int, string>
	 * @since 1.0.14
	 */
	private function parse_browser_language_preferences( string $accept_language_header ): array {
		$preferences = array();

		foreach ( explode( ',', $accept_language_header ) as $index => $segment ) {
			$segment = trim( $segment );

			if ( '' === $segment || '*' === $segment ) {
				continue;
			}

			$language_tag = $segment;
			$quality      = 1.0;

			if ( false !== strpos( $segment, ';' ) ) {
				$parts        = explode( ';', $segment );
				$language_tag = trim( (string) array_shift( $parts ) );

				foreach ( $parts as $parameter ) {
					if ( preg_match( '/^\s*q=([0-9.]+)\s*$/i', trim( $parameter ), $matches ) ) {
						$quality = (float) $matches[1];
						break;
					}
				}
			}

			if ( '' === $language_tag || '*' === $language_tag ) {
				continue;
			}

			$normalized_locale = $this->locale_helper->normalize_locale( $language_tag );

			$preferences[] = array(
				'locale'  => $normalized_locale,
				'quality' => $quality,
				'index'   => $index,
			);
		}

		usort(
			$preferences,
			static function ( array $left, array $right ): int {
				if ( $left['quality'] === $right['quality'] ) {
					return $left['index'] <=> $right['index'];
				}

				return $right['quality'] <=> $left['quality'];
			},
		);

		$locales = array();

		foreach ( $preferences as $preference ) {
			$locale = (string) ( $preference['locale'] ?? '' );

			if ( '' === $locale || in_array( $locale, $locales, true ) ) {
				continue;
			}

			$locales[] = $locale;
		}

		return $locales;
	}

	/**
	 * Find the installed locale that best matches the requested locale.
	 *
	 * @param array<int, string> $installed_locales Installed locale codes.
	 * @param string             $preferred_locale  Browser-preferred locale.
	 * @return string
	 * @since 1.0.14
	 */
	private function find_available_locale(
		array $installed_locales,
		string $preferred_locale
	): string {
		if ( in_array( $preferred_locale, $installed_locales, true ) ) {
			return $preferred_locale;
		}

		$preferred_base_locale = $this->locale_helper->get_base_locale( $preferred_locale );

		foreach ( $installed_locales as $installed_locale ) {
			if ( $preferred_base_locale === $this->locale_helper->get_base_locale( $installed_locale ) ) {
				return $installed_locale;
			}
		}

		return '';
	}
}
