<?php
/**
 * Webhook delivery and transport engine.
 *
 * Coordinates request signing, HTTP dispatch, atomic delivery claiming,
 * exponential backoff retries, and delivery history.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Scheduler\BackgroundRunner;
use PeakURL\Http\Request;
use PeakURL\Http\UserAgent;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Delivery — Webhook HTTP transport, signing, queueing, and retry processor.
 *
 * @since 1.7.2
 */
class Delivery {
	/**
	 * Default batch size for pending deliveries processing.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Maximum delivery attempts before marking a delivery as failed permanently.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Default retention period in days for terminal webhook delivery history.
	 *
	 * @var int
	 * @since 1.7.2
	 */
	public const DEFAULT_RETENTION_DAYS = 30;



	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.7.2
	 */
	private PeakURL_DB $db;

	/**
	 * Webhook validator.
	 *
	 * @var Validator
	 * @since 1.7.2
	 */
	private Validator $validator;

	/**
	 * Webhook subscriptions service.
	 *
	 * @var Subscriptions
	 * @since 1.7.2
	 */
	private Subscriptions $subscriptions;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.7.2
	 */
	private array $config;

	/**
	 * Custom HTTP sender callback for delivery attempts (useful for testing).
	 *
	 * @var (callable(array<string, mixed>, array<string, mixed>, float, array<int, string>): array<string, mixed>)|null
	 * @since 1.7.2
	 */
	private $http_sender = null;

	/**
	 * Background runner for immediate post-response dispatch.
	 *
	 * @var BackgroundRunner|null
	 * @since 1.7.2
	 */
	private ?BackgroundRunner $background_runner = null;

	/**
	 * Create a new Webhooks Delivery instance.
	 *
	 * @param PeakURL_DB           $db            Shared database wrapper.
	 * @param Validator            $validator     Webhook validator.
	 * @param Subscriptions        $subscriptions Webhook subscriptions service.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @since 1.7.2
	 */
	public function __construct(
		PeakURL_DB $db,
		Validator $validator,
		Subscriptions $subscriptions,
		array $config
	) {
		$this->db            = $db;
		$this->validator     = $validator;
		$this->subscriptions = $subscriptions;
		$this->config        = $config;
	}


	/**
	 * Set a custom HTTP sender callback for delivery attempts (useful for testing).
	 *
	 * @param (callable(array<string, mixed>, array<string, mixed>, float, array<int, string>): array<string, mixed>)|null $sender Custom sender callable.
	 * @return void
	 * @since 1.7.2
	 */
	public function set_http_sender( ?callable $sender ): void {
		$this->http_sender = $sender;
	}

	/**
	 * Set the background runner instance for immediate async dispatch.
	 *
	 * @param BackgroundRunner|null $runner Background runner instance.
	 * @return void
	 * @since 1.7.2
	 */
	public function set_background_runner( ?BackgroundRunner $runner ): void {
		$this->background_runner = $runner;
	}

	/**
	 * Trigger immediate background worker processing for queued deliveries.
	 *
	 * Notifies the background runner to schedule post-response delivery execution.
	 *
	 * @return void
	 * @since 1.7.1
	 */
	public function trigger_background_delivery(): void {
		if ( null !== $this->background_runner ) {
			$this->background_runner->enqueue_webhooks();
		}
	}


	/**
	 * Create an opaque webhook delivery identifier.
	 *
	 * @return string Webhook delivery tracking identifier with peakurl_del_ prefix.
	 * @since 1.7.1
	 */
	public function create_delivery_id(): string {
		return 'peakurl_del_' . Str::random_id( 16 );
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
		$event_id    = $event_id ?? (string) ( $payload['id'] ?? ( 'peakurl_evt_' . Str::random_id( 16 ) ) );
		$now         = Date::now();

		$next_run = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay_seconds ) );

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
				'max_attempts'    => self::DEFAULT_MAX_ATTEMPTS,
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
	public function record_synchronous_test_delivery(
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
				'max_attempts'    => self::DEFAULT_MAX_ATTEMPTS,
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
	public function is_retryable_failure( int $status_code ): bool {
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
	public function calculate_retry_delay( int $attempts, ?string $retry_after = null ): int {
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
	public function claim_pending_deliveries( int $batch_limit = self::DEFAULT_BATCH_SIZE ): array {
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
			$max_attempts = (int) ( $delivery['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS );
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

		$this->subscriptions->get_accessible_webhook( $request, $webhook_id );

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
					'maxAttempts'   => (int) ( $row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ),
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

		$webhook = $this->subscriptions->get_accessible_webhook( $request, $webhook_id );

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

		$this->trigger_background_delivery();

		return array(
			'id'            => (string) $delivery['id'],
			'webhookId'     => (string) $delivery['webhook_id'],
			'eventId'       => (string) ( $delivery['event_id'] ?? '' ),
			'event'         => (string) $delivery['event'],
			'status'        => 'pending',
			'attempts'      => 0,
			'maxAttempts'   => (int) ( $delivery['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ),
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
	 * @param int $retention_days Retention period in days (default self::DEFAULT_RETENTION_DAYS).
	 * @param int $batch_limit    Maximum records to delete in one invocation (default 500).
	 * @return int Number of deleted delivery history rows.
	 * @since 1.7.1
	 */
	public function cleanup_delivery_history( int $retention_days = self::DEFAULT_RETENTION_DAYS, int $batch_limit = 500 ): int {

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
	public function compute_signature( int $timestamp, string $raw_body, string $raw_secret ): string {

		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $raw_secret );
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
	public function create_webhook_headers(
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

		$raw_secret = $this->subscriptions->decrypt_secret( (string) ( $webhook['secret'] ?? '' ) );

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
	public function post_webhook_with_curl(
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
}
