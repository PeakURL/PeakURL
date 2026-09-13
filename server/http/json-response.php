<?php
/**
 * Standard response helpers.
 *
 * Every public method returns an associative array with 'status', 'headers',
 * and 'body' keys that the Application kernel can serialise.
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
 * Static factory for structured HTTP responses.
 *
 * @since 1.0.0
 */
class JsonResponse {

	/**
	 * Build a success JSON response.
	 *
	 * @param mixed  $data    Payload data (any JSON-serialisable value).
	 * @param string $message Human-readable success message.
	 * @param int    $status  HTTP status code (default 200).
	 * @return array<string, mixed> Structured response array.
	 * @since 1.0.0
	 */
	public static function success(
		$data = null,
		string $message = 'OK',
		int $status = 200
	): array {
		return self::json( true, $message, $data, $status );
	}

	/**
	 * Build an error JSON response.
	 *
	 * @param string               $message Human-readable error message.
	 * @param int                  $status  HTTP status code (default 500).
	 * @param array<string, mixed> $data    Optional contextual error data.
	 * @return array<string, mixed> Structured response array.
	 * @since 1.0.0
	 */
	public static function error(
		string $message = '',
		int $status = 500,
		array $data = array()
	): array {
		if ( '' === $message ) {
			$message = __( 'Something went wrong.', 'peakurl' );
		}

		return self::json( false, $message, $data, $status );
	}

	/**
	 * Build the shared JSON response envelope.
	 *
	 * Keeps success and error responses on the same response structure without
	 * duplicating headers, timestamps, or body keys.
	 *
	 * @param bool   $success Whether the request succeeded.
	 * @param string $message Human-readable response message.
	 * @param mixed  $data    JSON-serialisable payload data.
	 * @param int    $status  HTTP status code.
	 * @return array<string, mixed> Structured response array.
	 * @since 1.2.2
	 */
	private static function json(
		bool $success,
		string $message,
		$data,
		int $status
	): array {
		return array(
			'status'  => $status,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'    => array(
				'success'   => $success,
				'message'   => $message,
				'data'      => $data,
				'timestamp' => gmdate( DATE_ATOM ),
			),
		);
	}

	/**
	 * Build a plain-text response.
	 *
	 * @param string $body         Response body string.
	 * @param int    $status       HTTP status code (default 200).
	 * @param string $content_type Content-Type header value.
	 * @return array<string, mixed> Structured response array.
	 * @since 1.0.0
	 */
	public static function text(
		string $body,
		int $status = 200,
		string $content_type = 'text/plain; charset=utf-8'
	): array {
		return array(
			'status'  => $status,
			'headers' => array(
				'Content-Type' => $content_type,
			),
			'body'    => $body,
		);
	}

	/**
	 * Build an HTTP redirect response.
	 *
	 * @param string $location Target URL.
	 * @param int    $status   HTTP redirect status (default 302).
	 * @return array<string, mixed> Structured response array.
	 * @since 1.0.0
	 */
	public static function redirect(
		string $location,
		int $status = 302
	): array {
		return array(
			'status'  => $status,
			'headers' => array(
				'Location' => $location,
			),
			'body'    => '',
		);
	}
}
