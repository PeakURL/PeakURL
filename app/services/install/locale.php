<?php
/**
 * Release installer locale bootstrap.
 *
 * @package PeakURL\Services\Install
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Install;

use PeakURL\Includes\Constants;
use PeakURL\Services\I18n;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Locale — installer translation bootstrap for setup and install screens.
 *
 * Loads bundled language packs directly from `content/languages` before the
 * database-backed runtime locale is available.
 *
 * @since 1.0.14
 */
class Locale {

	/**
	 * Shared i18n service configured for the installer.
	 *
	 * @var I18n
	 * @since 1.0.14
	 */
	private I18n $i18n_service;

	/**
	 * Active installer locale.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $locale;

	/**
	 * Create a new installer locale service.
	 *
	 * @param string      $root_path              Absolute release-root path.
	 * @param string|null $requested_locale       Requested locale from the installer.
	 * @param string|null $accept_language_header Browser language header.
	 * @since 1.0.14
	 */
	public function __construct(
		string $root_path,
		?string $requested_locale = null,
		?string $accept_language_header = null
	) {
		$this->i18n_service = new I18n(
			$this->get_service_config( $root_path ),
			null,
		);
		$this->locale       = $this->resolve_locale(
			$requested_locale,
			$accept_language_header,
		);
		$this->i18n_service = new I18n(
			$this->get_service_config( $root_path, $this->locale ),
			null,
		);
		$this->i18n_service->load_locale( $this->locale );
	}

	/**
	 * Return the active installer locale.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_locale(): string {
		return $this->locale;
	}

	/**
	 * Return the configured installer i18n service.
	 *
	 * @return I18n
	 * @since 1.0.14
	 */
	public function get_i18n_service(): I18n {
		return $this->i18n_service;
	}

	/**
	 * Return the installer HTML lang attribute.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_html_lang(): string {
		return $this->i18n_service->get_html_lang( $this->locale );
	}

	/**
	 * Return the installer text direction.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_direction(): string {
		return $this->i18n_service->get_text_direction( $this->locale );
	}

	/**
	 * Return the installed language choices available to the installer.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.14
	 */
	public function list_languages(): array {
		return $this->i18n_service->list_languages();
	}

	/**
	 * Resolve the requested installer locale.
	 *
	 * @param string|null $requested_locale       Requested installer locale.
	 * @param string|null $accept_language_header Browser language header.
	 * @return string
	 * @since 1.0.14
	 */
	private function resolve_locale(
		?string $requested_locale,
		?string $accept_language_header
	): string {
		if ( null !== $requested_locale && '' !== trim( $requested_locale ) ) {
			$normalized_locale = $this->i18n_service->normalize_locale( $requested_locale );

			if ( $this->i18n_service->is_locale_available( $normalized_locale ) ) {
				return $normalized_locale;
			}
		}

		$browser_locale = $this->match_browser_locale(
			(string) $accept_language_header,
		);

		if ( '' !== $browser_locale ) {
			return $browser_locale;
		}

		return $this->i18n_service->get_default_locale();
	}

	/**
	 * Match the browser language preferences against installed locales.
	 *
	 * @param string $accept_language_header Browser language header.
	 * @return string
	 * @since 1.0.14
	 */
	private function match_browser_locale( string $accept_language_header ): string {
		$accept_language_header = trim( $accept_language_header );

		if ( '' === $accept_language_header ) {
			return '';
		}

		$installed_locales = array_values(
			array_filter(
				array_map(
					static function ( array $language ): string {
						return (string) ( $language['locale'] ?? '' );
					},
					$this->i18n_service->list_languages(),
				),
			),
		);

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

			$normalized_locale = $this->i18n_service->normalize_locale( $language_tag );

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

		$preferred_base_locale = $this->get_base_locale( $preferred_locale );

		foreach ( $installed_locales as $installed_locale ) {
			if ( $preferred_base_locale === $this->get_base_locale( $installed_locale ) ) {
				return $installed_locale;
			}
		}

		return '';
	}

	/**
	 * Return the base language portion of a locale code.
	 *
	 * @param string $locale Locale identifier.
	 * @return string
	 * @since 1.0.14
	 */
	private function get_base_locale( string $locale ): string {
		$base_locale = strstr( $locale, '_', true );

		if ( false === $base_locale || '' === $base_locale ) {
			return strtolower( $locale );
		}

		return strtolower( $base_locale );
	}

	/**
	 * Return the runtime configuration map used by installer i18n.
	 *
	 * @param string $root_path Absolute release-root path.
	 * @param string $locale    Active installer locale.
	 * @return array<string, string>
	 * @since 1.0.14
	 */
	private function get_service_config(
		string $root_path,
		string $locale = ''
	): array {
		$config = array(
			Constants::CONFIG_CONTENT_DIR => rtrim( $root_path, '/\\' ) . DIRECTORY_SEPARATOR . Constants::DEFAULT_CONTENT_DIR,
			Constants::CONFIG_SITE_URL    => '',
		);

		if ( '' !== $locale ) {
			$config['PEAKURL_SITE_LANGUAGE'] = $locale;
		}

		return $config;
	}
}
