<?php
/**
 * Locale rules and formatting helpers for PeakURL i18n.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

use PeakURL\Core\Config\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Locale — normalize locale identifiers and derive locale display flags.
 *
 * @since 1.0.14
 */
class Locale {

	/**
	 * Base locale codes that use right-to-left text direction.
	 *
	 * @var array<int, string>
	 * @since 1.0.14
	 */
	private const RTL_BASE_LOCALES = array(
		'ar',
		'arc',
		'azb',
		'ckb',
		'dv',
		'fa',
		'he',
		'ps',
		'sd',
		'ug',
		'ur',
		'yi',
	);

	/**
	 * Get the default locale used when no setting exists.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_default_locale(): string {
		return Constants::DEFAULT_LOCALE;
	}

	/**
	 * Canonicalize a raw locale string to a standard identifier.
	 *
	 * Returns an empty string if the input is empty or does not match a valid
	 * locale pattern. Does not choose a fallback locale.
	 *
	 * @param string $locale Raw locale value.
	 * @return string Canonical locale identifier or empty string when invalid.
	 * @since 1.0.14
	 */
	public function canonicalize_locale( string $locale ): string {
		$locale = trim( str_replace( '-', '_', $locale ) );

		if ( '' === $locale ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
			return '';
		}

		$parts = explode( '_', $locale );

		if ( empty( $parts ) ) {
			return '';
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
	 * Convert the locale to an HTML lang attribute value.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_html_lang( string $locale ): string {
		$canonical_locale = $this->canonicalize_locale( $locale );

		if ( '' === $canonical_locale ) {
			return '';
		}

		return str_replace( '_', '-', $canonical_locale );
	}

	/**
	 * Determine whether a locale should render right-to-left.
	 *
	 * @param string $locale Locale identifier.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_locale_rtl( string $locale ): bool {
		$base_locale = $this->get_base_locale( $locale );

		if ( '' === $base_locale ) {
			return false;
		}

		return in_array(
			$base_locale,
			self::RTL_BASE_LOCALES,
			true,
		);
	}

	/**
	 * Get the document text direction for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_direction( string $locale ): string {
		return $this->is_locale_rtl( $locale ) ? 'rtl' : 'ltr';
	}

	/**
	 * Resolve the base language code for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @return string Base language code in lowercase or empty string.
	 * @since 1.0.14
	 */
	public function get_base_locale( string $locale ): string {
		$canonical_locale = $this->canonicalize_locale( $locale );

		if ( '' === $canonical_locale ) {
			return '';
		}

		$base_locale = strstr( $canonical_locale, '_', true );

		if ( false === $base_locale || '' === $base_locale ) {
			return strtolower( $canonical_locale );
		}

		return strtolower( $base_locale );
	}
}
