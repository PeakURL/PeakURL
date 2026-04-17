<?php
/**
 * Dashboard admin notice registry.
 *
 * @package PeakURL\Utils
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Utils;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Collect and normalize dashboard admin notices.
 *
 * @since 1.0.3
 */
class AdminNoticeRegistry {

	/**
	 * Collected notices keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>
	 * @since 1.0.3
	 */
	private array $notices = array();

	/**
	 * Add one dashboard notice.
	 *
	 * @param array<string, mixed> $notice Raw notice payload.
	 * @return void
	 * @since 1.0.3
	 */
	public function add( array $notice ): void {
		$normalized = $this->normalize_notice( $notice );

		if ( null === $normalized ) {
			return;
		}

		$this->notices[ $normalized['id'] ] = $normalized;
	}

	/**
	 * Return all normalized notices.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.3
	 */
	public function all(): array {
		return array_values( $this->notices );
	}

	/**
	 * Normalize a raw notice array into the API contract.
	 *
	 * @param array<string, mixed> $notice Raw notice payload.
	 * @return array<string, mixed>|null
	 * @since 1.0.3
	 */
	private function normalize_notice( array $notice ): ?array {
		$id      = trim( (string) ( $notice['id'] ?? '' ) );
		$type    = $this->normalize_type( (string) ( $notice['type'] ?? 'info' ) );
		$title   = trim( (string) ( $notice['title'] ?? '' ) );
		$message = trim( (string) ( $notice['message'] ?? '' ) );
		$action  = $this->normalize_action( $notice['action'] ?? null );

		if ( '' === $id ) {
			$id = 'notice_' . (string) ( count( $this->notices ) + 1 );
		}

		if ( '' === $title && '' === $message ) {
			return null;
		}

		return array(
			'id'          => $id,
			'type'        => $type,
			'title'       => $title,
			'message'     => $message,
			'action'      => $action,
			'dismissible' => ! empty( $notice['dismissible'] ),
		);
	}

	/**
	 * Normalize a notice type to the allowed dashboard set.
	 *
	 * @param string $type Raw notice type.
	 * @return string
	 * @since 1.0.3
	 */
	private function normalize_type( string $type ): string {
		$normalized = strtolower( trim( $type ) );

		if ( in_array( $normalized, array( 'success', 'warning', 'error', 'info' ), true ) ) {
			return $normalized;
		}

		return 'info';
	}

	/**
	 * Normalize an optional notice action.
	 *
	 * @param mixed $action Raw action payload.
	 * @return array<string, string>|null
	 * @since 1.0.3
	 */
	private function normalize_action( $action ): ?array {
		if ( ! is_array( $action ) ) {
			return null;
		}

		$label = trim( (string) ( $action['label'] ?? '' ) );
		$url   = sanitize_url(
			(string) ( $action['url'] ?? '' ),
			array( 'http', 'https' ),
			true,
		);

		if ( '' === $label || '' === $url ) {
			return null;
		}

		return array(
			'label' => $label,
			'url'   => $url,
		);
	}
}
