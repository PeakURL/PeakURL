<?php
/**
 * Webhook subscription persistence and configuration domain service.
 *
 * Coordinates webhook subscription persistence, secret encryption,
 * secret rotation, masking, and health metrics aggregation.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Subscriptions — Handles webhook endpoint registration, secrets, and health metrics.
 *
 * @since 1.7.2
 */
class Subscriptions {


	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.2
	 */
	private PeakURL_DB $db;

	/**
	 * Webhook input validator.
	 *
	 * @var Validator
	 * @since 1.7.2
	 */
	private Validator $validator;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.7.2
	 */
	private AuthService $auth_service;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.7.2
	 */
	private Roles $roles;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.7.2
	 */
	private Authorization $authorization;

	/**
	 * Cryptography helper for encrypting webhook signing secrets at rest.
	 *
	 * @var Crypto
	 * @since 1.7.2
	 */
	private Crypto $crypto_service;

	/**
	 * In-memory cache of active webhook rows for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 * @since 1.7.2
	 */
	private ?array $active_webhooks_cache = null;

	/**
	 * Create a new Webhooks Subscriptions instance.
	 *
	 * @param PeakURL_DB    $db             Shared database wrapper.
	 * @param Validator     $validator      Webhook input validator.
	 * @param AuthService   $auth_service   Authentication domain service.
	 * @param Roles         $roles          Roles registry.
	 * @param Authorization $authorization  Shared authorization helper.
	 * @param Crypto        $crypto_service Centralized crypto service.
	 * @since 1.7.2
	 */
	public function __construct(
		PeakURL_DB $db,
		Validator $validator,
		AuthService $auth_service,
		Roles $roles,
		Authorization $authorization,
		Crypto $crypto_service
	) {
		$this->db             = $db;
		$this->validator      = $validator;
		$this->auth_service   = $auth_service;
		$this->roles          = $roles;
		$this->authorization  = $authorization;
		$this->crypto_service = $crypto_service;
	}

	/**
	 * Create an opaque webhook endpoint identifier.
	 *
	 * @return string Webhook endpoint identifier with peakurl_wh_ prefix.
	 * @since 1.7.1
	 */
	public function create_webhook_id(): string {
		return 'peakurl_wh_' . Str::random_id( 16 );
	}

	/**
	 * Create a standardized webhook signing secret.
	 *
	 * @return string Secret with standard peakurl_whsec_ prefix.
	 * @since 1.7.1
	 */
	public function create_signing_secret(): string {
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
	public function encrypt_secret( string $secret ): string {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			throw new \InvalidArgumentException( __( 'Webhook signing secret cannot be empty.', 'peakurl' ) );
		}

		return $this->crypto_service->encrypt( $secret );
	}

	/**
	 * Decrypt a stored signing secret for delivery payload signature generation.
	 *
	 * @param string $stored Encrypted payload from database.
	 * @return string Plaintext signing secret.
	 *
	 * @throws \RuntimeException On decryption failure or malformed payload.
	 * @since 1.7.1
	 */
	public function decrypt_secret( string $stored ): string {
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
	public function get_accessible_webhook( Request $request, string $id ): array {
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
	 * Retrieve all active webhook records, utilizing per-request in-memory cache.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function get_active_webhooks(): array {
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
	public function clear_active_webhooks_cache(): void {
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
	public function get_subscribed_webhooks( string $event, int|string|null $user_id ): array {

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
	 * Return the current webhook user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Current user.
	 * @since 1.0.0
	 */
	public function get_webhook_user( Request $request ): array {
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
	public function batch_load_webhook_health( array $webhook_ids ): array {
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
	public function format_webhook(
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
	 * Create a masked signing-secret preview for dashboard display.
	 *
	 * @param string $secret Webhook signing secret.
	 * @return string Masked secret preview.
	 * @since 1.0.0
	 */
	public function mask_webhook_secret( string $secret ): string {
		$secret_prefix = substr( $secret, 0, 18 );

		if ( '' === $secret_prefix ) {
			return 'peakurl_whsec_••••••••••••••••';
		}

		return $secret_prefix . str_repeat( '•', 18 );
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
	public function get_webhook_health_summary( string $webhook_id ): array {
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
	 * Safely decode a JSON string into an array.
	 *
	 * @param string $json JSON string.
	 * @return array<mixed> Decoded array.
	 * @since 1.0.0
	 */
	public function decode_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
