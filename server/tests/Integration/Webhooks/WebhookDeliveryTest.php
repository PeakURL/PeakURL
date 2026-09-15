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

		$this->webhooks_service = new WebhooksService(
			$this->db,
			new WebhooksValidator(),
			$auth_service,
			$roles,
			$authorization,
			$config
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
		$webhook_id = Str::random_id( 16 );
		$now        = Date::now();
		$active_val = $is_active ? 1 : 0;

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, url, secret, events, is_active, created_at, updated_at)
			VALUES ('{$webhook_id}', 1, '{$url}', 'test_sec_123', '[\"link.created\"]', {$active_val}, '{$now}', '{$now}')"
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
					'response'   => '{"ok":true}',
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

	public function test_delivery_failure_4xx_schedules_first_retry(): void {
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
					'response'   => 'Endpoint not found',
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
		$this->assertSame( 404, (int) $row['response_code'] );
		$this->assertStringContainsString( 'Not Found', (string) $row['last_error'] );
		$this->assertGreaterThan( time(), strtotime( (string) $row['next_attempt_at'] . ' UTC' ) );
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
					'response'   => '',
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
					'response'   => 'ok',
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
					'response'   => 'Server broke',
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
					'response'   => 'ok',
				);
			}
		);

		$result = $this->webhooks_service->process_pending_deliveries( 2 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['delivered'] );

		$row_3 = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $id_3 ), array( 'status' ) );
		$this->assertSame( 'pending', $row_3['status'] );
	}
}
