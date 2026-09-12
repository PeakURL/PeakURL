<?php
/**
 * Favicon web manifest helpers.
 *
 * @package PeakURL\Services\Favicon
 * @since 1.1.1
 */

declare(strict_types=1);

namespace PeakURL\Services\Favicon;

use PeakURL\Utils\File;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Manifest — write and return favicon web manifest files.
 *
 * @since 1.1.1
 */
class Manifest {

	/**
	 * Favicon path helper.
	 *
	 * @var Paths
	 * @since 1.1.1
	 */
	private Paths $paths;

	/**
	 * Create a new manifest helper.
	 *
	 * @param Paths $paths Favicon path helper.
	 * @since 1.1.1
	 */
	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	/**
	 * Write the current site web manifest.
	 *
	 * @param string               $site_name Configured site name.
	 * @param array<string, mixed> $metadata  Stored favicon metadata.
	 * @param string               $icon_path Absolute icon file path backing the current favicon.
	 * @return void
	 *
	 * @throws \RuntimeException When the manifest cannot be written.
	 * @since 1.1.1
	 */
	public function write(
		string $site_name,
		array $metadata,
		string $icon_path = ''
	): void {
		$current_icon_path = '' !== $icon_path ? $icon_path : $this->paths->get_icon_path();
		$width             = (int) ( $metadata['width'] ?? 0 );
		$height            = (int) ( $metadata['height'] ?? 0 );

		if ( $width <= 0 || $height <= 0 || ! is_readable( $current_icon_path ) ) {
			File::delete( $this->paths->get_manifest_path(), true );
			return;
		}

		$this->mkdir_p();

		$version  = $this->get_version_token( $metadata, $current_icon_path );
		$manifest = array(
			'name'             => $site_name,
			'short_name'       => $site_name,
			'start_url'        => './dashboard',
			'scope'            => './',
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => 'transparent',
			'icons'            => array(
				array(
					'src'     => './favicon.png?v=' . $version,
					'sizes'   => $this->get_icon_sizes( $metadata ),
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => './apple-touch-icon.png?v=' . $version,
					'sizes'   => $this->get_icon_sizes( $metadata ),
					'type'    => 'image/png',
					'purpose' => 'any',
				),
			),
		);
		$json     = json_encode(
			$manifest,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);

		if ( ! is_string( $json ) || false === file_put_contents( $this->paths->get_manifest_path(), $json, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not write the favicon web manifest.', 'peakurl' ),
			);
		}
	}

	/**
	 * Return the current manifest file path.
	 *
	 * @param bool $is_custom Whether the active favicon is a custom upload.
	 * @return string
	 * @since 1.1.1
	 */
	public function get_path( bool $is_custom = false ): string {
		$manifest_path = $this->paths->get_manifest_path();

		if ( $is_custom && is_readable( $manifest_path ) ) {
			return $manifest_path;
		}

		$default_manifest_path = $this->paths->get_bundled_manifest_path();

		if ( is_readable( $default_manifest_path ) ) {
			return $default_manifest_path;
		}

		return $manifest_path;
	}

	/**
	 * Return the version token used for cache busting.
	 *
	 * @param array<string, mixed> $metadata  Stored metadata payload.
	 * @param string               $icon_path Absolute icon file path.
	 * @return string
	 * @since 1.1.1
	 */
	public function get_version_token( array $metadata, string $icon_path = '' ): string {
		$updated_at = trim( (string) ( $metadata['updatedAt'] ?? '' ) );
		$path       = '' !== $icon_path ? $icon_path : $this->paths->get_icon_path();

		if ( '' === $updated_at ) {
			$modified_at = filemtime( $path );

			if ( false === $modified_at ) {
				$modified_at = time();
			}

			return (string) $modified_at;
		}

		$timestamp = strtotime( $updated_at . ' UTC' );

		return false !== $timestamp ? (string) $timestamp : $updated_at;
	}

	/**
	 * Return the display size string for the icon.
	 *
	 * @param array<string, mixed> $metadata Stored metadata payload.
	 * @return string
	 * @since 1.1.1
	 */
	public function get_icon_sizes( array $metadata ): string {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		if ( $width <= 0 || $height <= 0 ) {
			return '512x512';
		}

		return $width . 'x' . $height;
	}

	/**
	 * Create the favicon directory recursively when needed.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 * @since 1.1.1
	 */
	private function mkdir_p(): void {
		if ( ! File::mkdir_p( $this->paths->get_directory_path(), 0755 ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the favicon uploads directory.', 'peakurl' ),
			);
		}
	}
}
