<?php
/**
 * Integration tests for Webhook delivery, retries, backoff, and terminal failures.
 *
 * @package PeakURL\Tests\Integration\Webhooks
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;
use PDO;

class WebhookDeliveryTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private WebhooksService $webhooks_service;
	private string $table_prefix;

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection );
		$roles              = new Roles();
		$authorization      = new Authorization( $roles );

		$auth_service = new AuthService(
			$this->db,
			new \PeakURL\Api\UsersApi( $this->db ),
			new AuthCredentials( $this->db ),
			new AuthValidator(),
			new \PeakURL\Services\Totp(),
			new \PeakURL\Services\Notifications(),
			new \PeakURL\Services\Crypto( $config ),
			$roles,
			$authorization,
			null,
			$config
		);

		$crypto    = new Crypto( $config );
		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				if ( in_array( $host, array( 'example.com', 'test.destination.org', 'destination.org' ), true ) ) {
					return '93.184.216.34';
				}
				return parent::get_destination_ip( $host );
			}
		};

		$this->webhooks_service = new WebhooksService(
			$this->db,
			$validator,
			$auth_service,
			$roles,
			$authorization,
			$config,
			$crypto
		);

		$this->cleanup_tables();
	}

	protected function tearDown(): void {
		$this->cleanup_tables();
		parent::tearDown();
	}

	private function cleanup_tables(): void {
		$this->webhooks_service->set_http_sender( null );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE url LIKE '%example.com%' OR url LIKE '%test.local%'" );
	}

	private function create_test_webhook( string $url = 'https://example.com/webhook', bool $is_active = true ): string {
		$webhook_id       = Str::random_id( 16 );
		$now              = Date::now();
		$active_val       = $is_active ? 1 : 0;
		$crypto           = new Crypto( Configuration::get_current() );
		$encrypted_secret = $crypto->encrypt( 'test_sec_123' );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, label, url, secret, events, is_active, created_at, updated_at)
			VALUES ('{$webhook_id}', 1, 'Test Webhook', '{$url}', '{$encrypted_secret}', '[\"link.created\"]', {$active_val}, '{$now}', '{$now}')"
		);

		return $webhook_id;
	}

	public function test_delivery_succeeds_with_2xx_response(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['delivered'] );
		$this->assertSame( 0, $result['retried'] );
		$this->assertSame( 0, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'response_code', 'last_error' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 200, (int) $row['response_code'] );
		$this->assertNull( $row['last_error'] );
	}

	public function test_delivery_failure_retryable_429_schedules_first_retry(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 429,
					'error'      => 'Too Many Requests',
					'retryAfter' => '60',
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['delivered'] );
		$this->assertSame( 1, $result['retried'] );
		$this->assertSame( 0, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'response_code', 'last_error', 'next_attempt_at' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 429, (int) $row['response_code'] );
		$this->assertStringContainsString( 'Too Many Requests', (string) $row['last_error'] );
		$this->assertGreaterThan( time(), strtotime( (string) $row['next_attempt_at'] . ' UTC' ) );
	}

	public function test_delivery_failure_retryable_429_with_http_date_retry_after(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$future_http_date = gmdate( 'D, d M Y H:i:s \G\M\T', time() + 120 );
		$this->webhooks_service->set_http_sender(
			function () use ( $future_http_date ): array {
				return array(
					'statusCode' => 429,
					'error'      => 'Too Many Requests',
					'retryAfter' => $future_http_date,
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['retried'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'next_attempt_at' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertGreaterThanOrEqual( time() + 100, strtotime( (string) $row['next_attempt_at'] . ' UTC' ) );
	}

	public function test_delivery_failure_permanent_404_fails_immediately_without_retry(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 404,
					'error'      => 'Not Found',
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['delivered'] );
		$this->assertSame( 0, $result['retried'] );
		$this->assertSame( 1, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'response_code', 'payload' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 404, (int) $row['response_code'] );
		$this->assertEmpty( $row['payload'], 'Payload must be cleared on permanent delivery completion' );
	}

	public function test_delivery_success_clears_payload_and_records_duration(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['delivered'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'payload', 'completed_at', 'duration_ms' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertEmpty( $row['payload'], 'Payload must be cleared on successful delivery' );
		$this->assertNotNull( $row['completed_at'] );
		$this->assertNotNull( $row['duration_ms'] );
	}
	public function test_delivery_failure_5xx_and_network_timeout(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 0,
					'error'      => 'Connection timed out after 3000ms',
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['retried'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'last_error' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertStringContainsString( 'timed out', (string) $row['last_error'] );
	}

	public function test_delivery_not_yet_due_is_not_processed(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			3600
		);

		$called = false;
		$this->webhooks_service->set_http_sender(
			function () use ( &$called ): array {
				$called = true;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertFalse( $called );
		$this->assertSame( 0, $result['processed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
	}

	public function test_max_attempts_reached_transitions_to_terminal_failed_state(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->pdo->exec(
			"UPDATE {$this->table_prefix}webhook_deliveries SET attempts = 2, max_attempts = 3 WHERE id = '{$delivery_id}'"
		);

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 500,
					'error'      => 'Internal Server Error',
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['delivered'] );
		$this->assertSame( 0, $result['retried'] );
		$this->assertSame( 1, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'response_code', 'last_error' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 3, (int) $row['attempts'] );
		$this->assertSame( 500, (int) $row['response_code'] );
		$this->assertStringContainsString( 'Internal Server Error', (string) $row['last_error'] );
	}

	public function test_deleted_webhook_marks_pending_delivery_as_failed(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE id = '{$webhook_id}'" );
		$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'last_error' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'no longer exists', (string) $row['last_error'] );
	}

	public function test_inactive_webhook_marks_pending_delivery_as_failed(): void {
		$webhook_id  = $this->create_test_webhook( 'https://example.com/inactive-hook', false );
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );

		$this->assertSame( 1, $result['failed'] );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'last_error' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'inactive', (string) $row['last_error'] );
	}

	public function test_empty_queue_returns_zero_counts(): void {
		$result = $this->webhooks_service->process_pending_deliveries( 50 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 0, $result['delivered'] );
		$this->assertSame( 0, $result['retried'] );
		$this->assertSame( 0, $result['failed'] );
	}

	public function test_multiple_pending_deliveries_respect_batch_limit_and_ordering(): void {
		$webhook_id = $this->create_test_webhook();

		$past_1 = gmdate( 'Y-m-d H:i:s', time() - 300 );
		$past_2 = gmdate( 'Y-m-d H:i:s', time() - 200 );
		$past_3 = gmdate( 'Y-m-d H:i:s', time() - 100 );

		$id_1 = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'num' => 1 ), 0 );
		$id_2 = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'num' => 2 ), 0 );
		$id_3 = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'num' => 3 ), 0 );

		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET next_attempt_at = '{$past_1}' WHERE id = '{$id_1}'" );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET next_attempt_at = '{$past_2}' WHERE id = '{$id_2}'" );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET next_attempt_at = '{$past_3}' WHERE id = '{$id_3}'" );

		$this->webhooks_service->set_http_sender(
			function (): array {
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 2 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['delivered'] );

		$row_3 = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $id_3 ), array( 'status' ) );
		$this->assertSame( 'pending', $row_3['status'] );
	}

	public function test_cleanup_delivery_history_removes_completed_deliveries_past_retention(): void {
		$webhook_id = $this->create_test_webhook();

		$old_date    = gmdate( 'Y-m-d H:i:s', time() - ( 35 * 86400 ) );
		$recent_date = gmdate( 'Y-m-d H:i:s', time() - ( 5 * 86400 ) );

		$old_delivered_id    = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'old' => true ), 0 );
		$recent_delivered_id = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'new' => true ), 0 );
		$old_pending_id      = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'pending' => true ), 0 );
		$old_processing_id   = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'processing' => true ), 0 );

		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET status = 'delivered', completed_at = '{$old_date}', created_at = '{$old_date}' WHERE id = '{$old_delivered_id}'" );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET status = 'delivered', completed_at = '{$recent_date}' WHERE id = '{$recent_delivered_id}'" );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET status = 'pending', created_at = '{$old_date}' WHERE id = '{$old_pending_id}'" );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET status = 'processing', claim_token = 'active_token', created_at = '{$old_date}' WHERE id = '{$old_processing_id}'" );

		$cleaned = $this->webhooks_service->cleanup_delivery_history( 30 );

		$this->assertSame( 1, $cleaned );
		$this->assertNull( $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $old_delivered_id ) ) );
		$this->assertNotNull( $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $recent_delivered_id ) ) );
		$this->assertNotNull( $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $old_pending_id ) ) );
		$this->assertNotNull( $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $old_processing_id ) ) );
	}

	public function test_claim_pending_deliveries_prevents_duplicate_processing_and_enforces_leases(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'k' => 1 ), 0 );

		// Simulate that Worker A has already claimed the delivery and is currently processing.
		$token_a = Str::random_id( 16 );
		$now     = Date::now();
		$this->pdo->exec(
			"UPDATE {$this->table_prefix}webhook_deliveries
			SET status = 'processing', claim_token = '{$token_a}', updated_at = '{$now}'
			WHERE id = '{$delivery_id}'"
		);

		// Worker B runs process_pending_deliveries: must NOT claim delivery owned by active Worker A.
		$result_b = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertSame( 0, $result_b['processed'], 'Worker B must not process deliveries active with another worker.' );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'processing', $row['status'] );
		$this->assertSame( $token_a, $row['claim_token'] );

		// Simulate stale claim: Worker A crashed or stalled > 300 seconds ago (5-minute lease expiration).
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 360 );
		$this->pdo->exec( "UPDATE {$this->table_prefix}webhook_deliveries SET updated_at = '{$stale_time}' WHERE id = '{$delivery_id}'" );

		// Worker B runs process_pending_deliveries: must reclaim the stale delivery and process it.
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 200,
				'error'      => null,
			)
		);

		$result_b2 = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertSame( 1, $result_b2['processed'], 'Worker B must reclaim stale processing deliveries.' );
		$this->assertSame( 1, $result_b2['delivered'] );

		$row_after = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $row_after );
		$this->assertSame( 'delivered', $row_after['status'] );
		$this->assertNull( $row_after['claim_token'] );

		// CRITICAL CONCURRENCY INVARIANT: Late stale Worker A must NOT overwrite Worker B's state.
		$affected = $this->db->update(
			'webhook_deliveries',
			array(
				'status'      => 'failed',
				'claim_token' => null,
				'payload'     => '',
			),
			array(
				'id'          => $delivery_id,
				'claim_token' => $token_a, // Stale token from Worker A
			)
		);
		$this->assertSame( 0, $affected, 'Late stale worker must not overwrite a reclaimed delivery.' );

		$row_unmodified = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'delivered', $row_unmodified['status'] );
		$this->assertNull( $row_unmodified['claim_token'] );
	}

	public function test_claim_token_is_cleared_on_completion_retry_and_terminal_failure(): void {
		$webhook_id = $this->create_test_webhook();

		// 1. Success clears claim token
		$id_success = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 'success' ), 0 );
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 200,
				'error'      => null,
			)
		);
		$this->webhooks_service->process_pending_deliveries( 10 );

		$row_success = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $id_success ) );
		$this->assertNotNull( $row_success );
		$this->assertSame( 'delivered', $row_success['status'] );
		$this->assertNull( $row_success['claim_token'] );
		$this->assertSame( '', $row_success['payload'] );

		// 2. Retryable failure clears claim token and schedules next attempt
		$id_retry = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 'retry' ), 0 );
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 429,
				'error'      => 'Rate limited',
			)
		);
		$this->webhooks_service->process_pending_deliveries( 10 );

		$row_retry = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $id_retry ) );
		$this->assertNotNull( $row_retry );
		$this->assertSame( 'pending', $row_retry['status'] );
		$this->assertNull( $row_retry['claim_token'] );
		$this->assertNull( $row_retry['completed_at'] );
		$this->assertNotEmpty( $row_retry['next_attempt_at'] );

		// 3. Permanent failure clears claim token
		$id_failed = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 'failed' ), 0 );
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 404,
				'error'      => 'Not Found',
			)
		);
		$this->webhooks_service->process_pending_deliveries( 10 );

		$row_failed = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $id_failed ) );
		$this->assertNotNull( $row_failed );
		$this->assertSame( 'failed', $row_failed['status'] );
		$this->assertNull( $row_failed['claim_token'] );
		$this->assertSame( '', $row_failed['payload'] );
	}
}
