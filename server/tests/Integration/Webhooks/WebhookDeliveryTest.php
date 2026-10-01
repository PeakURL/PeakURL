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
use PeakURL\Features\Webhooks\Controller as WebhooksController;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Http\Request;
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
	private array $config;
	private AuthService $auth_service;
	private array $admin_user;
	private array $editor_user;

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$this->config       = $config;
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection );
		$roles              = new Roles();
		$authorization      = new Authorization( $roles );

		$auth_service       = new AuthService(
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
		$this->auth_service = $auth_service;

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

		$now               = Date::now();
		$this->admin_user  = array(
			'id'                => 99981,
			'username'          => 'deliveryadmin',
			'email'             => 'deliveryadmin@example.com',
			'first_name'        => 'Delivery',
			'last_name'         => 'Admin',
			'display_name'      => 'Delivery Admin',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'admin',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$this->editor_user = array(
			'id'                => 99982,
			'username'          => 'deliveryeditor',
			'email'             => 'deliveryeditor@example.com',
			'first_name'        => 'Delivery',
			'last_name'         => 'Editor',
			'display_name'      => 'Delivery Editor',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'editor',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$this->cleanup_tables();

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (99981, 'deliveryadmin', 'deliveryadmin@example.com', 'Delivery', 'Admin', 'Delivery Admin', '{$this->admin_user['password_hash']}', 'admin', 1, '{$now}', '{$now}')
			ON DUPLICATE KEY UPDATE username = VALUES(username), role = 'admin'"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (99982, 'deliveryeditor', 'deliveryeditor@example.com', 'Delivery', 'Editor', 'Delivery Editor', '{$this->editor_user['password_hash']}', 'editor', 1, '{$now}', '{$now}')
			ON DUPLICATE KEY UPDATE username = VALUES(username), role = 'editor'"
		);
	}

	protected function tearDown(): void {
		$this->cleanup_tables();
		parent::tearDown();
	}

	private function cleanup_tables(): void {
		$this->webhooks_service->set_http_sender( null );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE url LIKE '%example.com%' OR url LIKE '%test.local%' OR url LIKE '%test.destination%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id IN (99981, 99982)" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id IN (99981, 99982)" );
	}

	private function create_request_for_user( array $user, string $method = 'GET', string $path = '/' ): Request {
		$session_token = Str::random_id( 32 );
		$session_id    = Str::random_id( 16 );
		$now           = Date::now();

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, last_active_at, created_at)
			VALUES ('{$session_id}', {$user['id']}, '" . hash( 'sha256', $session_token ) . "', '{$now}', '{$now}')"
		);

		$crypto        = new Crypto( $this->config );
		$signed_cookie = $crypto->sign_session_token( $session_token );

		return new Request(
			$method,
			$path,
			array(),
			array(),
			array(
				'peakurl_session' => $signed_cookie,
			)
		);
	}

	private function create_test_webhook( string $url = 'https://example.com/webhook', bool $is_active = true, int $user_id = 99981 ): string {
		$webhook_id       = Str::random_id( 16 );
		$now              = Date::now();
		$active_val       = $is_active ? 1 : 0;
		$crypto           = new Crypto( Configuration::get_current() );
		$encrypted_secret = $crypto->encrypt( 'test_sec_123' );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, label, url, secret, events, is_active, created_at, updated_at)
			VALUES ('{$webhook_id}', {$user_id}, 'Test Webhook', '{$url}', '{$encrypted_secret}', '[\"link.created\"]', {$active_val}, '{$now}', '{$now}')"
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
		$this->assertNotEmpty( $row['payload'], 'Payload must be preserved on terminal delivery failure for manual retry.' );
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
		$this->assertNotEmpty( $row_failed['payload'], 'Payload must be preserved on terminal failure for manual retry.' );
	}

	public function test_manual_retry_terminal_failed_delivery_requeues_and_preserves_identifiers(): void {
		$webhook_id = $this->create_test_webhook();
		$event_data = array(
			'url'   => 'https://peakurl.dev/r/abc',
			'title' => 'Test Link',
		);

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			$event_data,
			0
		);

		// 1. Process delivery to terminal failure.
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 404,
				'error'      => 'Receiver not found',
			)
		);
		$this->webhooks_service->process_pending_deliveries( 10 );

		$initial_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $initial_row );
		$this->assertSame( 'failed', $initial_row['status'] );
		$this->assertSame( 1, (int) $initial_row['attempts'] );
		$this->assertNotNull( $initial_row['completed_at'] );
		$this->assertNotEmpty( $initial_row['payload'], 'Payload must be preserved on terminal failure.' );
		$original_event_id = $initial_row['event_id'];

		// Verify no synchronous HTTP is called during manual retry.
		$http_called = false;
		$this->webhooks_service->set_http_sender(
			function () use ( &$http_called ): array {
				$http_called = true;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		// 2. Perform manual retry via service.
		$request      = $this->create_request_for_user( $this->admin_user, 'POST', "/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry" );
		$retry_result = $this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $delivery_id );

		$this->assertFalse( $http_called, 'Manual retry must not perform synchronous outbound HTTP requests.' );
		$this->assertSame( $delivery_id, $retry_result['id'], 'Delivery ID must remain unchanged.' );
		$this->assertSame( $original_event_id, $retry_result['eventId'], 'Event ID must remain unchanged.' );
		$this->assertSame( 'pending', $retry_result['status'] );
		$this->assertSame( 0, $retry_result['attempts'], 'Attempt counter must be reset to 0 for a fresh cycle.' );
		$this->assertNull( $retry_result['completedAt'] );

		// 3. Verify updated database state.
		$retried_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $retried_row );
		$this->assertSame( 'pending', $retried_row['status'] );
		$this->assertSame( 0, (int) $retried_row['attempts'] );
		$this->assertNull( $retried_row['completed_at'] );
		$this->assertNull( $retried_row['claim_token'] );
		$this->assertNotEmpty( $retried_row['next_attempt_at'] );
		$this->assertSame( $original_event_id, $retried_row['event_id'] );
		$this->assertSame( $initial_row['payload'], $retried_row['payload'], 'Original payload must remain intact.' );

		// Verify no duplicate row was created.
		$total_rows = $this->pdo->query( "SELECT COUNT(*) FROM {$this->table_prefix}webhook_deliveries WHERE id = '{$delivery_id}'" )->fetchColumn();
		$this->assertSame( 1, (int) $total_rows, 'No duplicate delivery row should be created.' );
	}

	public function test_manual_retry_exhausted_max_attempts_starts_fresh_cycle_and_worker_delivers(): void {
		$webhook_id = $this->create_test_webhook();

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'exhausted' ),
			0
		);

		// Update max_attempts to 1 and attempts to 1, status failed.
		$this->db->update(
			'webhook_deliveries',
			array(
				'max_attempts' => 1,
				'attempts'     => 1,
				'status'       => 'failed',
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		// Worker must not pick up terminal failed deliveries that exhausted attempts.
		$worker_before = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertSame( 0, $worker_before['processed'] );

		// Admin manually retries the exhausted delivery.
		$request = $this->create_request_for_user( $this->admin_user, 'POST', "/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry" );
		$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $delivery_id );

		$retried_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'pending', $retried_row['status'] );
		$this->assertSame( 0, (int) $retried_row['attempts'] );

		// Worker now picks it up for the new cycle and delivers it successfully.
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 200,
				'error'      => null,
			)
		);

		$worker_after = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertSame( 1, $worker_after['processed'] );
		$this->assertSame( 1, $worker_after['delivered'] );

		$delivered_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'delivered', $delivered_row['status'] );
		$this->assertSame( 1, (int) $delivered_row['attempts'] );
		$this->assertSame( 200, (int) $delivered_row['response_code'] );
		$this->assertSame( '', $delivered_row['payload'], 'Payload must be cleared upon successful delivery.' );
	}

	public function test_manual_retry_rejects_non_failed_deliveries(): void {
		$webhook_id = $this->create_test_webhook();
		$request    = $this->create_request_for_user( $this->admin_user, 'POST', "/api/v1/webhooks/{$webhook_id}/deliveries/any/retry" );

		// 1. Reject delivered.
		$deliv_delivered = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 1 ), 0 );
		$this->db->update( 'webhook_deliveries', array( 'status' => 'delivered' ), array( 'id' => $deliv_delivered ) );

		try {
			$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $deliv_delivered );
			$this->fail( 'Expected ApiException for delivered record.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( 'Only failed webhook deliveries can be retried.', $e->getMessage() );
		}

		// 2. Reject pending.
		$deliv_pending = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 2 ), 0 );

		try {
			$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $deliv_pending );
			$this->fail( 'Expected ApiException for pending record.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( 'Only failed webhook deliveries can be retried.', $e->getMessage() );
		}

		// 3. Reject processing.
		$deliv_proc = $this->webhooks_service->queue_delivery( $webhook_id, 'link.created', array( 'test' => 3 ), 0 );
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'      => 'processing',
				'claim_token' => 'active_token',
			),
			array( 'id' => $deliv_proc )
		);

		try {
			$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $deliv_proc );
			$this->fail( 'Expected ApiException for processing record.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertSame( 'Only failed webhook deliveries can be retried.', $e->getMessage() );
		}
	}

	public function test_manual_retry_rejects_mismatched_webhook_or_nonexistent_delivery(): void {
		$webhook_a = $this->create_test_webhook( 'https://example.com/webhook-a' );
		$webhook_b = $this->create_test_webhook( 'https://example.com/webhook-b' );
		$request   = $this->create_request_for_user( $this->admin_user, 'POST', '/' );

		// Delivery on webhook B.
		$delivery_b = $this->webhooks_service->queue_delivery( $webhook_b, 'link.created', array( 'test' => 'mismatch' ), 0 );
		$this->db->update( 'webhook_deliveries', array( 'status' => 'failed' ), array( 'id' => $delivery_b ) );

		// Attempt to retry delivery B via webhook A -> 404.
		try {
			$this->webhooks_service->retry_failed_delivery( $request, $webhook_a, $delivery_b );
			$this->fail( 'Expected ApiException for mismatched webhook.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertSame( 'Webhook delivery does not belong to this webhook.', $e->getMessage() );
		}

		// Attempt to retry nonexistent delivery -> 404.
		try {
			$this->webhooks_service->retry_failed_delivery( $request, $webhook_a, 'nonexistent_deliv_id' );
			$this->fail( 'Expected ApiException for nonexistent delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertSame( 'Webhook delivery not found.', $e->getMessage() );
		}
	}

	public function test_manual_retry_failed_replay_enters_normal_retry_policy_again(): void {
		$webhook_id = $this->create_test_webhook();

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'policy_check' ),
			0
		);

		// Fail it initially.
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'attempts'     => 1,
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		// Manually retry.
		$request = $this->create_request_for_user( $this->admin_user, 'POST', "/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry" );
		$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $delivery_id );

		// On retry attempt, simulate 500 error (transient/retryable).
		$this->webhooks_service->set_http_sender(
			fn(): array => array(
				'statusCode' => 500,
				'error'      => 'Internal Server Error',
			)
		);

		$result = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['retried'] );

		// Delivery should now be pending for next automatic retry attempt.
		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNull( $row['completed_at'] );
		$this->assertGreaterThan( time(), strtotime( (string) $row['next_attempt_at'] ) );
	}

	public function test_manual_retry_replay_generates_fresh_signature_and_timestamp_with_original_payload(): void {
		$webhook_id = $this->create_test_webhook();
		$event_id   = 'peakurl_evt_' . Str::random_id( 16 );
		$payload    = array(
			'id'        => $event_id,
			'type'      => 'link.created',
			'createdAt' => Date::to_iso( Date::now() ),
			'data'      => array(
				'url_id' => 'abc_123',
				'domain' => 'peakurl.dev',
				'hits'   => 42,
			),
		);

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			$payload,
			0,
			$event_id
		);

		// Initial fail.
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'attempts'     => 1,
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		$initial_row       = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$initial_payload   = json_decode( (string) $initial_row['payload'], true );
		$original_event_id = $initial_payload['id'];
		$this->assertNotEmpty( $original_event_id );

		// Manually retry.
		$request = $this->create_request_for_user( $this->admin_user, 'POST', "/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry" );
		$this->webhooks_service->retry_failed_delivery( $request, $webhook_id, $delivery_id );

		// Capture headers and payload sent during replay.
		$captured_headers = array();
		$captured_payload = array();

		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout, array $headers ) use ( &$captured_headers, &$captured_payload ): array {
				$captured_headers = $headers;
				$captured_payload = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$this->webhooks_service->process_pending_deliveries( 10 );

		// Verify replayed delivery headers.
		$this->assertNotEmpty( $captured_headers );
		$header_map = array();
		foreach ( $captured_headers as $line ) {
			if ( str_contains( $line, ':' ) ) {
				list( $key, $val )          = explode( ':', $line, 2 );
				$header_map[ trim( $key ) ] = trim( $val );
			}
		}

		$this->assertSame( $delivery_id, $header_map['X-PeakURL-Delivery'], 'Delivery ID header must match original delivery ID.' );
		$this->assertNotEmpty( $header_map['X-PeakURL-Timestamp'] );
		$this->assertNotEmpty( $header_map['X-PeakURL-Signature'] );
		$this->assertGreaterThanOrEqual( time() - 5, (int) $header_map['X-PeakURL-Timestamp'] );

		// Verify payload data.
		$this->assertSame( $original_event_id, $captured_payload['id'], 'Event ID in replayed payload must match original event ID.' );
		$this->assertSame( 'link.created', $captured_payload['type'] );
		$this->assertSame( 'peakurl.dev', $captured_payload['data']['domain'] );
		$this->assertSame( 42, $captured_payload['data']['hits'] );

		// Verify signature was freshly computed over the replayed timestamp and payload.
		$raw_json = (string) json_encode( $captured_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$expected = hash_hmac( 'sha256', "{$header_map['X-PeakURL-Timestamp']}.{$raw_json}", 'test_sec_123' );
		$this->assertSame( $expected, $header_map['X-PeakURL-Signature'], 'Fresh signature must match newly computed HMAC.' );
	}

	public function test_manual_retry_authorization_and_controller_contract(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'controller_auth' ),
			0
		);

		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'attempts'     => 1,
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		$controller = new WebhooksController( $this->webhooks_service );

		// 1. Editor user without manage_webhooks cannot retry -> 403.
		$editor_request = $this->create_request_for_user(
			$this->editor_user,
			'POST',
			"/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry"
		);
		$editor_request->set_route_params(
			array(
				'id'          => $webhook_id,
				'delivery_id' => $delivery_id,
			)
		);

		try {
			$controller->retry_delivery( $editor_request );
			$this->fail( 'Expected ApiException 403 for unauthorized editor.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 2. Admin user can retry -> 200 OK.
		$admin_request = $this->create_request_for_user(
			$this->admin_user,
			'POST',
			"/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}/retry"
		);
		$admin_request->set_route_params(
			array(
				'id'          => $webhook_id,
				'delivery_id' => $delivery_id,
			)
		);

		$response = $controller->retry_delivery( $admin_request );
		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['success'] );
		$this->assertSame( 'Delivery queued for retry.', $response['body']['message'] );
		$this->assertSame( 'pending', $response['body']['data']['status'] );
		$this->assertSame( 0, $response['body']['data']['attempts'] );
		$this->assertSame( $delivery_id, $response['body']['data']['id'] );
	}
}
