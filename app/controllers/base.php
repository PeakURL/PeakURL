<?php
/**
 * Shared controller helpers.
 *
 * @package PeakURL\Controllers
 * @since 1.1.1
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\JsonResponse;
use PeakURL\Store;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * BaseController — shared store wiring and JSON response helpers.
 *
 * @since 1.1.1
 */
abstract class BaseController {

	/**
	 * Shared data-store dependency.
	 *
	 * @var Store
	 * @since 1.1.1
	 */
	protected Store $data_store;

	/**
	 * Create a new controller instance.
	 *
	 * @param Store $data_store Shared data-store dependency.
	 * @since 1.1.1
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Build a standard success response.
	 *
	 * @param mixed  $data    Response payload.
	 * @param string $message Human-readable success message.
	 * @param int    $status  HTTP status code.
	 * @return array<string, mixed>
	 * @since 1.1.1
	 */
	protected function success_response(
		$data = null,
		string $message = 'OK',
		int $status = 200
	): array {
		return JsonResponse::success( $data, $message, $status );
	}

	/**
	 * Build a standard error response.
	 *
	 * @param string               $message Human-readable error message.
	 * @param int                  $status  HTTP status code.
	 * @param array<string, mixed> $data    Optional contextual error data.
	 * @return array<string, mixed>
	 * @since 1.1.1
	 */
	protected function error_response(
		string $message = '',
		int $status = 500,
		array $data = array()
	): array {
		return JsonResponse::error( $message, $status, $data );
	}

	/**
	 * Build a standard 404 error response.
	 *
	 * @param string $message Human-readable not-found message.
	 * @return array<string, mixed>
	 * @since 1.1.1
	 */
	protected function not_found_response( string $message ): array {
		return $this->error_response( $message, 404 );
	}

	/**
	 * Build a success response with one boolean confirmation flag.
	 *
	 * @param string $key     Response payload key.
	 * @param string $message Human-readable success message.
	 * @return array<string, mixed>
	 * @since 1.1.1
	 */
	protected function boolean_response( string $key, string $message ): array {
		return $this->success_response( array( $key => true ), $message );
	}
}
