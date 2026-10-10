<?php
/**
 * Webhook event dispatcher and payload builder.
 *
 * Coordinates event envelope construction, event data extraction,
 * destination targeting, and queueing deliveries.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Config\Constants;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Dispatcher — Webhook event payload construction and target dispatching engine.
 *
 * @since 1.7.2
 */
class Dispatcher {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.2
	 */
	private PeakURL_DB $db;

	/**
	 * Webhook subscriptions service.
	 *
	 * @var Subscriptions
	 * @since 1.7.2
	 */
	private Subscriptions $subscriptions;

	/**
	 * Webhook delivery service instance.
	 *
	 * @var Delivery
	 * @since 1.7.2
	 */
	private Delivery $delivery;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.7.2
	 */
	private array $config;

	/**
	 * Create a new Webhooks Dispatcher instance.
	 *
	 * @param PeakURL_DB           $db            Shared database wrapper.
	 * @param Subscriptions        $subscriptions Webhook subscriptions service.
	 * @param Delivery             $delivery      Webhook delivery service instance.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @since 1.7.2
	 */
	public function __construct(
		PeakURL_DB $db,
		Subscriptions $subscriptions,
		Delivery $delivery,
		array $config
	) {
		$this->db            = $db;
		$this->subscriptions = $subscriptions;
		$this->delivery      = $delivery;
		$this->config        = $config;
	}


	/**
	 * Create an opaque webhook event identifier.
	 *
	 * @return string Webhook event tracking identifier with peakurl_evt_ prefix.
	 * @since 1.7.1
	 */
	public function create_event_id(): string {
		return 'peakurl_evt_' . Str::random_id( 16 );
	}

