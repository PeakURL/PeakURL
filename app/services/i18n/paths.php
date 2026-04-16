<?php
/**
 * Filesystem paths for PeakURL translation catalogs.
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
 * Paths — resolve i18n content and catalog file paths.
 *
 * @since 1.0.14
 */
class Paths {

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Create a new i18n paths helper.
	 *
	 * @param array<string, mixed> $config Runtime configuration map.
	 * @since 1.0.14
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Get the active gettext text domain.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_text_domain(): string {
		return Constants::I18N_TEXT_DOMAIN;
	}

	/**
	 * Get the absolute persistent content directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_content_dir(): string {
		return rtrim(
			(string) ( $this->config[ Constants::CONFIG_CONTENT_DIR ] ?? ABSPATH . Constants::DEFAULT_CONTENT_DIR ),
			'/\\',
		);
	}

	/**
	 * Get the absolute languages directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_languages_dir(): string {
		return $this->get_content_dir() .
			DIRECTORY_SEPARATOR .
			Constants::LANGUAGES_DIRECTORY;
	}

	/**
	 * Build the absolute PHP catalog path for a locale.
	 *
	 * @param string $locale Locale identifier.
	 * @param string $type   Either `po` or `mo`.
	 * @return string
	 * @since 1.0.14
	 */
	public function get_php_catalog_path(
		string $locale,
		string $type
	): string {
		return $this->get_languages_dir() .
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
	 * @since 1.0.14
	 */
	public function get_dashboard_catalog_path( string $locale ): string {
		return $this->get_languages_dir() .
			DIRECTORY_SEPARATOR .
			sprintf(
				'%s-%s.json',
				$this->get_text_domain(),
				$locale,
			);
	}

	/**
	 * Prepare the persistent languages directory when the install can create it.
	 *
	 * @return array{created: bool, exists: bool}
	 * @since 1.0.14
	 */
	public function prepare_languages_directory(): array {
		$content_directory   = $this->get_content_dir();
		$languages_directory = $this->get_languages_dir();
		$created_directory   = false;

		if ( ! is_dir( $content_directory ) ) {
			$created_directory = $this->create_directory_if_missing( $content_directory ) || $created_directory;
		}

		if ( is_dir( $content_directory ) && ! is_dir( $languages_directory ) ) {
			$created_directory = $this->create_directory_if_missing( $languages_directory ) || $created_directory;
		}

		if ( $created_directory ) {
			clearstatcache();
		}

		return array(
			'created' => $created_directory,
			'exists'  => is_dir( $languages_directory ),
		);
	}

	/**
	 * Create a directory when its immediate parent already exists and is writable.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 * @since 1.0.14
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
