<?php
/**
 * Site favicon management service.
 *
 * Stores the uploaded favicon under `content/uploads/favicon`, writes the
 * related web manifest, and exposes stable public URLs for the runtime shell.
 *
 * @package PeakURL\Services
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;
use PeakURL\Services\Favicon\Manifest;
use PeakURL\Services\Favicon\Paths;
use PeakURL\Utils\Date;
use PeakURL\Utils\File;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Favicon — manage the configured site icon and manifest file.
 *
 * @since 1.0.14
 */
class Favicon {

	/** @var int Minimum favicon width/height in pixels. */
	private const MIN_SIZE = 180;

	/**
	 * Settings API dependency.
	 *
	 * @var SettingsApi
	 * @since 1.0.14
	 */
	private SettingsApi $settings_api;

	/**
	 * Favicon path helper.
	 *
	 * @var Paths
	 * @since 1.1.1
	 */
	private Paths $paths;

	/**
	 * Favicon manifest helper.
	 *
	 * @var Manifest
	 * @since 1.1.1
	 */
	private Manifest $manifest;

	/**
	 * Create a new favicon service.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi          $settings_api Settings API dependency.
	 * @since 1.0.14
	 */
	public function __construct( array $config, SettingsApi $settings_api ) {
		$this->settings_api = $settings_api;
		$this->paths        = new Paths( $config );
		$this->manifest     = new Manifest( $this->paths );
	}

	/**
	 * Return the current favicon settings payload.
	 *
	 * @param string $site_name Configured site name.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_settings( string $site_name = 'PeakURL' ): array {
		$icon = $this->current_icon();

		if ( array() === $icon ) {
			$this->delete_metadata();

			return $this->empty_settings();
		}

		if ( ! empty( $icon['isCustom'] ) ) {
			try {
				$this->manifest->write(
					$site_name,
					$icon,
					(string) ( $icon['iconPath'] ?? '' ),
				);
			} catch ( \RuntimeException $exception ) {
				// Keep icon delivery working even when the manifest cannot be rewritten.
			}
		}

		$icon_path = (string) ( $icon['iconPath'] ?? '' );
		$version   = $this->manifest->get_version_token( $icon, $icon_path );
		$sizes     = $this->manifest->get_icon_sizes( $icon );

		return array(
			'configured'      => true,
			'isCustom'        => ! empty( $icon['isCustom'] ),
			'url'             => $this->favicon_url( 'favicon.png', $version ),
			'iconUrl'         => $this->favicon_url( 'favicon.ico', $version ),
			'appleTouchUrl'   => $this->favicon_url( 'apple-touch-icon.png', $version ),
			'manifestUrl'     => $this->favicon_url( 'site.webmanifest', $version ),
			'mimeType'        => 'image/png',
			'width'           => (int) ( $icon['width'] ?? 0 ),
			'height'          => (int) ( $icon['height'] ?? 0 ),
			'sizes'           => $sizes,
			'updatedAt'       => Date::mysql_to_rfc3339(
				(string) ( $icon['updatedAt'] ?? '' ),
			),
			'canUpload'       => true,
			'recommendedSize' => '512x512',
		);
	}

	/**
	 * Return the current favicon file paths for runtime alias routes.
	 *
	 * @param string $site_name Configured site name.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_assets( string $site_name = 'PeakURL' ): array {
		$icon = $this->current_icon();

		if ( array() === $icon ) {
			return array(
				'configured' => false,
				'isCustom'   => false,
			);
		}

		$icon_path = trim( (string) ( $icon['iconPath'] ?? '' ) );

		if ( '' === $icon_path || ! is_readable( $icon_path ) ) {
			return array(
				'configured' => false,
				'isCustom'   => false,
			);
		}

		if ( ! empty( $icon['isCustom'] ) ) {
			try {
				$this->manifest->write( $site_name, $icon, $icon_path );
			} catch ( \RuntimeException $exception ) {
				// Keep icon delivery working even when the manifest cannot be rewritten.
			}
		}

		$manifest_path = $this->manifest->get_path(
			! empty( $icon['isCustom'] ),
		);

		return array(
			'configured'   => true,
			'isCustom'     => ! empty( $icon['isCustom'] ),
			'iconPath'     => $icon_path,
			'manifestPath' => $manifest_path,
			'mimeType'     => 'image/png',
			'sizes'        => $this->manifest->get_icon_sizes( $icon ),
			'version'      => $this->manifest->get_version_token( $icon, $icon_path ),
		);
	}

	/**
	 * Save or remove the configured favicon.
	 *
	 * @param array<string, mixed>|null $file          Uploaded favicon file.
	 * @param bool                      $remove_favicon Whether the stored favicon should be removed.
	 * @param string                    $site_name      Configured site name.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the upload is invalid or cannot be stored.
	 * @since 1.0.14
	 */
	public function save(
		?array $file,
		bool $remove_favicon,
		string $site_name = 'PeakURL'
	): array {
		if ( $this->has_uploaded_file( $file ) ) {
			$metadata = $this->store_uploaded_file( $file );
			$this->save_metadata( $metadata );
			$this->manifest->write( $site_name, $metadata );

			return $this->get_settings( $site_name );
		}

		if ( $remove_favicon ) {
			$this->remove_generated_files();
			$this->delete_metadata();

			return $this->get_settings( $site_name );
		}

		$settings = $this->get_settings( $site_name );

		if ( ! empty( $settings['configured'] ) ) {
			return $settings;
		}

		return $this->empty_settings();
	}

