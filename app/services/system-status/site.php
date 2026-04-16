<?php
/**
 * Site status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Site — build the site section of the system-status payload.
 *
 * @since 1.0.14
 */
class Site {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new site status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build site-level status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		$settings_api        = $this->context->get_settings_api();
		$i18n_service        = $this->context->get_i18n_service();
		$config              = $this->context->get_config();
		$site_name           = trim( (string) $settings_api->get_option( 'site_name' ) );
		$site_url            = trim( (string) $settings_api->get_option( 'site_url' ) );
		$locale              = $i18n_service->get_site_locale();
		$available_languages = $i18n_service->list_languages();
		$active_language     = $this->find_language(
			$available_languages,
			$locale,
		);

		if ( '' === $site_name ) {
			$site_name = 'PeakURL';
		}

		if ( '' === $site_url ) {
			$site_url = trim(
				(string) ( $config[ Constants::CONFIG_SITE_URL ] ?? '' ),
			);
		}

		return array(
			'name'                    => $site_name,
			'url'                     => $site_url,
			'version'                 => $this->context->get_version(),
			'environment'             => trim(
				(string) ( $config[ Constants::CONFIG_ENV ] ?? 'production' ),
			),
			'installType'             => $this->context->is_source_checkout()
				? 'source'
				: 'release',
			'debugEnabled'            => ! empty(
				$config[ Constants::CONFIG_DEBUG ]
			),
			'locale'                  => $locale,
			'htmlLang'                => $i18n_service->get_html_lang( $locale ),
			'languageLabel'           => (string) ( $active_language['label'] ?? $locale ),
			'languageNativeName'      => (string) ( $active_language['nativeName'] ?? $locale ),
			'languageEnglishLabel'    => (string) ( $active_language['englishLabel'] ?? $locale ),
			'installedLanguagesCount' => count( $available_languages ),
			'defaultLocale'           => $i18n_service->get_default_locale(),
		);
	}

	/**
	 * Find the language entry for the active locale.
	 *
	 * @param array<int, array<string, mixed>> $languages Installed languages.
	 * @param string                           $locale    Active locale.
	 * @return array<string, mixed>|null
	 * @since 1.0.14
	 */
	private function find_language( array $languages, string $locale ): ?array {
		foreach ( $languages as $language ) {
			if ( (string) ( $language['locale'] ?? '' ) === $locale ) {
				return $language;
			}
		}

		return null;
	}
}
