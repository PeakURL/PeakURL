<?php
/**
 * Favicon path helpers.
 *
 * @package PeakURL\Services\Favicon
 * @since 1.1.1
 */

declare(strict_types=1);

namespace PeakURL\Services\Favicon;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Paths — return configured favicon storage and bundled fallback paths.
 *
 * @since 1.1.1
 */
class Paths {

	/** @var string Stored favicon filename. */
	private const ICON_FILE = 'favicon.png';

	/** @var string Stored web manifest filename. */
	private const MANIFEST_FILE = 'site.webmanifest';

	/** @var string Relative uploads directory used for favicon assets. */
	private const DIRECTORY = 'uploads/favicon';

	/** @var string Bundled fallback favicon asset path relative to the app root. */
	private const BUNDLED_ICON_FILE = 'app/public/default-favicon.png';

	/** @var string Bundled fallback manifest path relative to the app root. */
	private const BUNDLED_MANIFEST_FILE = 'app/public/default-site.webmanifest';

	/**
	 * Absolute persistent content directory.
	 *
	 * @var string
	 * @since 1.1.1
	 */
	private string $content_dir;

	/**
	 * Create a new favicon path helper.
	 *
	 * @param array<string, mixed> $config Runtime configuration map.
	 * @since 1.1.1
	 */
	public function __construct( array $config ) {
		$this->content_dir = rtrim(
			(string) (
				$config[ Constants::CONTENT_DIR ]
				?? ABSPATH . Constants::DEFAULT_CONTENT_DIR
			),
			'/\\',
		);
	}

	/**
	 * Return the absolute favicon directory path.
	 *
	 * @return string
	 * @since 1.1.1
	 */
	public function get_directory_path(): string {
		return $this->content_dir . '/' . self::DIRECTORY;
	}

	/**
	 * Return the absolute favicon image path.
	 *
	 * @return string
	 * @since 1.1.1
	 */
	public function get_icon_path(): string {
		return $this->get_directory_path() . '/' . self::ICON_FILE;
	}

	/**
	 * Return the absolute manifest file path.
	 *
	 * @return string
	 * @since 1.1.1
	 */
	public function get_manifest_path(): string {
		return $this->get_directory_path() . '/' . self::MANIFEST_FILE;
	}

	/**
	 * Return the bundled fallback favicon asset path.
	 *
	 * @return string
	 * @since 1.1.1
	 */
	public function get_bundled_icon_path(): string {
		return untrailingslashit( ABSPATH ) . '/' . self::BUNDLED_ICON_FILE;
	}

	/**
	 * Return the bundled fallback manifest asset path.
	 *
	 * @return string
	 * @since 1.1.1
	 */
	public function get_bundled_manifest_path(): string {
		return untrailingslashit( ABSPATH ) . '/' . self::BUNDLED_MANIFEST_FILE;
	}
}
