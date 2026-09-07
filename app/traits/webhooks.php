<?php
/**
 * Data store webhooks trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Includes\Constants;

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
	 * In-memory cache of active webhook rows for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.6.1
	 */
	private ?array $active_webhooks_cache = null;

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
		$user = $this->get_webhook_user( $request );
		$rows = $this->db->get_results_by(
			'webhooks',
			array( 'user_id' => $user['id'] ),
			array( '*' ),
			array( 'created_at' => 'DESC' ),
		);

		return array_map(
			fn( array $row ): array => $this->format_webhook( $row ),
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
		$user = $this->get_webhook_user( $request );
		$url  = trim( (string) ( $payload['url'] ?? '' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
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
			throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
		}

		$row = array(
			'id'         => $this->generate_random_id(),
			'user_id'    => $user['id'],
			'url'        => $url,
			'events'     => $this->encode_json( array_values( array_unique( $events ) ) ),
			'secret'     => 'whsec_' . bin2hex( random_bytes( 18 ) ),
			'is_active'  => 1,
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		);

		$this->db->insert( 'webhooks', $row );
		$this->clear_active_webhooks_cache();

		return $this->format_webhook( $row, true );
	}

	/**
	 * Update an existing webhook registration.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Webhook row ID.
	 * @param array<string, mixed> $payload Updated webhook fields (url, events, isActive).
	 * @return array<string, mixed> Updated webhook record.
	 *
	 * @throws ApiException On validation failure or missing webhook.
	 * @since 1.6.1
	 */
	public function update_webhook( Request $request, string $id, array $payload ): array {
		$user    = $this->get_webhook_user( $request );
		$webhook = $this->db->get_row_by(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		if ( ! $webhook && $this->roles->has_capability( $user, 'manage_webhooks' ) ) {
			$webhook = $this->db->get_row_by(
				'webhooks',
				array( 'id' => $id ),
			);
		}

		if ( ! $webhook ) {
			throw new ApiException( __( 'Webhook not found.', 'peakurl' ), 404 );
		}

		$updates = array();

		if ( array_key_exists( 'url', $payload ) ) {
			$url = trim( (string) $payload['url'] );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				throw new ApiException( __( 'A valid webhook URL is required.', 'peakurl' ), 422 );
			}
			$updates['url'] = $url;
		}

		if ( array_key_exists( 'events', $payload ) ) {
			$events = array_values(
				array_filter(
					array_map(
						'strval',
						is_array( $payload['events'] ) ? $payload['events'] : array(),
					),
					static fn( string $event ): bool => '' !== trim( $event ),
				),
			);

			if ( empty( $events ) ) {
				throw new ApiException( __( 'Select at least one webhook event.', 'peakurl' ), 422 );
			}

			$updates['events'] = $this->encode_json( array_values( array_unique( $events ) ) );
		}

		if ( array_key_exists( 'isActive', $payload ) ) {
			$updates['is_active'] = ! empty( $payload['isActive'] ) ? 1 : 0;
		} elseif ( array_key_exists( 'is_active', $payload ) ) {
			$updates['is_active'] = ! empty( $payload['is_active'] ) ? 1 : 0;
		}

		if ( ! empty( $updates ) ) {
			$updates['updated_at'] = $this->now();
			$this->db->update( 'webhooks', $updates, array( 'id' => $id ) );
			$webhook = array_merge( $webhook, $updates );
			$this->clear_active_webhooks_cache();
		}

		return $this->format_webhook( $webhook );
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
		$user = $this->get_webhook_user( $request );

		$deleted = $this->db->delete(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		) > 0;

		if ( $deleted ) {
			$this->clear_active_webhooks_cache();
		}

		return $deleted;
	}

	/**
	 * Send a test webhook ping to verify receiver endpoint connectivity.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Delivery outcome details.
	 *
	 * @throws ApiException When the webhook does not exist.
	 * @since 1.6.1
	 */
	public function test_webhook( Request $request, string $id ): array {
		$user    = $this->get_webhook_user( $request );
		$webhook = $this->db->get_row_by(
			'webhooks',
			array(
				'id'      => $id,
				'user_id' => $user['id'],
			),
		);

		if ( ! $webhook && $this->roles->has_capability( $user, 'manage_webhooks' ) ) {
			$webhook = $this->db->get_row_by(
				'webhooks',
				array( 'id' => $id ),
			);
		}

		if ( ! $webhook ) {
			throw new ApiException( __( 'Webhook not found.', 'peakurl' ), 404 );
		}

		$events          = $this->decode_json_array( (string) ( $webhook['events'] ?? '[]' ) );
		$requested_event = trim( (string) $request->get_body_param( 'event', '' ) );
		$test_event      = '' !== $requested_event && in_array( $requested_event, $events, true )
			? $requested_event
			: ( ! empty( $events ) ? $events[0] : 'link.created' );

		$site_url  = $this->resolve_webhook_site_url();
		$test_data = array(
			'id'             => $this->generate_random_id(),
			'alias'          => 'test-link',
			'shortUrl'       => rtrim( $site_url, '/' ) . '/test-link',
			'destinationUrl' => 'https://example.com/webhook-test',
			'title'          => 'Webhook Test Ping',
			'hasPassword'    => false,
			'expiresAt'      => null,
			'hasOpenGraph'   => false,
			'user'           => array(
				'id'       => (int) $user['id'],
				'username' => (string) ( $user['username'] ?? 'admin' ),
				'name'     => (string) ( $user['display_name'] ?? $user['username'] ?? 'Admin' ),
				'email'    => (string) ( $user['email'] ?? '' ),
			),
			'createdAt'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updatedAt'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		if ( 'link.clicked' === $test_event ) {
			$test_data['ip']          = $request->get_ip_address() ?? '127.0.0.1';
			$test_data['country']     = 'United States';
			$test_data['countryCode'] = 'US';
			$test_data['city']        = 'Austin';
			$test_data['device']      = 'desktop';
			$test_data['browser']     = 'Chrome';
			$test_data['os']          = 'macOS';
			$test_data['referrer']    = 'https://news.ycombinator.com/';
			$test_data['clickedAt']   = gmdate( 'Y-m-d\TH:i:s\Z' );
			$test_data['click']       = array(
				'ip'          => $test_data['ip'],
				'country'     => 'United States',
				'countryCode' => 'US',
				'city'        => 'Austin',
				'device'      => 'desktop',
				'browser'     => 'Chrome',
				'os'          => 'macOS',
				'referrer'    => 'https://news.ycombinator.com/',
			);
		}

		$event_id = $this->generate_random_id();
		$now_ts   = time();
		$payload  = array(
			'event'      => $test_event,
			'id'         => $event_id,
			'timestamp'  => $now_ts,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now_ts ),
			'data'       => $test_data,
		);

		return $this->send_webhook_payload( $webhook, $payload, 5.0 );
	}

	/**
	 * Retrieve all active webhook records, utilizing per-request in-memory cache.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.6.1
	 */
	private function get_active_webhooks(): array {
		if ( null !== $this->active_webhooks_cache ) {
			return $this->active_webhooks_cache;
		}

		$this->active_webhooks_cache = $this->db->get_results(
			'SELECT w.*, u.role AS user_role
			FROM webhooks w
			LEFT JOIN users u ON u.id = w.user_id
			WHERE w.is_active = 1'
		);

		return $this->active_webhooks_cache;
	}

	/**
	 * Clear the active webhooks in-memory cache.
	 *
	 * @return void
	 * @since 1.6.1
	 */
	private function clear_active_webhooks_cache(): void {
		$this->active_webhooks_cache = null;
	}

	/**
	 * Filter active webhooks subscribed to a specific event and authorized for a user.
	 *
	 * @param string          $event   Webhook event identifier.
	 * @param int|string|null $user_id Owner or actor user ID.
	 * @return array<int, array<string, mixed>> Matching webhook rows.
	 * @since 1.6.1
	 */
	private function get_subscribed_webhooks( string $event, int|string|null $user_id ): array {
		$webhooks = $this->get_active_webhooks();
		if ( empty( $webhooks ) ) {
			return array();
		}

		$matching = array();
		foreach ( $webhooks as $webhook ) {
			$subscribed = $this->decode_json_array(
				(string) ( $webhook['events'] ?? '[]' )
			);

			if ( ! in_array( $event, $subscribed, true ) ) {
				continue;
			}

			$is_admin = 'admin' === (string) ( $webhook['user_role'] ?? '' );
			$is_owner = null !== $user_id && (string) $webhook['user_id'] === (string) $user_id;

			if ( ! $is_admin && ! $is_owner ) {
				continue;
			}

			$matching[] = $webhook;
		}

		return $matching;
	}

	/**
	 * Dispatch a webhook event to all subscribed and eligible webhook endpoints.
	 *
	 * Queries active webhooks, filters matching subscriptions by event name
	 * and user permissions, constructs the signed outbound payload, and delivers
	 * notifications either concurrently or sequentially.
	 *
	 * @param int|string|null      $user_id User ID associated with the link event (link owner or actor).
	 * @param string               $event   Event identifier (e.g. 'link.created', 'link.clicked').
	 * @param array<string, mixed> $data    Event payload data block.
	 * @param bool                 $fast    Whether to enforce tight latency limits (e.g. for visitor redirects).
	 * @return array<int, array<string, mixed>> Delivery results per webhook.
	 * @since 1.6.1
	 */
	public function dispatch_webhook_event(
		int|string|null $user_id,
		string $event,
		array $data,
		bool $fast = false
	): array {
		try {
			$matching_webhooks = $this->get_subscribed_webhooks( $event, $user_id );

			if ( empty( $matching_webhooks ) ) {
				return array();
			}

			return $this->dispatch_webhook_event_to_targets( $matching_webhooks, $event, $data, $fast );
		} catch ( \Throwable $exception ) {
			// Webhook delivery must never interrupt the primary transaction.
			error_log( 'PeakURL Webhook Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Dispatch a prepared event payload to matching webhook targets.
	 *
	 * @param array<int, array<string, mixed>> $targets Subscribed webhook rows.
	 * @param string                           $event   Webhook event identifier.
	 * @param array<string, mixed>             $data    Event payload data block.
	 * @param bool                             $fast    Whether to enforce tight latency limits.
	 * @return array<int, array<string, mixed>> Delivery results per webhook.
	 * @since 1.6.1
	 */
	private function dispatch_webhook_event_to_targets(
		array $targets,
		string $event,
		array $data,
		bool $fast = false
	): array {
		$now_ts   = time();
		$now_iso  = gmdate( 'Y-m-d\TH:i:s\Z', $now_ts );
		$event_id = $this->generate_random_id();

		$payload = array(
			'event'      => $event,
			'id'         => $event_id,
			'timestamp'  => $now_ts,
			'created_at' => $now_iso,
			'data'       => $data,
		);

		// Latency budget: clicks must resolve within 1.5s; other events get 3.0s.
		$timeout = $fast ? 1.5 : 3.0;

		if ( count( $targets ) > 1 && function_exists( 'curl_multi_init' ) ) {
			return $this->post_webhooks_parallel( $targets, $payload, $timeout );
		}

		$results = array();
		foreach ( $targets as $webhook ) {
			$results[] = $this->send_webhook_payload( $webhook, $payload, $timeout );
		}

		return $results;
	}

	/**
	 * Build payload and dispatch a link lifecycle or click event to webhooks.
	 *
	 * Short-circuits immediately before building event payloads if no active webhooks
	 * are subscribed to the event, preventing redundant database queries on link visits.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Formatted link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous link state for updates.
	 * @param array<string, mixed>|null $click     Recorded click details for click events.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.6.1
	 */
	public function dispatch_link_event(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		try {
			$owner_id = $user['id'] ?? $link_data['user_id'] ?? null;
			$targets  = $this->get_subscribed_webhooks( $event, $owner_id );

			// Short-circuit: avoid payload construction and DB user queries if nobody is listening.
			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->prepare_link_event_payload(
				$event,
				$link_data,
				$user,
				$previous,
				$click
			);

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data,
				'link.clicked' === $event
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Link Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Prepare normalized event data block conforming to PeakURL webhook schemas.
	 *
	 * Standardizes payload attributes to canonical camelCase representation.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous state for link updates.
	 * @param array<string, mixed>|null $click     Recorded click details.
	 * @return array<string, mixed> Normalized payload data.
	 * @since 1.6.1
	 */
	public function prepare_link_event_payload(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		$short_code = (string) ( $link_data['shortCode'] ?? $link_data['short_code'] ?? $link_data['alias'] ?? '' );
		$id         = (string) ( $link_data['id'] ?? '' );
		$dest_url   = (string) ( $link_data['destinationUrl'] ?? $link_data['destination_url'] ?? $link_data['url'] ?? '' );
		$title      = (string) ( $link_data['title'] ?? '' );
		$short_url  = (string) ( $link_data['shortUrl'] ?? $link_data['short_url'] ?? '' );

		if ( '' === $short_url && '' !== $short_code ) {
			$short_url = $this->resolve_webhook_site_url( rawurlencode( $short_code ) );
		}

		$user_data = array(
			'id'       => 0,
			'username' => '',
			'name'     => '',
			'email'    => '',
		);

		if ( ! empty( $user ) && is_array( $user ) ) {
			$user_data = array(
				'id'       => (int) ( $user['id'] ?? 0 ),
				'username' => (string) ( $user['username'] ?? '' ),
				'name'     => (string) ( $user['display_name'] ?? $user['name'] ?? $user['username'] ?? '' ),
				'email'    => (string) ( $user['email'] ?? '' ),
			);
		} elseif ( ! empty( $link_data['user_id'] ) ) {
			$user_row = $this->db->get_row_by(
				'users',
				array( 'id' => $link_data['user_id'] ),
				array( 'id', 'username', 'display_name', 'email' )
			);
			if ( $user_row ) {
				$display_name = trim( (string) ( $user_row['display_name'] ?? '' ) );
				$username     = (string) ( $user_row['username'] ?? '' );
				$user_data    = array(
					'id'       => (int) $user_row['id'],
					'username' => $username,
					'name'     => '' !== $display_name ? $display_name : $username,
					'email'    => (string) ( $user_row['email'] ?? '' ),
				);
			}
		}

		if ( 'link.deleted' === $event ) {
			return array(
				'id'             => $id,
				'alias'          => $short_code,
				'shortUrl'       => $short_url,
				'destinationUrl' => $dest_url,
				'title'          => $title,
				'user'           => $user_data,
				'deletedAt'      => $this->to_iso( $this->now() ),
			);
		}

		if ( 'link.clicked' === $event ) {
			$ip_address   = (string) ( $click['ip_address'] ?? '' );
			$country_name = (string) ( $click['country_name'] ?? '' );
			$country_code = (string) ( $click['country_code'] ?? '' );
			$city_name    = (string) ( $click['city_name'] ?? '' );
			$device       = (string) ( $click['device'] ?? '' );
			$browser      = (string) ( $click['browser'] ?? '' );
			$os           = (string) ( $click['operating_system'] ?? '' );
			$referrer     = (string) ( $click['referrer_domain'] ?? $click['referrer_name'] ?? '' );
			$user_agent   = (string) ( $click['user_agent'] ?? '' );
			$clicked_at   = ! empty( $click['clicked_at'] )
				? $this->to_iso( (string) $click['clicked_at'] )
				: $this->to_iso( $this->now() );

			return array(
				'id'             => $id,
				'alias'          => $short_code,
				'shortUrl'       => $short_url,
				'destinationUrl' => $dest_url,
				'ip'             => $ip_address,
				'country'        => $country_name,
				'countryCode'    => $country_code,
				'city'           => $city_name,
				'device'         => $device,
				'browser'        => $browser,
				'os'             => $os,
				'referrer'       => $referrer,
				'userAgent'      => $user_agent,
				'clickedAt'      => $clicked_at,
				'user'           => $user_data,
				'click'          => array(
					'ip'          => $ip_address,
					'country'     => $country_name,
					'countryCode' => $country_code,
					'city'        => $city_name,
					'device'      => $device,
					'browser'     => $browser,
					'os'          => $os,
					'referrer'    => $referrer,
				),
			);
		}

		$data = array(
			'id'             => $id,
			'alias'          => $short_code,
			'shortUrl'       => $short_url,
			'destinationUrl' => $dest_url,
			'title'          => $title,
			'hasPassword'    => ! empty( $link_data['hasPassword'] ) || ! empty( $link_data['password_value'] ),
			'expiresAt'      => $link_data['expiresAt'] ?? ( ! empty( $link_data['expires_at'] ) ? $this->to_iso( (string) $link_data['expires_at'] ) : null ),
			'hasOpenGraph'   => ! empty( $link_data['hasOpenGraph'] ) || ! empty( $link_data['social_title'] ) || ! empty( $link_data['social_image_url'] ) || ! empty( $link_data['social_image_path'] ),
			'user'           => $user_data,
			'updatedAt'      => $link_data['updatedAt'] ?? ( ! empty( $link_data['updated_at'] ) ? $this->to_iso( (string) $link_data['updated_at'] ) : $this->to_iso( $this->now() ) ),
		);

		if ( 'link.created' === $event ) {
			$data['createdAt'] = $link_data['createdAt'] ?? ( ! empty( $link_data['created_at'] ) ? $this->to_iso( (string) $link_data['created_at'] ) : $this->to_iso( $this->now() ) );
			return $data;
		}

		if ( 'link.updated' === $event && ! empty( $previous ) && is_array( $previous ) ) {
			$prev_code = (string) ( $previous['alias'] ?? $previous['shortCode'] ?? $previous['short_code'] ?? '' );
			$prev_dest = (string) ( $previous['destinationUrl'] ?? $previous['destination_url'] ?? $previous['url'] ?? '' );
			$prev_url  = (string) ( $previous['shortUrl'] ?? $previous['short_url'] ?? '' );
			if ( '' === $prev_url && '' !== $prev_code ) {
				$prev_url = $this->resolve_webhook_site_url( rawurlencode( $prev_code ) );
			}
			$data['previous'] = array(
				'alias'          => $prev_code,
				'shortUrl'       => $prev_url,
				'destinationUrl' => $prev_dest,
				'title'          => (string) ( $previous['title'] ?? '' ),
			);
		}

		return $data;
	}

	/**
	 * Backward compatibility alias for prepare_link_event_payload.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous state for link updates.
	 * @param array<string, mixed>|null $click     Recorded click details.
	 * @return array<string, mixed>
	 * @since 1.6.1
	 */
	public function build_link_event_data(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		return $this->prepare_link_event_payload( $event, $link_data, $user, $previous, $click );
	}

	/**
	 * Prepare standardized webhook delivery HTTP headers.
	 *
	 * @param string $event       Event identifier.
	 * @param int    $timestamp   UNIX timestamp.
	 * @param string $signature   HMAC-SHA256 signature hash.
	 * @param string $delivery_id Delivery tracking ID.
	 * @return array<int, string> Formatted HTTP headers.
	 * @since 1.6.1
	 */
	private function prepare_webhook_headers(
		string $event,
		int $timestamp,
		string $signature,
		string $delivery_id
	): array {
		return array(
			'Content-Type: application/json; charset=utf-8',
			'X-PeakURL-Event: ' . $event,
			'X-PeakURL-Timestamp: ' . $timestamp,
			'X-PeakURL-Signature: ' . $signature,
			'X-Hub-Signature-256: sha256=' . $signature,
			'X-PeakURL-Delivery: ' . $delivery_id,
			'User-Agent: PeakURL-Webhook/1.0 (+https://peakurl.org)',
		);
	}

	/**
	 * Send an HTTP POST webhook payload to a registered endpoint.
	 *
	 * Signs the raw JSON body with HMAC-SHA256 using the webhook secret.
	 *
	 * @param array<string, mixed> $webhook Webhook row.
	 * @param array<string, mixed> $payload Structured webhook event payload.
	 * @param float                $timeout Request timeout in seconds.
	 * @return array<string, mixed> Delivery outcome details.
	 * @since 1.6.1
	 */
	public function send_webhook_payload(
		array $webhook,
		array $payload,
		float $timeout = 2.5
	): array {
		$url = trim( (string) ( $webhook['url'] ?? '' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'webhookId'  => (string) ( $webhook['id'] ?? '' ),
				'url'        => $url,
				'statusCode' => 0,
				'success'    => false,
				'error'      => 'Invalid webhook URL.',
				'durationMs' => 0,
			);
		}

		$json_body = json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $json_body ) {
			$json_body = (string) json_encode( $payload );
		}

		$secret    = (string) ( $webhook['secret'] ?? '' );
		$signature = hash_hmac( 'sha256', $json_body, $secret );
		$timestamp = (int) ( $payload['timestamp'] ?? time() );
		$event     = (string) ( $payload['event'] ?? '' );
		$event_id  = (string) ( $payload['id'] ?? '' );
		$headers   = $this->prepare_webhook_headers( $event, $timestamp, $signature, $event_id );

		$start_time = microtime( true );

		if ( function_exists( 'curl_init' ) ) {
			$result = $this->post_webhook_with_curl( $url, $json_body, $headers, $timeout );
		} else {
			$result = $this->post_webhook_with_stream( $url, $json_body, $headers, $timeout );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		return array(
			'webhookId'  => (string) ( $webhook['id'] ?? '' ),
			'url'        => $url,
			'statusCode' => $result['statusCode'],
			'success'    => $result['statusCode'] >= 200 && $result['statusCode'] < 300,
			'error'      => $result['error'],
			'durationMs' => $duration_ms,
			'response'   => $result['response'],
		);
	}

	/**
	 * Deliver a webhook event to multiple endpoints concurrently via cURL multi.
	 *
	 * @param array<int, array<string, mixed>> $webhooks Subscribed webhook rows.
	 * @param array<string, mixed>             $payload  Structured event payload.
	 * @param float                            $timeout  Global timeout budget in seconds.
	 * @return array<int, array<string, mixed>>
	 * @since 1.6.1
	 */
	private function post_webhooks_parallel(
		array $webhooks,
		array $payload,
		float $timeout
	): array {
		$mh         = curl_multi_init();
		$handles    = array();
		$start_time = microtime( true );

		$json_body = json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( false === $json_body ) {
			$json_body = (string) json_encode( $payload );
		}
		$timestamp = (int) ( $payload['timestamp'] ?? time() );
		$event     = (string) ( $payload['event'] ?? '' );
		$event_id  = (string) ( $payload['id'] ?? '' );

		$timeout_ms = (int) max( 100, round( $timeout * 1000 ) );
		$connect_ms = (int) min( $timeout_ms, 1500 );

		foreach ( $webhooks as $webhook ) {
			$url = trim( (string) ( $webhook['url'] ?? '' ) );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$secret    = (string) ( $webhook['secret'] ?? '' );
			$signature = hash_hmac( 'sha256', $json_body, $secret );
			$headers   = $this->prepare_webhook_headers( $event, $timestamp, $signature, $event_id );

			$ch = curl_init( $url );
			if ( false === $ch ) {
				continue;
			}

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST              => true,
					CURLOPT_POSTFIELDS        => $json_body,
					CURLOPT_HTTPHEADER        => $headers,
					CURLOPT_RETURNTRANSFER    => true,
					CURLOPT_NOSIGNAL          => 1,
					CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
					CURLOPT_TIMEOUT_MS        => $timeout_ms,
					CURLOPT_FOLLOWLOCATION    => false,
					CURLOPT_MAXREDIRS         => 0,
					CURLOPT_SSL_VERIFYPEER    => true,
					CURLOPT_SSL_VERIFYHOST    => 2,
				)
			);

			curl_multi_add_handle( $mh, $ch );
			$handles[] = array(
				'ch'      => $ch,
				'webhook' => $webhook,
			);
		}

		if ( empty( $handles ) ) {
			curl_multi_close( $mh );
			return array();
		}

		$active = null;
		do {
			$mrc = curl_multi_exec( $mh, $active );
		} while ( CURLM_CALL_MULTI_PERFORM === $mrc );

		while ( $active && CURLM_OK === $mrc ) {
			$select = curl_multi_select( $mh, 0.2 );
			if ( $select >= 0 ) {
				do {
					$mrc = curl_multi_exec( $mh, $active );
				} while ( CURLM_CALL_MULTI_PERFORM === $mrc );
			} elseif ( -1 === $select ) {
				usleep( 5000 );
			}
			if ( ( microtime( true ) - $start_time ) >= $timeout ) {
				break;
			}
		}

		$results     = array();
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		foreach ( $handles as $item ) {
			$ch      = $item['ch'];
			$webhook = $item['webhook'];

			$response  = curl_multi_getcontent( $ch );
			$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error     = curl_error( $ch );

			$results[] = array(
				'webhookId'  => (string) ( $webhook['id'] ?? '' ),
				'url'        => (string) ( $webhook['url'] ?? '' ),
				'statusCode' => $http_code,
				'success'    => $http_code >= 200 && $http_code < 300,
				'error'      => '' !== $error ? $error : null,
				'durationMs' => $duration_ms,
				'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
			);

			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $mh );

		return $results;
	}

	/**
	 * POST the webhook payload using cURL.
	 *
	 * @param string             $url     Target destination URL.
	 * @param string             $body    Raw JSON payload.
	 * @param array<int, string> $headers HTTP headers.
	 * @param float              $timeout Timeout in seconds.
	 * @return array<string, mixed>
	 * @since 1.6.1
	 */
	private function post_webhook_with_curl(
		string $url,
		string $body,
		array $headers,
		float $timeout
	): array {
		$ch = curl_init( $url );

		if ( false === $ch ) {
			return array(
				'statusCode' => 0,
				'error'      => 'Failed to initialize cURL.',
				'response'   => '',
			);
		}

		$timeout_ms = (int) max( 100, round( $timeout * 1000 ) );
		$connect_ms = (int) min( $timeout_ms, 1500 );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST              => true,
				CURLOPT_POSTFIELDS        => $body,
				CURLOPT_HTTPHEADER        => $headers,
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_NOSIGNAL          => 1,
				CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
				CURLOPT_TIMEOUT_MS        => $timeout_ms,
				CURLOPT_FOLLOWLOCATION    => false,
				CURLOPT_MAXREDIRS         => 0,
				CURLOPT_SSL_VERIFYPEER    => true,
				CURLOPT_SSL_VERIFYHOST    => 2,
			)
		);

		$response  = curl_exec( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error     = curl_error( $ch );

		curl_close( $ch );

		return array(
			'statusCode' => $http_code,
			'error'      => '' !== $error ? $error : null,
			'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
		);
	}

	/**
	 * POST the webhook payload using PHP streams fallback.
	 *
	 * @param string             $url     Target destination URL.
	 * @param string             $body    Raw JSON payload.
	 * @param array<int, string> $headers HTTP headers.
	 * @param float              $timeout Timeout in seconds.
	 * @return array<string, mixed>
	 * @since 1.6.1
	 */
	private function post_webhook_with_stream(
		string $url,
		string $body,
		array $headers,
		float $timeout
	): array {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        => implode( "\r\n", $headers ),
					'content'       => $body,
					'timeout'       => $timeout,
					'ignore_errors' => true,
				),
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			)
		);

		$error_message = null;
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$error_message ): bool {
				$error_message = $errstr;
				return true;
			}
		);

		$response = @file_get_contents( $url, false, $context );
		restore_error_handler();

		$status_code      = 0;
		$response_headers = function_exists( 'http_get_last_response_headers' )
			? http_get_last_response_headers()
			: ( $http_response_header ?? array() );

		if ( ! empty( $response_headers ) && is_array( $response_headers ) ) {
			if ( preg_match( '#HTTP/\S+\s+(\d{3})#', $response_headers[0] ?? '', $matches ) ) {
				$status_code = (int) $matches[1];
			}
		}

		return array(
			'statusCode' => $status_code,
			'error'      => $error_message,
			'response'   => is_string( $response ) ? substr( $response, 0, 1000 ) : '',
		);
	}

	/**
	 * Return the current webhook user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	private function get_webhook_user( Request $request ): array {
		$user = $this->get_current_user( $request );
		$this->validate_capability(
			$user,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);

		return $user;
	}

	/**
	 * Format a webhook row for API responses.
	 *
	 * @param array<string, mixed> $row          Raw webhook row.
	 * @param bool                 $show_secret Whether to include the raw secret.
	 * @return array<string, mixed> Webhook response payload.
	 * @since 1.0.0
	 */
	private function format_webhook( array $row, bool $show_secret = false ): array {
		$webhook = array(
			'id'     => (string) $row['id'],
			'url'    => (string) $row['url'],
			'events' => $this->decode_json_array(
				(string) ( $row['events'] ?? '[]' ),
			),
		);

		if ( $show_secret ) {
			$webhook['secret'] = (string) ( $row['secret'] ?? '' );
		}

		return array_merge(
			$webhook,
			array(
				'secretHint' => $this->mask_webhook_secret(
					(string) ( $row['secret'] ?? '' ),
				),
				'isActive'   => ! empty( $row['is_active'] ),
				'createdAt'  => $this->to_iso( (string) $row['created_at'] ),
			),
		);
	}

	/**
	 * Safely resolve a canonical site URL path for webhook payloads.
	 *
	 * Falls back gracefully to configured constants if settings lookup is unavailable.
	 *
	 * @param string $path Relative path to append.
	 * @return string
	 * @since 1.6.1
	 */
	private function resolve_webhook_site_url( string $path = '' ): string {
		try {
			return \get_site_url( $path );
		} catch ( \Throwable $e ) {
			$base = ! empty( $this->config[ Constants::SITE_URL ] )
				? (string) $this->config[ Constants::SITE_URL ]
				: 'https://peakurl.dev';
			return '' !== $path ? rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) : rtrim( $base, '/' );
		}
	}
}
