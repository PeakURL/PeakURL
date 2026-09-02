<?php
/**
 * Cache and performance configuration endpoints.
 *
 * Provides admin-only handlers for viewing cache diagnostics,
 * updating TTL / driver configuration, and purging cache objects.
 *
 * @package PeakURL\Controllers
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CacheController — admin cache and performance management.
 *
 * @since 1.6.0
 */
class CacheController extends BaseController {

	/**
	 * Return the current cache status and diagnostic metrics.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.6.0
	 */
	public function status( Request $request ): array {
		return $this->success_response(
			$this->data_store->get_cache_status( $request ),
			__( 'Cache status loaded.', 'peakurl' ),
		);
	}

	/**
	 * Save cache and performance settings into persistent storage.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.6.0
	 */
	public function update( Request $request ): array {
		return $this->success_response(
			$this->data_store->save_cache_configuration(
				$request,
				$request->get_body_params(),
			),
			__( 'Cache settings saved.', 'peakurl' ),
		);
	}

	/**
	 * Clear all cached objects across the active cache driver and disk storage.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON success response.
	 * @since 1.6.0
	 */
	public function clear( Request $request ): array {
		return $this->success_response(
			$this->data_store->clear_cache( $request ),
			__( 'Object cache purged successfully.', 'peakurl' ),
		);
	}
}
