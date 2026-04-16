<?php
/**
 * Locale rules and formatting helpers for PeakURL i18n.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

use PeakURL\Includes\Constants;

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
	 * Normalize a locale string to a WordPress-style identifier.
	 *
	 * @param string $locale Raw locale value.
	 * @return string
	 * @since 1.0.14
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
	 * Convert the locale to an HTML lang attribute value.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_html_lang( string $locale ): string {
		$parts = explode( '_', $this->normalize_locale( $locale ) );

		foreach ( $parts as $index => $part ) {
			$parts[ $index ] = 0 === $index
				? strtolower( $part )
				: strtoupper( $part );
		}

		return implode( '-', $parts );
	}

	/**
	 * Determine whether a locale should render right-to-left.
	 *
	 * @param string $locale Locale identifier.
	 * @return bool
	 * @since 1.0.14
	 */
	public function is_locale_rtl( string $locale ): bool {
		return in_array(
			$this->get_base_locale( $this->normalize_locale( $locale ) ),
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
	 * @return string
	 * @since 1.0.14
	 */
	public function get_base_locale( string $locale ): string {
		$base_locale = strstr( $this->normalize_locale( $locale ), '_', true );

		if ( false === $base_locale || '' === $base_locale ) {
			return strtolower( $locale );
		}

		return strtolower( $base_locale );
	}
}
