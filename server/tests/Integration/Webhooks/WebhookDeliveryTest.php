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
	private Roles $roles;
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
		$settings_api       = new \PeakURL\Api\SettingsApi( $this->db );
		$roles              = new Roles( $settings_api );
		$this->roles        = $roles;
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
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE user_id IN (99981, 99982, 99983)" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE id IN (99981, 99982, 99983)" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}settings WHERE setting_key = '" . \PeakURL\Core\Config\Constants::SETTING_ROLE_CAPABILITIES . "'" );
	}

	private function create_request_for_user( array $user, string $method = 'GET', string $path = '/', array $body = array() ): Request {
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
			$body,
			array(
				'peakurl_session' => $signed_cookie,
			)
		);
	}

	private function create_test_webhook( string $url = 'https://example.com/webhook', bool $is_active = true, int $user_id = 99981, int $retention_days = 30, string $raw_secret = 'test_sec_123' ): string {
		$webhook_id       = Str::random_id( 16 );
		$now              = Date::now();
		$active_val       = $is_active ? 1 : 0;
		$crypto           = new Crypto( Configuration::get_current() );
		$encrypted_secret = $crypto->encrypt( $raw_secret );
		$secret_hint      = str_starts_with( $raw_secret, 'peakurl_whsec_' )
			? substr( $raw_secret, 0, 18 ) . str_repeat( '•', 18 )
			: 'test_sec_••••••••••••';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, label, url, secret, secret_hint, events, is_active, delivery_retention_days, created_at, updated_at)
			VALUES ('{$webhook_id}', {$user_id}, 'Test Webhook', '{$url}', '{$encrypted_secret}', '{$secret_hint}', '[\"link.created\"]', {$active_val}, {$retention_days}, '{$now}', '{$now}')"
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

		// 1. Editor user without manage_integrations cannot retry -> 403.
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

	public function test_delete_delivery_individual_terminal_records(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'event_data' => 'test_delivered' ),
			0
		);

		// Mark as delivered
		$now = Date::now();
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'delivered',
				'completed_at' => $now,
			),
			array( 'id' => $delivery_id )
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );
		$result        = $this->webhooks_service->delete_delivery( $admin_request, $webhook_id, $delivery_id );
		$this->assertTrue( $result );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNull( $row );

		// Test deleting a failed delivery permanently deletes its payload too
		$failed_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'event_data' => 'test_failed_replay_payload' ),
			0
		);
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'completed_at' => $now,
			),
			array( 'id' => $failed_id )
		);

		$failed_result = $this->webhooks_service->delete_delivery( $admin_request, $webhook_id, $failed_id );
		$this->assertTrue( $failed_result );

		$failed_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $failed_id ) );
		$this->assertNull( $failed_row );
	}

	public function test_delete_delivery_rejects_active_states_with_409(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'event_data' => 'test_pending' ),
			0
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );

		// 1. Pending delivery throws 409
		try {
			$this->webhooks_service->delete_delivery( $admin_request, $webhook_id, $delivery_id );
			$this->fail( 'Expected ApiException 409 for pending delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->get_status() );
			$this->assertSame( 'Active webhook deliveries cannot be deleted.', $e->getMessage() );
		}

		// 2. Processing delivery throws 409
		$this->db->update(
			'webhook_deliveries',
			array( 'status' => 'processing' ),
			array( 'id' => $delivery_id )
		);

		try {
			$this->webhooks_service->delete_delivery( $admin_request, $webhook_id, $delivery_id );
			$this->fail( 'Expected ApiException 409 for processing delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->get_status() );
			$this->assertSame( 'Active webhook deliveries cannot be deleted.', $e->getMessage() );
		}
	}

	public function test_delete_delivery_missing_and_mismatched_returns_404(): void {
		$webhook_a = $this->create_test_webhook();
		$webhook_b = $this->create_test_webhook( 'https://destination.org/webhook' );

		$delivery_b = $this->webhooks_service->queue_delivery(
			$webhook_b,
			'link.created',
			array( 'test' => true ),
			0
		);
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'delivered',
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_b )
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );

		// 1. Missing delivery returns 404
		try {
			$this->webhooks_service->delete_delivery( $admin_request, $webhook_a, 'nonexistent_del_id' );
			$this->fail( 'Expected ApiException 404 for missing delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->get_status() );
		}

		// 2. Delivery belonging to Webhook B requested under Webhook A returns 404
		try {
			$this->webhooks_service->delete_delivery( $admin_request, $webhook_a, $delivery_b );
			$this->fail( 'Expected ApiException 404 for mismatched webhook delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 404, $e->get_status() );
			$this->assertSame( 'Webhook delivery does not belong to this webhook.', $e->getMessage() );
		}
	}

	public function test_delete_delivery_conditional_statement_protects_against_retry_race(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'race_protection_payload' ),
			0
		);

		// Initially mark as failed so it passes the initial terminal status checks
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		$delete_reached = false;
		$interceptor    = new class( $this->connection, $this->pdo, $this->table_prefix, $delivery_id, $delete_reached ) extends PeakURL_DB {
			private PDO $raw_pdo;
			private string $prefix;
			private string $target_id;
			public bool $delete_reached;
			public int $delete_affected_rows = -1;

			public function __construct( Connection $conn, PDO $pdo, string $prefix, string $target_id, bool &$delete_reached ) {
				parent::__construct( $conn );
				$this->raw_pdo        = $pdo;
				$this->prefix         = $prefix;
				$this->target_id      = $target_id;
				$this->delete_reached = &$delete_reached;
			}

			public function query( string $sql, array $params = array() ): int {
				// Intercept the DELETE statement after the initial read but before DELETE executes against MySQL
				if ( str_contains( $sql, 'DELETE FROM webhook_deliveries' ) && ( $params['id'] ?? '' ) === $this->target_id ) {
					$this->delete_reached = true;
					// Deterministically transition delivery to 'pending'
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhook_deliveries SET status = 'pending', completed_at = NULL WHERE id = '{$this->target_id}'"
					);
				}

				$affected = parent::query( $sql, $params );
				if ( $this->delete_reached && -1 === $this->delete_affected_rows ) {
					$this->delete_affected_rows = $affected;
				}
				return $affected;
			}
		};

		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				return '93.184.216.34';
			}
		};

		$isolated_service = new WebhooksService(
			$interceptor,
			$validator,
			$this->auth_service,
			new Roles(),
			new Authorization( new Roles() ),
			$this->config,
			new Crypto( $this->config )
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );

		try {
			$isolated_service->delete_delivery( $admin_request, $webhook_id, $delivery_id );
			$this->fail( 'Expected ApiException 409 due to state race conflict.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->get_status() );
			$this->assertSame( 'Active webhook deliveries cannot be deleted.', $e->getMessage() );
		}

		$this->assertTrue( $delete_reached, 'Conditional DELETE must be reached after initial failed status check.' );
		$this->assertSame( 0, $interceptor->delete_affected_rows, 'Conditional DELETE statement must affect 0 rows.' );

		// Confirm delivery and replay payload are preserved
		$current = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $current );
		$this->assertSame( 'pending', $current['status'] );
		$this->assertNull( $current['completed_at'] );
		$payload_data = json_decode( (string) $current['payload'], true );
		$this->assertSame( 'race_protection_payload', $payload_data['test'] );
	}

	public function test_delete_delivery_conditional_statement_protects_against_processing_race(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'processing_race_payload' ),
			0
		);

		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'failed',
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		$delete_reached = false;
		$interceptor    = new class( $this->connection, $this->pdo, $this->table_prefix, $delivery_id, $delete_reached ) extends PeakURL_DB {
			private PDO $raw_pdo;
			private string $prefix;
			private string $target_id;
			public bool $delete_reached;
			public int $delete_affected_rows = -1;

			public function __construct( Connection $conn, PDO $pdo, string $prefix, string $target_id, bool &$delete_reached ) {
				parent::__construct( $conn );
				$this->raw_pdo        = $pdo;
				$this->prefix         = $prefix;
				$this->target_id      = $target_id;
				$this->delete_reached = &$delete_reached;
			}

			public function query( string $sql, array $params = array() ): int {
				if ( str_contains( $sql, 'DELETE FROM webhook_deliveries' ) && ( $params['id'] ?? '' ) === $this->target_id ) {
					$this->delete_reached = true;
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhook_deliveries SET status = 'processing' WHERE id = '{$this->target_id}'"
					);
				}

				$affected = parent::query( $sql, $params );
				if ( $this->delete_reached && -1 === $this->delete_affected_rows ) {
					$this->delete_affected_rows = $affected;
				}
				return $affected;
			}
		};

		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				return '93.184.216.34';
			}
		};

		$isolated_service = new WebhooksService(
			$interceptor,
			$validator,
			$this->auth_service,
			new Roles(),
			new Authorization( new Roles() ),
			$this->config,
			new Crypto( $this->config )
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );

		try {
			$isolated_service->delete_delivery( $admin_request, $webhook_id, $delivery_id );
			$this->fail( 'Expected ApiException 409 due to processing state race conflict.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 409, $e->get_status() );
			$this->assertSame( 'Active webhook deliveries cannot be deleted.', $e->getMessage() );
		}

		$this->assertTrue( $delete_reached );
		$this->assertSame( 0, $interceptor->delete_affected_rows );

		$current = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $current );
		$this->assertSame( 'processing', $current['status'] );
		$payload_data = json_decode( (string) $current['payload'], true );
		$this->assertSame( 'processing_race_payload', $payload_data['test'] );
	}

	public function test_clear_delivery_history_clears_terminal_records_and_preserves_active_and_other_webhooks(): void {
		$webhook_a = $this->create_test_webhook( 'https://example.com/webhook-a' );
		$webhook_b = $this->create_test_webhook( 'https://destination.org/webhook-b' );

		$now = Date::now();

		// Bulk insert 1,050 delivered/failed records for Webhook A
		$rows_batch = array();
		for ( $i = 1; $i <= 1050; $i++ ) {
			$id           = 'test_del_a_' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT );
			$status       = 0 === ( $i % 2 ) ? 'delivered' : 'failed';
			$rows_batch[] = "('{$id}', '{$webhook_a}', 'evt_{$i}', 'link.created', '{$status}', 1, 3, '{$now}', '{$now}', '{$now}', '{$now}', '{}')";
		}

		// Insert in chunks of 500 for SQL length safety
		foreach ( array_chunk( $rows_batch, 500 ) as $chunk ) {
			$values = implode( ',', $chunk );
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload)
				VALUES {$values}"
			);
		}

		// Insert 2 active records for Webhook A (pending and processing)
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, created_at, updated_at, payload)
			VALUES ('test_active_pending_a', '{$webhook_a}', 'evt_pending', 'link.created', 'pending', 0, 3, '{$now}', '{$now}', '{$now}', '{}'),
			       ('test_active_processing_a', '{$webhook_a}', 'evt_proc', 'link.created', 'processing', 1, 3, '{$now}', '{$now}', '{$now}', '{}')"
		);

		// Insert 2 delivered records for Webhook B
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload)
			VALUES ('test_del_b_1', '{$webhook_b}', 'evt_b1', 'link.created', 'delivered', 1, 3, '{$now}', '{$now}', '{$now}', '{$now}', '{}'),
			       ('test_del_b_2', '{$webhook_b}', 'evt_b2', 'link.created', 'delivered', 1, 3, '{$now}', '{$now}', '{$now}', '{$now}', '{}')"
		);

		$admin_request = $this->create_request_for_user( $this->admin_user );

		// Clear delivery history for Webhook A
		$deleted_count = $this->webhooks_service->clear_delivery_history( $admin_request, $webhook_a );
		$this->assertSame( 1050, $deleted_count );

		// Webhook A should only have the 2 active records left
		$remaining_a = $this->db->get_results(
			'SELECT id, status FROM webhook_deliveries WHERE webhook_id = :id',
			array( 'id' => $webhook_a )
		);
		$this->assertCount( 2, $remaining_a );
		$statuses_a = array_column( $remaining_a, 'status' );
		$this->assertContains( 'pending', $statuses_a );
		$this->assertContains( 'processing', $statuses_a );

		// Webhook B should still have its 2 delivered records intact
		$count_b = (int) $this->db->get_var(
			'SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id = :id',
			array( 'id' => $webhook_b )
		);
		$this->assertSame( 2, $count_b );

		// Second clear on Webhook A returns 0
		$second_clear = $this->webhooks_service->clear_delivery_history( $admin_request, $webhook_a );
		$this->assertSame( 0, $second_clear );
	}

	public function test_cleanup_policy_delivery_history_respects_per_webhook_retention(): void {
		// Webhook 1: 7-day retention
		$wh_7 = $this->create_test_webhook( 'https://example.com/wh-7', true, 99981, 7 );
		// Webhook 2: 30-day retention
		$wh_30 = $this->create_test_webhook( 'https://example.com/wh-30', true, 99981, 30 );
		// Webhook 3: disabled retention (0)
		$wh_0 = $this->create_test_webhook( 'https://example.com/wh-0', true, 99981, 0 );
		// Webhook 4: inactive webhook with 7-day retention
		$wh_inactive = $this->create_test_webhook( 'https://example.com/wh-inactive', false, 99981, 7 );

		$time_now     = time();
		$t_minus_10d  = gmdate( 'Y-m-d H:i:s', $time_now - ( 10 * 86400 ) );
		$t_minus_5d   = gmdate( 'Y-m-d H:i:s', $time_now - ( 5 * 86400 ) );
		$t_minus_40d  = gmdate( 'Y-m-d H:i:s', $time_now - ( 40 * 86400 ) );
		$t_minus_100d = gmdate( 'Y-m-d H:i:s', $time_now - ( 100 * 86400 ) );
		$t_minus_20d  = gmdate( 'Y-m-d H:i:s', $time_now - ( 20 * 86400 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_wh7_old', '{$wh_7}', 'e1', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}'),
			('del_wh7_fresh', '{$wh_7}', 'e2', 'link.created', 'delivered', 1, 3, '{$t_minus_5d}', '{$t_minus_5d}', '{$t_minus_5d}', '{$t_minus_5d}', '{}'),
			('del_wh30_mid', '{$wh_30}', 'e3', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}'),
			('del_wh30_old', '{$wh_30}', 'e4', 'link.created', 'failed', 3, 3, '{$t_minus_40d}', '{$t_minus_40d}', '{$t_minus_40d}', '{$t_minus_40d}', '{}'),
			('del_wh0_ancient', '{$wh_0}', 'e5', 'link.created', 'delivered', 1, 3, '{$t_minus_100d}', '{$t_minus_100d}', '{$t_minus_100d}', '{$t_minus_100d}', '{}'),
			('del_whinact_old', '{$wh_inactive}', 'e6', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}')"
		);

		// Also insert an old pending delivery without completed_at: must NEVER be deleted
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_wh7_pending', '{$wh_7}', 'e7', 'link.created', 'pending', 0, 3, '{$t_minus_20d}', NULL, '{$t_minus_20d}', '{$t_minus_20d}', '{}')"
		);

		// Run policy cleanup through WebhooksService with default null parameter
		$cleaned = $this->webhooks_service->cleanup_delivery_history();

		// Should clean exactly 3 rows: del_wh7_old (10d > 7d), del_wh30_old (40d > 30d), and del_whinact_old (10d > 7d on inactive webhook)
		$this->assertSame( 3, $cleaned );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries' ),
			'id'
		);

		$this->assertNotContains( 'del_wh7_old', $remaining_ids );
		$this->assertNotContains( 'del_wh30_old', $remaining_ids );
		$this->assertNotContains( 'del_whinact_old', $remaining_ids );

		// Preserved rows:
		$this->assertContains( 'del_wh7_fresh', $remaining_ids );    // 5d < 7d
		$this->assertContains( 'del_wh30_mid', $remaining_ids );     // 10d < 30d
		$this->assertContains( 'del_wh0_ancient', $remaining_ids );  // retention = 0 (disabled)
		$this->assertContains( 'del_wh7_pending', $remaining_ids );  // null completed_at preserved
	}

	public function test_cleanup_policy_delivery_history_race_protects_concurrently_modified_records(): void {
		// Webhook 1: 7-day retention (will be changed to 0 between select and delete)
		$wh_r0 = $this->create_test_webhook( 'https://example.com/wh-r0', true, 99981, 7 );
		// Webhook 2: 7-day retention (will be increased to 90 between select and delete)
		$wh_inc = $this->create_test_webhook( 'https://example.com/wh-inc', true, 99981, 7 );
		// Webhook 3: 7-day retention (delivery will complete again with fresh timestamp)
		$wh_retry = $this->create_test_webhook( 'https://example.com/wh-retry', true, 99981, 7 );
		// Webhook 4: 7-day retention (delivery will become pending)
		$wh_active = $this->create_test_webhook( 'https://example.com/wh-active', true, 99981, 7 );
		// Webhook 5: 7-day retention (delivery stays expired and will be deleted)
		$wh_expired = $this->create_test_webhook( 'https://example.com/wh-expired', true, 99981, 7 );

		$time_now    = time();
		$t_minus_10d = gmdate( 'Y-m-d H:i:s', $time_now - ( 10 * 86400 ) );
		$fresh_now   = gmdate( 'Y-m-d H:i:s', $time_now );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_race_r0', '{$wh_r0}', 'e1', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{\"test\":\"payload1\"}'),
			('del_race_inc', '{$wh_inc}', 'e2', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{\"test\":\"payload2\"}'),
			('del_race_retry', '{$wh_retry}', 'e3', 'link.created', 'failed', 3, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{\"test\":\"payload3\"}'),
			('del_race_active', '{$wh_active}', 'e4', 'link.created', 'failed', 3, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{\"test\":\"payload4\"}'),
			('del_race_expired', '{$wh_expired}', 'e5', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{\"test\":\"payload5\"}')"
		);

		$interceptor = new class( $this->connection, $this->pdo, $this->table_prefix, $wh_r0, $wh_inc, $fresh_now ) extends PeakURL_DB {
			private PDO $raw_pdo;
			private string $prefix;
			private string $wh_r0;
			private string $wh_inc;
			private string $fresh_now;
			public bool $delete_reached      = false;
			public int $delete_affected_rows = -1;

			public function __construct( Connection $conn, PDO $pdo, string $prefix, string $wh_r0, string $wh_inc, string $fresh_now ) {
				parent::__construct( $conn );
				$this->raw_pdo   = $pdo;
				$this->prefix    = $prefix;
				$this->wh_r0     = $wh_r0;
				$this->wh_inc    = $wh_inc;
				$this->fresh_now = $fresh_now;
			}

			public function query( string $sql, array $params = array() ): int {
				if ( str_contains( $sql, 'DELETE d' ) && str_contains( $sql, 'webhook_deliveries d' ) ) {
					$this->delete_reached = true;

					// 1. Retention changes to 0 (keep indefinitely)
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhooks SET delivery_retention_days = 0 WHERE id = '{$this->wh_r0}'"
					);

					// 2. Retention increases from 7 to 90 (so 10d completion is within the new 90d window)
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhooks SET delivery_retention_days = 90 WHERE id = '{$this->wh_inc}'"
					);

					// 3. A retry completes again with a fresh completed_at and terminal status
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhook_deliveries SET completed_at = '{$this->fresh_now}', status = 'delivered' WHERE id = 'del_race_retry'"
					);

					// 4. A selected delivery becomes pending
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhook_deliveries SET status = 'pending', completed_at = NULL WHERE id = 'del_race_active'"
					);
				}

				$affected = parent::query( $sql, $params );
				if ( $this->delete_reached && -1 === $this->delete_affected_rows ) {
					$this->delete_affected_rows = $affected;
				}
				return $affected;
			}
		};

		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				return '93.184.216.34';
			}
		};

		$isolated_service = new WebhooksService(
			$interceptor,
			$validator,
			$this->auth_service,
			new Roles(),
			new Authorization( new Roles() ),
			$this->config,
			new Crypto( $this->config )
		);

		$cleaned = $isolated_service->cleanup_policy_delivery_history();

		$this->assertTrue( $interceptor->delete_reached );
		$this->assertSame( 1, $cleaned );
		$this->assertSame( 1, $interceptor->delete_affected_rows );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries' ),
			'id'
		);

		// All 4 race-modified records MUST survive:
		$this->assertContains( 'del_race_r0', $remaining_ids, 'Record whose webhook changed retention to 0 must survive.' );
		$this->assertContains( 'del_race_inc', $remaining_ids, 'Record whose webhook increased retention to 90 must survive.' );
		$this->assertContains( 'del_race_retry', $remaining_ids, 'Record that retried and completed with fresh timestamp must survive.' );
		$this->assertContains( 'del_race_active', $remaining_ids, 'Record that transitioned to pending must survive.' );

		// The unchanged expired record is deleted:
		$this->assertNotContains( 'del_race_expired', $remaining_ids, 'Unchanged expired record must be deleted.' );
	}

	public function test_cleanup_policy_delivery_history_batch_limits(): void {
		$wh_a = $this->create_test_webhook( 'https://example.com/batch-wh-a', true, 99981, 7 );
		$wh_b = $this->create_test_webhook( 'https://example.com/batch-wh-b', true, 99981, 7 );

		$time_now    = time();
		$t_minus_10d = gmdate( 'Y-m-d H:i:s', $time_now - ( 10 * 86400 ) );

		// 1. Separate small-limit (3) and negative-limit normalization to 1 checks:
		for ( $i = 1; $i <= 4; $i++ ) {
			$wh_target = 0 === ( $i % 2 ) ? $wh_a : $wh_b;
			$status    = 0 === ( $i % 3 ) ? 'failed' : 'delivered';
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
				('norm_batch_{$i}', '{$wh_target}', 'evt_norm_{$i}', 'link.created', '{$status}', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}')"
			);
		}

		$cleaned_small = $this->webhooks_service->cleanup_policy_delivery_history( 3 );
		$this->assertSame( 3, $cleaned_small );

		$cleaned_neg = $this->webhooks_service->cleanup_policy_delivery_history( -5 );
		$this->assertSame( 1, $cleaned_neg );

		$count_norm = (int) $this->db->get_var( "SELECT COUNT(*) FROM webhook_deliveries WHERE id LIKE 'norm_batch_%'" );
		$this->assertSame( 0, $count_norm );

		// 2. Comprehensive global default (500) and maximum cap (1,000) sequence across 2,005 records:
		// Insert 2,005 eligible records distributed across Webhook A (1,005) and Webhook B (1,000)
		$rows_batch = array();
		for ( $i = 1; $i <= 2005; $i++ ) {
			$wh_target    = $i <= 1005 ? $wh_a : $wh_b;
			$status       = 0 === ( $i % 2 ) ? 'delivered' : 'failed';
			$del_id       = 'bulk_lim_' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT );
			$rows_batch[] = "('{$del_id}', '{$wh_target}', 'evt_bl_{$i}', 'link.created', '{$status}', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}')";
		}

		foreach ( array_chunk( $rows_batch, 500 ) as $chunk ) {
			$values = implode( ',', $chunk );
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES {$values}"
			);
		}

		$initial_total = (int) $this->db->get_var( "SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id IN ('{$wh_a}', '{$wh_b}')" );
		$this->assertSame( 2005, $initial_total );

		// Step 1: Default limit deletes exactly 500 records across all webhooks (leaves 1,505)
		$deleted_default = $this->webhooks_service->cleanup_policy_delivery_history();
		$this->assertSame( 500, $deleted_default );
		$remaining_step1 = (int) $this->db->get_var( "SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id IN ('{$wh_a}', '{$wh_b}')" );
		$this->assertSame( 1505, $remaining_step1 );

		// Step 2: Maximum cap deletes exactly 1,000 records (5000 is capped at 1000, leaves 505)
		$deleted_max = $this->webhooks_service->cleanup_policy_delivery_history( 5000 );
		$this->assertSame( 1000, $deleted_max );
		$remaining_step2 = (int) $this->db->get_var( "SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id IN ('{$wh_a}', '{$wh_b}')" );
		$this->assertSame( 505, $remaining_step2 );

		// Step 3: Remaining batch deletes all remaining 505 records (requested 1000, leaves 0)
		$deleted_rem = $this->webhooks_service->cleanup_policy_delivery_history( 1000 );
		$this->assertSame( 505, $deleted_rem );
		$remaining_step3 = (int) $this->db->get_var( "SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id IN ('{$wh_a}', '{$wh_b}')" );
		$this->assertSame( 0, $remaining_step3 );

		// Step 4: Repeat cleanup on empty table returns 0
		$deleted_repeat = $this->webhooks_service->cleanup_policy_delivery_history();
		$this->assertSame( 0, $deleted_repeat );
	}

	public function test_cleanup_policy_delivery_history_preserves_terminal_with_null_completion(): void {
		$wh          = $this->create_test_webhook( 'https://example.com/wh-null-comp', true, 99981, 7 );
		$t_minus_10d = gmdate( 'Y-m-d H:i:s', time() - ( 10 * 86400 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_delivered_null_comp', '{$wh}', 'e_null_1', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', NULL, '{$t_minus_10d}', '{$t_minus_10d}', '{}'),
			('del_failed_null_comp', '{$wh}', 'e_null_2', 'link.created', 'failed', 3, 3, '{$t_minus_10d}', NULL, '{$t_minus_10d}', '{$t_minus_10d}', '{}')"
		);

		$cleaned = $this->webhooks_service->cleanup_policy_delivery_history();
		$this->assertSame( 0, $cleaned );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries' ),
			'id'
		);
		$this->assertContains( 'del_delivered_null_comp', $remaining_ids );
		$this->assertContains( 'del_failed_null_comp', $remaining_ids );
	}

	public function test_cleanup_policy_delivery_history_preserves_old_event_with_recent_completion(): void {
		$wh             = $this->create_test_webhook( 'https://example.com/wh-recent-comp', true, 99981, 7 );
		$time_now       = time();
		$t_created_60d  = gmdate( 'Y-m-d H:i:s', $time_now - ( 60 * 86400 ) );
		$t_completed_1d = gmdate( 'Y-m-d H:i:s', $time_now - ( 1 * 86400 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_old_event_recent_comp', '{$wh}', 'e_old_evt', 'link.created', 'delivered', 1, 3, '{$t_created_60d}', '{$t_completed_1d}', '{$t_created_60d}', '{$t_completed_1d}', '{}')"
		);

		$cleaned = $this->webhooks_service->cleanup_policy_delivery_history();
		$this->assertSame( 0, $cleaned );

		$current = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => 'del_old_event_recent_comp' ) );
		$this->assertNotNull( $current, 'Recent completion must survive even if created_at is older than retention.' );
	}

	public function test_cleanup_policy_delivery_history_strict_cutoff_boundary(): void {
		$wh = $this->create_test_webhook( 'https://example.com/wh-cutoff', true, 99981, 7 );

		$captured_now   = null;
		$delete_reached = false;

		$interceptor = new class( $this->connection, $this->pdo, $this->table_prefix, $wh, $captured_now, $delete_reached ) extends PeakURL_DB {
			private PDO $raw_pdo;
			private string $prefix;
			private string $wh_id;
			public ?string $captured_now;
			public bool $delete_reached;
			public string $exact_cutoff   = '';
			public string $one_sec_before = '';
			public string $one_sec_after  = '';
			public array $candidate_ids   = array();

			public function __construct( Connection $conn, PDO $pdo, string $prefix, string $wh_id, ?string &$captured_now, bool &$delete_reached ) {
				parent::__construct( $conn );
				$this->raw_pdo        = $pdo;
				$this->prefix         = $prefix;
				$this->wh_id          = $wh_id;
				$this->captured_now   = &$captured_now;
				$this->delete_reached = &$delete_reached;
			}

			public function get_results( string $sql, array $params = array() ): array {
				// Intercept the candidate SELECT: capture exact :now bound parameter
				if ( isset( $params['now'] ) && str_contains( $sql, 'SELECT d.id' ) ) {
					$this->captured_now   = (string) $params['now'];
					$cutoff_ts            = strtotime( $this->captured_now . ' UTC' ) - ( 7 * 86400 );
					$this->exact_cutoff   = gmdate( 'Y-m-d H:i:s', $cutoff_ts );
					$this->one_sec_before = gmdate( 'Y-m-d H:i:s', $cutoff_ts - 1 );
					$this->one_sec_after  = gmdate( 'Y-m-d H:i:s', $cutoff_ts + 1 );

					// Insert fixture rows derived strictly from the invocation's actual bound :now:
					// 1. One second before cutoff: strictly older than cutoff -> will be selected
					// 2. Exactly at the cutoff: not older than cutoff -> will NOT be selected
					// 3. One second after the cutoff: not older than cutoff -> will NOT be selected
					// 4. Candidate initially older (one second before), but will be changed to exact cutoff before DELETE
					$this->raw_pdo->exec(
						"INSERT INTO {$this->prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
						('del_sec_before', '{$this->wh_id}', 'e_before', 'link.created', 'delivered', 1, 3, '{$this->one_sec_before}', '{$this->one_sec_before}', '{$this->one_sec_before}', '{$this->one_sec_before}', '{}'),
						('del_exact_cutoff', '{$this->wh_id}', 'e_exact', 'link.created', 'delivered', 1, 3, '{$this->exact_cutoff}', '{$this->exact_cutoff}', '{$this->exact_cutoff}', '{$this->exact_cutoff}', '{}'),
						('del_sec_after', '{$this->wh_id}', 'e_after', 'link.created', 'delivered', 1, 3, '{$this->one_sec_after}', '{$this->one_sec_after}', '{$this->one_sec_after}', '{$this->one_sec_after}', '{}'),
						('del_race_to_cutoff', '{$this->wh_id}', 'e_race', 'link.created', 'delivered', 1, 3, '{$this->one_sec_before}', '{$this->one_sec_before}', '{$this->one_sec_before}', '{$this->one_sec_before}', '{}')"
					);

					$results             = parent::get_results( $sql, $params );
					$this->candidate_ids = array_column( $results, 'id' );
					return $results;
				}

				return parent::get_results( $sql, $params );
			}

			public function query( string $sql, array $params = array() ): int {
				if ( str_contains( $sql, 'DELETE d' ) && str_contains( $sql, 'webhook_deliveries d' ) ) {
					$this->delete_reached = true;

					if ( isset( $params['now'] ) && $params['now'] !== $this->captured_now ) {
						throw new \RuntimeException( 'DELETE :now parameter mismatch!' );
					}

					// Test strict comparison in DELETE independently of candidate selection:
					// del_race_to_cutoff was selected into candidates because it was initially older.
					// Now, immediately before the joined DELETE executes against MySQL,
					// change its completed_at to EXACTLY the cutoff:
					$this->raw_pdo->exec(
						"UPDATE {$this->prefix}webhook_deliveries SET completed_at = '{$this->exact_cutoff}' WHERE id = 'del_race_to_cutoff'"
					);
				}

				return parent::query( $sql, $params );
			}
		};

		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				return '93.184.216.34';
			}
		};

		$isolated_service = new WebhooksService(
			$interceptor,
			$validator,
			$this->auth_service,
			new Roles(),
			new Authorization( new Roles() ),
			$this->config,
			new Crypto( $this->config )
		);

		$cleaned = $isolated_service->cleanup_policy_delivery_history();

		$this->assertNotNull( $captured_now, 'Bound :now parameter must be captured.' );
		$this->assertTrue( $delete_reached, 'Final DELETE must execute.' );

		// Verify candidate selection predicate (< operator) directly on captured candidates:
		$this->assertContains( 'del_sec_before', $interceptor->candidate_ids, 'Candidate SELECT must include record strictly older than cutoff.' );
		$this->assertContains( 'del_race_to_cutoff', $interceptor->candidate_ids, 'Candidate SELECT must include record initially older than cutoff.' );
		$this->assertNotContains( 'del_exact_cutoff', $interceptor->candidate_ids, 'Candidate SELECT must exclude record exactly at cutoff (< operator).' );
		$this->assertNotContains( 'del_sec_after', $interceptor->candidate_ids, 'Candidate SELECT must exclude record after cutoff.' );

		// Exactly 1 row must be deleted: del_sec_before.
		// If candidate selection had used <=, del_exact_cutoff would have been selected into candidates.
		// If final DELETE had used <=, del_race_to_cutoff would have been deleted.
		$this->assertSame( 1, $cleaned );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $wh ) ),
			'id'
		);

		// Deleted:
		$this->assertNotContains( 'del_sec_before', $remaining_ids );

		// Preserved:
		$this->assertContains( 'del_exact_cutoff', $remaining_ids, 'Record exactly at cutoff must survive selection (strict < comparison).' );
		$this->assertContains( 'del_sec_after', $remaining_ids, 'Record after cutoff must survive selection.' );
		$this->assertContains( 'del_race_to_cutoff', $remaining_ids, 'Candidate changed to exact cutoff before DELETE must survive final DELETE predicate (strict < comparison).' );
	}

	public function test_cleanup_policy_delivery_history_90_day_policy(): void {
		$wh_90 = $this->create_test_webhook( 'https://example.com/wh-90', true, 99981, 90 );

		$time_now    = time();
		$t_minus_80d = gmdate( 'Y-m-d H:i:s', $time_now - ( 80 * 86400 ) );
		$t_minus_95d = gmdate( 'Y-m-d H:i:s', $time_now - ( 95 * 86400 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_wh90_survive', '{$wh_90}', 'e_90_s', 'link.created', 'delivered', 1, 3, '{$t_minus_80d}', '{$t_minus_80d}', '{$t_minus_80d}', '{$t_minus_80d}', '{}'),
			('del_wh90_delete', '{$wh_90}', 'e_90_d', 'link.created', 'delivered', 1, 3, '{$t_minus_95d}', '{$t_minus_95d}', '{$t_minus_95d}', '{$t_minus_95d}', '{}')"
		);

		$cleaned = $this->webhooks_service->cleanup_policy_delivery_history();
		$this->assertSame( 1, $cleaned );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries' ),
			'id'
		);
		$this->assertContains( 'del_wh90_survive', $remaining_ids );
		$this->assertNotContains( 'del_wh90_delete', $remaining_ids );
	}

	public function test_cleanup_delivery_history_explicit_uniform_retention_compatibility(): void {
		// Webhook has 90-day policy configured
		$wh = $this->create_test_webhook( 'https://example.com/wh-legacy', true, 99981, 90 );

		$time_now = time();
		// Completed 20 days ago: within 90-day policy, but older than uniform 14-day argument
		$t_minus_20d = gmdate( 'Y-m-d H:i:s', $time_now - ( 20 * 86400 ) );
		// Completed 10 days ago: within 14-day argument
		$t_minus_10d = gmdate( 'Y-m-d H:i:s', $time_now - ( 10 * 86400 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, status, attempts, max_attempts, next_attempt_at, completed_at, created_at, updated_at, payload) VALUES
			('del_legacy_old', '{$wh}', 'e_leg_1', 'link.created', 'delivered', 1, 3, '{$t_minus_20d}', '{$t_minus_20d}', '{$t_minus_20d}', '{$t_minus_20d}', '{}'),
			('del_legacy_fresh', '{$wh}', 'e_leg_2', 'link.created', 'delivered', 1, 3, '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{$t_minus_10d}', '{}')"
		);

		// Explicit legacy call with integer $retention_days = 14
		$cleaned = $this->webhooks_service->cleanup_delivery_history( 14, 500 );
		$this->assertSame( 1, $cleaned );

		$remaining_ids = array_column(
			$this->db->get_results( 'SELECT id FROM webhook_deliveries' ),
			'id'
		);
		$this->assertNotContains( 'del_legacy_old', $remaining_ids );
		$this->assertContains( 'del_legacy_fresh', $remaining_ids );
	}

	public function test_controller_delete_delivery_and_clear_deliveries_authorization(): void {
		$webhook_id  = $this->create_test_webhook();
		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => 'auth' ),
			0
		);

		$this->db->update(
			'webhook_deliveries',
			array(
				'status'       => 'delivered',
				'completed_at' => Date::now(),
			),
			array( 'id' => $delivery_id )
		);

		$controller = new WebhooksController( $this->webhooks_service );

		// 1. Editor user without manage_integrations cannot delete delivery -> 403
		$editor_request = $this->create_request_for_user(
			$this->editor_user,
			'DELETE',
			"/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}"
		);
		$editor_request->set_route_params(
			array(
				'id'          => $webhook_id,
				'delivery_id' => $delivery_id,
			)
		);

		try {
			$controller->delete_delivery( $editor_request );
			$this->fail( 'Expected ApiException 403 for unauthorized editor deleting delivery.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 2. Editor user cannot clear deliveries -> 403
		$editor_clear_req = $this->create_request_for_user(
			$this->editor_user,
			'DELETE',
			"/api/v1/webhooks/{$webhook_id}/deliveries"
		);
		$editor_clear_req->set_route_params( array( 'id' => $webhook_id ) );

		try {
			$controller->clear_deliveries( $editor_clear_req );
			$this->fail( 'Expected ApiException 403 for unauthorized editor clearing deliveries.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 3. Admin user can delete delivery -> 200 OK with { deleted: true }
		$admin_delete_req = $this->create_request_for_user(
			$this->admin_user,
			'DELETE',
			"/api/v1/webhooks/{$webhook_id}/deliveries/{$delivery_id}"
		);
		$admin_delete_req->set_route_params(
			array(
				'id'          => $webhook_id,
				'delivery_id' => $delivery_id,
			)
		);

		$delete_response = $controller->delete_delivery( $admin_delete_req );
		$this->assertSame( 200, $delete_response['status'] );
		$this->assertTrue( $delete_response['body']['success'] );
		$this->assertTrue( $delete_response['body']['data']['deleted'] );

		// 4. Admin user can clear deliveries -> 200 OK with { deletedCount: 0 }
		$admin_clear_req = $this->create_request_for_user(
			$this->admin_user,
			'DELETE',
			"/api/v1/webhooks/{$webhook_id}/deliveries"
		);
		$admin_clear_req->set_route_params( array( 'id' => $webhook_id ) );

		$clear_response = $controller->clear_deliveries( $admin_clear_req );
		$this->assertSame( 200, $clear_response['status'] );
		$this->assertTrue( $clear_response['body']['success'] );
		$this->assertSame( 0, $clear_response['body']['data']['deletedCount'] );
	}

	public function test_site_wide_integrations_management_and_multi_owner_isolation(): void {
		$now = Date::now();
		// 1. Seed user 99983 (Creator 2)
		$creator2_user = array(
			'id'                => 99983,
			'username'          => 'webhookcreator2',
			'email'             => 'creator2@example.com',
			'first_name'        => 'Creator',
			'last_name'         => 'Two',
			'display_name'      => 'Creator Two',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'editor',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (99983, 'webhookcreator2', 'creator2@example.com', 'Creator', 'Two', 'Creator Two', '{$creator2_user['password_hash']}', 'editor', 1, '{$now}', '{$now}')
			ON DUPLICATE KEY UPDATE username = VALUES(username), role = 'editor'"
		);

		// 2. Create webhooks for two different creators:
		// Webhook 1 owned by Admin (99981)
		$wh1_id = $this->create_test_webhook( 'https://example.com/c1-webhook', true, 99981, 30, 'peakurl_whsec_' . Str::random_id( 18 ) );
		// Webhook 2 owned by Creator 2 (99983)
		$wh2_id = $this->create_test_webhook( 'https://example.com/c2-webhook', true, 99983, 30, 'peakurl_whsec_' . Str::random_id( 18 ) );

		$controller = new WebhooksController( $this->webhooks_service );

		// 3. Editor without manage_integrations: rejected with 403 on all endpoints
		$editor_req = $this->create_request_for_user( $this->editor_user, 'GET', '/api/v1/webhooks' );
		try {
			$controller->index( $editor_req );
			$this->fail( 'Expected 403 when index called without manage_integrations' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$editor_update_req = $this->create_request_for_user( $this->editor_user, 'PUT', "/api/v1/webhooks/{$wh2_id}" );
		$editor_update_req->set_route_params( array( 'id' => $wh2_id ) );
		try {
			$controller->update( $editor_update_req );
			$this->fail( 'Expected 403 when update called without manage_integrations' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$editor_rotate_req = $this->create_request_for_user( $this->editor_user, 'POST', "/api/v1/webhooks/{$wh2_id}/rotate-secret" );
		$editor_rotate_req->set_route_params( array( 'id' => $wh2_id ) );
		try {
			$controller->rotate_secret( $editor_rotate_req );
			$this->fail( 'Expected 403 when rotate_secret called without manage_integrations' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$editor_delete_req = $this->create_request_for_user( $this->editor_user, 'DELETE', "/api/v1/webhooks/{$wh2_id}" );
		$editor_delete_req->set_route_params( array( 'id' => $wh2_id ) );
		try {
			$controller->delete( $editor_delete_req );
			$this->fail( 'Expected 403 when delete called without manage_integrations' );
		} catch ( ApiException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		// 4. Now delegate manage_integrations capability to editor role
		$delegated_caps = array(
			'editor' => array(
				'manage_integrations' => true,
				'view_links'          => true,
				'create_links'        => true,
				'edit_links'          => true,
				'trash_links'         => true,
				'view_analytics'      => true,
			),
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}settings (setting_key, setting_value, updated_at)
			VALUES ('" . \PeakURL\Core\Config\Constants::SETTING_ROLE_CAPABILITIES . "', '" . json_encode( $delegated_caps ) . "', NOW())
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
		);
		$this->roles->set_settings_api( new \PeakURL\Api\SettingsApi( $this->db ) );

		// 5. Delegated editor can list site-wide webhooks across owners
		$delegated_list_req = $this->create_request_for_user( $this->editor_user, 'GET', '/api/v1/webhooks' );
		$list_response      = $controller->index( $delegated_list_req );
		$this->assertSame( 200, $list_response['status'] );
		$items = $list_response['body']['data'];
		$this->assertIsArray( $items );
		$item_ids = array_column( $items, 'id' );
		$this->assertContains( $wh1_id, $item_ids, 'Delegated editor must see Admin webhook in site-wide list.' );
		$this->assertContains( $wh2_id, $item_ids, 'Delegated editor must see Creator 2 webhook in site-wide list.' );

		// Verify secrets remain masked on list response (no raw secrets exposed)
		foreach ( $items as $item ) {
			if ( in_array( $item['id'], array( $wh1_id, $wh2_id ), true ) ) {
				$this->assertArrayNotHasKey( 'secret', $item, 'List response must not disclose raw webhook signing secret.' );
				$this->assertArrayHasKey( 'secretHint', $item );
				$this->assertStringStartsWith( 'peakurl_whsec_', $item['secretHint'] );
				$this->assertStringContainsString( '••••', $item['secretHint'] );
			}
		}

		// 6. Delegated editor can update Creator 2's webhook without modifying original user_id
		$delegated_update_req = $this->create_request_for_user(
			$this->editor_user,
			'PUT',
			"/api/v1/webhooks/{$wh2_id}",
			array(
				'label' => 'Updated by Delegated Editor',
				'url'   => 'https://example.com/c2-updated',
			)
		);
		$delegated_update_req->set_route_params( array( 'id' => $wh2_id ) );
		$update_response = $controller->update( $delegated_update_req );
		$this->assertSame( 200, $update_response['status'] );
		$this->assertSame( 'Updated by Delegated Editor', $update_response['body']['data']['label'] );
		$this->assertSame( 'https://example.com/c2-updated', $update_response['body']['data']['url'] );
		$this->assertArrayNotHasKey( 'secret', $update_response['body']['data'], 'Update response must not disclose secret.' );

		// Database verify: user_id must remain 99983 (Creator 2)
		$stmt = $this->pdo->prepare( "SELECT user_id, label, url FROM {$this->table_prefix}webhooks WHERE id = :id" );
		$stmt->execute( array( 'id' => $wh2_id ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );
		$this->assertSame( 99983, (int) $row['user_id'], 'Webhook creator user_id must remain unchanged after update.' );
		$this->assertSame( 'Updated by Delegated Editor', $row['label'] );

		// 7. Delegated editor can rotate secret for Creator 2's webhook
		$delegated_rotate_req = $this->create_request_for_user( $this->editor_user, 'POST', "/api/v1/webhooks/{$wh2_id}/rotate-secret" );
		$delegated_rotate_req->set_route_params( array( 'id' => $wh2_id ) );
		$rotate_response = $controller->rotate_secret( $delegated_rotate_req );
		$this->assertSame( 200, $rotate_response['status'] );
		$this->assertArrayHasKey( 'secret', $rotate_response['body']['data'], 'Rotate secret must return one-time secret.' );
		$this->assertStringStartsWith( 'peakurl_whsec_', $rotate_response['body']['data']['secret'] );

		// Database verify: user_id must remain 99983 (Creator 2)
		$stmt->execute( array( 'id' => $wh2_id ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );
		$this->assertSame( 99983, (int) $row['user_id'], 'Webhook creator user_id must remain unchanged after secret rotation.' );

		// 8. Delegated editor can delete Creator 2's webhook
		$delegated_delete_req = $this->create_request_for_user( $this->editor_user, 'DELETE', "/api/v1/webhooks/{$wh2_id}" );
		$delegated_delete_req->set_route_params( array( 'id' => $wh2_id ) );
		$delete_response = $controller->delete( $delegated_delete_req );
		$this->assertSame( 200, $delete_response['status'] );

		$stmt->execute( array( 'id' => $wh2_id ) );
		$this->assertFalse( $stmt->fetch(), 'Webhook row must be deleted from database.' );
	}
}
