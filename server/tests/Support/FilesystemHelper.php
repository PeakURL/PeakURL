<?php
/**
 * Filesystem cleanup helpers for PHPUnit test suites.
 *
 * @package PeakURL\Tests\Support
 */

declare(strict_types=1);

namespace PeakURL\Tests\Support;

class FilesystemHelper {

	/**
	 * Recursively remove a temporary directory and all of its contents.
	 *
	 * @param string $dir Directory path to remove.
	 * @return void
	 */
	public static function recursive_delete( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				@unlink( $path );
			} else {
				self::recursive_delete( $path );
			}
		}

		@rmdir( $dir );
	}
}
