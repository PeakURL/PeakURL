<?php
/**
 * Webhook input validation.
 *
 * Validates URLs and event selections for webhook creation and updates.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Errors\ApiException;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Validator — Webhook input sanitization and verification.
 *
 * @since 1.0.0
 */
class Validator {

	/**
	 * Canonical supported webhook events.
	 *
	 * @var array<int, string>
	 * @since 1.6.0
	 */
	public const SUPPORTED_EVENTS = array(
		'link.created',
		'link.updated',
		'link.clicked',
		'link.deleted',
	);

	/**
	 * Validate and sanitize payload for creating a new webhook.
	 *
	 * @param array<string, mixed> $payload Request body parameters.
	 * @return array{url: string, events: array<int, string>} Sanitized fields.
	 *
	 * @throws ApiException When URL is invalid (422) or no events selected (422).
	 * @since 1.0.0
	 */
	public function validate_create( array $payload ): array {
		$url = trim( (string) ( $payload['url'] ?? '' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
		}

		$events = $this->validate_events( $payload['events'] ?? null );

		return array(
			'url'    => $url,
			'events' => $events,
		);
	}

	/**
	 * Validate and sanitize payload for updating an existing webhook.
	 *
	 * @param array<string, mixed> $payload Request body parameters.
	 * @return array<string, mixed> Sanitized update fields.
	 *
	 * @throws ApiException When URL is invalid (422) or events array is empty (422).
	 * @since 1.0.0
	 */
	public function validate_update( array $payload ): array {
		$updates = array();

		if ( array_key_exists( 'url', $payload ) ) {
			$url = trim( (string) $payload['url'] );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
			}
			$updates['url'] = $url;
		}

		if ( array_key_exists( 'events', $payload ) ) {
			$updates['events'] = $this->validate_events( $payload['events'] );
		}

		if ( array_key_exists( 'isActive', $payload ) ) {
			$updates['is_active'] = ! empty( $payload['isActive'] ) ? 1 : 0;
		} elseif ( array_key_exists( 'is_active', $payload ) ) {
			$updates['is_active'] = ! empty( $payload['is_active'] ) ? 1 : 0;
		}

		return $updates;
	}

	/**
	 * Validate and sanitize a list of event strings against supported events.
	 *
	 * @param mixed $events Raw event array.
	 * @return array<int, string> Validated unique event list.
	 *
	 * @throws ApiException When events is empty or contains unsupported events.
	 * @since 1.0.0
	 */
	public function validate_events( $events ): array {
		if ( ! is_array( $events ) || empty( $events ) ) {
			throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
		}

		$validated = array();

		foreach ( $events as $raw_event ) {
			$event = strtolower( trim( (string) $raw_event ) );

			if ( '' === $event ) {
				continue;
			}

			if ( ! in_array( $event, self::SUPPORTED_EVENTS, true ) ) {
				throw new ApiException(
					/* translators: %s: webhook event name */
					sprintf( __( 'Unsupported webhook event: %s.', 'peakurl' ), $event ),
					422,
				);
			}

			$validated[ $event ] = true;
		}

		if ( empty( $validated ) ) {
			throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
		}

		return array_keys( $validated );
	}

	/**
	 * Sanitize and deduplicate a list of event strings.
	 *
	 * @param mixed $events Raw event array.
	 * @return array<int, string> Filtered, unique event list.
	 * @since 1.0.0
	 */
	public function sanitize_events( $events ): array {
		if ( ! is_array( $events ) ) {
			return array();
		}

		$valid = array();

		foreach ( $events as $event ) {
			$event = strtolower( trim( (string) $event ) );
			if ( in_array( $event, self::SUPPORTED_EVENTS, true ) ) {
				$valid[ $event ] = true;
			}
		}

		return array_keys( $valid );
	}
}
