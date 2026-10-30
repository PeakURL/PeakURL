<?php
/**
 * Translation catalog loading helpers for PeakURL i18n.
 *
 * @package PeakURL\Services\I18n
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\I18n;

use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Translations as GettextTranslations;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Loader — load gettext catalogs and dashboard JSON translation packs.
 *
 * @since 1.0.14
 */
class Loader {

	/**
	 * Filesystem paths helper.
	 *
	 * @var Paths
	 * @since 1.0.14
	 */
	private Paths $paths;

	/**
	 * Create a new catalog loader.
	 *
	 * @param Paths $paths Filesystem paths helper.
	 * @since 1.0.14
	 */
	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	/**
	 * Load the PHP catalog for a locale from `.mo` or `.po`.
	 *
	 * @param string $locale Locale to load.
	 * @return GettextTranslations|null
	 * @since 1.0.14
	 */
	public function load_php_catalog( string $locale ): ?GettextTranslations {
		$mo_path = $this->paths->get_php_catalog_path( $locale, 'mo' );
		$po_path = $this->paths->get_php_catalog_path( $locale, 'po' );

		try {
			if ( file_exists( $mo_path ) ) {
				$catalog = ( new MoLoader() )->loadFile( $mo_path );
				$catalog->setDomain( $this->paths->get_text_domain() );

				return $catalog;
			}

			if ( file_exists( $po_path ) ) {
				$catalog = ( new PoLoader() )->loadFile( $po_path );
				$catalog->setDomain( $this->paths->get_text_domain() );

				return $catalog;
			}
		} catch ( \Throwable $exception ) {
			return null;
		}

		return null;
	}

	/**
	 * Return the dashboard translation catalog for a locale.
	 *
	 * @param string $locale              Locale identifier.
	 * @param string $plural_forms_header Default plural-forms header.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_dashboard_catalog(
		string $locale,
		string $plural_forms_header
	): array {
		$catalog_path = $this->paths->get_json_catalog_path( $locale );

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
			'domain'                    => $this->paths->get_text_domain(),
			'locale_data'               => array(
				'messages' => array(
					'' => array(
						'domain'       => $this->paths->get_text_domain(),
						'lang'         => $locale,
						'plural-forms' => $plural_forms_header,
					),
				),
			),
		);
	}

	/**
	 * Load a domain-specific PHP gettext catalog from a languages directory.
	 *
	 * @param string $domain        Gettext text domain.
	 * @param string $languages_dir Absolute directory path.
	 * @param string $locale        Locale identifier.
	 * @return GettextTranslations|null
	 * @since 1.8.0
	 */
	public function load_domain_catalog(
		string $domain,
		string $languages_dir,
		string $locale
	): ?GettextTranslations {
		$real_dir = realpath( $languages_dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) || is_link( $languages_dir ) ) {
			return null;
		}
		$clean_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		$candidates = $this->get_domain_catalog_candidates( $domain, $languages_dir, $locale );

		foreach ( $candidates as $path ) {
			if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$real_file = realpath( $path );
			if ( false === $real_file || is_link( $real_file ) || ! str_starts_with( $real_file, $clean_dir ) ) {
				continue;
			}

			try {
				if ( str_ends_with( $path, '.mo' ) ) {
					$catalog = ( new MoLoader() )->loadFile( $path );
					$catalog->setDomain( $domain );

					return $catalog;
				}

				if ( str_ends_with( $path, '.po' ) ) {
					$catalog = ( new PoLoader() )->loadFile( $path );
					$catalog->setDomain( $domain );

					return $catalog;
				}
			} catch ( \Throwable ) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Return the dashboard JSON translation catalog for a domain and locale if present.
	 *
	 * @param string $domain        Gettext text domain.
	 * @param string $languages_dir Absolute directory path.
	 * @param string $locale        Locale identifier.
	 * @return array<string, mixed>|null
	 * @since 1.8.0
	 */
	public function get_domain_dashboard_catalog(
		string $domain,
		string $languages_dir,
		string $locale
	): ?array {
		$real_dir = realpath( $languages_dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) || is_link( $languages_dir ) ) {
			return null;
		}
		$clean_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		$candidates = $this->get_domain_json_candidates( $domain, $languages_dir, $locale );

		foreach ( $candidates as $path ) {
			if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$real_file = realpath( $path );
			if ( false === $real_file || is_link( $real_file ) || ! str_starts_with( $real_file, $clean_dir ) ) {
				continue;
			}

			$contents = file_get_contents( $path );
			if ( false !== $contents ) {
				$decoded = json_decode( $contents, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	/**
	 * Build candidate file paths for gettext translation catalogs.
	 *
	 * @param string $domain Gettext text domain.
	 * @param string $dir    Languages directory.
	 * @param string $locale Locale identifier.
	 * @return array<int, string>
	 */
	private function get_domain_catalog_candidates( string $domain, string $dir, string $locale ): array {
		$clean_dir = rtrim( $dir, '/\\' );
		$locales   = array( $locale );
		if ( str_contains( $locale, '_' ) ) {
			$locales[] = explode( '_', $locale )[0];
		}

		$candidates = array();
		foreach ( $locales as $loc ) {
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s-%s.mo', $domain, $loc );
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s-%s.po', $domain, $loc );
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s.mo', $loc );
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s.po', $loc );
		}

		return $candidates;
	}

	/**
	 * Build candidate file paths for dashboard JSON catalogs.
	 *
	 * @param string $domain Gettext text domain.
	 * @param string $dir    Languages directory.
	 * @param string $locale Locale identifier.
	 * @return array<int, string>
	 */
	private function get_domain_json_candidates( string $domain, string $dir, string $locale ): array {
		$clean_dir = rtrim( $dir, '/\\' );
		$locales   = array( $locale );
		if ( str_contains( $locale, '_' ) ) {
			$locales[] = explode( '_', $locale )[0];
		}

		$candidates = array();
		foreach ( $locales as $loc ) {
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s-%s.json', $domain, $loc );
			$candidates[] = $clean_dir . DIRECTORY_SEPARATOR . sprintf( '%s.json', $loc );
		}

		return $candidates;
	}
}
