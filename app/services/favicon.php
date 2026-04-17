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

	/** @var string Relative uploads directory used for favicon assets. */
	private const DIRECTORY = 'uploads/favicon';

	/** @var string Previous uploads directory kept for migration. */
	private const LEGACY_DIRECTORY = 'uploads/favicons';

	/** @var string Stored favicon filename. */
	private const ICON_FILE = 'favicon.png';

	/** @var string Stored web manifest filename. */
	private const MANIFEST_FILE = 'site.webmanifest';

	/** @var int Minimum favicon width/height in pixels. */
	private const MIN_SIZE = 180;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.14
	 */
	private array $config;

	/**
	 * Settings API dependency.
	 *
	 * @var SettingsApi
	 * @since 1.0.14
	 */
	private SettingsApi $settings_api;

	/**
	 * Absolute persistent content directory.
	 *
	 * @var string
	 * @since 1.0.14
	 */
	private string $content_dir;

	/**
	 * Create a new favicon service.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi          $settings_api Settings API dependency.
	 * @since 1.0.14
	 */
	public function __construct( array $config, SettingsApi $settings_api ) {
		$this->config       = $config;
		$this->settings_api = $settings_api;
		$this->content_dir  = rtrim(
			(string) (
				$config[ Constants::CONFIG_CONTENT_DIR ]
				?? ABSPATH . Constants::DEFAULT_CONTENT_DIR
			),
			'/\\',
		);
	}

	/**
	 * Return the current favicon settings payload.
	 *
	 * @param string $site_name Configured site name.
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function get_settings( string $site_name = 'PeakURL' ): array {
		$this->maybe_migrate_legacy_directory();
		$metadata = $this->get_metadata();

		if ( ! $this->metadata_exists( $metadata ) ) {
			$this->remove_generated_files();
			$this->delete_metadata();

			return $this->get_empty_settings();
		}

		$version = $this->get_version_token( $metadata );
		$sizes   = $this->get_icon_sizes( $metadata );

		return array(
			'configured'      => true,
			'url'             => $this->build_public_url( 'favicon.png', $version ),
			'iconUrl'         => $this->build_public_url( 'favicon.ico', $version ),
			'appleTouchUrl'   => $this->build_public_url( 'apple-touch-icon.png', $version ),
			'manifestUrl'     => $this->build_public_url( 'site.webmanifest', $version ),
			'mimeType'        => 'image/png',
			'width'           => (int) ( $metadata['width'] ?? 0 ),
			'height'          => (int) ( $metadata['height'] ?? 0 ),
			'sizes'           => $sizes,
			'updatedAt'       => peakurl_mysql_to_rfc3339(
				(string) ( $metadata['updatedAt'] ?? '' ),
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
		$this->maybe_migrate_legacy_directory();
		$metadata = $this->get_metadata();
		$settings = $this->get_settings( $site_name );

		if ( empty( $settings['configured'] ) ) {
			return array(
				'configured' => false,
			);
		}

		try {
			$this->write_manifest( $site_name, $metadata );
		} catch ( \RuntimeException $exception ) {
			// Keep icon delivery working even when the manifest cannot be rewritten.
		}

		return array(
			'configured'   => true,
			'iconPath'     => $this->get_icon_path(),
			'manifestPath' => $this->get_manifest_path(),
			'mimeType'     => 'image/png',
			'sizes'        => (string) ( $settings['sizes'] ?? '' ),
			'version'      => $this->get_version_token( $metadata ),
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
		$this->maybe_migrate_legacy_directory();

		if ( $this->has_uploaded_file( $file ) ) {
			$metadata = $this->store_uploaded_file( $file );
			$this->save_metadata( $metadata );
			$this->write_manifest( $site_name, $metadata );

			return $this->get_settings( $site_name );
		}

		if ( $remove_favicon ) {
			$this->remove_generated_files();
			$this->delete_metadata();

			return $this->get_empty_settings();
		}

		$settings = $this->get_settings( $site_name );

		if ( ! empty( $settings['configured'] ) ) {
			$this->write_manifest( $site_name, $this->get_metadata() );
			return $settings;
		}

		return $this->get_empty_settings();
	}

	/**
	 * Check whether an uploaded favicon file is present.
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
		$this->assert_valid_upload( $file );

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );
		$info     = $this->get_image_size( $tmp_path );

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

		$this->create_directory( $this->get_directory_path() );

		$temp_path  = $this->get_directory_path() . '/.favicon-' . bin2hex( random_bytes( 4 ) ) . '.png';
		$final_path = $this->get_icon_path();

		if ( ! move_uploaded_file( $tmp_path, $temp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not store the uploaded favicon.', 'peakurl' ),
			);
		}

		if ( file_exists( $final_path ) && ! unlink( $final_path ) ) {
			@unlink( $temp_path );
			throw new \RuntimeException(
				__( 'PeakURL could not replace the current favicon.', 'peakurl' ),
			);
		}

		if ( ! rename( $temp_path, $final_path ) ) {
			@unlink( $temp_path );
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
	private function assert_valid_upload( array $file ): void {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException( $this->get_upload_error_message( $error ) );
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
	private function get_image_size( string $path ) {
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
	private function get_upload_error_message( int $error ): string {
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
	 * Write the current site web manifest.
	 *
	 * @param string               $site_name Configured site name.
	 * @param array<string, mixed> $metadata  Stored favicon metadata.
	 * @return void
	 *
	 * @throws \RuntimeException When the manifest cannot be written.
	 * @since 1.0.14
	 */
	private function write_manifest( string $site_name, array $metadata ): void {
		if ( ! $this->metadata_exists( $metadata ) ) {
			$manifest_path = $this->get_manifest_path();

			if ( file_exists( $manifest_path ) ) {
				@unlink( $manifest_path );
			}

			return;
		}

		$this->create_directory( $this->get_directory_path() );

		$version  = $this->get_version_token( $metadata );
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

		if ( ! is_string( $json ) || false === file_put_contents( $this->get_manifest_path(), $json, LOCK_EX ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not write the favicon web manifest.', 'peakurl' ),
			);
		}
	}

	/**
	 * Return the stored favicon metadata.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function get_metadata(): array {
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
				$this->get_icon_path(),
				$this->get_manifest_path(),
				$this->get_legacy_icon_path(),
				$this->get_legacy_manifest_path(),
			) as $path
		) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
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
	private function metadata_exists( array $metadata ): bool {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		return $width > 0 &&
			$height > 0 &&
			is_readable( $this->get_icon_path() );
	}

	/**
	 * Return the version token used for cache busting.
	 *
	 * @param array<string, mixed> $metadata Stored metadata payload.
	 * @return string
	 * @since 1.0.14
	 */
	private function get_version_token( array $metadata ): string {
		$updated_at = trim( (string) ( $metadata['updatedAt'] ?? '' ) );

		if ( '' === $updated_at ) {
			$modified_at = filemtime( $this->get_icon_path() );

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
	 * @since 1.0.14
	 */
	private function get_icon_sizes( array $metadata ): string {
		$width  = (int) ( $metadata['width'] ?? 0 );
		$height = (int) ( $metadata['height'] ?? 0 );

		if ( $width <= 0 || $height <= 0 ) {
			return '512x512';
		}

		return $width . 'x' . $height;
	}

	/**
	 * Build an absolute public URL for a favicon alias route.
	 *
	 * @param string $path    Root-relative favicon route.
	 * @param string $version Cache-busting version token.
	 * @return string
	 * @since 1.0.14
	 */
	private function build_public_url( string $path, string $version ): string {
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
	private function create_directory( string $path ): void {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the favicon uploads directory.', 'peakurl' ),
			);
		}
	}

	/**
	 * Return the absolute favicon directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_directory_path(): string {
		return $this->content_dir . '/' . self::DIRECTORY;
	}

	/**
	 * Return the previous favicon directory path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_legacy_directory_path(): string {
		return $this->content_dir . '/' . self::LEGACY_DIRECTORY;
	}

	/**
	 * Return the absolute favicon image path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_icon_path(): string {
		return $this->get_directory_path() . '/' . self::ICON_FILE;
	}

	/**
	 * Return the previous favicon image path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_legacy_icon_path(): string {
		return $this->get_legacy_directory_path() . '/' . self::ICON_FILE;
	}

	/**
	 * Return the absolute manifest file path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_manifest_path(): string {
		return $this->get_directory_path() . '/' . self::MANIFEST_FILE;
	}

	/**
	 * Return the previous manifest file path.
	 *
	 * @return string
	 * @since 1.0.14
	 */
	private function get_legacy_manifest_path(): string {
		return $this->get_legacy_directory_path() . '/' . self::MANIFEST_FILE;
	}

	/**
	 * Move previously generated favicon files into the singular directory.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	private function maybe_migrate_legacy_directory(): void {
		$legacy_directory = $this->get_legacy_directory_path();
		$directory        = $this->get_directory_path();

		if ( ! is_dir( $legacy_directory ) || is_dir( $directory ) ) {
			return;
		}

		if ( $this->move_path( $legacy_directory, $directory ) ) {
			return;
		}

		$this->create_directory( $directory );

		foreach (
			array(
				self::ICON_FILE,
				self::MANIFEST_FILE,
			) as $file_name
		) {
			$legacy_path = $legacy_directory . '/' . $file_name;
			$path        = $directory . '/' . $file_name;

			if ( ! is_file( $legacy_path ) ) {
				continue;
			}

			if ( ! $this->copy_file( $legacy_path, $path ) ) {
				continue;
			}

			$this->delete_file( $legacy_path );
		}

		$this->remove_directory( $legacy_directory );
	}

	/**
	 * Move a file or directory without surfacing PHP warnings.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return bool
	 * @since 1.0.14
	 */
	private function move_path( string $source, string $target ): bool {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			return rename( $source, $target );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Copy a file without surfacing PHP warnings.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return bool
	 * @since 1.0.14
	 */
	private function copy_file( string $source, string $target ): bool {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			return copy( $source, $target );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Delete a file without surfacing PHP warnings.
	 *
	 * @param string $path File path.
	 * @return void
	 * @since 1.0.14
	 */
	private function delete_file( string $path ): void {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			unlink( $path );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Remove a directory without surfacing PHP warnings.
	 *
	 * @param string $path Directory path.
	 * @return void
	 * @since 1.0.14
	 */
	private function remove_directory( string $path ): void {
		set_error_handler(
			static function (): bool {
				return true;
			},
		);

		try {
			rmdir( $path );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Return the empty favicon payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	private function get_empty_settings(): array {
		return array(
			'configured'      => false,
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
