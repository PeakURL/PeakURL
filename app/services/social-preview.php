<?php
/**
 * Social preview metadata and image uploads.
 *
 * @package PeakURL\Services
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PeakURL\Services;

use PeakURL\Api\SettingsApi;
use PeakURL\Includes\Constants;
use PeakURL\Utils\Date;
use PeakURL\Utils\File;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SocialPreview — manage Open Graph metadata for public short links.
 *
 * @since 1.2.0
 */
class SocialPreview {

	/** @var string Default social preview image upload directory. */
	private const GLOBAL_DIRECTORY = 'uploads/social-preview';

	/** @var string Per-link social preview image upload directory. */
	private const LINK_DIRECTORY = 'uploads/social-preview/links';

	/** @var string Stored default social preview image basename. */
	private const GLOBAL_BASENAME = 'default';

	/** @var string Recommended Open Graph image dimensions. */
	private const RECOMMENDED_SIZE = '1200x630';

	/** @var int Maximum per-link preview title length. */
	private const TITLE_LIMIT = 191;

	/** @var int Maximum per-link preview description length. */
	private const DESCRIPTION_LIMIT = 300;

	/**
	 * Settings API helper.
	 *
	 * @var SettingsApi
	 * @since 1.2.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Absolute persistent content directory.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private string $content_dir;

	/**
	 * Create a new social preview service.
	 *
	 * @param array<string, mixed> $config       Runtime configuration map.
	 * @param SettingsApi          $settings_api Settings API helper.
	 * @since 1.2.0
	 */
	public function __construct( array $config, SettingsApi $settings_api ) {
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
	 * Return the global social preview settings.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	public function get_settings(): array {
		$metadata = $this->current_global_image_metadata();

		if ( array() === $metadata ) {
			return $this->empty_settings();
		}

		return array_merge(
			$this->format_image_payload( $metadata ),
			array(
				'configured'      => true,
				'canUpload'       => true,
				'recommendedSize' => self::RECOMMENDED_SIZE,
			),
		);
	}

	/**
	 * Save or remove the global social preview image.
	 *
	 * @param array<string, mixed>|null $file         Uploaded image file.
	 * @param bool                      $remove_image Whether the stored image should be removed.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the upload is invalid or cannot be stored.
	 * @since 1.2.0
	 */
	public function save_settings( ?array $file, bool $remove_image ): array {
		if ( $this->has_uploaded_file( $file ) ) {
			$current  = $this->read_global_image_metadata();
			$metadata = $this->store_uploaded_file(
				$file,
				self::GLOBAL_DIRECTORY,
				self::GLOBAL_BASENAME,
				(string) ( $current['path'] ?? '' ),
			);
			$this->save_global_image_metadata( $metadata );

			return $this->get_settings();
		}

		if ( $remove_image ) {
			$this->delete_global_image();

			return $this->empty_settings();
		}

		return $this->get_settings();
	}

	/**
	 * Store or remove the social preview image for a short link.
	 *
	 * @param string                    $link_id       Short-link row ID.
	 * @param array<string, mixed>|null $file          Uploaded image file.
	 * @param bool                      $remove_image  Whether the stored link image should be removed.
	 * @param string                    $current_path  Current stored relative image path.
	 * @return string|null Stored relative image path, null when removed or absent.
	 *
	 * @throws \RuntimeException When the upload is invalid or cannot be stored.
	 * @since 1.2.0
	 */
	public function save_link_image(
		string $link_id,
		?array $file,
		bool $remove_image,
		string $current_path = ''
	): ?string {
		if ( $this->has_uploaded_file( $file ) ) {
			$metadata = $this->store_uploaded_file(
				$file,
				self::LINK_DIRECTORY,
				$this->sanitize_file_basename( $link_id ),
				$current_path,
			);

			return (string) $metadata['path'];
		}

		if ( $remove_image ) {
			$this->delete_stored_image_files( $current_path );
			return null;
		}

		return '' !== trim( $current_path ) ? $current_path : null;
	}

	/**
	 * Delete a stored per-link social preview image.
	 *
	 * @param string|null $path Stored relative image path.
	 * @return void
	 * @since 1.2.0
	 */
	public function delete_link_image( ?string $path ): void {
		$this->delete_stored_image_files( (string) $path );
	}

	/**
	 * Delete a set of stored per-link social preview images.
	 *
	 * @param array<int, mixed> $paths Stored relative image paths.
	 * @return void
	 * @since 1.2.0
	 */
	public function delete_link_images( array $paths ): void {
		$deleted_paths = array();

		foreach ( $paths as $path ) {
			$path = trim( (string) $path );

			if ( '' === $path || isset( $deleted_paths[ $path ] ) ) {
				continue;
			}

			$deleted_paths[ $path ] = true;
			$this->delete_link_image( $path );
		}
	}

	/**
	 * Normalize an optional social preview title.
	 *
	 * @param mixed $value Submitted title value.
	 * @return string|null
	 * @since 1.2.0
	 */
	public function normalize_title( $value ): ?string {
		return $this->trim_to_limit( $value, self::TITLE_LIMIT );
	}

	/**
	 * Normalize an optional social preview description.
	 *
	 * @param mixed $value Submitted description value.
	 * @return string|null
	 * @since 1.2.0
	 */
	public function normalize_description( $value ): ?string {
		return $this->trim_to_limit( $value, self::DESCRIPTION_LIMIT );
	}

	/**
	 * Return a public URL for a stored per-link social preview image.
	 *
	 * @param string|null $path Stored relative image path.
	 * @return string
	 * @since 1.2.0
	 */
	public function get_link_image_url( ?string $path ): string {
		$path = trim( (string) $path );

		if ( '' === $path || ! is_readable( $this->absolute_image_path( $path ) ) ) {
			return '';
		}

		return $this->public_image_url( $path );
	}

	/**
	 * Build public Open Graph metadata for a short link.
	 *
	 * @param array<string, mixed> $url       Raw URL row.
	 * @param string               $short_url Canonical public short URL.
	 * @param string               $site_name    Configured site name.
	 * @param string               $site_tagline Configured site tagline.
	 * @return array<string, string>
	 * @since 1.2.0
	 */
	public function get_link_preview(
		array $url,
		string $short_url,
		string $site_name,
		string $site_tagline
	): array {
		$link_title    = trim( (string) ( $url['social_title'] ?? '' ) );
		$stored_title  = trim( (string) ( $url['title'] ?? '' ) );
		$destination   = trim( (string) ( $url['destination_url'] ?? '' ) );
		$description   = trim( (string) ( $url['social_description'] ?? '' ) );
		$link_image    = $this->get_link_image_url(
			(string) ( $url['social_image_path'] ?? '' ),
		);
		$global_image  = $this->get_global_image_url();
		$display_title = '' !== $link_title
			? $link_title
			: ( '' !== $stored_title ? $stored_title : __( 'Untitled Link', 'peakurl' ) );

		if ( '' === $description ) {
			$description = '' !== trim( $site_tagline )
				? trim( $site_tagline )
				: $display_title;
		}

		return array(
			'title'       => $display_title,
			'description' => $description,
			'imageUrl'    => '' !== $link_image ? $link_image : $global_image,
			'url'         => $short_url,
			'destination' => $destination,
			'siteName'    => $site_name,
		);
	}

	/**
	 * Return whether an uploaded image file is present.
	 *
	 * @param array<string, mixed>|null $file Uploaded file data.
	 * @return bool
	 * @since 1.2.0
	 */
	private function has_uploaded_file( ?array $file ): bool {
		if ( ! is_array( $file ) || ! array_key_exists( 'error', $file ) ) {
			return false;
		}

		return UPLOAD_ERR_NO_FILE !== (int) $file['error'];
	}

	/**
	 * Store an uploaded image and return its metadata.
	 *
	 * @param array<string, mixed> $file         Uploaded file data.
	 * @param string               $directory    Directory relative to content root.
	 * @param string               $basename     Stored file basename without extension.
	 * @param string               $current_path Current relative image path to replace.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the image is invalid or cannot be stored.
	 * @since 1.2.0
	 */
	private function store_uploaded_file(
		array $file,
		string $directory,
		string $basename,
		string $current_path = ''
	): array {
		$this->validate_upload( $file );

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );
		$info     = $this->read_image_size( $tmp_path );
		$type     = is_array( $info ) ? (int) ( $info[2] ?? 0 ) : 0;
		$format   = $this->get_image_format( $type );

		if ( array() === $format ) {
			throw new \RuntimeException(
				__( 'Upload a PNG, JPG, or WebP social preview image.', 'peakurl' ),
			);
		}

		$width  = (int) ( $info[0] ?? 0 );
		$height = (int) ( $info[1] ?? 0 );

		if ( $width <= 0 || $height <= 0 ) {
			throw new \RuntimeException(
				__( 'PeakURL could not read the social preview image dimensions.', 'peakurl' ),
			);
		}

		$directory_path = $this->absolute_image_path( $directory );
		$extension      = (string) $format['extension'];
		$relative_path  = trim( $directory, '/\\' ) . '/' . $basename . '.' . $extension;
		$temp_path      = $directory_path . '/.' . $basename . '-' . bin2hex( random_bytes( 4 ) ) . '.' . $extension;
		$final_path     = $this->absolute_image_path( $relative_path );

		if ( ! File::mkdir_p( $directory_path, 0755 ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the social preview uploads directory.', 'peakurl' ),
			);
		}

		if ( ! move_uploaded_file( $tmp_path, $temp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not store the uploaded social preview image.', 'peakurl' ),
			);
		}

		if ( ! File::move( $temp_path, $final_path, true ) ) {
			File::delete( $temp_path, true );
			throw new \RuntimeException(
				__( 'PeakURL could not activate the uploaded social preview image.', 'peakurl' ),
			);
		}

		$this->delete_image_file( $current_path, $final_path );
		$this->delete_matching_image_files( $directory_path, $basename, $final_path );

		return array(
			'path'      => $relative_path,
			'mimeType'  => (string) $format['mimeType'],
			'width'     => $width,
			'height'    => $height,
			'updatedAt' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Validate the uploaded file payload.
	 *
	 * @param array<string, mixed> $file Uploaded file data.
	 * @return void
	 *
	 * @throws \RuntimeException When the upload is invalid.
	 * @since 1.2.0
	 */
	private function validate_upload( array $file ): void {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException( $this->upload_error_message( $error ) );
		}

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not verify the uploaded social preview image.', 'peakurl' ),
			);
		}
	}

	/**
	 * Read image metadata without surfacing PHP warnings.
	 *
	 * @param string $path Absolute uploaded file path.
	 * @return array<int, mixed>|false
	 * @since 1.2.0
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
	 * Return supported image format metadata for a PHP image type.
	 *
	 * @param int $type PHP image type constant.
	 * @return array<string, string>
	 * @since 1.2.0
	 */
	private function get_image_format( int $type ): array {
		$formats = array(
			IMAGETYPE_PNG  => array(
				'extension' => 'png',
				'mimeType'  => 'image/png',
			),
			IMAGETYPE_JPEG => array(
				'extension' => 'jpg',
				'mimeType'  => 'image/jpeg',
			),
			IMAGETYPE_WEBP => array(
				'extension' => 'webp',
				'mimeType'  => 'image/webp',
			),
		);

		return $formats[ $type ] ?? array();
	}

	/**
	 * Return a user-facing upload error message.
	 *
	 * @param int $error Upload error code.
	 * @return string
	 * @since 1.2.0
	 */
	private function upload_error_message( int $error ): string {
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return __( 'The uploaded social preview image exceeds the server maximum file size limit.', 'peakurl' );
		}

		if ( UPLOAD_ERR_PARTIAL === $error ) {
			return __( 'The social preview image upload did not finish. Try again.', 'peakurl' );
		}

		if ( UPLOAD_ERR_NO_TMP_DIR === $error ) {
			return __( 'The server is missing a temporary upload directory.', 'peakurl' );
		}

		if ( UPLOAD_ERR_CANT_WRITE === $error ) {
			return __( 'The server could not write the uploaded social preview image to disk.', 'peakurl' );
		}

		if ( UPLOAD_ERR_EXTENSION === $error ) {
			return __( 'A PHP extension stopped the social preview image upload.', 'peakurl' );
		}

		return __( 'PeakURL could not upload the social preview image.', 'peakurl' );
	}

	/**
	 * Return the stored global social preview image metadata.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function read_global_image_metadata(): array {
		$value = $this->settings_api->get_option( Constants::SETTING_SOCIAL_PREVIEW_IMAGE );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Return the valid stored global social preview image metadata.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function current_global_image_metadata(): array {
		$metadata = $this->read_global_image_metadata();

		if ( $this->has_image_metadata( $metadata ) ) {
			return $metadata;
		}

		if ( array() !== $metadata ) {
			$this->delete_global_image_metadata();
		}

		return array();
	}

	/**
	 * Persist global social preview image metadata.
	 *
	 * @param array<string, mixed> $metadata Metadata to store.
	 * @return void
	 * @since 1.2.0
	 */
	private function save_global_image_metadata( array $metadata ): void {
		$this->settings_api->update_option(
			Constants::SETTING_SOCIAL_PREVIEW_IMAGE,
			(string) json_encode(
				$metadata,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
			),
			(string) ( $metadata['updatedAt'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			false,
		);
	}

	/**
	 * Delete the stored global social preview image and metadata.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function delete_global_image(): void {
		$metadata = $this->read_global_image_metadata();

		$this->delete_image_file( (string) ( $metadata['path'] ?? '' ) );
		$this->delete_matching_image_files(
			$this->absolute_image_path( self::GLOBAL_DIRECTORY ),
			self::GLOBAL_BASENAME,
		);
		$this->delete_global_image_metadata();
	}

	/**
	 * Delete the stored global social preview image metadata.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function delete_global_image_metadata(): void {
		$this->settings_api->delete_options(
			array( Constants::SETTING_SOCIAL_PREVIEW_IMAGE ),
		);
	}

	/**
	 * Determine whether stored image metadata points to a readable file.
	 *
	 * @param array<string, mixed> $metadata Stored metadata payload.
	 * @return bool
	 * @since 1.2.0
	 */
	private function has_image_metadata( array $metadata ): bool {
		$path = trim( (string) ( $metadata['path'] ?? '' ) );

		return '' !== $path && is_readable( $this->absolute_image_path( $path ) );
	}

	/**
	 * Return a public URL for the global social preview image.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function get_global_image_url(): string {
		$metadata = $this->current_global_image_metadata();

		return array() === $metadata
			? ''
			: (string) $this->format_image_payload( $metadata )['url'];
	}

	/**
	 * Format stored image metadata for dashboard API responses.
	 *
	 * @param array<string, mixed> $metadata Stored image metadata.
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function format_image_payload( array $metadata ): array {
		return array(
			'url'       => $this->public_image_url(
				(string) ( $metadata['path'] ?? '' ),
				(string) ( $metadata['updatedAt'] ?? '' ),
			),
			'mimeType'  => (string) ( $metadata['mimeType'] ?? '' ),
			'width'     => (int) ( $metadata['width'] ?? 0 ),
			'height'    => (int) ( $metadata['height'] ?? 0 ),
			'sizes'     => (int) ( $metadata['width'] ?? 0 ) . 'x' . (int) ( $metadata['height'] ?? 0 ),
			'updatedAt' => Date::mysql_to_rfc3339(
				(string) ( $metadata['updatedAt'] ?? '' ),
			),
		);
	}

	/**
	 * Return an absolute public URL for a content-stored image.
	 *
	 * @param string $path       Relative path inside the content directory.
	 * @param string $updated_at Optional metadata timestamp.
	 * @return string
	 * @since 1.2.0
	 */
	private function public_image_url( string $path, string $updated_at = '' ): string {
		$path = trim( $path, '/\\' );

		if ( '' === $path ) {
			return '';
		}

		$url     = get_site_url( 'content/' . $path );
		$version = $this->image_version( $path, $updated_at );

		return '' !== $version ? $url . '?v=' . rawurlencode( $version ) : $url;
	}

	/**
	 * Return a cache-busting version token for a stored image.
	 *
	 * @param string $path       Relative path inside the content directory.
	 * @param string $updated_at Optional metadata timestamp.
	 * @return string
	 * @since 1.2.0
	 */
	private function image_version( string $path, string $updated_at = '' ): string {
		if ( '' !== trim( $updated_at ) ) {
			$timestamp = strtotime( trim( $updated_at ) . ' UTC' );

			return false !== $timestamp ? (string) $timestamp : trim( $updated_at );
		}

		$modified_at = filemtime( $this->absolute_image_path( $path ) );

		return false !== $modified_at ? (string) $modified_at : '';
	}

	/**
	 * Return an absolute path inside the content directory.
	 *
	 * @param string $path Relative path inside content.
	 * @return string
	 * @since 1.2.0
	 */
	private function absolute_image_path( string $path ): string {
		return File::join_path( $this->content_dir, trim( $path, '/\\' ) );
	}

	/**
	 * Delete a stored relative image path when it is inside content.
	 *
	 * @param string $path Relative path inside content.
	 * @return void
	 * @since 1.2.0
	 */
	private function delete_image_file( string $path, string $keep_path = '' ): void {
		$path = trim( $path );

		if ( '' === $path ) {
			return;
		}

		$file_path   = File::normalize_path( $this->absolute_image_path( $path ) );
		$content_dir = File::normalize_path( $this->content_dir );
		$keep_path   = '' !== trim( $keep_path ) ? File::normalize_path( $keep_path ) : '';

		if ( '' !== $keep_path && $file_path === $keep_path ) {
			return;
		}

		if ( File::is_path_inside( $file_path, $content_dir ) ) {
			File::delete( $file_path, true );
		}
	}

	/**
	 * Delete a stored image and stale same-basename files.
	 *
	 * @param string $path Relative path inside content.
	 * @return void
	 * @since 1.2.0
	 */
	private function delete_stored_image_files( string $path ): void {
		$path = trim( $path );

		if ( '' === $path ) {
			return;
		}

		$file_path   = File::normalize_path( $this->absolute_image_path( $path ) );
		$content_dir = File::normalize_path( $this->content_dir );

		if ( ! File::is_path_inside( $file_path, $content_dir ) ) {
			return;
		}

		$this->delete_image_file( $path );

		$basename = pathinfo( $file_path, PATHINFO_FILENAME );

		if ( '' === $basename ) {
			return;
		}

		$this->delete_matching_image_files( dirname( $file_path ), $basename );
	}

	/**
	 * Delete stale same-basename images after an extension change.
	 *
	 * @param string $directory_path Absolute upload directory path.
	 * @param string $basename       Stored file basename without extension.
	 * @param string $keep_path      Absolute path that should remain.
	 * @return void
	 * @since 1.2.0
	 */
	private function delete_matching_image_files(
		string $directory_path,
		string $basename,
		string $keep_path = ''
	): void {
		$directory_path = File::normalize_path( $directory_path );
		$content_dir    = File::normalize_path( $this->content_dir );
		$keep_path      = '' !== trim( $keep_path ) ? File::normalize_path( $keep_path ) : '';

		if ( ! File::is_path_inside( $directory_path, $content_dir ) ) {
			return;
		}

		$matches = glob( $directory_path . '/' . $basename . '.*' );

		if ( ! is_array( $matches ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			$match = File::normalize_path( $match );

			if ( '' !== $keep_path && $match === $keep_path ) {
				continue;
			}

			File::delete( $match, true );
		}
	}

	/**
	 * Return a filesystem-safe stored image basename.
	 *
	 * @param string $value Raw basename.
	 * @return string
	 * @since 1.2.0
	 */
	private function sanitize_file_basename( string $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
		$value = is_string( $value ) ? trim( $value, '_-' ) : '';

		return '' !== $value ? $value : bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Trim a text value to a storage limit.
	 *
	 * @param mixed $value Submitted text value.
	 * @param int   $limit Maximum character count.
	 * @return string|null
	 * @since 1.2.0
	 */
	private function trim_to_limit( $value, int $limit ): ?string {
		$text = trim( (string) $value );

		if ( '' === $text ) {
			return null;
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $limit ) {
			return mb_substr( $text, 0, $limit, 'UTF-8' );
		}

		return strlen( $text ) > $limit ? substr( $text, 0, $limit ) : $text;
	}

	/**
	 * Return the empty social preview image payload.
	 *
	 * @return array<string, mixed>
	 * @since 1.2.0
	 */
	private function empty_settings(): array {
		return array(
			'configured'      => false,
			'url'             => null,
			'mimeType'        => null,
			'width'           => null,
			'height'          => null,
			'sizes'           => null,
			'updatedAt'       => null,
			'canUpload'       => true,
			'recommendedSize' => self::RECOMMENDED_SIZE,
		);
	}
}
