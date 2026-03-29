<?php
/**
 * Data store webhooks trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Store_Webhooks_Trait — webhook CRUD methods for Data_Store.
 *
 * @since 1.0.0
 */
trait Store_Webhooks_Trait {

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
		$rows = $this->query_all(
			'SELECT * FROM webhooks WHERE user_id = :user_id ORDER BY created_at DESC',
			array( 'user_id' => $user['id'] ),
		);

		return array_map(
			fn( array $row ): array => array(
				'id'        => (string) $row['id'],
				'url'       => (string) $row['url'],
				'events'    => $this->decode_json_array(
					(string) ( $row['events'] ?? '[]' ),
				),
				'secret'    => (string) $row['secret'],
				'isActive'  => ! empty( $row['is_active'] ),
				'createdAt' => $this->to_iso( (string) $row['created_at'] ),
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
	 * @throws Api_Exception On validation failure (422).
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
			throw new Api_Exception( 'A valid webhook URL is required.', 422 );
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
			throw new Api_Exception( 'Select at least one webhook event.', 422 );
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

		$this->execute(
			'INSERT INTO webhooks (id, user_id, url, events, secret, is_active, created_at, updated_at)
	            VALUES (:id, :user_id, :url, :events, :secret, 1, :created_at, :updated_at)',
			$row,
		);

		return array(
			'id'        => $row['id'],
			'url'       => $row['url'],
			'events'    => $this->decode_json_array( $row['events'] ),
			'secret'    => $row['secret'],
			'isActive'  => true,
			'createdAt' => $this->to_iso( $row['created_at'] ),
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

		return $this->execute_statement(
			'DELETE FROM webhooks WHERE id = :id AND user_id = :user_id',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		) > 0;
	}
}
