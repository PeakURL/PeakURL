<?php
/**
 * Catalog loading helpers for PeakURL i18n.
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
 * Catalog — load gettext catalogs and dashboard JSON translation packs.
 *
 * @since 1.0.14
 */
class Catalog {

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
		$mo_path = $this->paths->build_php_catalog_path( $locale, 'mo' );
		$po_path = $this->paths->build_php_catalog_path( $locale, 'po' );

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
		$catalog_path = $this->paths->build_dashboard_catalog_path( $locale );

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
}
