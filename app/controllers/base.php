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
use PeakURL\Http\Request;
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
	 * Build a success response with a true confirmation flag.
	 *
	 * @param string $key     Response payload key.
	 * @param string $message Human-readable success message.
	 * @return array<string, mixed>
	 * @since 1.1.1
	 */
	protected function confirm_response( string $key, string $message ): array {
		return $this->success_response( array( $key => true ), $message );
	}

	/**
	 * Return a route parameter as a string.
	 *
	 * @param Request $request Incoming request.
	 * @param string  $key     Route parameter name.
	 * @return string
	 * @since 1.2.2
	 */
	protected function route_param( Request $request, string $key ): string {
		return (string) $request->get_route_param( $key );
	}

	/**
	 * Return a body parameter only when it is an array.
	 *
	 * @param Request $request Incoming request.
	 * @param string  $key     Body parameter name.
	 * @return array<int|string, mixed>
	 * @since 1.2.2
	 */
	protected function body_array_param( Request $request, string $key ): array {
		$value = $request->get_body_param( $key, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Build query parameters from a default-value map.
	 *
	 * @param Request              $request  Incoming request.
	 * @param array<string, mixed> $defaults Query parameter defaults keyed by name.
	 * @return array<string, mixed>
	 * @since 1.2.2
	 */
	protected function query_params( Request $request, array $defaults ): array {
		$params = array();

		foreach ( $defaults as $key => $default ) {
			$params[ $key ] = $request->get_query_param( (string) $key, $default );
		}

		return $params;
	}

	/**
	 * Build a delete confirmation or a standard 404 response.
	 *
	 * @param bool   $deleted           Whether the delete operation succeeded.
	 * @param string $not_found_message Message returned when the record is missing.
	 * @param string $success_message   Message returned when the record is deleted.
	 * @return array<string, mixed>
	 * @since 1.2.2
	 */
	protected function delete_response(
		bool $deleted,
		string $not_found_message,
		string $success_message
	): array {
		if ( ! $deleted ) {
			return $this->not_found_response( $not_found_message );
		}

		return $this->confirm_response( 'deleted', $success_message );
	}

	/**
	 * Build a found-item success response or a standard 404 response.
	 *
	 * @param mixed  $payload           Payload returned by the data store.
	 * @param string $not_found_message Message returned when the payload is empty.
	 * @param string $success_message   Message returned when the payload is present.
	 * @return array<string, mixed>
	 * @since 1.2.2
	 */
	protected function found_response(
		$payload,
		string $not_found_message,
		string $success_message
	): array {
		if ( ! $payload ) {
			return $this->not_found_response( $not_found_message );
		}

		return $this->success_response( $payload, $success_message );
	}
}
