<?php
/**
 * Webhooks domain service facade.
 *
 * Coordinates webhook subscription persistence, encrypted signing secrets,
 * durable delivery, atomic job claiming, exponential backoff retries,
 * and event dispatching by delegating to cohesive domain components.
 *
 * @package PeakURL\Features\Webhooks
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Webhooks;

use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Scheduler\BackgroundRunner;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Webhooks domain service facade orchestrating Subscriptions, Delivery, and Dispatcher.

 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Default maximum deliveries to process in one scheduled job run.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_BATCH_SIZE = Delivery::DEFAULT_BATCH_SIZE;

	/**
	 * Default maximum number of delivery attempts for webhooks before marking as permanently failed.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_MAX_DELIVERY_ATTEMPTS = Delivery::DEFAULT_MAX_ATTEMPTS;

	/**
	 * Default retention period in days for terminal webhook delivery history.
	 *
	 * @var int
	 * @since 1.7.1
	 */
	public const DEFAULT_DELIVERY_RETENTION_DAYS = Delivery::DEFAULT_RETENTION_DAYS;


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
	 * Webhook dispatcher instance.
	 *
	 * @var Dispatcher
	 * @since 1.7.2
	 */
	private Dispatcher $dispatcher;

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
		$this->subscriptions = new Subscriptions(
			$db,
			$validator,
			$auth_service,
			$roles,
			$authorization,
			$crypto_service
		);
		$this->delivery      = new Delivery(
			$db,
			$validator,
			$this->subscriptions,
			$config
		);
		$this->dispatcher    = new Dispatcher(
			$db,
			$this->subscriptions,
			$this->delivery,
			$config
		);
	}


	/**
	 * Set a custom HTTP sender callback for delivery attempts (useful for testing).
	 *
	 * @param (callable(array<string, mixed>, array<string, mixed>, float, array<int, string>): array<string, mixed>)|null $sender Custom sender callable.
	 * @return void
	 * @since 1.7.0
	 */
	public function set_http_sender( ?callable $sender ): void {
		$this->delivery->set_http_sender( $sender );
	}

	/**
	 * Set the background runner instance for immediate async dispatch.
	 *
	 * @param BackgroundRunner|null $runner Background runner instance.
	 * @return void
	 * @since 1.7.1
	 */
	public function set_background_runner( ?BackgroundRunner $runner ): void {
		$this->delivery->set_background_runner( $runner );
	}
	/**
	 * Return the authoritative event catalogue for the frontend.
	 *
	 * @param Request|null $request Optional incoming HTTP request to authenticate/authorize.
	 * @return array<int, array{id: string, label: string, description: string, group: string}>
	 * @since 1.7.1
	 */
	public function get_event_catalogue( ?Request $request = null ): array {
		return $this->subscriptions->get_event_catalogue( $request );
	}

	/**
	 * List all webhooks for the authenticated user.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> Webhook rows with health summaries.
	 * @since 1.0.0
	 */
	public function list_webhooks( Request $request ): array {
		return $this->subscriptions->list_webhooks( $request );
	}

	/**
	 * Register a new webhook endpoint.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $payload Body with `url`, `events`, and optional `verifySsl`.
	 * @return array<string, mixed> Created webhook record including one-time secret.
	 * @since 1.0.0
	 */
	public function create_webhook( Request $request, array $payload ): array {
		return $this->subscriptions->create_webhook( $request, $payload );
	}

	/**
	 * Update an existing webhook registration.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param string               $id      Webhook row ID.
	 * @param array<string, mixed> $payload Updated webhook fields.
	 * @return array<string, mixed> Updated webhook record.
	 * @since 1.0.0
	 */
	public function update_webhook( Request $request, string $id, array $payload ): array {
		return $this->subscriptions->update_webhook( $request, $id, $payload );
	}

	/**
	 * Rotate the signing secret for an existing webhook and show it once.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Updated webhook record with the new one-time secret.
	 * @since 1.7.1
	 */
	public function rotate_secret( Request $request, string $id ): array {
		return $this->subscriptions->rotate_secret( $request, $id );
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
		return $this->subscriptions->delete_webhook( $request, $id );
	}

	/**
	 * Send a dedicated test webhook ping to verify receiver endpoint connectivity.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Webhook row ID.
	 * @return array<string, mixed> Delivery outcome details.
	 * @since 1.0.0
	 */
	public function test_webhook( Request $request, string $id ): array {
		return $this->dispatcher->test_webhook( $request, $id );
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
		return $this->dispatcher->dispatch_webhook_event( $user_id, $event, $data );
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
		return $this->dispatcher->dispatch_link_event( $event, $link_data, $user, $previous, $click );
	}

	/**
	 * Build payload and dispatch an API key lifecycle event to webhooks.
	 *
	 * @param string               $event    Webhook event identifier.
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
		return $this->dispatcher->dispatch_api_key_event( $event, $key_data, $user_id );
	}

	/**
	 * Build payload and dispatch a user lifecycle event to webhooks.
	 *
	 * @param string                    $event     Webhook event identifier.
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
		return $this->dispatcher->dispatch_user_event( $event, $user_data, $previous );
	}

	/**
	 * Determine all applicable link health webhook events based on the authoritative transition matrix.
	 *
	 * @param string|null $previous_status Previous health status string or null for first check.
	 * @param string      $current_status  Current health status string.
	 * @return array<int, string> Ordered list of applicable webhook event identifiers.
	 * @since 1.7.1
	 */
	public static function determine_health_events( ?string $previous_status, string $current_status ): array {
		return Dispatcher::determine_health_events( $previous_status, $current_status );
	}

	/**
	 * Build payload and dispatch a single link health event to webhooks.
	 *
	 * @param string                    $event           Webhook event identifier.
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
		return $this->dispatcher->dispatch_link_health_event(
			$event,
			$link_data,
			$current_health,
			$previous_health,
			$user
		);
	}

	/**
	 * Determine and dispatch all applicable health webhook events for a completed health check.
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
		return $this->dispatcher->dispatch_link_health_check(
			$link_data,
			$current_health,
			$previous_health,
			$user
		);
	}

	/**
	 * Send an HTTP POST webhook payload to a registered endpoint using cURL.
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
		return $this->delivery->send_webhook_payload( $webhook, $payload, $timeout, $delivery_id );
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
		return $this->delivery->queue_delivery( $webhook_id, $event, $payload, $delay_seconds, $event_id );
	}

	/**
	 * Process pending deferred webhook deliveries with atomic claiming and exponential backoff.
	 *
	 * @param int $batch_limit Maximum deliveries to process in one run.
	 * @return array{processed: int, delivered: int, retried: int, failed: int}
	 * @since 1.7.0
	 */
	public function process_pending_deliveries( int $batch_limit = self::DEFAULT_BATCH_SIZE ): array {
		return $this->delivery->process_pending_deliveries( $batch_limit );
	}

	/**
	 * List paginated delivery records for a specific webhook.
	 *
	 * @param Request $request    Incoming HTTP request.
	 * @param string  $webhook_id Webhook row ID.
	 * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
	 * @since 1.7.1
	 */
	public function list_deliveries( Request $request, string $webhook_id ): array {
		return $this->delivery->list_deliveries( $request, $webhook_id );
	}

	/**
	 * Build safe normalized event data block for link health events (compatibility forwarder).
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
		return $this->dispatcher->get_link_health_event_data( $event, $link_data, $current_health, $previous_health );
	}

	/**
	 * Batch load 24-hour health summaries for webhooks (compatibility forwarder).
	 *
	 * @param array<int, string> $webhook_ids List of webhook IDs.
	 * @return array<string, array{total24h: int, failed24h: int, lastStatus: string|null, lastResponseCode: int|null, lastError: string|null}>
	 * @since 1.7.1
	 */
	private function batch_load_webhook_health( array $webhook_ids ): array {
		return $this->subscriptions->batch_load_webhook_health( $webhook_ids );
	}


	/**
	 * Manually re-queue an existing terminal failed delivery back into the delivery pipeline.
	 *
	 * @param Request $request     Incoming HTTP request for authorization.
	 * @param string  $webhook_id  Target webhook ID.
	 * @param string  $delivery_id Target delivery ID.
	 * @return array<string, mixed> Queued delivery record.
	 * @since 1.7.1
	 */
	public function retry_failed_delivery( Request $request, string $webhook_id, string $delivery_id ): array {
		return $this->delivery->retry_failed_delivery( $request, $webhook_id, $delivery_id );
	}

	/**
	 * Clean up terminal webhook delivery history past the retention threshold.
	 *
	 * @param int $retention_days Retention period in days.
	 * @param int $batch_limit    Maximum records to delete in one invocation.
	 * @return int Number of deleted delivery history rows.
	 * @since 1.7.1
	 */
	public function cleanup_delivery_history(
		int $retention_days = self::DEFAULT_DELIVERY_RETENTION_DAYS,
		int $batch_limit = 500
	): int {
		return $this->delivery->cleanup_delivery_history( $retention_days, $batch_limit );
	}
}
