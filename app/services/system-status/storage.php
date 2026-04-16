<?php
/**
 * Storage status details builder.
 *
 * @package PeakURL\Services\SystemStatus
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\SystemStatus;

use FilesystemIterator;
use PeakURL\Includes\Constants;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Storage — build the storage section of the system-status payload.
 *
 * @since 1.0.14
 */
class Storage {

	/**
	 * Shared system-status context.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Create a new storage status helper.
	 *
	 * @param Context $context Shared system-status context.
	 * @since 1.0.14
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Build filesystem and storage status details.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.14
	 */
	public function build(): array {
		$i18n_service = $this->context->get_i18n_service();

		$i18n_service->prepare_languages_directory();

		$content_directory   = $i18n_service->get_content_dir();
		$languages_directory = $i18n_service->get_languages_dir();
		$debug_log_path      = $content_directory .
			DIRECTORY_SEPARATOR .
			Constants::DEBUG_LOG_FILE;
		$config_path         = ABSPATH . 'config.php';
		$app_directory       = ABSPATH . 'app';

		return array(
			'releaseRoot'                 => rtrim( ABSPATH, '/\\' ),
			'releaseRootSizeBytes'        => $this->get_path_size_bytes( ABSPATH ),
			'appDirectory'                => $app_directory,
			'appWritable'                 => is_dir( $app_directory ) && is_writable( $app_directory ),
			'appDirectorySizeBytes'       => $this->get_path_size_bytes( $app_directory ),
			'configPath'                  => $config_path,
			'configExists'                => file_exists( $config_path ),
			'configSizeBytes'             => $this->get_path_size_bytes( $config_path ),
			'contentDirectory'            => $content_directory,
			'contentExists'               => is_dir( $content_directory ),
			'contentWritable'             => is_dir( $content_directory ) && is_writable( $content_directory ),
			'contentDirectorySizeBytes'   => $this->get_path_size_bytes( $content_directory ),
			'languagesDirectory'          => $languages_directory,
			'languagesDirectoryExists'    => is_dir( $languages_directory ),
			'languagesDirectoryReadable'  => is_dir( $languages_directory ) &&
				is_readable( $languages_directory ),
			'languagesDirectoryWritable'  => is_dir( $languages_directory ) &&
				is_writable( $languages_directory ),
			'languagesDirectorySizeBytes' => $this->get_path_size_bytes( $languages_directory ),
			'debugLogPath'                => $debug_log_path,
			'debugLogExists'              => file_exists( $debug_log_path ),
			'debugLogReadable'            => file_exists( $debug_log_path ) &&
				is_readable( $debug_log_path ),
			'debugLogSizeBytes'           => $this->get_path_size_bytes( $debug_log_path ),
		);
	}

	/**
	 * Return the size of a file or directory in bytes.
	 *
	 * @param string $path Absolute file or directory path.
	 * @return int|null Byte size when readable, otherwise null.
	 * @since 1.0.14
	 */
	private function get_path_size_bytes( string $path ): ?int {
		if ( '' === trim( $path ) || ! file_exists( $path ) ) {
			return null;
		}

		if ( is_file( $path ) ) {
			$size = @filesize( $path );

			return false === $size ? null : (int) $size;
		}

		if ( ! is_dir( $path ) ) {
			return null;
		}

		$total_size = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$path,
					FilesystemIterator::SKIP_DOTS,
				),
			);
		} catch ( \UnexpectedValueException $exception ) {
			unset( $exception );
			return null;
		}

		foreach ( $iterator as $file_info ) {
			if (
				! $file_info instanceof SplFileInfo ||
				$file_info->isDir() ||
				$file_info->isLink()
			) {
				continue;
			}

			$file_size = $file_info->getSize();

			if ( $file_size > 0 ) {
				$total_size += $file_size;
			}
		}

		return $total_size;
	}
}
