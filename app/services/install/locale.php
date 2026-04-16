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
use PeakURL\Services\I18n\Manager as I18nManager;

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
	 * @var I18nManager
	 * @since 1.0.14
	 */
	private I18nManager $i18n_service;

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
		$this->i18n_service = new I18nManager(
			$this->get_service_config( $root_path ),
			null,
		);
		$this->locale       = $this->resolve_locale(
			$requested_locale,
			$accept_language_header,
		);
		$this->i18n_service = new I18nManager(
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
	 * @return I18nManager
	 * @since 1.0.14
	 */
	public function get_i18n_service(): I18nManager {
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
		return $this->i18n_service->match_browser_locale( $accept_language_header );
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
