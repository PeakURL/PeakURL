<?php
/**
 * Import/export maintenance background job.
 *
 * @package PeakURL\Features\Links\Jobs
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Jobs;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Core\Scheduler\ExecutionResult;
use PeakURL\Core\Scheduler\JobHandlerInterface;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * ImportExportJob — cleans up temporary import/export artifacts and scratch files.
 *
 * Removes stale export and upload files older than 24 hours from the content directory.
 *
 * @since 1.7.0
 */
class ImportExportJob implements JobHandlerInterface {

	/**
	 * Content directory path.
	 *
	 * @var string
	 * @since 1.7.0
	 */
	private string $content_dir;

	/**
	 * Create a new import/export maintenance job.
	 *
	 * @param array<string, mixed> $config Application configuration.
	 * @since 1.7.0
	 */
	public function __construct( array $config = array() ) {
		$this->content_dir = (string) ( $config[ Constants::CONTENT_DIR ] ?? Environment::get_instance()->get_content_path() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( ExecutionContext $context ): ExecutionResult {
		$scratch_dirs = array(
			rtrim( $this->content_dir, '/\\' ) . '/exports',
			rtrim( $this->content_dir, '/\\' ) . '/uploads/tmp',
		);

		$cleaned_files = 0;
		$cutoff_time   = time() - 86400; // 24 hours ago.

		foreach ( $scratch_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$items = @scandir( $dir );
			if ( false === $items ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item || '.htaccess' === $item || 'index.html' === $item ) {
					continue;
				}

				$file_path = $dir . '/' . $item;
				if ( is_file( $file_path ) ) {
					$mtime = @filemtime( $file_path );
					if ( false !== $mtime && $mtime < $cutoff_time ) {
						if ( @unlink( $file_path ) ) {
							++$cleaned_files;
						}
					}
				}
			}
		}

		$message = 1 === $cleaned_files
			? sprintf( 'Cleaned %d stale import/export temporary file.', $cleaned_files )
			: sprintf( 'Cleaned %d stale import/export temporary files.', $cleaned_files );

		return ExecutionResult::success(
			$message,
			array( 'cleanedFiles' => $cleaned_files )
		);
	}
}