	/**
	 * Return whether an uploaded favicon file is present.
	 *
	 * @param array<string, mixed>|null $file Uploaded file data.
	 * @return bool
	 * @since 1.0.14
	 */
	private function has_uploaded_file( ?array $file ): bool {
		if ( ! is_array( $file ) ) {
			return false;
		}

		if ( ! array_key_exists( 'error', $file ) ) {
			return false;
		}

		$error = (int) $file['error'];

		return UPLOAD_ERR_NO_FILE !== $error;
	}

	/**
	 * Store an uploaded favicon PNG and return its metadata.
	 *
	 * @param array<string, mixed> $file Uploaded file data.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the favicon is invalid or cannot be stored.
	 * @since 1.0.14
	 */
	private function store_uploaded_file( array $file ): array {
		$this->validate_upload( $file );

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );
		$info     = $this->read_image_size( $tmp_path );

		if ( ! is_array( $info ) || IMAGETYPE_PNG !== (int) ( $info[2] ?? 0 ) ) {
			throw new \RuntimeException(
				__( 'Upload a PNG favicon image.', 'peakurl' ),
			);
		}

		$width  = (int) ( $info[0] ?? 0 );
		$height = (int) ( $info[1] ?? 0 );

		if ( $width <= 0 || $height <= 0 || $width !== $height ) {
			throw new \RuntimeException(
				__( 'The favicon must be a square PNG image.', 'peakurl' ),
			);
		}

