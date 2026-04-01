<?php
/**
 * Data store webhooks trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * WebhooksTrait — webhook CRUD methods for Store.
 *
 * @since 1.0.0
 */
trait WebhooksTrait {

	/**
	 * Build a masked signing-secret preview for dashboard display.
	 *
	 * @param string $secret Stored webhook signing secret.
	 * @return string
	 */
	private function mask_webhook_secret( string $secret ): string {
		$prefix = substr( $secret, 0, 10 );

		if ( '' === $prefix ) {
			return '••••••••••••••••••••••••';
		}

		return $prefix . str_repeat( '•', 18 );
	}

	/**
	 * List all webhooks for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> Webhook rows.
	 * @since 1.0.0
	 */
	public function list_webhooks( Request $request ): array {
		$user = $this->assert_request_capability(
			$request,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);
		$rows = $this->db->get_results_by(
			'webhooks',
			array( 'user_id' => $user['id'] ),
			array( '*' ),
			array( 'created_at' => 'DESC' ),
		);

		return array_map(
			fn( array $row ): array => array(
				'id'         => (string) $row['id'],
				'url'        => (string) $row['url'],
				'events'     => $this->decode_json_array(
					(string) ( $row['events'] ?? '[]' ),
				),
				'secretHint' => $this->mask_webhook_secret(
					(string) ( $row['secret'] ?? '' ),
				),
				'isActive'   => ! empty( $row['is_active'] ),
				'createdAt'  => $this->to_iso( (string) $row['created_at'] ),
			),
			$rows,
		);
	}

	/**
	 * Register a new webhook endpoint.
	 *
	 * Validates the callback URL and event list.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `url` and `events`.
	 * @return array<string, mixed> Created webhook record.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function create_webhook( Request $request, array $payload ): array {
		$user = $this->assert_request_capability(
			$request,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);
		$url  = trim( (string) ( $payload['url'] ?? '' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ApiException( 'A valid webhook URL is required.', 422 );
		}

		$events = array_values(
			array_filter(
				array_map(
					'strval',
					is_array( $payload['events'] ?? null )
						? $payload['events']
						: array(),
				),
				static fn( string $event ): bool => '' !== trim( $event ),
			),
		);

		if ( empty( $events ) ) {
			throw new ApiException( 'Select at least one webhook event.', 422 );
		}

		$row = array(
			'id'         => $this->generate_id( 'webhook' ),
			'user_id'    => $user['id'],
			'url'        => $url,
			'events'     => $this->encode_json( array_values( array_unique( $events ) ) ),
			'secret'     => 'whsec_' . bin2hex( random_bytes( 18 ) ),
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		);

		$this->db->insert(
			'webhooks',
			array_merge(
				$row,
				array(
					'is_active' => 1,
				),
			),
		);

		return array(
			'id'         => $row['id'],
			'url'        => $row['url'],
			'events'     => $this->decode_json_array( $row['events'] ),
			'secret'     => $row['secret'],
			'secretHint' => $this->mask_webhook_secret( $row['secret'] ),
			'isActive'   => true,
			'createdAt'  => $this->to_iso( $row['created_at'] ),
		);
	}

	/**
	 * Delete a webhook by ID.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return bool True if a webhook was deleted.
	 * @since 1.0.0
	 */
	public function delete_webhook( Request $request, string $id ): bool {
		$user = $this->assert_request_capability(
			$request,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);

		return $this->db->delete(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		) > 0;
	}
}
