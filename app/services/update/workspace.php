<?php
/**
 * Dashboard updater workspace helpers.
 *
 * @package PeakURL\Services\Update
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Services\Update;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Workspace — updater lock, maintenance, and working-directory state.
 *
 * @since 1.0.14
 */
class Workspace {

	/** @var int Lock time-to-live for stale lock cleanup (30 minutes). */
	private const LOCK_TTL = 1800;

	/**
	 * Shared updater context helper.
	 *
	 * @var Context
	 * @since 1.0.14
	 */
	private Context $context;

	/**
	 * Shared updater filesystem helper.
	 *
	 * @var Filesystem
	 * @since 1.0.14
	 */
	private Filesystem $filesystem;

	/**
	 * Create a new updater workspace helper.
	 *
	 * @param Context     $context     Shared updater context helper.
	 * @param Filesystem  $filesystem  Shared updater filesystem helper.
	 * @since 1.0.14
	 */
	public function __construct(
		Context $context,
		Filesystem $filesystem
	) {
		$this->context    = $context;
		$this->filesystem = $filesystem;
	}

	/**
	 * Check whether an update lock is currently held.
	 *
	 * @return bool
	 * @since 1.0.14
	 */
	public function has_active_lock(): bool {
		$lock_path = $this->context->get_lock_path();

		$this->remove_stale_lock( $lock_path );

		return file_exists( $lock_path );
	}

	/**
	 * Acquire an exclusive file lock for the update process.
	 *
	 * @return array{path: string, handle: resource}
	 *
	 * @throws \RuntimeException When the lock cannot be acquired.
	 * @since 1.0.14
	 */
	public function acquire_lock(): array {
		$lock_path = $this->context->get_lock_path();

		$this->filesystem->create_directory( dirname( $lock_path ) );
		$this->remove_stale_lock( $lock_path );

		$handle = fopen( $lock_path, 'c+' );

		if ( false === $handle ) {
			throw new \RuntimeException(
				__( 'PeakURL could not create the update lock.', 'peakurl' ),
			);
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new \RuntimeException(
				__( 'Another PeakURL update is already running.', 'peakurl' ),
			);
		}

		ftruncate( $handle, 0 );
		fwrite(
			$handle,
			json_encode(
				array(
					'startedAt' => gmdate( DATE_ATOM ),
					'version'   => $this->context->get_current_version(),
				),
				JSON_PRETTY_PRINT,
			),
		);
		fflush( $handle );

		return array(
			'path'   => $lock_path,
			'handle' => $handle,
		);
	}

	/**
	 * Release a previously acquired update lock.
	 *
	 * @param array<string, mixed> $lock Lock metadata from acquire_lock().
	 * @return void
	 * @since 1.0.14
	 */
	public function release_lock( array $lock ): void {
		if ( isset( $lock['handle'] ) && is_resource( $lock['handle'] ) ) {
			flock( $lock['handle'], LOCK_UN );
			fclose( $lock['handle'] );
		}

		if ( ! empty( $lock['path'] ) && file_exists( (string) $lock['path'] ) ) {
			unlink( (string) $lock['path'] );
		}
	}

	/**
	 * Remove the obsolete update workspace from older packaged installs.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function remove_legacy_storage_root(): void {
		$legacy_storage_root = $this->context->get_legacy_storage_root();

		if ( $legacy_storage_root === $this->context->get_storage_root() ) {
			return;
		}

		$this->filesystem->delete_path( $legacy_storage_root );
	}

	/**
	 * Remove the update workspace tree when the update run leaves it empty.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function cleanup_storage_root(): void {
		$this->filesystem->delete_empty_directory_tree(
			$this->context->get_storage_root(),
		);
	}

	/**
	 * Enable maintenance mode by writing a JSON flag file.
	 *
	 * @param string $version Version being upgraded to.
	 * @return void
	 * @since 1.0.14
	 */
	public function enable_maintenance_mode( string $version ): void {
		$body = json_encode(
			array(
				'upgrading' => time(),
				'version'   => $version,
			),
			JSON_PRETTY_PRINT,
		);

		if ( false === $body ) {
			$body = '';
		}

		file_put_contents(
			$this->context->get_maintenance_path(),
			$body,
			LOCK_EX,
		);
	}

	/**
	 * Disable maintenance mode by removing the flag file.
	 *
	 * @return void
	 * @since 1.0.14
	 */
	public function disable_maintenance_mode(): void {
		$maintenance_path = $this->context->get_maintenance_path();

		if ( file_exists( $maintenance_path ) ) {
			unlink( $maintenance_path );
		}
	}

	/**
	 * Remove a stale lock file that has exceeded the lock TTL.
	 *
	 * @param string $lock_path Absolute path to the lock file.
	 * @return void
	 * @since 1.0.14
	 */
	private function remove_stale_lock( string $lock_path ): void {
		if ( ! file_exists( $lock_path ) ) {
			return;
		}

		$modified_at = filemtime( $lock_path );

		if ( false !== $modified_at && $modified_at >= ( time() - self::LOCK_TTL ) ) {
			return;
		}

		unlink( $lock_path );
	}
}