	/**
	 * Build the canonical JSON event envelope for webhooks.
	 *
	 * Structured standard envelope contains success, statusCode, message, event, id, type, timestamp, created_at, and data.
	 *
	 * @param string               $event_type Webhook event identifier.
	 * @param array<string, mixed> $data       Event data block.
	 * @param string|null          $event_id   Optional event identifier.
	 * @param int|null             $timestamp  Optional UNIX timestamp.
	 * @return array<string, mixed> Standardized webhook event envelope.
	 * @since 1.7.1
	 */
	public function create_event_payload(
		string $event_type,
		array $data,
		?string $event_id = null,
		?int $timestamp = null
	): array {
		$event_timestamp = $timestamp ?? time();

		return array(
			'success'    => true,
			'statusCode' => 200,
			'message'    => 'Webhook event dispatched.',
			'event'      => $event_type,
			'id'         => $event_id ?? $this->create_event_id(),
			'type'       => $event_type,
			'timestamp'  => $event_timestamp,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $event_timestamp ),
			'data'       => $data,
		);
	}

	/**
	 * Safely resolve a canonical site URL path for webhook payloads.
	 *
	 * @param string $path Relative path to append.
	 * @return string Canonical site URL.
	 * @since 1.0.0
	 */
	public function get_webhook_site_url( string $path = '' ): string {
		try {
			return \get_site_url( null, $path );
		} catch ( \Throwable $e ) {
			$base = ! empty( $this->config[ Constants::SITE_URL ] )
				? (string) $this->config[ Constants::SITE_URL ]
				: 'https://peakurl.dev';
			return '' !== $path ? rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) : rtrim( $base, '/' );
		}
	}

	/**
	 * Determine all applicable link health webhook events based on the authoritative transition matrix.
	 *
	 * Every successfully completed health check produces 'link.health.checked'.
	 * Status transitions produce 'link.health.changed'.
	 * Entering 'healthy' from a non-healthy state produces 'link.health.recovered'.
	 * Entering 'slow' produces 'link.health.degraded'.
	 * Entering a broken status produces 'link.health.broken' and the exact status event.
	 * Entering 'ssrf_blocked' produces 'link.health.ssrf_blocked'.
	 *
	 * @param string|null $previous_status Previous health status string or null for first check.
	 * @param string      $current_status  Current health status string.
	 * @return array<int, string> Ordered list of applicable webhook event identifiers.
	 * @since 1.7.1
	 */
	public static function determine_health_events( ?string $previous_status, string $current_status ): array {
		$broken_statuses = array(
			'unreachable',
			'dns_error',
			'tls_error',
			'timeout',
			'http_error',
			'redirect_loop',
		);

		$events = array( 'link.health.checked' );

		// Status transition (not first-ever check, and status actually changed).
		if ( null !== $previous_status && $previous_status !== $current_status ) {
			$events[] = 'link.health.changed';
		}

		// Transition into healthy from an existing non-healthy status.
		if ( 'healthy' === $current_status ) {
			if ( null !== $previous_status && 'healthy' !== $previous_status ) {
				$events[] = 'link.health.recovered';
			}
			return $events;
		}

		// Transition into slow (degraded) from a different state (or first-ever check).
		if ( 'slow' === $current_status ) {
			if ( $previous_status !== $current_status ) {
				$events[] = 'link.health.degraded';
			}
			return $events;
		}

		// Transition into a broken status from a different state.
		if ( in_array( $current_status, $broken_statuses, true ) ) {
			if ( $previous_status !== $current_status ) {
				$events[] = 'link.health.broken';
				$events[] = 'link.health.' . $current_status;
			}
			return $events;
		}

		// Transition into ssrf_blocked from a different state (security policy, not broken).
		if ( 'ssrf_blocked' === $current_status ) {
			if ( $previous_status !== $current_status ) {
				$events[] = 'link.health.ssrf_blocked';
			}
			return $events;
		}

		return $events;
	}

	/**
	 * Dispatch a webhook event to all subscribed and eligible webhook endpoints.
	 *
	 * @param int|string|null      $user_id User ID associated with the link event.
	 * @param string               $event   Event identifier.
	 * @param array<string, mixed> $data    Event payload data block.
	 * @return array<int, array<string, mixed>> Delivery results per webhook.
	 * @since 1.0.0
	 */
	public function dispatch_webhook_event(
		int|string|null $user_id,
		string $event,
		array $data
	): array {
		try {
			$matching_webhooks = $this->subscriptions->get_subscribed_webhooks( $event, $user_id );

			if ( empty( $matching_webhooks ) ) {
				return array();
			}

			return $this->dispatch_webhook_event_to_targets( $matching_webhooks, $event, $data );
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Dispatch an application event by queuing durable deliveries for matching targets.
	 *
	 * Webhook requests are never executed synchronously during web requests; they are
	 * processed in the background by the scheduled job worker (peakurl_webhook_delivery).
	 *
	 * @param array<int, array<string, mixed>> $targets Subscribed webhook rows.
	 * @param string                           $event   Webhook event identifier.
	 * @param array<string, mixed>             $data    Event payload data block.
	 * @return array<int, array<string, mixed>> Queued delivery summaries.
	 * @since 1.0.0
	 */
	public function dispatch_webhook_event_to_targets(
		array $targets,
		string $event,
		array $data
	): array {
		$now_ts   = time();
		$event_id = $this->create_event_id();
		$payload  = $this->create_event_payload( $event, $data, $event_id, $now_ts );

		$queued_results = array();
		foreach ( $targets as $webhook ) {
			$delivery_id      = $this->delivery->queue_delivery(
				(string) $webhook['id'],
				$event,
				$payload,
				0,
				$event_id
			);
			$queued_results[] = array(
				'deliveryId' => $delivery_id,
				'eventId'    => $event_id,
				'webhookId'  => (string) $webhook['id'],
				'url'        => (string) $webhook['url'],
				'statusCode' => 0,
				'success'    => true,
				'queued'     => true,
				'durationMs' => 0,
				'error'      => null,
			);
		}

		if ( ! empty( $queued_results ) ) {
			$this->delivery->trigger_background_delivery();
		}

		return $queued_results;
	}

	/**
	 * Build payload and dispatch a link lifecycle or click event to webhooks.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Formatted link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous link state for updates.
	 * @param array<string, mixed>|null $click     Recorded click details for click events.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.0.0
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
			$targets  = $this->subscriptions->get_subscribed_webhooks( $event, $owner_id );

			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->get_link_event_data(
				$event,
				$link_data,
				$user,
				$previous,
				$click,
			);

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data,
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Link Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Build payload and dispatch an API key lifecycle event to webhooks.
	 *
	 * Guarantees zero credential leakage: never includes raw token, token hash, or secrets.
	 *
	 * @param string               $event    Webhook event identifier ('api_key.created' or 'api_key.revoked').
	 * @param array<string, mixed> $key_data API key record or metadata.
	 * @param int|string|null      $user_id  Associated user ID.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.7.1
	 */
	public function dispatch_api_key_event(
		string $event,
		array $key_data,
		int|string|null $user_id = null
	): array {
		try {
			$owner_id = $user_id ?? $key_data['user_id'] ?? null;
			$targets  = $this->subscriptions->get_subscribed_webhooks( $event, $owner_id );

			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->get_api_key_event_data( $event, $key_data );

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data,
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook API Key Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Build payload and dispatch a user lifecycle event to webhooks.
	 *
	 * Guarantees zero credential leakage: strictly whitelists safe profile metadata.
	 *
	 * @param string                    $event     Webhook event identifier ('user.created', 'user.updated', or 'user.deleted').
	 * @param array<string, mixed>      $user_data User account record.
	 * @param array<string, mixed>|null $previous  Previous user record for updates.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.7.1
	 */
	public function dispatch_user_event(
		string $event,
		array $user_data,
		?array $previous = null
	): array {
		try {
			$user_id = $user_data['id'] ?? null;
			$targets = $this->subscriptions->get_subscribed_webhooks( $event, $user_id );

			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->get_user_event_data( $event, $user_data, $previous );

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data,
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook User Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Build payload and dispatch a single link health event to webhooks.
	 *
	 * @param string                    $event           Webhook event identifier (e.g. 'link.health.checked').
	 * @param array<string, mixed>      $link_data       Link database row or metadata array.
	 * @param array<string, mixed>      $current_health  Current health snapshot data.
	 * @param array<string, mixed>|null $previous_health Optional previous health snapshot.
	 * @param array<string, mixed>|null $user            Optional user record for ownership override.
	 * @return array<int, array<string, mixed>> Delivery results.
	 * @since 1.7.1
	 */
	public function dispatch_link_health_event(
		string $event,
		array $link_data,
		array $current_health,
		?array $previous_health = null,
		?array $user = null
	): array {
		try {
			$owner_id = $user['id'] ?? $link_data['user_id'] ?? null;
			$targets  = $this->subscriptions->get_subscribed_webhooks( $event, $owner_id );

			if ( empty( $targets ) ) {
				return array();
			}

			$data = $this->get_link_health_event_data(
				$event,
				$link_data,
				$current_health,
				$previous_health
			);

			return $this->dispatch_webhook_event_to_targets(
				$targets,
				$event,
				$data
			);
		} catch ( \Throwable $exception ) {
			error_log( 'PeakURL Webhook Link Health Event Dispatch Error: ' . $exception->getMessage() );
			return array();
		}
	}

	/**
	 * Determine and dispatch all applicable health webhook events for a completed health check.
	 *
	 * Evaluates the authoritative health transition matrix and dispatches each
	 * applicable event type with its own unique event ID.
	 *
	 * @param array<string, mixed>      $link_data       Link database row or metadata.
	 * @param array<string, mixed>      $current_health  Current health snapshot data.
	 * @param array<string, mixed>|null $previous_health Previous health snapshot data or null.
	 * @param array<string, mixed>|null $user            Optional user record.
	 * @return array<string, array<int, array<string, mixed>>> Dispatched deliveries keyed by event identifier.
	 * @since 1.7.1
	 */
	public function dispatch_link_health_check(
		array $link_data,
		array $current_health,
		?array $previous_health = null,
		?array $user = null
	): array {
		$prev_status = isset( $previous_health['status'] ) ? (string) $previous_health['status'] : null;
		$curr_status = (string) ( $current_health['status'] ?? '' );

		$events = self::determine_health_events( $prev_status, $curr_status );

		$results = array();
		foreach ( $events as $event ) {
			$results[ $event ] = $this->dispatch_link_health_event(
				$event,
				$link_data,
				$current_health,
				$previous_health,
				$user
			);
		}

		return $results;
	}
	/**
	 * Send a dedicated test webhook ping to verify receiver endpoint connectivity.
	 *
	 * Uses dedicated event type 'webhook.test' and a clearly identifiable payload.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Delivery outcome details.
	 *
	 * @throws \Throwable When delivery error occurs or webhook does not exist.
	 * @since 1.0.0
	 */
	public function test_webhook( Request $request, string $id ): array {

		$webhook  = $this->subscriptions->get_accessible_webhook( $request, $id );
		$site_url = $this->get_webhook_site_url();
		$now_ts   = time();

		$event_id = $this->create_event_id();
		$payload  = $this->create_event_payload(
			'webhook.test',
			array(
				'message'   => 'This is a test webhook notification from PeakURL.',
				'webhookId' => (string) $webhook['id'],
				'event'     => 'webhook.test',
				'siteUrl'   => $site_url,
				'timestamp' => $now_ts,
			),
			$event_id,
			$now_ts,
		);

		// Record the test delivery in the delivery log for visibility (marked processing with sync token).
		$sync_token  = Str::random_id( 16 );
		$delivery_id = $this->delivery->record_synchronous_test_delivery(
			(string) $webhook['id'],
			$event_id,
			$payload,
			$sync_token
		);

		try {
			$result = $this->delivery->send_webhook_payload( $webhook, $payload, 5.0, $delivery_id );
		} catch ( \Throwable $send_error ) {
			$this->db->update(
				'webhook_deliveries',
				array(
					'status'          => 'failed',
					'attempts'        => 1,
					'completed_at'    => Date::now(),
					'last_attempt_at' => Date::now(),
					'duration_ms'     => 0,
					'response_code'   => 0,
					'last_error'      => $send_error->getMessage(),
					'claim_token'     => null,
					'updated_at'      => Date::now(),
				),
				array(
					'id'          => $delivery_id,
					'claim_token' => $sync_token,
				),
			);
			throw $send_error;
		}

		$duration_ms = (int) ( $result['durationMs'] ?? 0 );
		$status_code = (int) ( $result['statusCode'] ?? 0 );
		$is_success  = ! empty( $result['success'] );

		$this->db->update(
			'webhook_deliveries',
			array(
				'status'          => $is_success ? 'delivered' : 'failed',
				'attempts'        => 1,
				'completed_at'    => Date::now(),
				'last_attempt_at' => Date::now(),
				'duration_ms'     => $duration_ms,
				'response_code'   => $status_code,
				'last_error'      => $result['error'],
				'claim_token'     => null,
				'payload'         => $is_success ? '' : (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'updated_at'      => Date::now(),
			),
			array(
				'id'          => $delivery_id,
				'claim_token' => $sync_token,
			),
		);

		return array(
			'deliveryId' => $delivery_id,
			'eventId'    => $event_id,
			'webhookId'  => (string) ( $webhook['id'] ?? '' ),
			'event'      => 'webhook.test',
			'url'        => (string) ( $webhook['url'] ?? '' ),
			'statusCode' => $status_code,
			'success'    => $is_success,
			'durationMs' => $duration_ms,
			'error'      => $result['error'],
		);
	}


	/**
	 * Build safe normalized event data block for link health events.
	 *
	 * Excludes all sensitive internals (credentials, lock tokens, resolved IPs, cURL details).
	 *
	 * @param string                    $event           Webhook event identifier.
	 * @param array<string, mixed>      $link_data       Link array or database row.
	 * @param array<string, mixed>      $current_health  Current health snapshot.
	 * @param array<string, mixed>|null $previous_health Optional previous health snapshot.
	 * @return array<string, mixed> Normalized health event data block.
	 * @since 1.7.1
	 */
	public function get_link_health_event_data(
		string $event,
		array $link_data,
		array $current_health,
		?array $previous_health = null
	): array {
		$short_code = (string) ( $link_data['alias'] ?? $link_data['short_code'] ?? '' );
		$id         = (string) ( $link_data['id'] ?? '' );
		$dest_url   = (string) ( $link_data['destinationUrl'] ?? $link_data['destination_url'] ?? '' );
		$short_url  = (string) ( $link_data['shortUrl'] ?? $link_data['short_url'] ?? '' );

		if ( '' === $short_url && '' !== $short_code ) {
			$short_url = $this->get_webhook_site_url( rawurlencode( $short_code ) );
		}

		$formatted_health = $this->format_health_snapshot( $current_health );
		$data             = array(
			'id'             => $id,
			'alias'          => $short_code,
			'shortUrl'       => $short_url,
			'destinationUrl' => $dest_url,
			'health'         => $formatted_health,
		);

		// Include previousHealth for changed, high-level transition, and status-specific events.
		if ( 'link.health.checked' !== $event ) {
			$data['previousHealth'] = $this->format_health_snapshot( $previous_health );
		}

		return $data;
	}

	/**
	 * Format and sanitize a link health snapshot for webhooks.
	 *
	 * @param array<string, mixed>|null $snapshot Raw or database health snapshot.
	 * @return array<string, mixed>|null Formatted snapshot or null if empty.
	 * @since 1.7.1
	 */
	public function format_health_snapshot( ?array $snapshot ): ?array {
		if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
			return null;
		}

		$checked_at = (string) ( $snapshot['checkedAt'] ?? $snapshot['checked_at'] ?? '' );

		return array(
			'status'         => (string) ( $snapshot['status'] ?? '' ),
			'checkedAt'      => '' !== $checked_at ? Date::to_iso( $checked_at ) : Date::to_iso( Date::now() ),
			'responseCode'   => isset( $snapshot['responseCode'] )
				? ( null !== $snapshot['responseCode'] ? (int) $snapshot['responseCode'] : null )
				: ( isset( $snapshot['response_code'] ) && null !== $snapshot['response_code'] && '' !== $snapshot['response_code']
					? (int) $snapshot['response_code']
					: null ),
			'responseTimeMs' => isset( $snapshot['responseTimeMs'] )
				? ( null !== $snapshot['responseTimeMs'] ? (int) $snapshot['responseTimeMs'] : null )
				: ( isset( $snapshot['response_time_ms'] ) && null !== $snapshot['response_time_ms'] && '' !== $snapshot['response_time_ms']
					? (int) $snapshot['response_time_ms']
					: null ),
			'errorMessage'   => Str::nullable( $snapshot['errorMessage'] ?? $snapshot['error_message'] ?? null ),
			'redirectCount'  => (int) ( $snapshot['redirectCount'] ?? $snapshot['redirect_count'] ?? 0 ),
		);
	}

	/**
	 * Prepare normalized event data block conforming to PeakURL webhook schemas.
	 *
	 * @param string                    $event     Webhook event identifier.
	 * @param array<string, mixed>      $link_data Link array or database row.
	 * @param array<string, mixed>|null $user      Current user row or null.
	 * @param array<string, mixed>|null $previous  Previous state for link updates.
	 * @param array<string, mixed>|null $click     Recorded click details.
	 * @return array<string, mixed> Normalized payload data.
	 * @since 1.0.0
	 */
	public function get_link_event_data(
		string $event,
		array $link_data,
		?array $user = null,
		?array $previous = null,
		?array $click = null
	): array {
		$short_code = (string) ( $link_data['alias'] ?? '' );
		$id         = (string) ( $link_data['id'] ?? '' );
		$dest_url   = (string) ( $link_data['destinationUrl'] ?? '' );

		$raw_title  = trim( (string) ( $link_data['title'] ?? '' ) );
		$meta_title = trim( (string) ( $link_data['socialPreview']['title'] ?? '' ) );
		$title      = '' !== $raw_title ? $raw_title : ( '' !== $meta_title ? $meta_title : 'Untitled' );

		$short_url = (string) ( $link_data['shortUrl'] ?? '' );

		if ( '' === $short_url && '' !== $short_code ) {
			$short_url = $this->get_webhook_site_url( rawurlencode( $short_code ) );
		}

		$user_data = array(
			'id'        => 0,
			'username'  => '',
			'name'      => '',
			'firstName' => '',
			'lastName'  => '',
			'email'     => '',
			'role'      => '',
			'company'   => '',
			'jobTitle'  => '',
		);

		if ( ! empty( $user ) && is_array( $user ) ) {
			$display_name = trim( (string) ( $user['display_name'] ?? $user['name'] ?? $user['username'] ?? '' ) );
			$username     = (string) ( $user['username'] ?? '' );
			$user_data    = array(
				'id'        => (int) ( $user['id'] ?? 0 ),
				'username'  => $username,
				'name'      => '' !== $display_name ? $display_name : $username,
				'firstName' => (string) ( $user['first_name'] ?? '' ),
				'lastName'  => (string) ( $user['last_name'] ?? '' ),
				'email'     => (string) ( $user['email'] ?? '' ),
				'role'      => (string) ( $user['role'] ?? 'admin' ),
				'company'   => (string) ( $user['company'] ?? '' ),
				'jobTitle'  => (string) ( $user['job_title'] ?? '' ),
			);
		} elseif ( ! empty( $link_data['userId'] ) ) {
			try {
				$user_row = $this->db->get_row_by(
					'users',
					array( 'id' => $link_data['userId'] ),
					array( 'id', 'username', 'display_name', 'email', 'first_name', 'last_name', 'role', 'company', 'job_title' ),
				);
				if ( $user_row && is_array( $user_row ) ) {
					$display_name = trim( (string) ( $user_row['display_name'] ?? '' ) );
					$username     = (string) ( $user_row['username'] ?? '' );
					$user_data    = array(
						'id'        => (int) $user_row['id'],
						'username'  => $username,
						'name'      => '' !== $display_name ? $display_name : $username,
						'firstName' => (string) ( $user_row['first_name'] ?? '' ),
						'lastName'  => (string) ( $user_row['last_name'] ?? '' ),
						'email'     => (string) ( $user_row['email'] ?? '' ),
						'role'      => (string) ( $user_row['role'] ?? 'admin' ),
						'company'   => (string) ( $user_row['company'] ?? '' ),
						'jobTitle'  => (string) ( $user_row['job_title'] ?? '' ),
					);
				}
			} catch ( \Throwable $e ) {
				// Non-fatal user lookup fallback.
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
				'deletedAt'      => Date::to_iso( Date::now() ),
			);
		}

		if ( 'link.clicked' === $event ) {
			return array(
				'id'               => $id,
				'alias'            => $short_code,
				'shortUrl'         => $short_url,
				'destinationUrl'   => $dest_url,
				'title'            => $title,
				'visitorHash'      => (string) ( $click['visitor_hash'] ?? '' ),
				'ip'               => (string) ( $click['ip_address'] ?? '' ),
				'country'          => (string) ( $click['country_name'] ?? '' ),
				'countryCode'      => (string) ( $click['country_code'] ?? '' ),
				'city'             => (string) ( $click['city_name'] ?? '' ),
				'device'           => (string) ( $click['device'] ?? '' ),
				'browser'          => (string) ( $click['browser'] ?? '' ),
				'os'               => (string) ( $click['operating_system'] ?? '' ),
				'referrerName'     => (string) ( $click['referrer_name'] ?? '' ),
				'referrerDomain'   => (string) ( $click['referrer_domain'] ?? '' ),
				'referrerCategory' => (string) ( $click['referrer_category'] ?? '' ),
				'utmSource'        => (string) ( $click['utm_source'] ?? '' ),
				'utmMedium'        => (string) ( $click['utm_medium'] ?? '' ),
				'utmCampaign'      => (string) ( $click['utm_campaign'] ?? '' ),
				'utmTerm'          => (string) ( $click['utm_term'] ?? '' ),
				'utmContent'       => (string) ( $click['utm_content'] ?? '' ),
				'userAgent'        => (string) ( $click['user_agent'] ?? '' ),
				'clickedAt'        => ! empty( $click['clicked_at'] ) ? Date::to_iso( (string) $click['clicked_at'] ) : Date::to_iso( Date::now() ),
				'user'             => $user_data,
			);
		}

		$social_title = (string) ( $link_data['socialPreview']['title'] ?? '' );
		$social_desc  = (string) ( $link_data['socialPreview']['description'] ?? '' );
		$social_img   = (string) ( $link_data['socialPreview']['imageUrl'] ?? '' );
		$has_og       = ! empty( $link_data['hasOpenGraph'] ) || '' !== $social_title || '' !== $social_desc || '' !== $social_img;

		$has_password = ! empty( $link_data['hasPassword'] );
		$expires_at   = $link_data['expiresAt'] ?? null;
		$created_at   = $link_data['createdAt'] ?? Date::to_iso( Date::now() );
		$updated_at   = $link_data['updatedAt'] ?? Date::to_iso( Date::now() );

		$data = array(
			'id'                => $id,
			'alias'             => $short_code,
			'shortUrl'          => $short_url,
			'destinationUrl'    => $dest_url,
			'title'             => $title,
			'status'            => (string) ( $link_data['status'] ?? 'active' ),
			'hasPassword'       => $has_password,
			'expiresAt'         => $expires_at,
			'hasOpenGraph'      => $has_og,
			'socialTitle'       => $social_title,
			'socialDescription' => $social_desc,
			'socialImageUrl'    => $social_img,
			'utmSource'         => (string) ( $link_data['utmSource'] ?? '' ),
			'utmMedium'         => (string) ( $link_data['utmMedium'] ?? '' ),
			'utmCampaign'       => (string) ( $link_data['utmCampaign'] ?? '' ),
			'utmTerm'           => (string) ( $link_data['utmTerm'] ?? '' ),
			'utmContent'        => (string) ( $link_data['utmContent'] ?? '' ),
			'user'              => $user_data,
			'createdAt'         => $created_at,
			'updatedAt'         => $updated_at,
		);

		if ( 'link.created' === $event ) {
			return $data;
		}

		if ( in_array( $event, array( 'link.updated', 'link.activated', 'link.deactivated', 'link.restored', 'link.expired' ), true ) && ! empty( $previous ) && is_array( $previous ) ) {
			$prev_code = (string) ( $previous['alias'] ?? '' );
			$prev_dest = (string) ( $previous['destinationUrl'] ?? '' );
			$prev_url  = (string) ( $previous['shortUrl'] ?? '' );
			if ( '' === $prev_url && '' !== $prev_code ) {
				$prev_url = $this->get_webhook_site_url( rawurlencode( $prev_code ) );
			}
			$prev_title = trim( (string) ( $previous['title'] ?? '' ) );
			if ( '' === $prev_title ) {
				$prev_title = 'Untitled';
			}

			$data['previous'] = array(
				'alias'          => $prev_code,
				'shortUrl'       => $prev_url,
				'destinationUrl' => $prev_dest,
				'title'          => $prev_title,
				'status'         => (string) ( $previous['status'] ?? 'active' ),
			);
		}

		return $data;
	}

	/**
	 * Prepare sanitized API key event data block.
	 *
	 * Whitelists strictly safe non-credential fields. Never includes key or key_hash.
	 *
	 * @param string               $event    Event identifier.
	 * @param array<string, mixed> $key_data API key data.
	 * @return array<string, mixed> Safe API key metadata.
	 * @since 1.7.1
	 */
	public function get_api_key_event_data( string $event, array $key_data ): array {
		$payload = array(
			'id'         => (string) ( $key_data['id'] ?? '' ),
			'label'      => (string) ( $key_data['label'] ?? '' ),
			'prefix'     => (string) ( $key_data['prefix'] ?? '' ),
			'last_four'  => (string) ( $key_data['last_four'] ?? '' ),
			'created_at' => Date::to_iso( (string) ( $key_data['created_at'] ?? Date::now() ) ),
		);

		if ( 'api_key.revoked' === $event ) {
			$payload['revoked_at'] = Date::to_iso( (string) ( $key_data['revoked_at'] ?? Date::now() ) );
		}

		return $payload;
	}


	/**
	 * Prepare sanitized user event data block.
	 *
	 * Whitelists strictly safe non-sensitive profile fields.
	 * Never includes password hashes, tokens, session cookies, or API keys.
	 *
	 * @param string                    $event     Event identifier.
	 * @param array<string, mixed>      $user_data User row or profile data.
	 * @param array<string, mixed>|null $previous  Previous state for updates.
	 * @return array<string, mixed> Safe user metadata.
	 * @since 1.7.1
	 */
	public function get_user_event_data(
		string $event,
		array $user_data,
		?array $previous = null
	): array {
		$first_name   = (string) ( $user_data['first_name'] ?? '' );
		$last_name    = (string) ( $user_data['last_name'] ?? '' );
		$display_name = (string) ( $user_data['display_name'] ?? '' );
		if ( '' === $display_name ) {
			$display_name = trim( $first_name . ' ' . $last_name );
			if ( '' === $display_name ) {
				$display_name = (string) ( $user_data['username'] ?? '' );
			}
		}

		$payload = array(
			'id'           => (string) ( $user_data['id'] ?? '' ),
			'username'     => (string) ( $user_data['username'] ?? '' ),
			'email'        => (string) ( $user_data['email'] ?? '' ),
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $display_name,
			'role'         => (string) ( $user_data['role'] ?? 'editor' ),
			'created_at'   => Date::to_iso( (string) ( $user_data['created_at'] ?? Date::now() ) ),
			'updated_at'   => Date::to_iso( (string) ( $user_data['updated_at'] ?? Date::now() ) ),
		);

		if ( 'user.deleted' === $event ) {
			$payload['deleted_at'] = Date::to_iso( Date::now() );
		}

		if ( 'user.updated' === $event && ! empty( $previous ) && is_array( $previous ) ) {
			$prev_first   = (string) ( $previous['first_name'] ?? '' );
			$prev_last    = (string) ( $previous['last_name'] ?? '' );
			$prev_display = (string) ( $previous['display_name'] ?? '' );
			if ( '' === $prev_display ) {
				$prev_display = trim( $prev_first . ' ' . $prev_last );
			}

			$payload['previous'] = array(
				'username'     => (string) ( $previous['username'] ?? '' ),
				'email'        => (string) ( $previous['email'] ?? '' ),
				'first_name'   => $prev_first,
				'last_name'    => $prev_last,
				'display_name' => $prev_display,
				'role'         => (string) ( $previous['role'] ?? '' ),
			);
		}

		return $payload;
	}
}
