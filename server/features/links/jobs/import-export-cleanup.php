<?php
/**
 * Import/export scratch cleanup background job.
 *
 * @package PeakURL\Features\Links\Jobs
 * @since 1.7.1
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
 * ImportExportCleanupJob — cleans up temporary import/export artifacts and scratch files.
 *
 * Removes stale export and upload temporary files older than 24 hours from the content directory.
 * Preserves security and index files (.htaccess, index.html, index.php).
 *
 * @since 1.7.1
 */
class ImportExportCleanupJob implements JobHandlerInterface {

	/**
	 * Maximum number of stale scratch files to clean per execution.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	private const MAX_FILES_PER_RUN = 500;

	/**
	 * Content directory path.
	 *
	 * @var string
	 * @since 1.7.1
	 */
	private string $content_dir;

	/**
	 * Create a new import/export scratch cleanup job.
	 *
	 * @param array<string, mixed> $config Application configuration.
	 * @since 1.7.1
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
			if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Scanning scratch directory.
			$items = @scandir( $dir );
			if ( false === $items ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item || '.htaccess' === $item || 'index.html' === $item || 'index.php' === $item ) {
					continue;
				}

				$file_path = $dir . '/' . $item;
				if ( is_file( $file_path ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checking file mtime.
					$mtime = @filemtime( $file_path );
					if ( false !== $mtime && $mtime < $cutoff_time ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deleting stale scratch file.
						if ( @unlink( $file_path ) ) {
							++$cleaned_files;
							if ( $cleaned_files >= self::MAX_FILES_PER_RUN ) {
								break 2;
							}
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
