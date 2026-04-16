<?php
/**
 * Dashboard updater context helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Context — shared updater config and path state.
 *
 * Keeps stable runtime details such as versions, content paths, and
 * apply-availability checks out of the public service facade.
 *
 * @since 1.0.14
 */
class Context {

	/**
	 * Shared runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Shared updater filesystem helper.
	 *
	 * @var Filesystem
	 * @since 1.0.14
	 */
	private Filesystem $filesystem;

	/**
	 * Create a new updater context helper.
	 *
	 * @param array<string, mixed> $config     Shared runtime configuration.
	 * @param Filesystem           $filesystem Shared updater filesystem helper.
	 * @since 1.0.14
	 */
	public function __construct( array $config, Filesystem $filesystem ) {
		$this->config     = $config;
		$this->filesystem = $filesystem;
	}

	/**
	 * Read a raw config value with an optional default.
	 *
	 * @param string $key            Runtime config key.
	 * @param mixed  $fallback_value Fallback value.
	 * @return mixed
	 * @since 1.0.14
	 */
	public function get_config_value( string $key, $fallback_value = null ) {
		return array_key_exists( $key, $this->config )
			? $this->config[ $key ]
			: $fallback_value;
	}

	/**
	 * Get the currently installed PeakURL version string.
	 *
	 * @return string Semantic version.
	 * @since 1.0.14
	 */
	public function get_current_version(): string {
		$version = trim(
			(string) ( $this->config[ Constants::CONFIG_VERSION ] ?? '' ),
		);

		return '' !== $version ? $version : Constants::DEFAULT_VERSION;
	}

	/**
	 * Get the configured site URL for updater user-agent headers.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_site_url(): string {
		return (string) (
			$this->config[ Constants::CONFIG_SITE_URL ]
			?? 'unknown-site'
		);
	}

	/**
	 * Get the configured persistent content directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_content_dir(): string {
		$content_dir = trim(
			(string) ( $this->config[ Constants::CONFIG_CONTENT_DIR ] ?? '' ),
		);

		if ( '' === $content_dir ) {
			return $this->filesystem->build_path(
				ABSPATH,
				Constants::DEFAULT_CONTENT_DIR,
			);
		}

		return rtrim( $content_dir, '/\\' );
	}

	/**
	 * Get the base storage directory for update working files.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_storage_dir(): string {
		return $this->filesystem->build_path(
			$this->get_content_dir(),
			'updates',
		);
	}

	/**
	 * Get the obsolete pre-1.0.9 update workspace path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_legacy_storage_dir(): string {
		return $this->filesystem->build_path(
			$this->get_content_dir(),
			'uploads',
			'updates',
		);
	}

	/**
	 * Get the updater lock file path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_lock_path(): string {
		return $this->filesystem->build_path(
			$this->get_storage_dir(),
			'update.lock',
		);
	}

	/**
	 * Get the maintenance-mode flag file path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	public function get_maintenance_path(): string {
		return $this->filesystem->build_path( ABSPATH, '.maintenance' );
	}

	/**
	 * Evaluate whether this installation can apply updates in-place.
	 *
	 * Checks for development markers, \ZipArchive extension, and write permissions.
	 *
	 * @return array{allowed: bool, reason: string|null}
	 * @since 1.0.14
	 */
	public function get_availability(): array {
		if ( file_exists( ABSPATH . 'package.json' ) || is_dir( ABSPATH . '.git' ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'Dashboard updates can only be applied from an installed release package.', 'peakurl' ),
			);
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The ZipArchive PHP extension is required to apply updates.', 'peakurl' ),
			);
		}

		if ( ! is_writable( ABSPATH ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The release root is not writable.', 'peakurl' ),
			);
		}

		if ( ! is_writable( $this->filesystem->build_path( ABSPATH, 'app' ) ) ) {
			return array(
				'allowed' => false,
				'reason'  => __( 'The app directory is not writable.', 'peakurl' ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => null,
		);
	}
}
