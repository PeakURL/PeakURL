<?php
/**
 * Exception type for structured API failures.
 *
 * Carries an HTTP status code and optional data array so the Application
 * kernel can produce a consistent JSON error response.
 *
 * @package PeakURL\Http
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Http;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Application-level API exception.
 *
 * Thrown in controllers or the data store when a request cannot be
 * fulfilled. The Application kernel catches it and converts it into
 * a JsonResponse::error() result.
 *
 * @since 1.0.0
 */
class ApiException extends \RuntimeException {

	/** @var int HTTP status code for the error response. */
	private int $status;

	/**
	 * Optional contextual data included in the response body.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $data;

	/**
	 * Create a new API exception.
	 *
	 * @param string               $message Human-readable error message.
	 * @param int                  $status  HTTP status code (default 500).
	 * @param array<string, mixed> $data    Additional error context.
	 * @since 1.0.0
	 */
	public function __construct(
		string $message,
		int $status = 500,
		array $data = array()
	) {
		parent::__construct( $message, $status );

		$this->status = $status;
		$this->data   = $data;
	}

	/**
	 * Get the HTTP status code for this exception.
	 *
	 * @return int HTTP status code.
	 * @since 1.0.0
	 */
	public function get_status(): int {
		return $this->status;
	}

	/**
	 * Get any additional error context data.
	 *
	 * @return array<string, mixed> Contextual data map.
	 * @since 1.0.0
	 */
	public function get_data(): array {
		return $this->data;
	}
}