		if ( $width < self::MIN_SIZE ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: minimum favicon width in pixels, 2: minimum favicon height in pixels. */
					__( 'The favicon must be at least %1$d x %2$d pixels.', 'peakurl' ),
					self::MIN_SIZE,
					self::MIN_SIZE,
				),
			);
		}

		$this->mkdir_p( $this->paths->get_directory_path() );

		$temp_path  = $this->paths->get_directory_path() . '/.favicon-' . bin2hex( random_bytes( 4 ) ) . '.png';
		$final_path = $this->paths->get_icon_path();

		if ( ! move_uploaded_file( $tmp_path, $temp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not store the uploaded favicon.', 'peakurl' ),
			);
		}

		if ( ! File::delete( $final_path, true ) ) {
			File::delete( $temp_path, true );
			throw new \RuntimeException(
				__( 'PeakURL could not replace the current favicon.', 'peakurl' ),
			);
		}

		if ( ! File::move( $temp_path, $final_path ) ) {
			File::delete( $temp_path, true );
			throw new \RuntimeException(
				__( 'PeakURL could not activate the uploaded favicon.', 'peakurl' ),
			);
		}

		$updated_at = gmdate( 'Y-m-d H:i:s' );

		return array(
			'width'     => $width,
			'height'    => $height,
			'updatedAt' => $updated_at,
		);
	}

	/**
	 * Validate the uploaded file payload.
	 *
	 * @param array<string, mixed> $file Uploaded file data.
	 * @return void
	 *
	 * @throws \RuntimeException When the upload is invalid.
	 * @since 1.0.14
	 */
	private function validate_upload( array $file ): void {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException( $this->upload_error_message( $error ) );
		}

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not verify the uploaded favicon.', 'peakurl' ),
			);
		}
	}

	/**
	 * Read image metadata without surfacing PHP warnings to the response body.
	 *
	 * @param string $path Absolute uploaded file path.
	 * @return array<int, mixed>|false
	 * @since 1.0.14
	 */
	private function read_image_size( string $path ) {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			return getimagesize( $path );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Return a user-facing message for an upload error code.
	 *
	 * @param int $error Upload error code.
	 * @return string
	 * @since 1.0.14
	 */
	private function upload_error_message( int $error ): string {
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return __( 'The uploaded favicon is too large for this server.', 'peakurl' );
		}

		if ( UPLOAD_ERR_PARTIAL === $error ) {
			return __( 'The favicon upload did not finish. Try again.', 'peakurl' );
		}

		if ( UPLOAD_ERR_NO_TMP_DIR === $error ) {
			return __( 'The server is missing a temporary upload directory.', 'peakurl' );
		}

		if ( UPLOAD_ERR_CANT_WRITE === $error ) {
			return __( 'The server could not write the uploaded favicon to disk.', 'peakurl' );
		}

		if ( UPLOAD_ERR_EXTENSION === $error ) {
			return __( 'A PHP extension stopped the favicon upload.', 'peakurl' );
		}

		return __( 'PeakURL could not upload the favicon.', 'peakurl' );
	}

	/**
	 * Return the stored favicon metadata.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function read_metadata(): array {
		$value = $this->settings_api->get_option( Constants::SETTING_SITE_FAVICON );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist favicon metadata in the settings table.
	 *
	 * @param array<string, mixed> $metadata Metadata to store.
	 * @return void
	 * @since 1.0.14
	 */
	private function save_metadata( array $metadata ): void {
		$this->settings_api->update_option(
			Constants::SETTING_SITE_FAVICON,
			(string) json_encode(
				$metadata,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
			),
			(string) ( $metadata['updatedAt'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			false,
		);
	}

	/**
	 * Delete the stored favicon metadata.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	private function delete_metadata(): void {
		$this->settings_api->delete_options(
			array( Constants::SETTING_SITE_FAVICON ),
		);
	}

	/**
	 * Remove the generated favicon and manifest files.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	private function remove_generated_files(): void {
		foreach (
			array(
				$this->paths->get_icon_path(),
				$this->paths->get_manifest_path(),
			) as $path
		) {
			if ( file_exists( $path ) ) {
				File::delete( $path, true );
			}
		}
	}

	/**
	 * Determine whether stored metadata points to a valid favicon file.
	 *
	 * @param array<string, mixed> $metadata Stored metadata payload.
	 * @return bool
	 * @since 1.0.14
	 */
	private function has_metadata( array $metadata ): bool {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		return $width > 0 &&
			$height > 0 &&
			is_readable( $this->paths->get_icon_path() );
	}

	/**
	 * Get an absolute public URL for a favicon alias route.
	 *
	 * @param string $path    Root-relative favicon route.
	 * @param string $version Cache-busting version token.
	 * @return string
	 * @since 1.0.14
	 */
	private function favicon_url( string $path, string $version ): string {
		$url = get_site_url( ltrim( $path, '/' ) );

		if ( '' !== $version ) {
			$url .= '?v=' . rawurlencode( $version );
		}

		return $url;
	}

	/**
	 * Create a directory recursively when needed.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 * @since 1.0.14
	 */
	private function mkdir_p( string $path ): void {
		if ( ! File::mkdir_p( $path, 0755 ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the favicon uploads directory.', 'peakurl' ),
			);
		}
	}

	/**
	 * Return the current favicon payload.
	 *
	 * Prefers the saved custom upload when metadata is present and falls back
	 * to the bundled default favicon when no custom icon is configured.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function current_icon(): array {
		$metadata = $this->read_metadata();

		if ( $this->has_metadata( $metadata ) ) {
			$metadata['isCustom'] = true;
			$metadata['iconPath'] = $this->paths->get_icon_path();

			return $metadata;
		}

		if ( array() !== $metadata ) {
			$this->delete_metadata();
		}

		$this->remove_generated_files();

		$default_icon_path = $this->default_icon_path();

		if ( '' === $default_icon_path ) {
			return array();
		}

		$default_metadata = $this->read_icon_metadata( $default_icon_path );

		if ( array() === $default_metadata ) {
			return array();
		}

		$default_metadata['isCustom'] = false;
		$default_metadata['iconPath'] = $default_icon_path;

		return $default_metadata;
	}

	/**
	 * Return the bundled fallback favicon file path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function default_icon_path(): string {
		$path = $this->paths->get_bundled_icon_path();

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Read icon metadata for an arbitrary favicon path.
	 *
	 * @param string $path Absolute favicon file path.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function read_icon_metadata( string $path ): array {
		$info = $this->read_image_size( $path );

		if ( ! is_array( $info ) || IMAGETYPE_PNG !== (int) ( $info[2] ?? 0 ) ) {
			return array();
		}

		$width  = (int) ( $info[0] ?? 0 );
		$height = (int) ( $info[1] ?? 0 );

		if ( $width <= 0 || $height <= 0 ) {
			return array();
		}

		$modified_at = filemtime( $path );
		$updated_at  = false !== $modified_at
			? gmdate( 'Y-m-d H:i:s', (int) $modified_at )
			: gmdate( 'Y-m-d H:i:s' );

		return array(
			'width'     => $width,
			'height'    => $height,
			'updatedAt' => $updated_at,
		);
	}

	/**
	 * Return the empty favicon payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function empty_settings(): array {
		return array(
			'configured'      => false,
			'isCustom'        => false,
			'url'             => null,
			'iconUrl'         => null,
			'appleTouchUrl'   => null,
			'manifestUrl'     => null,
			'mimeType'        => null,
			'width'           => null,
			'height'          => null,
			'sizes'           => null,
			'updatedAt'       => null,
			'canUpload'       => true,
			'recommendedSize' => '512x512',
		);
	}
}
