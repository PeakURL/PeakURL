<?php
/**
 * Webhooks domain service.
 *
 * Coordinates webhook subscription persistence, encrypted signing secrets,
 * durable delivery, atomic job claiming, exponential backoff retries,
 * and event dispatching.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Scheduler\BackgroundRunner;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Http\UserAgent;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Webhooks management, signing, and durable delivery engine.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Webhook input validator.
	 *
	 * @var Validator
	 * @since 1.0.0
	 */
	private Validator $validator;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Cryptography helper for encrypting webhook signing secrets at rest.
	 *
	 * @var Crypto
	 * @since 1.7.1
	 */
	private Crypto $crypto_service;

	/**
	 * Default maximum deliveries to process in one scheduled job run.
	 *
	 * Bounded to 25 deliveries per 5-minute cron invocation. Accounting for end-to-end
	 * work per delivery (up to 3.0s HTTP timeout plus destination DNS resolution),
	 * the total processing budget remains safely bounded under ~100 seconds, preserving
	 * ample headroom within the 300-second job lease and cadence.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Default maximum number of delivery attempts for webhooks before marking as permanently failed.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_MAX_DELIVERY_ATTEMPTS = 5;

	/**
	 * Default retention period in days for terminal webhook delivery history.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_DELIVERY_RETENTION_DAYS = 30;

	/**
	 * Custom HTTP sender callback for delivery attempts (useful for testing).
	 *
	 * @var (callable(array<string, mixed>, array<string, mixed>, float): array<string, mixed>)|null
	 * @since 1.7.0
	 */
	private $http_sender = null;

	/**
	 * In-memory cache of active webhook rows for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.0.0
	 */
	private ?array $active_webhooks_cache = null;

	/**
	 * Create a new Webhooks Service instance.
	 *
	 * @param PeakURL_DB           $db             Shared database wrapper.
	 * @param Validator            $validator      Webhook input validator.
	 * @param AuthService          $auth_service   Authentication domain service.
	 * @param Roles                $roles          Roles registry.
	 * @param Authorization        $authorization  Shared authorization helper.
	 * @param array<string, mixed> $config         Runtime config map.
	 * @param Crypto               $crypto_service Centralized crypto service.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		Validator $validator,
		AuthService $auth_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		Crypto $crypto_service
	) {
		$this->db             = $db;
		$this->validator      = $validator;
		$this->auth_service   = $auth_service;
		$this->roles          = $roles;
		$this->authorization  = $authorization;
		$this->config         = $config;
		$this->crypto_service = $crypto_service;
	}

	/**
	 * Background runner for immediate post-response dispatch.
	 *
	 * @var BackgroundRunner|null
	 * @since 1.7.1
	 */
	private ?BackgroundRunner $background_runner = null;

	/**
	 * Set a custom HTTP sender callback for delivery attempts (useful for testing).
	 *
	 * @param (callable(array<string, mixed>, array<string, mixed>, float): array<string, mixed>)|null $sender Custom sender callable.
	 * @return void
	 * @since 1.7.0
	 */
	public function set_http_sender( ?callable $sender ): void {
		$this->http_sender = $sender;
	}

	/**
	 * Set the background runner instance for immediate async dispatch.
	 *
	 * @param BackgroundRunner|null $runner Background runner instance.
	 * @return void
	 * @since 1.7.1
	 */
	public function set_background_runner( ?BackgroundRunner $runner ): void {
		$this->background_runner = $runner;
	}

	/**
	 * Create an opaque webhook endpoint identifier.
	 *
	 * @return string Webhook endpoint identifier with peakurl_wh_ prefix.
	 * @since 1.7.1
	 */
	private function create_webhook_id(): string {
		return 'peakurl_wh_' . Str::random_id( 16 );
	}

	/**
	 * Create an opaque webhook event identifier.
	 *
	 * @return string Webhook event tracking identifier with peakurl_evt_ prefix.
	 * @since 1.7.1
	 */
	private function create_event_id(): string {
		return 'peakurl_evt_' . Str::random_id( 16 );
	}

	/**
	 * Create an opaque webhook delivery identifier.
	 *
	 * @return string Webhook delivery tracking identifier with peakurl_del_ prefix.
	 * @since 1.7.1
	 */
	private function create_delivery_id(): string {
		return 'peakurl_del_' . Str::random_id( 16 );
	}

	/**
	 * Create a standardized webhook signing secret.
	 *
	 * @return string Secret with standard peakurl_whsec_ prefix.
	 * @since 1.7.1
	 */
	private function create_signing_secret(): string {
		return 'peakurl_whsec_' . Str::random_id( 18 );
	}

	/**
	 * Encrypt a signing secret for safe database storage.
	 *
	 * @param string $secret Plaintext secret.
	 * @return string Encrypted payload.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 * @since 1.7.1
	 */
	private function encrypt_secret( string $secret ): string {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			throw new \InvalidArgumentException( __( 'Webhook signing secret cannot be empty.', 'peakurl' ) );
		}

		return $this->crypto_service->encrypt( $secret );
	}

	/**
	 * Decrypt a stored signing secret for outbound HMAC generation.
	 *
	 * @param string $stored Stored encrypted string.
	 * @return string Decrypted plaintext secret.
	 *
	 * @throws \RuntimeException When the stored secret is missing, unencrypted, or decryption fails.
	 * @since 1.7.1
	 */
	private function decrypt_secret( string $stored ): string {
		$stored = trim( $stored );

		if ( '' === $stored ) {
			throw new \RuntimeException( __( 'Webhook signing secret is missing.', 'peakurl' ) );
		}

		if ( ! str_starts_with( $stored, 'enc:v1:' ) ) {
			throw new \RuntimeException( __( 'Webhook signing secret is not properly encrypted.', 'peakurl' ) );
		}

		$decrypted = $this->crypto_service->decrypt( $stored );

		if ( '' === $decrypted ) {
			throw new \RuntimeException( __( 'Failed to decrypt webhook signing secret.', 'peakurl' ) );
		}

		return $decrypted;
	}

	/**
	 * Return the authoritative event catalogue for the frontend.
	 *
	 * @param Request|null $request Optional incoming HTTP request to authenticate/authorize.
	 * @return array<int, array{id: string, label: string, description: string, group: string}>
	 * @since 1.7.1
	 */
	public function get_event_catalogue( ?Request $request = null ): array {
		if ( null !== $request ) {
			$this->get_webhook_user( $request );
		}

		return Validator::get_event_catalogue();
	}

	/**
	 * List all webhooks for the authenticated user.
	 *
	 * Efficiently batch-loads 24h health metrics and latest delivery status
	 * to prevent N+1 queries.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> Webhook rows with health summaries.
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

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$webhook_ids = array_map( static fn( array $r ): string => (string) $r['id'], $rows );
		$health_map  = $this->batch_load_webhook_health( $webhook_ids );

		return array_map(
			fn( array $row ): array => $this->format_webhook( $row, $health_map[ (string) $row['id'] ] ?? null ),
			$rows,
		);
	}

	/**
	 * Register a new webhook endpoint.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `url`, `events`, and optional `verifySsl`.
	 * @return array<string, mixed> Created webhook record including one-time secret.
	 *
	 * @throws ApiException On validation failure (422).
	 * @since 1.0.0
	 */
	public function create_webhook( Request $request, array $payload ): array {
		$user      = $this->get_webhook_user( $request );
		$validated = $this->validator->validate_create( $payload );

		$webhook_id       = $this->create_webhook_id();
		$raw_secret       = $this->create_signing_secret();
		$encrypted_secret = $this->encrypt_secret( $raw_secret );
		$secret_hint      = $this->mask_webhook_secret( $raw_secret );
		$now              = Date::now();

		$row = array(
			'id'          => $webhook_id,
			'user_id'     => $user['id'],
			'label'       => $validated['label'],
			'url'         => $validated['url'],
			'events'      => peakurl_json_encode( $validated['events'] ),
			'secret'      => $encrypted_secret,
			'secret_hint' => $secret_hint,
			'verify_ssl'  => $validated['verify_ssl'],
			'is_active'   => 1,
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$this->db->insert( 'webhooks', $row );
		$this->clear_active_webhooks_cache();

		return $this->format_webhook( $row, null, $raw_secret );
	}

	/**
	 * Update an existing webhook registration.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Webhook row ID.
	 * @param array<string, mixed> $payload Updated webhook fields (url, events, verifySsl, isActive).
	 * @return array<string, mixed> Updated webhook record.
	 *
	 * @throws ApiException On validation failure or missing webhook.
	 * @since 1.0.0
	 */
	public function update_webhook( Request $request, string $id, array $payload ): array {
		$webhook = $this->get_accessible_webhook( $request, $id );
		$updates = $this->validator->validate_update( $payload );

		if ( array_key_exists( 'events', $updates ) ) {
			$updates['events'] = peakurl_json_encode( $updates['events'] );
		}

		if ( ! empty( $updates ) ) {
			$updates['updated_at'] = Date::now();
			$this->db->update( 'webhooks', $updates, array( 'id' => $id ) );
			$webhook = array_merge( $webhook, $updates );
			$this->clear_active_webhooks_cache();
		}

		return $this->format_webhook( $webhook );
	}

	/**
	 * Rotate the signing secret for an existing webhook and show it once.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Updated webhook record with the new one-time secret.
	 *
	 * @throws ApiException When the webhook does not exist.
	 * @since 1.7.1
	 */
	public function rotate_secret( Request $request, string $id ): array {
		$webhook          = $this->get_accessible_webhook( $request, $id );
		$raw_secret       = $this->create_signing_secret();
		$encrypted_secret = $this->encrypt_secret( $raw_secret );
		$secret_hint      = $this->mask_webhook_secret( $raw_secret );
		$now              = Date::now();

		$updates = array(
			'secret'      => $encrypted_secret,
			'secret_hint' => $secret_hint,
			'updated_at'  => $now,
		);

		$this->db->update( 'webhooks', $updates, array( 'id' => $id ) );
		$webhook = array_merge( $webhook, $updates );
		$this->clear_active_webhooks_cache();

		return $this->format_webhook( $webhook, null, $raw_secret );
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

		$criteria = array( 'id' => $id );
		if ( ! $this->roles->has_capability( $user, 'manage_webhooks' ) ) {
			$criteria['user_id'] = $user['id'];
		}

		$deleted = $this->db->delete( 'webhooks', $criteria ) > 0;

		if ( $deleted ) {
			$this->clear_active_webhooks_cache();
		}

		return $deleted;
	}

	/**
	 * Fetch an accessible webhook by ID for the current request user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook ID.
	 * @return array<string, mixed> Webhook row.
	 *
	 * @throws ApiException When webhook is not found.
	 * @since 1.7.1
	 */
	private function get_accessible_webhook( Request $request, string $id ): array {
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

		return $webhook;
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
	 * @throws ApiException When the webhook does not exist.
	 * @since 1.0.0
	 */
	public function test_webhook( Request $request, string $id ): array {
		$webhook  = $this->get_accessible_webhook( $request, $id );
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
		$delivery_id = $this->record_synchronous_test_delivery(
			(string) $webhook['id'],
			$event_id,
			$payload,
			$sync_token
		);

		try {
			$result = $this->send_webhook_payload( $webhook, $payload, 5.0, $delivery_id );
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
	 * Retrieve all active webhook records, utilizing per-request in-memory cache.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	private function get_subscribed_webhooks( string $event, int|string|null $user_id ): array {
		$webhooks = $this->get_active_webhooks();
		if ( empty( $webhooks ) ) {
			return array();
		}

		$matching = array();
		foreach ( $webhooks as $webhook ) {
			$subscribed = $this->decode_json_array(
				(string) ( $webhook['events'] ?? '[]' ),
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
			$matching_webhooks = $this->get_subscribed_webhooks( $event, $user_id );

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
	private function dispatch_webhook_event_to_targets(
		array $targets,
		string $event,
		array $data
	): array {
		$now_ts   = time();
		$event_id = $this->create_event_id();
		$payload  = $this->create_event_payload( $event, $data, $event_id, $now_ts );

		$queued_results = array();
		foreach ( $targets as $webhook ) {
			$delivery_id      = $this->queue_delivery(
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

		if ( ! empty( $queued_results ) && null !== $this->background_runner ) {
			$this->background_runner->enqueue_webhooks();
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
			$targets  = $this->get_subscribed_webhooks( $event, $owner_id );

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
			$targets  = $this->get_subscribed_webhooks( $event, $owner_id );

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
			$targets = $this->get_subscribed_webhooks( $event, $user_id );

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
			$targets  = $this->get_subscribed_webhooks( $event, $owner_id );

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
	private function get_link_health_event_data(
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
	private function format_health_snapshot( ?array $snapshot ): ?array {
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
	private function create_event_payload(
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
	 * Compute the HMAC-SHA256 signature for a webhook payload.
	 *
	 * Computes canonical replay-safe HMAC over '{timestamp}.{raw_body}'.
	 *
	 * @param int    $timestamp  UNIX timestamp matching X-PeakURL-Timestamp.
	 * @param string $raw_body   Exact serialized JSON payload body.
	 * @param string $raw_secret Webhook signing secret.
	 * @return string HMAC-SHA256 hex signature.
	 * @since 1.7.1
	 */
	private function compute_signature( int $timestamp, string $raw_body, string $raw_secret ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $raw_secret );
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
	private function get_link_event_data(
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
	private function get_api_key_event_data( string $event, array $key_data ): array {
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
	private function get_user_event_data(
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

	/**
	 * Send an HTTP POST webhook payload to a registered endpoint using cURL.
	 *
	 * Applies strict URL verification, SSL verification setting, HMAC signing,
	 * and bounded timeouts. Response bodies are NOT retained.
	 *
	 * @param array<string, mixed> $webhook     Webhook row.
	 * @param array<string, mixed> $payload     Structured webhook event envelope.
	 * @param float                $timeout     Request timeout in seconds.
	 * @param string|null          $delivery_id Optional delivery tracking ID.
	 * @return array<string, mixed> Delivery outcome details.
	 * @since 1.0.0
	 */
	public function send_webhook_payload(
		array $webhook,
		array $payload,
		float $timeout = 3.0,
		?string $delivery_id = null
	): array {
		$url            = trim( (string) ( $webhook['url'] ?? '' ) );
		$destination_ip = '' !== $url ? $this->validator->validate_destination( $url ) : null;

		if ( null === $destination_ip ) {
			return array(
				'webhookId'  => (string) ( $webhook['id'] ?? '' ),
				'url'        => $url,
				'statusCode' => 0,
				'success'    => false,
				'error'      => 'Invalid or unroutable webhook destination URL.',
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

		$raw_secret  = $this->decrypt_secret( (string) ( $webhook['secret'] ?? '' ) );
		$timestamp   = time();
		$signature   = $this->compute_signature( $timestamp, $json_body, $raw_secret );
		$event_type  = (string) ( $payload['type'] ?? '' );
		$delivery_id = $delivery_id ?? $this->create_delivery_id();
		$headers     = $this->create_webhook_headers( $event_type, $timestamp, $signature, $delivery_id );

		$verify_ssl = ! isset( $webhook['verify_ssl'] ) || ! empty( $webhook['verify_ssl'] );
		$start_time = microtime( true );

		if ( null !== $this->http_sender ) {
			$result = (array) call_user_func( $this->http_sender, $webhook, $payload, $timeout, $headers );
			if ( ! isset( $result['statusCode'] ) ) {
				$result['statusCode'] = 0;
			}
			if ( ! array_key_exists( 'error', $result ) ) {
				$result['error'] = null;
			}
		} else {
			$result = $this->post_webhook_with_curl( $url, (string) $destination_ip, $json_body, $headers, $timeout, $verify_ssl );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
		$status_code = (int) ( $result['statusCode'] ?? 0 );

		return array(
			'webhookId'  => (string) ( $webhook['id'] ?? '' ),
			'url'        => $url,
			'statusCode' => $status_code,
			'success'    => $status_code >= 200 && $status_code < 300,
			'error'      => $result['error'] ?? null,
			'retryAfter' => $result['retryAfter'] ?? null,
			'durationMs' => $duration_ms,
		);
	}

	/**
	 * Create a masked signing-secret preview for dashboard display.
	 *
	 * @param string $secret Webhook signing secret.
	 * @return string Masked secret preview.
	 * @since 1.0.0
	 */
	private function mask_webhook_secret( string $secret ): string {
		$secret_prefix = substr( $secret, 0, 18 );

		if ( '' === $secret_prefix ) {
			return 'peakurl_whsec_••••••••••••••••';
		}

		return $secret_prefix . str_repeat( '•', 18 );
	}

	/**
	 * Prepare standardized webhook delivery HTTP headers.
	 *
	 * Emits the canonical replay-safe X-PeakURL-Signature (timestamp.payload).
	 *
	 * @param string $event       Event identifier.
	 * @param int    $timestamp   UNIX timestamp.
	 * @param string $signature   Replay-safe HMAC-SHA256 signature hash.
	 * @param string $delivery_id Delivery tracking ID.
	 * @return array<int, string> Formatted HTTP headers.
	 * @since 1.0.0
	 */
	private function create_webhook_headers(
		string $event,
		int $timestamp,
		string $signature,
		string $delivery_id
	): array {
		$version = (string) ( $this->config[ Constants::VERSION ] ?? '' );

		return array(
			'Content-Type: application/json; charset=utf-8',
			'X-PeakURL-Event: ' . $event,
			'X-PeakURL-Delivery: ' . $delivery_id,
			'X-PeakURL-Timestamp: ' . $timestamp,
			'X-PeakURL-Signature: ' . $signature,
			'User-Agent: ' . UserAgent::format( $version ),
		);
	}

	/**
	 * POST the webhook payload using cURL with strict SSL verification and no redirects.
	 *
	 * Connects to a validated public IP via CURLOPT_RESOLVE to prevent DNS rebinding / TOCTOU SSRF attacks.
	 *
	 * @param string             $url            Target destination URL.
	 * @param string             $destination_ip Validated public destination IP address.
	 * @param string             $body           Raw JSON payload.
	 * @param array<int, string> $headers        HTTP headers.
	 * @param float              $timeout        Timeout in seconds.
	 * @param bool               $verify_ssl     Whether to verify the SSL certificate.
	 * @return array{statusCode: int, error: string|null, retryAfter: string|null}
	 * @since 1.0.0
	 */
	private function post_webhook_with_curl(
		string $url,
		string $destination_ip,
		string $body,
		array $headers,
		float $timeout,
		bool $verify_ssl = true
	): array {
		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
		$port = (int) ( parse_url( $url, PHP_URL_PORT ) ?? 443 );

		if ( '' === $destination_ip ) {
			return array(
				'statusCode' => 0,
				'error'      => 'Invalid or unroutable destination IP address.',
				'retryAfter' => null,
			);
		}

		$curl_handle = curl_init( $url );

		if ( false === $curl_handle ) {
			return array(
				'statusCode' => 0,
				'error'      => 'Failed to initialize cURL.',
				'retryAfter' => null,
			);
		}

		$timeout_ms  = (int) max( 100, round( $timeout * 1000 ) );
		$connect_ms  = (int) min( $timeout_ms, 1500 );
		$retry_after = null;
		$clean_ip    = trim( $destination_ip, '[]' );
		$pinned_ip   = str_contains( $clean_ip, ':' ) ? '[' . $clean_ip . ']' : $clean_ip;

		curl_setopt_array(
			$curl_handle,
			array(
				CURLOPT_RESOLVE           => array( sprintf( '%s:%d:%s', $host, $port, $pinned_ip ) ),
				CURLOPT_POST              => true,
				CURLOPT_POSTFIELDS        => $body,
				CURLOPT_HTTPHEADER        => $headers,
				CURLOPT_RETURNTRANSFER    => true,
				CURLOPT_NOSIGNAL          => 1,
				CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
				CURLOPT_TIMEOUT_MS        => $timeout_ms,
				CURLOPT_FOLLOWLOCATION    => false,
				CURLOPT_MAXREDIRS         => 0,
				CURLOPT_SSL_VERIFYPEER    => $verify_ssl,
				CURLOPT_SSL_VERIFYHOST    => $verify_ssl ? 2 : 0,
				CURLOPT_HEADERFUNCTION    => static function ( $curl, string $header_line ) use ( &$retry_after ): int {
					$header_length = strlen( $header_line );
					if ( 0 === stripos( $header_line, 'retry-after:' ) ) {
						$parts = explode( ':', $header_line, 2 );
						if ( isset( $parts[1] ) ) {
							$retry_after = trim( $parts[1] );
						}
					}
					return $header_length;
				},
			)
		);

		curl_exec( $curl_handle );
		$http_code = (int) curl_getinfo( $curl_handle, CURLINFO_HTTP_CODE );
		$error     = curl_error( $curl_handle );

		unset( $curl_handle );

		return array(
			'statusCode' => $http_code,
			'error'      => '' !== $error ? $error : null,
			'retryAfter' => $retry_after,
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'manage_webhooks',
			'You do not have permission to manage webhooks.',
		);

		return $user;
	}

	/**
	 * Batch load 24-hour health summaries for a list of webhooks in bounded queries.
	 *
	 * Avoids N+1 queries when loading webhook lists.
	 *
	 * @param array<int, string> $webhook_ids List of webhook IDs.
	 * @return array<string, array{total24h: int, failed24h: int, lastStatus: string|null, lastResponseCode: int|null, lastError: string|null}> Health map keyed by webhook ID.
	 * @since 1.7.1
	 */
	private function batch_load_webhook_health( array $webhook_ids ): array {
		if ( empty( $webhook_ids ) ) {
			return array();
		}

		$health_map = array();
		foreach ( $webhook_ids as $id ) {
			$health_map[ $id ] = array(
				'total24h'         => 0,
				'failed24h'        => 0,
				'lastStatus'       => null,
				'lastResponseCode' => null,
				'lastError'        => null,
			);
		}

		$in_clause = $this->db->in_placeholders( $webhook_ids, 'webhook_id' );
		$since     = gmdate( 'Y-m-d H:i:s', time() - 86400 );

		// 1. Grouped 24-hour completed delivery counts per webhook and status.
		// Excludes in-flight pending and processing records, filtering strictly on completion timestamp.
		$count_sql = "SELECT webhook_id, status, COUNT(*) AS count_val
			FROM webhook_deliveries
			WHERE webhook_id IN ({$in_clause['sql']})
			AND completed_at >= :since
			AND status IN ('delivered', 'failed')
			GROUP BY webhook_id, status";

		$count_params = array_merge( $in_clause['params'], array( 'since' => $since ) );
		$count_rows   = $this->db->get_results( $count_sql, $count_params );

		if ( is_array( $count_rows ) ) {
			foreach ( $count_rows as $count_row ) {
				$webhook_id = (string) ( $count_row['webhook_id'] ?? '' );
				$status     = (string) ( $count_row['status'] ?? '' );
				$count      = (int) ( $count_row['count_val'] ?? 0 );

				if ( isset( $health_map[ $webhook_id ] ) ) {
					$health_map[ $webhook_id ]['total24h'] += $count;
					if ( 'failed' === $status ) {
						$health_map[ $webhook_id ]['failed24h'] += $count;
					}
				}
			}
		}

		// 2. Latest delivery outcome per webhook deterministically resolved.
		// When multiple delivery rows share the same created_at timestamp, resolves ties using MAX(id).
		$latest_sql = "SELECT d.webhook_id, d.status, d.response_code, d.last_error
			FROM webhook_deliveries d
			INNER JOIN (
				SELECT d1.webhook_id, MAX(d1.id) AS max_id
				FROM webhook_deliveries d1
				INNER JOIN (
					SELECT webhook_id, MAX(created_at) AS max_created
					FROM webhook_deliveries
					WHERE webhook_id IN ({$in_clause['sql']})
					GROUP BY webhook_id
				) latest_created ON d1.webhook_id = latest_created.webhook_id AND d1.created_at = latest_created.max_created
				GROUP BY d1.webhook_id
			) latest_row ON d.id = latest_row.max_id";

		$latest_rows = $this->db->get_results( $latest_sql, $in_clause['params'] );

		if ( is_array( $latest_rows ) ) {
			foreach ( $latest_rows as $latest_row ) {
				$webhook_id = (string) ( $latest_row['webhook_id'] ?? '' );
				if ( isset( $health_map[ $webhook_id ] ) ) {
					$health_map[ $webhook_id ]['lastStatus']       = Str::nullable( $latest_row['status'] ?? null );
					$health_map[ $webhook_id ]['lastResponseCode'] = ( null !== ( $latest_row['response_code'] ?? null ) && '' !== $latest_row['response_code'] )
						? (int) $latest_row['response_code']
						: null;
					$health_map[ $webhook_id ]['lastError']        = Str::nullable( $latest_row['last_error'] ?? null );
				}
			}
		}

		return $health_map;
	}

	/**
	 * Format a webhook row for API responses.
	 *
	 * Decrypted secrets are NEVER returned unless explicitly passed as $one_time_secret
	 * during creation or secret rotation.
	 *
	 * @param array<string, mixed>      $row             Raw webhook row.
	 * @param array<string, mixed>|null $health          Pre-calculated health summary or null.
	 * @param string|null               $one_time_secret One-time plaintext secret to return once.
	 * @return array<string, mixed> Webhook response payload.
	 * @since 1.0.0
	 */
	private function format_webhook(
		array $row,
		?array $health = null,
		?string $one_time_secret = null
	): array {
		$hint = (string) ( $row['secret_hint'] ?? '' );
		if ( '' === $hint && null !== $one_time_secret && '' !== $one_time_secret ) {
			$hint = $this->mask_webhook_secret( $one_time_secret );
		}

		$webhook_id     = (string) $row['id'];
		$health_summary = $health ?? $this->get_webhook_health_summary( $webhook_id );

		$webhook = array(
			'id'         => $webhook_id,
			'label'      => (string) ( $row['label'] ?? '' ),
			'url'        => (string) $row['url'],
			'events'     => $this->decode_json_array( (string) ( $row['events'] ?? '[]' ) ),
			'secretHint' => $hint,
			'verifySsl'  => ! isset( $row['verify_ssl'] ) || ! empty( $row['verify_ssl'] ),
			'isActive'   => ! empty( $row['is_active'] ),
			'createdAt'  => Date::to_iso( (string) $row['created_at'] ),
			'updatedAt'  => ! empty( $row['updated_at'] ) ? Date::to_iso( (string) $row['updated_at'] ) : null,
			'health'     => $health_summary,
		);

		if ( null !== $one_time_secret && '' !== $one_time_secret ) {
			$webhook['secret'] = $one_time_secret;
		}

		return $webhook;
	}

	/**
	 * Get a quick 24-hour health summary for a webhook endpoint.
	 *
	 * Delegates to the shared batch health loader to guarantee identical metric calculations.
	 *
	 * @param string $webhook_id Webhook row ID.
	 * @return array{total24h: int, failed24h: int, lastStatus: string|null, lastResponseCode: int|null, lastError: string|null}
	 * @since 1.7.1
	 */
	private function get_webhook_health_summary( string $webhook_id ): array {
		$batch = $this->batch_load_webhook_health( array( $webhook_id ) );

		return $batch[ $webhook_id ] ?? array(
			'total24h'         => 0,
			'failed24h'        => 0,
			'lastStatus'       => null,
			'lastResponseCode' => null,
			'lastError'        => null,
		);
	}

	/**
	 * Safely resolve a canonical site URL path for webhook payloads.
	 *
	 * @param string $path Relative path to append.
	 * @return string Canonical site URL.
	 * @since 1.0.0
	 */
	private function get_webhook_site_url( string $path = '' ): string {
		try {
			return \get_site_url( $path );
		} catch ( \Throwable $e ) {
			$base = ! empty( $this->config[ Constants::SITE_URL ] )
				? (string) $this->config[ Constants::SITE_URL ]
				: 'https://peakurl.dev';
			return '' !== $path ? rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) : rtrim( $base, '/' );
		}
	}

	/**
	 * Safely decode a JSON string into an array.
	 *
	 * @param string $json JSON string.
	 * @return array<mixed> Decoded array.
	 * @since 1.0.0
	 */
	private function decode_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Queue a webhook payload for deferred delivery via the scheduled job worker.
	 *
	 * @param string               $webhook_id    Webhook row ID.
	 * @param string               $event         Event identifier.
	 * @param array<string, mixed> $payload       Event envelope payload.
	 * @param int                  $delay_seconds Initial delay in seconds before first attempt.
	 * @param string|null          $event_id      Optional event ID.
	 * @return string Delivery ID.
	 * @since 1.7.0
	 */
	public function queue_delivery(
		string $webhook_id,
		string $event,
		array $payload,
		int $delay_seconds = 0,
		?string $event_id = null
	): string {
		$delivery_id = $this->create_delivery_id();
		$event_id    = $event_id ?? (string) ( $payload['id'] ?? $this->create_event_id() );
		$now         = Date::now();
		$next_run    = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay_seconds ) );

		$this->db->insert(
			'webhook_deliveries',
			array(
				'id'              => $delivery_id,
				'webhook_id'      => $webhook_id,
				'event_id'        => $event_id,
				'event'           => $event,
				'payload'         => (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'status'          => 'pending',
				'attempts'        => 0,
				'max_attempts'    => self::DEFAULT_MAX_DELIVERY_ATTEMPTS,
				'next_attempt_at' => $next_run,
				'last_attempt_at' => null,
				'completed_at'    => null,
				'duration_ms'     => null,
				'last_error'      => null,
				'response_code'   => null,
				'claim_token'     => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return $delivery_id;
	}

	/**
	 * Record an in-flight synchronous test delivery record with atomic claim.
	 *
	 * @param string               $webhook_id  Webhook ID.
	 * @param string               $event_id    Event tracking ID.
	 * @param array<string, mixed> $payload     Test payload array.
	 * @param string               $claim_token Unique synchronous claim token.
	 * @return string Delivery tracking ID.
	 * @since 1.7.1
	 */
	private function record_synchronous_test_delivery(
		string $webhook_id,
		string $event_id,
		array $payload,
		string $claim_token
	): string {
		$delivery_id = $this->create_delivery_id();
		$now         = Date::now();

		$this->db->insert(
			'webhook_deliveries',
			array(
				'id'              => $delivery_id,
				'webhook_id'      => $webhook_id,
				'event_id'        => $event_id,
				'event'           => 'webhook.test',
				'payload'         => (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'status'          => 'processing',
				'attempts'        => 0,
				'max_attempts'    => self::DEFAULT_MAX_DELIVERY_ATTEMPTS,
				'next_attempt_at' => $now,
				'last_attempt_at' => $now,
				'completed_at'    => null,
				'duration_ms'     => null,
				'last_error'      => null,
				'response_code'   => null,
				'claim_token'     => $claim_token,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return $delivery_id;
	}

	/**
	 * Classify whether an HTTP failure or network error is retryable.
	 *
	 * Temporary failures (timeouts, connection errors, 408, 425, 429, 500, 502, 503, 504) are retryable.
	 * Permanent failures (400, 401, 403, 404, 410, etc.) are NOT retryable.
	 *
	 * @param int $status_code HTTP response status code.
	 * @return bool True when the attempt can be retried.
	 * @since 1.7.1
	 */
	private function is_retryable_failure( int $status_code ): bool {
		// Network connection error, timeout, or DNS resolution glitch.
		if ( 0 === $status_code ) {
			return true;
		}

		$retryable_status_codes = array( 408, 425, 429, 500, 502, 503, 504 );
		return in_array( $status_code, $retryable_status_codes, true );
	}

	/**
	 * Calculate the retry delay in seconds using bounded exponential backoff with jitter or Retry-After header.
	 *
	 * @param int         $attempts    Attempt number (1-based).
	 * @param string|null $retry_after Optional Retry-After header value.
	 * @return int Delay in seconds before next attempt.
	 * @since 1.7.1
	 */
	private function calculate_retry_delay( int $attempts, ?string $retry_after = null ): int {
		// Honor valid Retry-After header if present.
		if ( null !== $retry_after && '' !== trim( $retry_after ) ) {
			$trimmed = trim( $retry_after );
			if ( ctype_digit( $trimmed ) ) {
				return min( 86400, max( 1, (int) $trimmed ) );
			}
			$parsed_time = strtotime( $trimmed );
			if ( false !== $parsed_time && $parsed_time > time() ) {
				return min( 86400, max( 1, $parsed_time - time() ) );
			}
		}

		// Bounded exponential backoff with small jitter (60s, 120s, 240s, 480s...).
		$base_delay = 60;
		$backoff    = $base_delay * ( 1 << max( 0, min( 10, $attempts - 1 ) ) );
		$jitter     = random_int( 1, 15 );

		return min( 86400, $backoff + $jitter );
	}

	/**
	 * Atomically claim pending webhook deliveries so multiple workers do not process duplicates.
	 *
	 * Generates and owns a unique worker claim token internally.
	 *
	 * @param int $batch_limit Maximum deliveries to claim.
	 * @return array<int, array<string, mixed>> Claimed delivery rows.
	 * @since 1.7.1
	 */
	private function claim_pending_deliveries( int $batch_limit = self::DEFAULT_BATCH_SIZE ): array {
		$batch_limit  = max( 1, min( 100, $batch_limit ) );
		$now          = Date::now();
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 300 ); // 5 minutes stale threshold for crashed workers.
		$token        = Str::random_id( 16 );

		$sql = "UPDATE webhook_deliveries
			SET status = 'processing',
				claim_token = :claim_token,
				updated_at = :now
			WHERE (status = 'pending' AND next_attempt_at <= :now_check)
			   OR (status = 'processing' AND updated_at <= :stale_cutoff)
			ORDER BY next_attempt_at ASC
			LIMIT {$batch_limit}";

		$this->db->query(
			$sql,
			array(
				'claim_token'  => $token,
				'now'          => $now,
				'now_check'    => $now,
				'stale_cutoff' => $stale_cutoff,
			)
		);

		return $this->db->get_results(
			'SELECT d.*, w.url, w.secret, w.verify_ssl, w.is_active
			FROM webhook_deliveries d
			LEFT JOIN webhooks w ON d.webhook_id = w.id
			WHERE d.claim_token = :token
			ORDER BY d.next_attempt_at ASC',
			array( 'token' => $token ),
		);
	}

	/**
	 * Process pending deferred webhook deliveries with atomic claiming and exponential backoff.
	 *
	 * Enforces end-to-end claim ownership on state updates so stale workers cannot
	 * overwrite reclaimed deliveries. Retries keep the same delivery ID and event ID.
	 * Payload is cleared on terminal completion.
	 *
	 * @param int $batch_limit Maximum deliveries to process in one run.
	 * @return array{processed: int, delivered: int, retried: int, failed: int}
	 * @since 1.7.0
	 */
	public function process_pending_deliveries( int $batch_limit = self::DEFAULT_BATCH_SIZE ): array {
		$deliveries = $this->claim_pending_deliveries( $batch_limit );

		if ( empty( $deliveries ) || ! is_array( $deliveries ) ) {
			return array(
				'processed' => 0,
				'delivered' => 0,
				'retried'   => 0,
				'failed'    => 0,
			);
		}

		$delivered_count = 0;
		$retried_count   = 0;
		$failed_count    = 0;

		foreach ( $deliveries as $delivery ) {
			$delivery_id  = (string) ( $delivery['id'] ?? '' );
			$claim_token  = (string) ( $delivery['claim_token'] ?? '' );
			$attempts     = ( (int) ( $delivery['attempts'] ?? 0 ) ) + 1;
			$max_attempts = (int) ( $delivery['max_attempts'] ?? self::DEFAULT_MAX_DELIVERY_ATTEMPTS );
			$url          = trim( (string) ( $delivery['url'] ?? '' ) );
			$is_active    = (bool) ( $delivery['is_active'] ?? false );

			if ( '' === $delivery_id || '' === $claim_token ) {
				continue;
			}

			if ( '' === $url ) {
				$updated = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'failed',
						'attempts'        => $attempts,
						'last_attempt_at' => Date::now(),
						'completed_at'    => Date::now(),
						'last_error'      => 'Webhook endpoint no longer exists.',
						'claim_token'     => null,
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$failed_count;
				}
				continue;
			}

			if ( ! $is_active ) {
				$updated = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'failed',
						'attempts'        => $attempts,
						'last_attempt_at' => Date::now(),
						'completed_at'    => Date::now(),
						'last_error'      => 'Webhook is inactive.',
						'claim_token'     => null,
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$failed_count;
				}
				continue;
			}

			$raw_payload = (string) ( $delivery['payload'] ?? '{}' );
			$payload     = json_decode( $raw_payload, true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			$webhook_target = array(
				'id'         => (string) $delivery['webhook_id'],
				'url'        => $url,
				'secret'     => (string) ( $delivery['secret'] ?? '' ),
				'verify_ssl' => ! isset( $delivery['verify_ssl'] ) || ! empty( $delivery['verify_ssl'] ),
			);

			try {
				$result = $this->send_webhook_payload( $webhook_target, $payload, 3.0, $delivery_id );
			} catch ( \Throwable $delivery_error ) {
				// Delivery-specific failure (e.g. crypto error, payload issue): record failure and continue batch.
				$error_msg = $delivery_error->getMessage();
				$updated   = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'failed',
						'attempts'        => $attempts,
						'last_attempt_at' => Date::now(),
						'completed_at'    => Date::now(),
						'last_error'      => $error_msg,
						'claim_token'     => null,
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$failed_count;
				}
				continue;
			}

			$status_code = (int) ( $result['statusCode'] ?? 0 );
			$duration_ms = (int) ( $result['durationMs'] ?? 0 );
			$is_success  = ! empty( $result['success'] );

			if ( $is_success ) {
				$updated = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'delivered',
						'attempts'        => $attempts,
						'last_attempt_at' => Date::now(),
						'completed_at'    => Date::now(),
						'last_error'      => null,
						'duration_ms'     => $duration_ms,
						'response_code'   => $status_code,
						'claim_token'     => null,
						'payload'         => '', // Clear payload upon successful delivery.
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$delivered_count;
				}
			} elseif ( ! $this->is_retryable_failure( $status_code ) || $attempts >= $max_attempts ) {
				$error_msg = Str::nullable( $result['error'] ?? null )
					?? ( $attempts >= $max_attempts ? 'Delivery failed after maximum attempts.' : 'Delivery failed permanently.' );

				$updated = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'failed',
						'attempts'        => $attempts,
						'last_attempt_at' => Date::now(),
						'completed_at'    => Date::now(),
						'last_error'      => $error_msg,
						'duration_ms'     => $duration_ms,
						'response_code'   => $status_code,
						'claim_token'     => null,
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$failed_count;
				}
			} else {
				$delay        = $this->calculate_retry_delay( $attempts, $result['retryAfter'] ?? null );
				$next_attempt = gmdate( 'Y-m-d H:i:s', time() + $delay );
				$error_msg    = Str::nullable( $result['error'] ?? null ) ?? 'Delivery attempt failed.';

				$updated = $this->db->update(
					'webhook_deliveries',
					array(
						'status'          => 'pending',
						'attempts'        => $attempts,
						'next_attempt_at' => $next_attempt,
						'last_attempt_at' => Date::now(),
						'completed_at'    => null,
						'last_error'      => $error_msg,
						'duration_ms'     => $duration_ms,
						'response_code'   => $status_code,
						'claim_token'     => null,
						'updated_at'      => Date::now(),
					),
					array(
						'id'          => $delivery_id,
						'claim_token' => $claim_token,
					)
				);
				if ( $updated > 0 ) {
					++$retried_count;
				}
			}
		}

		return array(
			'processed' => $delivered_count + $retried_count + $failed_count,
			'delivered' => $delivered_count,
			'retried'   => $retried_count,
			'failed'    => $failed_count,
		);
	}

	/**
	 * List paginated delivery records for a specific webhook.
	 *
	 * Response bodies are NOT retained in delivery records.
	 *
	 * @param Request $request    Incoming HTTP request.
	 * @param string  $webhook_id Webhook row ID.
	 * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
	 * @since 1.7.1
	 */
	public function list_deliveries( Request $request, string $webhook_id ): array {
		$this->get_accessible_webhook( $request, $webhook_id );

		$page     = max( 1, (int) $request->get_query_param( 'page', '1' ) );
		$per_page = max( 1, min( 50, (int) $request->get_query_param( 'per_page', '15' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $this->db->get_var(
			'SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id = :id',
			array( 'id' => $webhook_id ),
		);

		$rows = $this->db->get_results(
			'SELECT id, webhook_id, event_id, event, status, attempts, max_attempts,
					next_attempt_at, last_attempt_at, completed_at, duration_ms,
					response_code, last_error, created_at, updated_at
			FROM webhook_deliveries
			WHERE webhook_id = :id
			ORDER BY created_at DESC, id DESC
			LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset,
			array( 'id' => $webhook_id ),
		);

		$items = array_map(
			function ( array $row ): array {
				return array(
					'id'            => (string) $row['id'],
					'webhookId'     => (string) $row['webhook_id'],
					'eventId'       => (string) ( $row['event_id'] ?? '' ),
					'event'         => (string) $row['event'],
					'status'        => (string) $row['status'],
					'attempts'      => (int) $row['attempts'],
					'maxAttempts'   => (int) ( $row['max_attempts'] ?? self::DEFAULT_MAX_DELIVERY_ATTEMPTS ),
					'nextAttemptAt' => ! empty( $row['next_attempt_at'] ) ? Date::to_iso( (string) $row['next_attempt_at'] ) : null,
					'lastAttemptAt' => ! empty( $row['last_attempt_at'] ) ? Date::to_iso( (string) $row['last_attempt_at'] ) : null,
					'completedAt'   => ! empty( $row['completed_at'] ) ? Date::to_iso( (string) $row['completed_at'] ) : null,
					'durationMs'    => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : null,
					'responseCode'  => isset( $row['response_code'] ) ? (int) $row['response_code'] : null,
					'lastError'     => $row['last_error'] ?? null,
					'createdAt'     => Date::to_iso( (string) $row['created_at'] ),
				);
			},
			$rows ?? array(),
		);

		return array(
			'items' => $items,
			'meta'  => array(
				'page'       => $page,
				'perPage'    => $per_page,
				'total'      => $total,
				'totalPages' => (int) ceil( $total / $per_page ),
			),
		);
	}

	/**
	 * Manually re-queue an existing terminal failed delivery back into the delivery pipeline.
	 *
	 * Preserves the original delivery ID, event ID, event type, and payload, resetting
	 * the attempt counter to 0 so the delivery can enter a fresh attempt cycle.
	 *
	 * @param Request $request     Incoming HTTP request for authorization.
	 * @param string  $webhook_id  Target webhook ID.
	 * @param string  $delivery_id Target delivery ID.
	 * @return array<string, mixed> Queued delivery record.
	 * @throws ApiException When webhook or delivery is not found, unauthorized, or not in failed state.
	 * @since 1.7.1
	 */
	public function retry_failed_delivery( Request $request, string $webhook_id, string $delivery_id ): array {
		$webhook = $this->get_accessible_webhook( $request, $webhook_id );

		$delivery = $this->db->get_row_by(
			'webhook_deliveries',
			array(
				'id' => $delivery_id,
			)
		);

		if ( ! $delivery ) {
			throw new ApiException( __( 'Webhook delivery not found.', 'peakurl' ), 404 );
		}

		if ( (string) $delivery['webhook_id'] !== (string) $webhook['id'] ) {
			throw new ApiException( __( 'Webhook delivery does not belong to this webhook.', 'peakurl' ), 404 );
		}

		if ( 'failed' !== (string) $delivery['status'] ) {
			throw new ApiException( __( 'Only failed webhook deliveries can be retried.', 'peakurl' ), 400 );
		}

		$now     = Date::now();
		$updated = $this->db->update(
			'webhook_deliveries',
			array(
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'completed_at'    => null,
				'claim_token'     => null,
				'updated_at'      => $now,
			),
			array(
				'id'         => $delivery_id,
				'webhook_id' => (string) $webhook['id'],
				'status'     => 'failed',
			)
		);

		if ( $updated <= 0 ) {
			throw new ApiException( __( 'Failed to queue webhook delivery for retry.', 'peakurl' ), 400 );
		}

		if ( null !== $this->background_runner ) {
			$this->background_runner->enqueue_webhooks();
		}

		return array(
			'id'            => (string) $delivery['id'],
			'webhookId'     => (string) $delivery['webhook_id'],
			'eventId'       => (string) ( $delivery['event_id'] ?? '' ),
			'event'         => (string) $delivery['event'],
			'status'        => 'pending',
			'attempts'      => 0,
			'maxAttempts'   => (int) ( $delivery['max_attempts'] ?? self::DEFAULT_MAX_DELIVERY_ATTEMPTS ),
			'nextAttemptAt' => Date::to_iso( $now ),
			'lastAttemptAt' => ! empty( $delivery['last_attempt_at'] ) ? Date::to_iso( (string) $delivery['last_attempt_at'] ) : null,
			'completedAt'   => null,
			'durationMs'    => isset( $delivery['duration_ms'] ) ? (int) $delivery['duration_ms'] : null,
			'responseCode'  => isset( $delivery['response_code'] ) ? (int) $delivery['response_code'] : null,
			'lastError'     => $delivery['last_error'] ?? null,
			'createdAt'     => Date::to_iso( (string) $delivery['created_at'] ),
		);
	}

	/**
	 * Clean up terminal webhook delivery history past the retention threshold.
	 *
	 * Operates in a bounded batch to prevent table locks and keep cron execution predictable.
	 * Filters strictly by completion timestamp (completed_at) on terminal deliveries.
	 *
	 * @param int $retention_days Retention period in days (default self::DEFAULT_DELIVERY_RETENTION_DAYS).
	 * @param int $batch_limit    Maximum records to delete in one invocation (default 500).
	 * @return int Number of deleted delivery history rows.
	 * @since 1.7.1
	 */
	public function cleanup_delivery_history( int $retention_days = self::DEFAULT_DELIVERY_RETENTION_DAYS, int $batch_limit = 500 ): int {
		$days   = max( 1, $retention_days );
		$limit  = max( 1, min( 1000, $batch_limit ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		// Delete terminal records past retention cutoff in a bounded batch based on completion time.
		$sql = "DELETE FROM webhook_deliveries
			WHERE status IN ('delivered', 'failed')
			AND completed_at IS NOT NULL
			AND completed_at < :cutoff
			ORDER BY completed_at ASC
			LIMIT {$limit}";

		return $this->db->query(
			$sql,
			array( 'cutoff' => $cutoff )
		);
	}
}
