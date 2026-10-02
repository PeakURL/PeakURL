<?php
/**
 * Real Database and Route Integration Tests for Link Health Monitoring.
 *
 * @package PeakURL\Tests\Integration\Links
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Links;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Application;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Features\Links\Health\Probe;
use PeakURL\Features\Links\Health\Resolver;
use PeakURL\Features\Links\Jobs\LinkHealthCheckJob;
use PeakURL\Http\Request;
use PeakURL\Http\Router;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;
use PDO;

class LinkHealthMonitoringIntegrationTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private Application $app;
	private Router $router;
	private string $table_prefix;
	private string $admin_token = 'test_health_admin_token_12345';
	private string $test_prefix;

	protected function setUp(): void {
		parent::setUp();

		\add_filter( 'site_url', static fn() => 'https://peakurl.dev' );

		$config             = Configuration::get_current();
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->db           = new PeakURL_DB( $this->connection );
		$this->table_prefix = $this->connection->get_table_prefix();

		$this->test_prefix = 'test-hlth-' . bin2hex( random_bytes( 4 ) ) . '-';

		// Ensure admin user (id: 1) exists in peakurl_users.
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (1, 'admin_hlth', 'admin_hlth@example.com', 'Admin', 'User', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW(), NOW())
			ON DUPLICATE KEY UPDATE role = 'admin'"
		);

		// Seed API key for admin.
		$admin_key_hash = hash( 'sha256', $this->admin_token );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}api_keys WHERE id = 'test_health_admin_key'" );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}api_keys (id, user_id, label, key_hash, key_prefix, key_last_four, created_at)
			VALUES ('test_health_admin_key', 1, 'Health Admin Key', '{$admin_key_hash}', 'test_hlth', '2345', NOW())"
		);

		$this->app    = new Application( $this->connection, $config, $this->create_checker() );
		$this->router = $this->app->get_router();
	}

	protected function tearDown(): void {
		if ( isset( $this->pdo ) && ! empty( $this->test_prefix ) ) {
			$stmt = $this->pdo->prepare( "DELETE FROM {$this->table_prefix}urls WHERE short_code LIKE :prefix1 OR alias LIKE :prefix2" );
			$stmt->execute(
				array(
					'prefix1' => $this->test_prefix . '%',
					'prefix2' => $this->test_prefix . '%',
				)
			);
			$this->pdo->exec( "DELETE FROM {$this->table_prefix}api_keys WHERE id = 'test_health_admin_key'" );
		}

		\remove_all_filters( 'site_url' );
		parent::tearDown();
	}

	private function admin_request( string $method, string $path, array $query = array(), array $body = array() ): Request {
		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer ' . $this->admin_token,
			'HTTP_ORIGIN'        => 'https://peakurl.dev',
		);
		return new Request( $method, $path, $query, $body, array(), array(), $headers );
	}

	private function unauthenticated_request( string $method, string $path ): Request {
		$headers = array(
			'HTTP_ORIGIN' => 'https://peakurl.dev',
		);
		return new Request( $method, $path, array(), array(), array(), array(), $headers );
	}

	private function create_checker(
		?callable $prober = null,
		?callable $dns = null,
		float $timeout = 3.0,
		int $max_redirects = 5,
		int $slow_threshold_ms = 1500
	): Checker {
		$resolver = new Resolver( $dns );
		$probe    = new Probe( $prober );
		return new Checker( $resolver, $probe, $timeout, $max_redirects, $slow_threshold_ms );
	}

	private function create_app_with_checker( Checker $checker ): Application {
		$config = Configuration::get_current();
		return new Application( $this->connection, $config, $checker );
	}

	private function create_router_with_checker( Checker $checker ): Router {
		return $this->create_app_with_checker( $checker )->get_router();
	}

	private function dispatch( Request $request, ?Router $router = null ): array {
		try {
			$r        = $router ?? $this->router;
			$response = $r->dispatch( $request );
			if ( isset( $response['body'] ) && is_array( $response['body'] ) ) {
				$body = $response['body'];
				if ( ! isset( $body['status'] ) && isset( $response['status'] ) ) {
					$body['status'] = $response['status'];
				}
				return $body;
			}
			return $response;
		} catch ( ApiException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
				'status'  => $e->get_status(),
			);
		}
	}

	public function test_manual_health_check_endpoint_checks_and_persists_health(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'chk1';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Health Target', 'http://127.0.0.1:8080/test', 'active', '{$now}', '{$now}')"
		);

		$request = $this->admin_request( 'POST', "/api/v1/urls/{$link_id}/health-check" );
		$data    = $this->dispatch( $request );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Health check completed.', $data['message'] );

		$health = $data['data'];
		$this->assertIsArray( $health );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $health['status'] );
		$this->assertNotNull( $health['checkedAt'] );
		$this->assertNull( $health['responseCode'] );
		$this->assertSame( 0, $health['redirectCount'] );
		$this->assertArrayNotHasKey( 'failureClass', $health );
		$this->assertArrayNotHasKey( 'urlId', $health );

		// Verify row in link_health table was persisted.
		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_SSRF_BLOCKED, $health_row['status'] );
		$this->assertArrayNotHasKey( 'failure_class', $health_row );

		// Verify URLs table was NOT modified (strictly observational).
		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertNotNull( $url_row );
		$this->assertSame( 'active', $url_row['status'] );
		$this->assertSame( 'http://127.0.0.1:8080/test', $url_row['destination_url'] );
	}

	public function test_manual_health_check_requires_authorization(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'auth1';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Auth Target', 'https://example.com/target', 'active', '{$now}', '{$now}')"
		);

		$request = $this->unauthenticated_request( 'POST', "/api/v1/urls/{$link_id}/health-check" );
		$data    = $this->dispatch( $request );

		$this->assertFalse( $data['success'] );
		$this->assertSame( 401, $data['status'] );
	}

	public function test_manual_health_check_returns_404_for_nonexistent_link(): void {
		$nonexistent_id = 'nonexistent_id_999';
		$request        = $this->admin_request( 'POST', "/api/v1/urls/{$nonexistent_id}/health-check" );
		$data           = $this->dispatch( $request );

		$this->assertFalse( $data['success'] );
		$this->assertSame( 404, $data['status'] );
	}

	public function test_get_urls_exposes_health_object_and_handles_never_checked_neutrally(): void {
		$now        = Date::now();
		$link_id_ok = Str::random_id( 16 );
		$link_id_no = Str::random_id( 16 );
		$code_ok    = $this->test_prefix . 'lst_ok';
		$code_no    = $this->test_prefix . 'lst_none';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id_ok}', 1, '{$code_ok}', '{$code_ok}', 'Checked Link', 'https://example.com/ok', 'active', '{$now}', '{$now}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id_no}', 1, '{$code_no}', '{$code_no}', 'Never Checked Link', 'https://example.com/no', 'active', '{$now}', '{$now}')"
		);

		// Insert health snapshot only for link_id_ok.
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id_ok}', 'healthy', '{$now}', 200, 150, NULL, 0, '{$now}', '{$now}')"
		);

		$request = $this->admin_request( 'GET', '/api/v1/urls', array( 'search' => $this->test_prefix . 'lst' ) );
		$data    = $this->dispatch( $request );

		$this->assertTrue( $data['success'] );
		$items = $data['data']['items'];
		$this->assertNotEmpty( $items );

		$items_by_id = array();
		foreach ( $items as $item ) {
			$items_by_id[ $item['id'] ] = $item;
		}

		$this->assertArrayHasKey( $link_id_ok, $items_by_id );
		$this->assertArrayHasKey( $link_id_no, $items_by_id );

		// Checked link has populated health object without failureClass.
		$this->assertNotNull( $items_by_id[ $link_id_ok ]['health'] );
		$this->assertSame( 'healthy', $items_by_id[ $link_id_ok ]['health']['status'] );
		$this->assertSame( 200, $items_by_id[ $link_id_ok ]['health']['responseCode'] );
		$this->assertSame( 150, $items_by_id[ $link_id_ok ]['health']['responseTimeMs'] );
		$this->assertArrayNotHasKey( 'failureClass', $items_by_id[ $link_id_ok ]['health'] );

		// Never-checked link is neutrally null, never defaulting to healthy.
		$this->assertNull( $items_by_id[ $link_id_no ]['health'] );
	}

	public function test_scheduled_job_rotates_coverage_with_bounded_batch_selection(): void {
		$time_now    = time();
		$now_dt      = gmdate( 'Y-m-d H:i:s', $time_now );
		$oldest_dt   = gmdate( 'Y-m-d H:i:s', $time_now - 10800 ); // 3 hours ago.
		$recent_dt   = gmdate( 'Y-m-d H:i:s', $time_now - 3600 );  // 1 hour ago.
		$newest_dt   = gmdate( 'Y-m-d H:i:s', $time_now - 300 );   // 5 minutes ago.
		$fixed_order = '2026-01-01 00:00:00';

		$id_never  = $this->test_prefix . 'rot_never';
		$id_oldest = $this->test_prefix . 'rot_oldest';
		$id_recent = $this->test_prefix . 'rot_recent';
		$id_newest = $this->test_prefix . 'rot_newest';

		$paused_ids = $this->pdo->query( "SELECT id FROM {$this->table_prefix}urls WHERE status = 'active'" )->fetchAll( \PDO::FETCH_COLUMN );
		if ( ! empty( $paused_ids ) ) {
			$this->pdo->exec( "UPDATE {$this->table_prefix}urls SET status = 'paused' WHERE status = 'active'" );
		}

		try {
			// Seed 4 links with deterministic created/updated order.
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
				VALUES
				('{$id_never}', 1, '{$id_never}', '{$id_never}', 'Never Checked', 'https://93.184.216.34/1', 'active', '{$fixed_order}', '{$fixed_order}'),
				('{$id_oldest}', 1, '{$id_oldest}', '{$id_oldest}', 'Oldest Checked', 'https://93.184.216.34/2', 'active', '{$fixed_order}', '{$fixed_order}'),
				('{$id_recent}', 1, '{$id_recent}', '{$id_recent}', 'Recent Checked', 'https://93.184.216.34/3', 'active', '{$fixed_order}', '{$fixed_order}'),
				('{$id_newest}', 1, '{$id_newest}', '{$id_newest}', 'Newest Checked', 'https://93.184.216.34/4', 'active', '{$fixed_order}', '{$fixed_order}')"
			);

			// Link A: never checked (no row in link_health).
			// Link B: oldest checked.
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
				VALUES ('{$id_oldest}', 'healthy', '{$oldest_dt}', 200, 100, NULL, 0, '{$oldest_dt}', '{$oldest_dt}')"
			);
			// Link C: recent checked.
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
				VALUES ('{$id_recent}', 'healthy', '{$recent_dt}', 200, 100, NULL, 0, '{$recent_dt}', '{$recent_dt}')"
			);
			// Link D: newest checked.
			$this->pdo->exec(
				"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
				VALUES ('{$id_newest}', 'healthy', '{$newest_dt}', 200, 100, NULL, 0, '{$newest_dt}', '{$newest_dt}')"
			);

			$checked_urls = array();
			$mock_checker = $this->create_checker(
				static function ( string $url ) use ( &$checked_urls ): array {
					$checked_urls[] = $url;
					return array(
						'response_code' => 200,
						'duration_ms'   => 50,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				},
				null,
				1.0,
				5,
				1500
			);

			// RUN 1 with batch_limit = 2.
			// Priority: never checked (Link A) > oldest checked (Link B).
			$job_run_1 = new LinkHealthCheckJob( $this->db, $mock_checker, 2 );
			$context_1 = new ExecutionContext( 'peakurl_link_health_check', 'run_batch_1', 1, false, $now_dt );
			$result_1  = $job_run_1->execute( $context_1 );

			$this->assertTrue( $result_1->is_success() );
			$this->assertSame( 2, count( $checked_urls ) );
			$this->assertContains( 'https://93.184.216.34/1', $checked_urls ); // Link A
			$this->assertContains( 'https://93.184.216.34/2', $checked_urls ); // Link B

			// Verify Link A now has a health record.
			$never_health_after_run1 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_never ) );
			$this->assertNotNull( $never_health_after_run1 );

			// Verify Link B received an updated checked_at.
			$oldest_health_after_run1 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_oldest ) );
			$this->assertNotNull( $oldest_health_after_run1 );
			$this->assertGreaterThan( $oldest_dt, $oldest_health_after_run1['checked_at'] );

			// Verify Link C and Link D were NOT checked in run 1.
			$recent_health_after_run1 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_recent ) );
			$this->assertSame( $recent_dt, $recent_health_after_run1['checked_at'] );
			$newest_health_after_run1 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_newest ) );
			$this->assertSame( $newest_dt, $newest_health_after_run1['checked_at'] );

			// RUN 2 with batch_limit = 2.
			// Priority rotates to next oldest: Link C (1h ago) > Link D (5m ago).
			$checked_urls = array();
			$context_2    = new ExecutionContext( 'peakurl_link_health_check', 'run_batch_2', 1, false, $now_dt );
			$result_2     = $job_run_1->execute( $context_2 );

			$this->assertTrue( $result_2->is_success() );
			$this->assertSame( 2, count( $checked_urls ) );
			$this->assertContains( 'https://93.184.216.34/3', $checked_urls ); // Link C
			$this->assertContains( 'https://93.184.216.34/4', $checked_urls ); // Link D

			// Verify Link C and Link D now have updated checked_at.
			$recent_health_after_run2 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_recent ) );
			$this->assertGreaterThan( $recent_dt, $recent_health_after_run2['checked_at'] );
			$newest_health_after_run2 = $this->db->get_row_by( 'link_health', array( 'link_id' => $id_newest ) );
			$this->assertGreaterThan( $newest_dt, $newest_health_after_run2['checked_at'] );
		} finally {
			if ( ! empty( $paused_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $paused_ids ), '?' ) );
				$stmt         = $this->pdo->prepare( "UPDATE {$this->table_prefix}urls SET status = 'active' WHERE id IN ({$placeholders})" );
				$stmt->execute( $paused_ids );
			}
		}
	}

	public function test_cascade_delete_removes_link_health_record(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'casc1';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Cascade Target', 'https://example.com/target', 'active', '{$now}', '{$now}')"
		);

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id}', 'healthy', '{$now}', 200, 150, NULL, 0, '{$now}', '{$now}')"
		);

		// Verify health record exists.
		$health_before = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_before );

		// Delete parent url row.
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE id = '{$link_id}'" );

		// Verify health record was cascaded and removed.
		$health_after = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_after );
	}

	public function test_create_url_with_healthy_destination_succeeds_and_stores_health(): void {
		$code       = $this->test_prefix . 'crt_ok';
		$dest       = 'https://93.184.216.34/healthy-dest';
		$call_count = 0;
		$checker    = $this->create_checker(
			static function () use ( &$call_count ): array {
				$call_count++;
				return array(
					'response_code' => 200,
					'duration_ms'   => 120,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);
		$app        = $this->create_app_with_checker( $checker );
		$router     = $app->get_router();

		$request = $this->admin_request(
			'POST',
			'/api/v1/urls',
			array(),
			array(
				'destinationUrl' => $dest,
				'title'          => 'Healthy Link',
				'customAlias'    => $code,
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 201, $data['status'] );
		$link_id = $data['data']['id'];

		// Asynchronous post-create: inline health is null, no sync probe executed.
		$this->assertNull( $data['data']['health'] );
		$this->assertSame( 0, $call_count, 'Creation must not invoke synchronous probe.' );

		// Targeted health check job is enqueued in scheduler.
		$scheduler = $app->get_scheduler();
		$job_row   = $scheduler->get_persisted_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertNotNull( $job_row, 'Targeted job must be persisted in cron_jobs.' );
		$this->assertSame( 0, (int) $job_row['schedule_interval'] );
		$this->assertSame( 1, (int) $job_row['is_enabled'] );
		$this->assertNotEmpty( $job_row['next_run_at'] );

		// Execute the targeted job.
		$result = $scheduler->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $call_count, 'Scheduler execution invokes probe.' );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_row['status'] );
		$this->assertSame( 200, (int) $health_row['response_code'] );
	}

	public function test_create_url_persists_link_with_null_health_and_background_worker_classifies_definitive_failures(): void {
		$scenarios = array(
			'ssrf'          => array(
				'url'             => 'http://127.0.0.1:8080/blocked',
				'response'        => array(),
				'expected_status' => Checker::STATUS_SSRF_BLOCKED,
			),
			'dns_error'     => array(
				'url'             => 'https://93.184.216.34/dns-fail',
				'response'        => array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 6,
					'error_message' => 'Could not resolve host',
					'redirect_url'  => null,
				),
				'expected_status' => Checker::STATUS_DNS_ERROR,
			),
			'timeout'       => array(
				'url'             => 'https://93.184.216.34/timed-out',
				'response'        => array(
					'response_code' => null,
					'duration_ms'   => 3000,
					'error_code'    => 28,
					'error_message' => 'Operation timed out',
					'redirect_url'  => null,
				),
				'expected_status' => Checker::STATUS_TIMEOUT,
			),
			'tls_error'     => array(
				'url'             => 'https://93.184.216.34/tls-broken',
				'response'        => array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 35,
					'error_message' => 'SSL connect error',
					'redirect_url'  => null,
				),
				'expected_status' => Checker::STATUS_TLS_ERROR,
			),
			'unreachable'   => array(
				'url'             => 'https://93.184.216.34/unreachable',
				'response'        => array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 7,
					'error_message' => 'Failed to connect',
					'redirect_url'  => null,
				),
				'expected_status' => Checker::STATUS_UNREACHABLE,
			),
			'redirect_loop' => array(
				'url'             => 'https://93.184.216.34/loop',
				'response'        => array(
					'response_code' => 302,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => 'https://93.184.216.34/loop',
				),
				'expected_status' => Checker::STATUS_REDIRECT_LOOP,
			),
		);

		foreach ( $scenarios as $type => $case ) {
			$call_count = 0;
			$prober     = ! empty( $case['response'] ) ? static function () use ( &$call_count, $case ): array {
				++$call_count;
				return $case['response'];
			} : null;
			$checker    = $this->create_checker( $prober );
			$app        = $this->create_app_with_checker( $checker );
			$router     = $app->get_router();
			$code       = $this->test_prefix . 'rej_' . $type;

			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => $case['url'],
					'title'          => 'Link ' . $type,
					'customAlias'    => $code,
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertTrue( $data['success'], "Creation must succeed without synchronous block for {$type}" );
			$this->assertSame( 201, $data['status'] );
			$this->assertNull( $data['data']['health'] );
			$this->assertSame( 0, $call_count, "Creation must not invoke synchronous probe for {$type}" );

			$link_id = $data['data']['id'];
			$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
			$this->assertNotNull( $url_row, "URL row must exist for {$type}" );
			$this->assertSame( 'active', $url_row['status'] );

			// Targeted job is enqueued in scheduler.
			$scheduler = $app->get_scheduler();
			$job_row   = $scheduler->get_persisted_job( "peakurl_link_health_check:{$link_id}" );
			$this->assertNotNull( $job_row, "Targeted job row must exist in cron_jobs for {$type}" );
			$this->assertSame( 0, (int) $job_row['schedule_interval'] );
			$this->assertSame( 1, (int) $job_row['is_enabled'] );

			// Background job runs and records the definitive failure classification in link_health.
			$result = $scheduler->run_job( "peakurl_link_health_check:{$link_id}" );
			$this->assertTrue( $result->is_success() );

			$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
			$this->assertNotNull( $health_row, "Health row must be recorded in background for {$type}" );
			$this->assertSame( $case['expected_status'], $health_row['status'] );
		}
	}

	public function test_create_url_with_404_and_410_destination_succeeds_and_records_health_observation(): void {
		$scenarios = array(
			'not_found_404' => array(
				'code' => 404,
				'dest' => 'https://93.184.216.34/not-found-page',
			),
			'gone_410'      => array(
				'code' => 410,
				'dest' => 'https://93.184.216.34/permanently-gone',
			),
		);

		foreach ( $scenarios as $scenario ) {
			$alias   = $this->test_prefix . 'obs_' . $scenario['code'];
			$checker = $this->create_checker(
				static fn() => array(
					'response_code' => $scenario['code'],
					'duration_ms'   => 85,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$app     = $this->create_app_with_checker( $checker );
			$router  = $app->get_router();

			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => $scenario['dest'],
					'title'          => 'HTTP ' . $scenario['code'] . ' Link',
					'customAlias'    => $alias,
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertTrue( $data['success'], "Creation should succeed for {$scenario['code']}" );
			$this->assertSame( 201, $data['status'] );
			$link_id = $data['data']['id'];

			$this->assertNull( $data['data']['health'] );

			$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
			$this->assertNotNull( $url_row, "URL row must exist in database for {$scenario['code']}" );
			$this->assertSame( $scenario['dest'], $url_row['destination_url'] );

			// Run targeted background check.
			$result = $app->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
			$this->assertTrue( $result->is_success() );

			$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
			$this->assertNotNull( $health_row, "link_health row must exist in database for {$scenario['code']}" );
			$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_row['status'] );
			$this->assertSame( $scenario['code'], (int) $health_row['response_code'] );
		}
	}

	public function test_create_url_with_various_http_error_statuses_saves_and_records_health(): void {
		$codes = array( 401, 403, 405, 429, 500, 503 );

		foreach ( $codes as $code ) {
			$alias   = $this->test_prefix . 'http_' . $code;
			$dest    = "https://93.184.216.34/code-{$code}";
			$checker = $this->create_checker(
				static fn() => array(
					'response_code' => $code,
					'duration_ms'   => 95,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$app     = $this->create_app_with_checker( $checker );
			$router  = $app->get_router();

			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => $dest,
					'title'          => "HTTP {$code} Link",
					'customAlias'    => $alias,
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertTrue( $data['success'], "Creation should succeed for HTTP {$code}" );
			$this->assertSame( 201, $data['status'] );
			$link_id = $data['data']['id'];

			$this->assertNull( $data['data']['health'] );

			// Run background check.
			$result = $app->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
			$this->assertTrue( $result->is_success() );

			$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
			$this->assertNotNull( $health_row );
			$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_row['status'] );
			$this->assertSame( $code, (int) $health_row['response_code'] );
		}
	}

	/**
	 * Regression test for SPA endpoints returning 404 to HEAD probe (e.g. n8n workflow).
	 *
	 * Method-aware probe falls back to GET, recovering healthy status without blocking user creation.
	 */
	public function test_workflow_destination_with_head_404_and_get_200_records_healthy_via_method_aware_probe(): void {
		$dest   = 'https://n8n.techsysforge.com/projects/pwtPCtqmMGFz0T4W/folders/fmp5BcZUgySDEVCQ/workflows';
		$code   = $this->test_prefix . 'n8n_wf';
		$prober = static function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ): array {
			if ( 'HEAD' === $method ) {
				return array(
					'response_code' => 404,
					'duration_ms'   => 80,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
			// GET fallback returns 200 OK.
			return array(
				'response_code' => 200,
				'duration_ms'   => 120,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			);
		};
		$dns    = static fn( string $host ) => array( '93.184.216.34' );
		$app    = $this->create_app_with_checker( $this->create_checker( $prober, $dns ) );
		$router = $app->get_router();

		$request = $this->admin_request(
			'POST',
			'/api/v1/urls',
			array(),
			array(
				'destinationUrl' => $dest,
				'title'          => 'n8n Workflow Link',
				'customAlias'    => $code,
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'], 'Creating link to workflow endpoint must succeed.' );
		$this->assertSame( 201, $data['status'] );
		$link_id = $data['data']['id'];

		$this->assertNull( $data['data']['health'] );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertNotNull( $url_row );
		$this->assertSame( $dest, $url_row['destination_url'] );

		// Run targeted background check.
		$result = $app->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result->is_success() );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_row['status'], 'n8n SPA route must recover to healthy via GET fallback.' );
		$this->assertSame( 200, (int) $health_row['response_code'] );
	}

	public function test_bulk_create_succeeds_without_synchronous_probes_and_schedules_targeted_checks(): void {
		$code_ok  = $this->test_prefix . 'blkok';
		$code_bad = $this->test_prefix . 'blkbad';

		$call_count = 0;
		$checker    = $this->create_checker(
			static function ( string $url ) use ( &$call_count ): array {
				$call_count++;
				if ( str_contains( $url, 'bad' ) ) {
					return array(
						'response_code' => null,
						'duration_ms'   => 50,
						'error_code'    => 7,
						'error_message' => 'Failed to connect',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);
		$app        = $this->create_app_with_checker( $checker );
		$router     = $app->get_router();

		$items = array(
			array(
				'destinationUrl' => 'https://93.184.216.34/good',
				'title'          => 'Bulk Good',
				'alias'          => $code_ok,
			),
			array(
				'destinationUrl' => 'https://93.184.216.34/bad',
				'title'          => 'Bulk Bad',
				'alias'          => $code_bad,
			),
		);

		$request = $this->admin_request( 'POST', '/api/v1/urls/bulk', array(), array( 'urls' => $items ) );
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['data']['results'] );
		$this->assertCount( 0, $data['data']['errors'] );
		$this->assertSame( 0, $call_count, 'Bulk create must not perform synchronous outbound probes.' );

		$ok_row = $this->db->get_row_by( 'urls', array( 'alias' => $code_ok ) );
		$this->assertNotNull( $ok_row );
		$bad_row = $this->db->get_row_by( 'urls', array( 'alias' => $code_bad ) );
		$this->assertNotNull( $bad_row );

		// Both items have jobs enqueued in scheduler.
		$scheduler  = $app->get_scheduler();
		$job_row_ok = $scheduler->get_persisted_job( "peakurl_link_health_check:{$ok_row['id']}" );
		$this->assertNotNull( $job_row_ok );
		$this->assertSame( 1, (int) $job_row_ok['is_enabled'] );

		$job_row_bad = $scheduler->get_persisted_job( "peakurl_link_health_check:{$bad_row['id']}" );
		$this->assertNotNull( $job_row_bad );
		$this->assertSame( 1, (int) $job_row_bad['is_enabled'] );

		// Run jobs.
		$scheduler->run_job( "peakurl_link_health_check:{$ok_row['id']}" );
		$scheduler->run_job( "peakurl_link_health_check:{$bad_row['id']}" );

		$ok_health = $this->db->get_row_by( 'link_health', array( 'link_id' => $ok_row['id'] ) );
		$this->assertNotNull( $ok_health );
		$this->assertSame( Checker::STATUS_HEALTHY, $ok_health['status'] );

		$bad_health = $this->db->get_row_by( 'link_health', array( 'link_id' => $bad_row['id'] ) );
		$this->assertNotNull( $bad_health );
		$this->assertSame( Checker::STATUS_UNREACHABLE, $bad_health['status'] );
	}

	public function test_bulk_create_allows_404_and_410_items_and_records_health_observations(): void {
		$code_404 = $this->test_prefix . 'blk404';
		$code_410 = $this->test_prefix . 'blk410';

		$checker = $this->create_checker(
			static function ( string $url ): array {
				if ( str_contains( $url, 'missing' ) ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 60,
						'error_code'    => 0,
						'error_message' => '',
						'redirect_url'  => null,
					);
				}
				return array(
					'response_code' => 410,
					'duration_ms'   => 70,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);
		$app     = $this->create_app_with_checker( $checker );
		$router  = $app->get_router();

		$items = array(
			array(
				'destinationUrl' => 'https://93.184.216.34/missing',
				'title'          => 'Bulk 404',
				'alias'          => $code_404,
			),
			array(
				'destinationUrl' => 'https://93.184.216.34/gone',
				'title'          => 'Bulk 410',
				'alias'          => $code_410,
			),
		);

		$request = $this->admin_request( 'POST', '/api/v1/urls/bulk', array(), array( 'urls' => $items ) );
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['data']['results'] );
		$this->assertCount( 0, $data['data']['errors'] );

		$row_404 = $this->db->get_row_by( 'urls', array( 'alias' => $code_404 ) );
		$this->assertNotNull( $row_404 );
		$row_410 = $this->db->get_row_by( 'urls', array( 'alias' => $code_410 ) );
		$this->assertNotNull( $row_410 );

		// Run background jobs for both.
		$scheduler = $app->get_scheduler();
		$scheduler->run_job( "peakurl_link_health_check:{$row_404['id']}" );
		$scheduler->run_job( "peakurl_link_health_check:{$row_410['id']}" );

		$health_404 = $this->db->get_row_by( 'link_health', array( 'link_id' => $row_404['id'] ) );
		$this->assertNotNull( $health_404 );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_404['status'] );
		$this->assertSame( 404, (int) $health_404['response_code'] );

		$health_410 = $this->db->get_row_by( 'link_health', array( 'link_id' => $row_410['id'] ) );
		$this->assertNotNull( $health_410 );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_410['status'] );
		$this->assertSame( 410, (int) $health_410['response_code'] );
	}

	public function test_update_url_with_healthy_changed_destination_replaces_health(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_ok';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Initial Link', 'https://93.184.216.34/initial', 'active', '{$now}', '{$now}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id}', 'slow', '{$now}', 200, 2500, NULL, 0, '{$now}', '{$now}')"
		);

		$checker = $this->create_checker(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 100,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$app     = $this->create_app_with_checker( $checker );
		$router  = $app->get_router();

		$request = $this->admin_request(
			'PUT',
			"/api/v1/urls/{$link_id}",
			array(),
			array(
				'destinationUrl' => 'https://93.184.216.34/new-target',
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertNull( $data['data']['health'], 'Update clears previous health and returns null inline.' );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertSame( 'https://93.184.216.34/new-target', $url_row['destination_url'] );

		// Prior health snapshot must be invalidated/cleared from link_health.
		$health_before_job = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_before_job, 'Previous health snapshot must be removed on destination change.' );

		// Run the targeted background job.
		$result = $app->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result->is_success() );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_row['status'] );
		$this->assertSame( 100, (int) $health_row['response_time_ms'] );
	}

	public function test_update_url_with_unreachable_destination_succeeds_clears_health_and_records_unreachable_in_background(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_rej';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Keep Link', 'https://93.184.216.34/keep', 'active', '{$now}', '{$now}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id}', 'healthy', '{$now}', 200, 100, NULL, 0, '{$now}', '{$now}')"
		);

		$checker = $this->create_checker(
			static fn() => array(
				'response_code' => null,
				'duration_ms'   => 50,
				'error_code'    => 7,
				'error_message' => 'Failed to connect',
				'redirect_url'  => null,
			)
		);
		$app     = $this->create_app_with_checker( $checker );
		$router  = $app->get_router();

		$request = $this->admin_request(
			'PUT',
			"/api/v1/urls/{$link_id}",
			array(),
			array(
				'destinationUrl' => 'https://93.184.216.34/broken',
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'], 'Updating destination must succeed without synchronous failure.' );
		$this->assertNull( $data['data']['health'] );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertSame( 'https://93.184.216.34/broken', $url_row['destination_url'] );

		// Prior health snapshot must be cleared.
		$health_before = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_before );

		// Run targeted background job.
		$result = $app->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result->is_success() );

		$health_after = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_after );
		$this->assertSame( Checker::STATUS_UNREACHABLE, $health_after['status'] );
	}

	public function test_update_url_with_404_and_410_destination_succeeds_and_replaces_health(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_404';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Original Link', 'https://93.184.216.34/initial', 'active', '{$now}', '{$now}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id}', 'healthy', '{$now}', 200, 100, NULL, 0, '{$now}', '{$now}')"
		);

		// Update to 404 destination
		$checker_404 = $this->create_checker(
			static fn() => array(
				'response_code' => 404,
				'duration_ms'   => 75,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$app_404     = $this->create_app_with_checker( $checker_404 );
		$router_404  = $app_404->get_router();

		$request_404 = $this->admin_request(
			'PUT',
			"/api/v1/urls/{$link_id}",
			array(),
			array(
				'destinationUrl' => 'https://93.184.216.34/now-missing',
			)
		);
		$data_404    = $this->dispatch( $request_404, $router_404 );

		$this->assertTrue( $data_404['success'], 'Update to 404 destination must succeed.' );
		$this->assertSame( 'https://93.184.216.34/now-missing', $data_404['data']['destinationUrl'] );
		$this->assertNull( $data_404['data']['health'], 'Update returns null health inline.' );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertSame( 'https://93.184.216.34/now-missing', $url_row['destination_url'] );

		// Run background check for 404.
		$result_404 = $app_404->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result_404->is_success() );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_row['status'] );
		$this->assertSame( 404, (int) $health_row['response_code'] );

		// Update again to 410 destination
		$checker_410 = $this->create_checker(
			static fn() => array(
				'response_code' => 410,
				'duration_ms'   => 80,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$app_410     = $this->create_app_with_checker( $checker_410 );
		$router_410  = $app_410->get_router();

		$request_410 = $this->admin_request(
			'PUT',
			"/api/v1/urls/{$link_id}",
			array(),
			array(
				'destinationUrl' => 'https://93.184.216.34/now-gone',
			)
		);
		$data_410    = $this->dispatch( $request_410, $router_410 );

		$this->assertTrue( $data_410['success'], 'Update to 410 destination must succeed.' );
		$this->assertSame( 'https://93.184.216.34/now-gone', $data_410['data']['destinationUrl'] );
		$this->assertNull( $data_410['data']['health'], 'Update returns null health inline.' );

		// Run background check for 410.
		$result_410 = $app_410->get_scheduler()->run_job( "peakurl_link_health_check:{$link_id}" );
		$this->assertTrue( $result_410->is_success() );

		$health_row_410 = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row_410 );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_row_410['status'] );
		$this->assertSame( 410, (int) $health_row_410['response_code'] );
	}

	public function test_update_url_with_unchanged_destination_does_not_invoke_health_check(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_skip';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Original Title', 'https://93.184.216.34/stable', 'active', '{$now}', '{$now}')"
		);

		$health_check_called = false;
		$checker             = $this->create_checker(
			static function () use ( &$health_check_called ): array {
				$health_check_called = true;
				return array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}
		);
		$router              = $this->create_router_with_checker( $checker );

		$request = $this->admin_request(
			'PUT',
			"/api/v1/urls/{$link_id}",
			array(),
			array(
				'title'          => 'Updated Title Only',
				'destinationUrl' => 'https://93.184.216.34/stable',
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $health_check_called, 'Health check must not run when destination URL has not changed.' );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertSame( 'Updated Title Only', $url_row['title'] );
	}

	public function test_scheduled_job_wiring_registers_bounded_link_health_check(): void {
		$config       = Configuration::get_current();
		$settings_api = new \PeakURL\Api\SettingsApi( $this->db );
		$cache        = new \PeakURL\Services\Cache\Drivers\NullCache();
		$geoip        = $this->createMock( \PeakURL\Services\Geoip::class );
		$webhooks     = $this->createMock( \PeakURL\Features\Webhooks\Service::class );
		$updater      = $this->createMock( \PeakURL\Services\Update\Manager::class );

		$scheduler = \PeakURL\Core\Scheduler\SchedulerFactory::create(
			$this->db,
			$config,
			$settings_api,
			$cache,
			$geoip,
			$webhooks,
			$updater,
			$this->create_checker()
		);

		$status = $scheduler->get_status();
		$jobs   = $status['jobs'] ?? array();

		$found = false;
		foreach ( $jobs as $job ) {
			if ( 'peakurl_link_health_check' === $job['id'] ) {
				$found = true;
				$this->assertSame( 86400, $job['interval_seconds'] );
				$this->assertSame( 'Link Destination Health Check', $job['title'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Job peakurl_link_health_check must be registered with daily schedule.' );
	}

	public function test_manual_health_check_handles_persistence_failure(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'pers_fail';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Persistence Fail Target', 'https://93.184.216.34/test', 'active', '{$now}', '{$now}')"
		);

		$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health TO {$this->table_prefix}link_health_bak" );

		try {
			$checker = $this->create_checker(
				static fn() => array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$router  = $this->create_router_with_checker( $checker );

			$request = $this->admin_request( 'POST', "/api/v1/urls/{$link_id}/health-check" );
			$data    = $this->dispatch( $request, $router );

			$this->assertFalse( $data['success'] );
			$this->assertSame( 500, $data['status'] );
			$this->assertSame( 'Could not record link health snapshot.', $data['message'] );
		} finally {
			$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health_bak TO {$this->table_prefix}link_health" );
		}
	}

	public function test_update_url_destination_change_when_health_persistence_fails(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_pfail';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Initial', 'https://93.184.216.34/initial', 'active', '{$now}', '{$now}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}link_health (link_id, status, checked_at, response_code, response_time_ms, error_message, redirect_count, created_at, updated_at)
			VALUES ('{$link_id}', 'healthy', '{$now}', 200, 50, NULL, 0, '{$now}', '{$now}')"
		);

		$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health TO {$this->table_prefix}link_health_bak" );

		try {
			$checker = $this->create_checker(
				static fn() => array(
					'response_code' => 200,
					'duration_ms'   => 50,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$router  = $this->create_router_with_checker( $checker );

			$request = $this->admin_request(
				'PUT',
				"/api/v1/urls/{$link_id}",
				array(),
				array(
					'destinationUrl' => 'https://93.184.216.34/new-target',
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertTrue( $data['success'] );
			$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
			$this->assertSame( 'https://93.184.216.34/new-target', $url_row['destination_url'] );
		} finally {
			$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health_bak TO {$this->table_prefix}link_health" );
		}
	}

	public function test_create_url_destination_when_health_persistence_fails(): void {
		$code = $this->test_prefix . 'crt_pfail';
		$dest = 'https://93.184.216.34/created-target';

		$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health TO {$this->table_prefix}link_health_bak" );

		try {
			$checker = $this->create_checker(
				static fn() => array(
					'response_code' => 200,
					'duration_ms'   => 80,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				)
			);
			$router  = $this->create_router_with_checker( $checker );

			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => $dest,
					'title'          => 'Persistence Fail Link',
					'customAlias'    => $code,
				)
			);
			$data    = $this->dispatch( $request, $router );

			// Creation still succeeds, but health is null because persistence failed
			$this->assertTrue( $data['success'] );
			$this->assertSame( 201, $data['status'] );
			$this->assertNull( $data['data']['health'] );

			$link_id = $data['data']['id'] ?? '';
			$this->assertNotEmpty( $link_id );
			$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
			$this->assertNotNull( $url_row );
		} finally {
			$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health_bak TO {$this->table_prefix}link_health" );
		}
	}

	public function test_save_link_health_strict_boolean_return_contract(): void {
		$links_api = new \PeakURL\Api\LinksApi( $this->db, new \PeakURL\Services\Cache\Drivers\NullCache() );
		$repo      = new \PeakURL\Features\Links\Repository( $this->db, $links_api );

		// Normal valid save succeeds
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'repo_hlth';
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Test', 'https://93.184.216.34/repo-target', 'active', '{$now}', '{$now}')"
		);

		$saved = $repo->save_link_health(
			$link_id,
			array(
				'status'           => 'healthy',
				'checked_at'       => $now,
				'response_code'    => 200,
				'response_time_ms' => 100,
				'error_message'    => null,
				'redirect_count'   => 0,
			)
		);
		$this->assertTrue( $saved );

		// Simulate DB upsert returning false or throwing
		$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health TO {$this->table_prefix}link_health_bak" );
		try {
			$failed = $repo->save_link_health(
				$link_id,
				array(
					'status'           => 'healthy',
					'checked_at'       => $now,
					'response_code'    => 200,
					'response_time_ms' => 100,
					'error_message'    => null,
					'redirect_count'   => 0,
				)
			);
			$this->assertFalse( $failed );
		} finally {
			$this->pdo->exec( "RENAME TABLE {$this->table_prefix}link_health_bak TO {$this->table_prefix}link_health" );
		}
	}

	public function test_save_and_delete_link_health_handle_invalid_db_return_strictly(): void {
		$mock_db = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$mock_db->method( 'upsert' )->willReturn( -1 );
		$mock_db->method( 'delete' )->willReturn( -1 );

		$links_api = new \PeakURL\Api\LinksApi( $this->db, new \PeakURL\Services\Cache\Drivers\NullCache() );
		$repo      = new \PeakURL\Features\Links\Repository( $mock_db, $links_api );

		$saved = $repo->save_link_health(
			'dummy_id',
			array(
				'status'           => 'healthy',
				'checked_at'       => Date::now(),
				'response_code'    => 200,
				'response_time_ms' => 100,
				'error_message'    => null,
				'redirect_count'   => 0,
			)
		);
		$this->assertFalse( $saved, 'save_link_health must return false when upsert returns negative integer.' );

		$deleted = $repo->delete_link_health( 'dummy_id' );
		$this->assertFalse( $deleted, 'delete_link_health must return false when delete returns negative integer.' );

		// Exception path also cleanly returns false.
		$mock_db_throws = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$mock_db_throws->method( 'upsert' )->willThrowException( new \RuntimeException( 'Connection lost' ) );
		$mock_db_throws->method( 'delete' )->willThrowException( new \RuntimeException( 'Connection lost' ) );
		$repo_throws = new \PeakURL\Features\Links\Repository( $mock_db_throws, $links_api );
		$this->assertFalse( $repo_throws->save_link_health( 'dummy_id', array() ) );
		$this->assertFalse( $repo_throws->delete_link_health( 'dummy_id' ) );

		// When delete returns integer >= 0, it must return true.
		$mock_db_success = $this->createMock( \PeakURL\Services\Database\PeakURL_DB::class );
		$mock_db_success->method( 'delete' )->willReturn( 1 );
		$repo_success = new \PeakURL\Features\Links\Repository( $mock_db_success, $links_api );
		$this->assertTrue( $repo_success->delete_link_health( 'dummy_id' ) );
	}

	public function test_canonical_schema_contains_required_tables_and_foreign_keys(): void {
		$this->assertSame( 10, \PeakURL\Core\Config\Constants::DB_SCHEMA_VERSION );

		// 1. Verify cron_jobs table exists.
		$cron_jobs = $this->pdo->query( "SHOW TABLES LIKE '{$this->table_prefix}cron_jobs'" )->fetchAll();
		$this->assertNotEmpty( $cron_jobs, 'cron_jobs table must exist in canonical schema.' );

		// 2. Verify cron_runs table exists.
		$cron_runs = $this->pdo->query( "SHOW TABLES LIKE '{$this->table_prefix}cron_runs'" )->fetchAll();
		$this->assertNotEmpty( $cron_runs, 'cron_runs table must exist in canonical schema.' );

		// 3. Verify link_health table exists.
		$link_health = $this->pdo->query( "SHOW TABLES LIKE '{$this->table_prefix}link_health'" )->fetchAll();
		$this->assertNotEmpty( $link_health, 'link_health table must exist in canonical schema.' );

		$db_name = (string) $this->connection->get_config()[ \PeakURL\Core\Config\Constants::DB_DATABASE ];

		// 4. Verify canonical fk_cron_runs_job_id (cron_runs.job_id -> cron_jobs.id ON DELETE CASCADE).
		$runs_fk_stmt = $this->pdo->prepare(
			'SELECT
				kcu.constraint_name,
				kcu.table_name,
				kcu.column_name,
				kcu.referenced_table_name,
				kcu.referenced_column_name,
				rc.delete_rule
			FROM information_schema.key_column_usage kcu
			JOIN information_schema.referential_constraints rc
				ON rc.constraint_schema = kcu.constraint_schema
				AND rc.constraint_name = kcu.constraint_name
				AND rc.table_name = kcu.table_name
			WHERE kcu.constraint_schema = :db_name
			AND kcu.table_name = :table_name'
		);
		$runs_fk_stmt->execute(
			array(
				'db_name'    => $db_name,
				'table_name' => "{$this->table_prefix}cron_runs",
			)
		);
		$runs_fk_raw = $runs_fk_stmt->fetch( \PDO::FETCH_ASSOC );
		$this->assertIsArray( $runs_fk_raw, 'cron_runs must have a foreign key defined in information_schema.' );
		$runs_fk = array_change_key_case( $runs_fk_raw, CASE_LOWER );
		$this->assertStringEndsWith( 'fk_cron_runs_job_id', (string) $runs_fk['constraint_name'] );
		$this->assertSame( "{$this->table_prefix}cron_runs", $runs_fk['table_name'] );
		$this->assertSame( 'job_id', $runs_fk['column_name'] );
		$this->assertSame( "{$this->table_prefix}cron_jobs", $runs_fk['referenced_table_name'] );
		$this->assertSame( 'id', $runs_fk['referenced_column_name'] );
		$this->assertSame( 'CASCADE', strtoupper( (string) $runs_fk['delete_rule'] ) );

		// 5. Verify canonical fk_link_health_link_id (link_health.link_id -> urls.id ON DELETE CASCADE).
		$health_fk_stmt = $this->pdo->prepare(
			'SELECT
				kcu.constraint_name,
				kcu.table_name,
				kcu.column_name,
				kcu.referenced_table_name,
				kcu.referenced_column_name,
				rc.delete_rule
			FROM information_schema.key_column_usage kcu
			JOIN information_schema.referential_constraints rc
				ON rc.constraint_schema = kcu.constraint_schema
				AND rc.constraint_name = kcu.constraint_name
				AND rc.table_name = kcu.table_name
			WHERE kcu.constraint_schema = :db_name
			AND kcu.table_name = :table_name'
		);
		$health_fk_stmt->execute(
			array(
				'db_name'    => $db_name,
				'table_name' => "{$this->table_prefix}link_health",
			)
		);
		$health_fk_raw = $health_fk_stmt->fetch( \PDO::FETCH_ASSOC );
		$this->assertIsArray( $health_fk_raw, 'link_health must have a foreign key defined in information_schema.' );
		$health_fk = array_change_key_case( $health_fk_raw, CASE_LOWER );
		$this->assertStringEndsWith( 'fk_link_health_link_id', (string) $health_fk['constraint_name'] );
		$this->assertSame( "{$this->table_prefix}link_health", $health_fk['table_name'] );
		$this->assertSame( 'link_id', $health_fk['column_name'] );
		$this->assertSame( "{$this->table_prefix}urls", $health_fk['referenced_table_name'] );
		$this->assertSame( 'id', $health_fk['referenced_column_name'] );
		$this->assertSame( 'CASCADE', strtoupper( (string) $health_fk['delete_rule'] ) );
	}

	public function test_targeted_health_job_skips_safely_when_link_was_deleted(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'del_tgt';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Deleted Link', 'https://93.184.216.34/deleted', 'active', '{$now}', '{$now}')"
		);

		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();

		// Enqueue targeted check.
		$job_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "peakurl_link_health_check:{$link_id}", $job_id );

		// Now delete the link from urls table before job executes.
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE id = '{$link_id}'" );

		// Execute job: must skip safely without error.
		$result = $scheduler->run_job( $job_id );
		$this->assertTrue( $result->is_skipped(), 'Targeted job must return skipped status when link was deleted.' );
		$this->assertStringContainsString( 'deleted', $result->get_summary() );

		// No row in link_health.
		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_row );
	}

	public function test_targeted_health_job_does_not_alter_recurring_daily_batch_schedule(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'no_alt';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Stable Link', 'https://93.184.216.34/stable', 'active', '{$now}', '{$now}')"
		);

		$checker   = $this->create_checker(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$scheduler->sync();

		// Inspect recurring daily batch job state before targeted run.
		$base_job_before = $this->db->get_row_by( 'cron_jobs', array( 'id' => 'peakurl_link_health_check' ) );
		$this->assertNotNull( $base_job_before );
		$this->assertSame( 86400, (int) $base_job_before['schedule_interval'] );
		$this->assertSame( 1, (int) $base_job_before['is_enabled'] );

		// Enqueue and run targeted job.
		$targeted_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$result      = $scheduler->run_job( $targeted_id );
		$this->assertTrue( $result->is_success() );

		// Base recurring batch job must be completely untouched.
		$base_job_after = $this->db->get_row_by( 'cron_jobs', array( 'id' => 'peakurl_link_health_check' ) );
		$this->assertNotNull( $base_job_after );
		$this->assertSame( 86400, (int) $base_job_after['schedule_interval'] );
		$this->assertSame( 1, (int) $base_job_after['is_enabled'] );
		$this->assertSame( $base_job_before['next_run_at'], $base_job_after['next_run_at'] );
	}

	public function test_targeted_health_job_is_one_off_and_disabled_after_success(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'one_off';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'One Off Target', 'https://93.184.216.34/one-off', 'active', '{$now}', '{$now}')"
		);

		$checker   = $this->create_checker(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 40,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			)
		);
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();

		$job_id     = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$row_before = $this->db->get_row_by( 'cron_jobs', array( 'id' => $job_id ) );
		$this->assertNotNull( $row_before );
		$this->assertSame( 0, (int) $row_before['schedule_interval'] );
		$this->assertSame( 1, (int) $row_before['is_enabled'] );

		$result = $scheduler->run_job( $job_id );
		$this->assertTrue( $result->is_success() );

		// After success, completed one-off targeted job is retained in cron_jobs and disabled, preserving execution history in cron_runs.
		$row_after = $this->db->get_row_by( 'cron_jobs', array( 'id' => $job_id ) );
		$this->assertNotNull( $row_after, 'Completed one-off targeted job must be retained in cron_jobs table.' );
		$this->assertSame( 0, (int) $row_after['is_enabled'], 'Completed one-off targeted job must be disabled.' );
		$this->assertSame( 'success', $row_after['status'] );

		// History is logged in cron_runs.
		$runs = $scheduler->get_repository()->get_job_runs( $job_id, 10 );
		$this->assertNotEmpty( $runs );
		$this->assertSame( 'success', $runs[0]['status'] );
	}

	public function test_destination_change_during_execution_discards_stale_result_and_preserves_new_job(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'race_ab';
		$dest_a  = 'https://93.184.216.34/dest-a';
		$dest_b  = 'https://93.184.216.34/dest-b';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Race Test', '{$dest_a}', 'active', '{$now}', '{$now}')"
		);

		$scheduler = null;
		$job_id_b  = null;
		$prober    = function ( string $url, int $timeout_ms, string $pinned_ip, string $host, int $port, string $method = 'HEAD' ) use ( $link_id, $dest_b, &$scheduler, &$job_id_b ): array {
			if ( str_contains( $url, 'dest-a' ) ) {
				// While check for A is running, simulate destination update to B and enqueue of B job.
				$now_update = Date::now();
				$this->pdo->exec(
					"UPDATE {$this->table_prefix}urls SET destination_url = '{$dest_b}', updated_at = '{$now_update}' WHERE id = '{$link_id}'"
				);
				if ( null !== $scheduler ) {
					$job_id_b = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
				}
				return array(
					'response_code' => 200,
					'duration_ms'   => 40,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				);
			}

			// Destination B probe response.
			return array(
				'response_code' => 200,
				'duration_ms'   => 45,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			);
		};

		$checker   = $this->create_checker( $prober );
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();

		// Enqueue job for initial destination A.
		$job_id_a = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		// Execute worker for A. Destination changed to B during probing.
		$result_a = $scheduler->run_job( $job_id_a );

		// Result for A must be skipped; stale health must not be persisted.
		$this->assertTrue( $result_a->is_skipped(), 'Worker must skip stale check when destination changed mid-flight.' );
		$this->assertStringContainsString( 'Destination changed', $result_a->get_summary() );

		// No health row should be associated with the stale A check.
		$health_before_b = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_before_b, 'No stale health record should exist before B execution.' );

		// B's targeted job was enqueued while A was running (routed to :next) and is pending in queue.
		$this->assertNotNull( $job_id_b );
		$this->assertSame( "{$job_id_a}:next", $job_id_b );
		$job_row_b = $scheduler->get_persisted_job( $job_id_b );
		$this->assertNotNull( $job_row_b );
		$this->assertSame( 1, (int) $job_row_b['is_enabled'] );
		$this->assertSame( 'idle', $job_row_b['status'] );

		// Execute worker for B.
		$result_b = $scheduler->run_job( $job_id_b );
		$this->assertTrue( $result_b->is_success(), 'Targeted job for B must succeed.' );

		// Health row now exists and is healthy for B.
		$health_after_b = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_after_b );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_after_b['status'] );
		$this->assertSame( 200, (int) $health_after_b['response_code'] );
	}

	public function test_destination_race_a_to_b_to_a_never_associates_stale_result(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'race_aba';
		$dest_a  = 'https://93.184.216.34/dest-a1';
		$dest_b  = 'https://93.184.216.34/dest-b1';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'ABA Race', '{$dest_a}', 'active', '{$now}', '{$now}')"
		);

		// Worker 1 checks dest_a. While it is checking dest_a, destination is changed to dest_b.
		$prober1 = function () use ( $link_id, $dest_b ): array {
			$this->pdo->exec(
				"UPDATE {$this->table_prefix}urls SET destination_url = '{$dest_b}' WHERE id = '{$link_id}'"
			);
			return array(
				'response_code' => 500, // dest_a would report 500 error
				'duration_ms'   => 50,
				'error_code'    => 0,
				'error_message' => '',
				'redirect_url'  => null,
			);
		};

		$checker1 = $this->create_checker( $prober1 );
		$app1     = $this->create_app_with_checker( $checker1 );
		$job_id   = $app1->get_scheduler()->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		$result1 = $app1->get_scheduler()->run_job( $job_id );
		$this->assertTrue( $result1->is_skipped(), 'Stale worker 1 must skip when destination is changed.' );

		// Ensure no health row was written by stale worker 1.
		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNull( $health_row, 'Stale error result from worker 1 must not be persisted.' );

		// Destination is changed back to dest_a, queuing a new check.
		$this->pdo->exec(
			"UPDATE {$this->table_prefix}urls SET destination_url = '{$dest_a}' WHERE id = '{$link_id}'"
		);

		// Worker 2 checks current destination dest_a, reporting 200.
		$prober2 = static fn(): array => array(
			'response_code' => 200,
			'duration_ms'   => 30,
			'error_code'    => 0,
			'error_message' => '',
			'redirect_url'  => null,
		);

		$checker2 = $this->create_checker( $prober2 );
		$app2     = $this->create_app_with_checker( $checker2 );
		$job_id2  = $app2->get_scheduler()->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		$result2 = $app2->get_scheduler()->run_job( $job_id2 );
		$this->assertTrue( $result2->is_success() );

		$final_health = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $final_health );
		$this->assertSame( Checker::STATUS_HEALTHY, $final_health['status'] );
		$this->assertSame( 200, (int) $final_health['response_code'] );
	}

	public function test_concurrent_enqueue_for_same_pending_target_deduplicates_to_single_row(): void {
		$link_id = Str::random_id( 16 );
		$checker = $this->create_checker();
		$app     = $this->create_app_with_checker( $checker );
		$sched   = $app->get_scheduler();

		// Two concurrent or repeated enqueue operations for the same pending target.
		$id1 = $sched->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$id2 = $sched->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		$this->assertSame( "peakurl_link_health_check:{$link_id}", $id1 );
		$this->assertSame( "peakurl_link_health_check:{$link_id}", $id2 );

		// Exactly one row exists in cron_jobs.
		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 1, $rows, 'Repeated enqueue calls for the same pending target must deduplicate to exactly one row.' );
	}

	public function test_enqueue_while_job_is_running_creates_next_execution_and_reuses_it_without_duplicate_explosion(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();

		// 1. Initial enqueue.
		$primary_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "peakurl_link_health_check:{$link_id}", $primary_id );

		// 2. Simulate worker claiming and running the primary job.
		$now        = Date::now();
		$lock_token = Str::random_id( 16 );
		$this->assertTrue( $repo->claim_job( $primary_id, $lock_token, 300, false, $now ) );

		// 3. Enqueue while primary is running: routes to deterministic secondary :next in waiting state.
		$secondary_id1 = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "{$primary_id}:next", $secondary_id1 );

		// Verify 2 rows in cron_jobs (primary + :next).
		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 2, $rows );

		// :next exists but is NOT runnable while primary is running.
		$next_row = $this->db->get_row_by( 'cron_jobs', array( 'id' => $secondary_id1 ) );
		$this->assertNotNull( $next_row );
		$this->assertSame( 'waiting', $next_row['status'], ':next must exist in non-runnable waiting state while primary is running.' );
		$this->assertSame( 0, (int) $next_row['is_enabled'], ':next must be disabled while waiting.' );

		// Verify :next is not discovered as due while primary is running.
		$due_jobs = $repo->get_due_jobs( $now );
		$due_ids  = array_column( $due_jobs, 'id' );
		$this->assertNotContains( $secondary_id1, $due_ids, ':next must not be due while primary is running.' );

		// Verify :next cannot be claimed while primary is running (both normal and forced).
		$this->assertFalse( $repo->claim_job( $secondary_id1, 'rogue_tok', 300, false, $now ) );
		$this->assertFalse( $repo->claim_job( $secondary_id1, 'rogue_tok', 300, true, $now ) );

		// 4. Repeated enqueue calls while primary is still running reuse the same :next row without explosion.
		$secondary_id2 = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$secondary_id3 = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "{$primary_id}:next", $secondary_id2 );
		$this->assertSame( "{$primary_id}:next", $secondary_id3 );

		$rows_after = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 2, $rows_after, 'Subsequent enqueues must reuse the :next row without duplicate explosion.' );

		// Primary running job's lock token and status must NOT be modified.
		$primary_row = $this->db->get_row_by( 'cron_jobs', array( 'id' => $primary_id ) );
		$this->assertSame( 'running', $primary_row['status'] );
		$this->assertSame( $lock_token, $primary_row['lock_token'] );

		// 5. Worker completes primary execution -> releases lock and promotes :next to runnable.
		$run_id_primary = $repo->record_run_start( $primary_id, 1, $now );
		$repo->record_success( $primary_id, $run_id_primary, $lock_token, $now, $now, 45, 'Primary check passed.' );

		$primary_after = $this->db->get_row_by( 'cron_jobs', array( 'id' => $primary_id ) );
		$this->assertSame( 'success', $primary_after['status'] );
		$this->assertSame( 0, (int) $primary_after['is_enabled'] );
		$this->assertNull( $primary_after['locked_at'] );

		// :next is now promoted to runnable.
		$next_promoted = $this->db->get_row_by( 'cron_jobs', array( 'id' => $secondary_id1 ) );
		$this->assertSame( 'idle', $next_promoted['status'], ':next must become idle when primary completes.' );
		$this->assertSame( 1, (int) $next_promoted['is_enabled'], ':next must become enabled when primary completes.' );

		// :next is now discoverable and claimable.
		$due_jobs_after = $repo->get_due_jobs( $now );
		$due_ids_after  = array_column( $due_jobs_after, 'id' );
		$this->assertContains( $secondary_id1, $due_ids_after, ':next must be due after primary finishes.' );

		$worker2_token = Str::random_id( 16 );
		$this->assertTrue( $repo->claim_job( $secondary_id1, $worker2_token, 300, false, $now ), ':next must be claimable by worker 2.' );

		// 6. JobRegistry and LinkHealthCheckJob can parse link_id from :next ID.
		$this->assertTrue( $scheduler->get_registry()->has( $secondary_id1 ) );
		$this->assertSame( $link_id, LinkHealthCheckJob::parse_link_id( $secondary_id1 ) );
	}

	public function test_two_workers_cannot_execute_primary_and_next_concurrently(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();
		$now       = Date::now();

		// Enqueue primary.
		$primary_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		// Worker 1 claims primary (normal claim).
		$token_w1 = 'worker1_token_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $primary_id, $token_w1, 300, false, $now ) );

		// Another worker competing for the same primary slot fails (normal and forced while lease active).
		$token_w2 = 'worker2_token_' . Str::random_id( 8 );
		$this->assertFalse( $repo->claim_job( $primary_id, $token_w2, 300, false, $now ), 'Worker 2 must not claim primary while Worker 1 owns it.' );
		$this->assertFalse( $repo->claim_job( $primary_id, $token_w2, 300, true, $now ), 'Worker 2 must not force-claim primary while Worker 1 lease is unexpired.' );

		// Enqueue while primary is running creates :next in waiting state.
		$next_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "{$primary_id}:next", $next_id );

		// Worker 2 attempts to claim :next while primary is running -> MUST fail for both normal and forced.
		$this->assertFalse( $repo->claim_job( $next_id, $token_w2, 300, false, $now ), 'Worker 2 must not normal-claim :next while primary is running.' );
		$this->assertFalse( $repo->claim_job( $next_id, $token_w2, 300, true, $now ), 'Worker 2 must not force-claim :next while primary is running.' );

		// Primary finishes execution and releases its lock.
		$run_id_primary = $repo->record_run_start( $primary_id, 1, $now );
		$repo->record_success( $primary_id, $run_id_primary, $token_w1, $now, $now, 40, 'Primary check passed.' );

		// :next is now runnable. Worker 2 claims :next.
		$this->assertTrue( $repo->claim_job( $next_id, $token_w2, 300, false, $now ), 'Worker 2 must be able to claim :next after primary completes.' );

		// While Worker 2 is executing :next, Worker 1 cannot claim primary (neither normal nor forced).
		$this->assertFalse( $repo->claim_job( $primary_id, $token_w1, 300, false, $now ), 'Worker 1 must not normal-claim primary while :next is running.' );
		$this->assertFalse( $repo->claim_job( $primary_id, $token_w1, 300, true, $now ), 'Worker 1 must not force-claim primary while :next is running.' );

		// Worker 2 completes :next and releases lock.
		$run_id_next = $repo->record_run_start( $next_id, 1, $now );
		$repo->record_success( $next_id, $run_id_next, $token_w2, $now, $now, 35, 'Follow-up check passed.' );

		// Neither is running now.
		$primary_row = $repo->get_job( $primary_id );
		$next_row    = $repo->get_job( $next_id );
		$this->assertSame( 'success', $primary_row['status'] );
		$this->assertSame( 'success', $next_row['status'] );
		$this->assertSame( 0, (int) $primary_row['is_enabled'] );
		$this->assertSame( 0, (int) $next_row['is_enabled'] );
	}

	public function test_atomic_cross_connection_claim_mutual_exclusion(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo1     = $scheduler->get_repository();
		$now       = Date::now();

		// Create independent connection & repository for Worker 2 to test cross-connection atomicity.
		$config = Configuration::get_current();
		$conn2  = new Connection( $config );
		$db2    = new PeakURL_DB( $conn2 );
		$repo2  = new SchedulerRepository( $db2 );

		// 1. Enqueue primary and claim by Worker 1.
		$primary_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$token_w1   = 'tok_w1_' . Str::random_id( 8 );
		$this->assertTrue( $repo1->claim_job( $primary_id, $token_w1, 300, false, $now ) );

		// 2. Enqueue while running creates :next (status = waiting).
		$next_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "{$primary_id}:next", $next_id );

		// 3. Worker 2 on Connection 2 attempts normal and forced claims on :next while primary is active -> MUST fail.
		$token_w2 = 'tok_w2_' . Str::random_id( 8 );
		$this->assertFalse( $repo2->claim_job( $next_id, $token_w2, 300, false, $now ), 'Worker 2 must not claim :next while primary is running.' );
		$this->assertFalse( $repo2->claim_job( $next_id, $token_w2, 300, true, $now ), 'Worker 2 must not force-claim :next while primary is running.' );

		// 4. Primary completes cleanly on Connection 1 -> releases lock and promotes :next.
		$run_id_primary = $repo1->record_run_start( $primary_id, 1, $now );
		$repo1->record_success( $primary_id, $run_id_primary, $token_w1, $now, $now, 45, 'Primary success.' );

		// 5. Worker 2 on Connection 2 now successfully claims :next.
		$this->assertTrue( $repo2->claim_job( $next_id, $token_w2, 300, false, $now ), 'Worker 2 must be able to claim :next after primary completes.' );

		// 6. While Worker 2 owns :next, Worker 1 on Connection 1 cannot claim primary (normal or forced).
		$this->assertFalse( $repo1->claim_job( $primary_id, $token_w1, 300, false, $now ), 'Worker 1 must not claim primary while :next is running.' );
		$this->assertFalse( $repo1->claim_job( $primary_id, $token_w1, 300, true, $now ), 'Worker 1 must not force-claim primary while :next is running.' );

		// 7. Worker 2 completes :next on Connection 2.
		$run_id_next = $repo2->record_run_start( $next_id, 1, $now );
		$repo2->record_success( $next_id, $run_id_next, $token_w2, $now, $now, 40, 'Follow-up success.' );

		// 8. Verify both are non-running.
		$row_primary = $repo1->get_job( $primary_id );
		$row_next    = $repo2->get_job( $next_id );
		$this->assertSame( 'success', $row_primary['status'] );
		$this->assertSame( 'success', $row_next['status'] );
		$this->assertSame( 0, (int) $row_primary['is_enabled'] );
		$this->assertSame( 0, (int) $row_next['is_enabled'] );
	}

	public function test_stale_worker_completion_cannot_promote_next_follow_up_job(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();

		// 1. Worker A owns primary with a short 30-second lease.
		$t0         = Date::now();
		$primary_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ), $t0 );
		$token_a    = 'worker_a_tok_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $primary_id, $token_a, 30, false, $t0 ) );

		// 2. Enqueue while primary is running creates :next in non-runnable waiting state.
		$next_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ), $t0 );
		$this->assertSame( "{$primary_id}:next", $next_id );

		$next_row_init = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_init['status'] );
		$this->assertSame( 0, (int) $next_row_init['is_enabled'] );

		// 3. Worker A's lease becomes stale (time advances 60s past expiry).
		$t_stale = gmdate( 'Y-m-d H:i:s', time() + 60 );

		// 4. Worker B legitimately reclaims primary with a new token.
		$token_b = 'worker_b_tok_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $primary_id, $token_b, 300, false, $t_stale ) );

		$primary_reclaimed = $repo->get_job( $primary_id );
		$this->assertSame( $token_b, $primary_reclaimed['lock_token'] );
		$this->assertSame( 'running', $primary_reclaimed['status'] );

		// 5. Worker A finishes late using the old stale token -> completion update affects 0 rows.
		$run_id_a = $repo->record_run_start( $primary_id, 1, $t0 );
		$repo->record_success( $primary_id, $run_id_a, $token_a, $t0, $t_stale, 45, 'Late Worker A result.' );

		// 6. Worker A does NOT promote :next. :next remains non-runnable while Worker B owns primary.
		$next_row_after_late_a = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_after_late_a['status'], ':next must remain waiting when stale worker A finishes.' );
		$this->assertSame( 0, (int) $next_row_after_late_a['is_enabled'], ':next must remain disabled.' );

		// Primary is still actively owned by Worker B with token_b.
		$primary_still_b = $repo->get_job( $primary_id );
		$this->assertSame( $token_b, $primary_still_b['lock_token'], 'Primary lock token must still belong to Worker B.' );
		$this->assertSame( 'running', $primary_still_b['status'], 'Primary status must still be running under Worker B.' );

		// 7. Verify stale retryable failure also does not promote :next or overwrite Worker B's lease.
		$repo->record_failure( $primary_id, $run_id_a, $token_a, $t0, $t_stale, false, 30, 'Late failure A' );
		$next_row_after_fail_a = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_after_fail_a['status'] );
		$this->assertSame( 0, (int) $next_row_after_fail_a['is_enabled'] );
		$primary_after_fail = $repo->get_job( $primary_id );
		$this->assertSame( $token_b, $primary_after_fail['lock_token'] );

		// 8. Verify stale terminal failure also does not promote :next or overwrite Worker B's lease.
		$repo->record_failure( $primary_id, $run_id_a, $token_a, $t0, $t_stale, true, 30, 'Late terminal A' );
		$next_row_after_term_a = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_after_term_a['status'] );
		$this->assertSame( 0, (int) $next_row_after_term_a['is_enabled'] );
		$primary_after_term = $repo->get_job( $primary_id );
		$this->assertSame( $token_b, $primary_after_term['lock_token'] );

		// 9. Verify stale skipped execution also does not promote :next or overwrite Worker B's lease.
		$repo->record_skipped( $primary_id, $run_id_a, $token_a, $t0, $t_stale, 20, 'Late skip A' );
		$next_row_after_skip_a = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_after_skip_a['status'] );
		$this->assertSame( 0, (int) $next_row_after_skip_a['is_enabled'] );
		$primary_after_skip = $repo->get_job( $primary_id );
		$this->assertSame( $token_b, $primary_after_skip['lock_token'] );

		// 10. Finally, Worker B legitimately finishes -> valid completion promotes :next to runnable.
		$run_id_b = $repo->record_run_start( $primary_id, 2, $t_stale );
		$repo->record_success( $primary_id, $run_id_b, $token_b, $t_stale, $t_stale, 50, 'Valid Worker B result.' );

		$primary_final = $repo->get_job( $primary_id );
		$this->assertSame( 'success', $primary_final['status'] );
		$this->assertSame( 0, (int) $primary_final['is_enabled'] );
		$this->assertNull( $primary_final['locked_at'] );

		$next_promoted = $repo->get_job( $next_id );
		$this->assertSame( 'idle', $next_promoted['status'], ':next must become idle when legitimate owner completes.' );
		$this->assertSame( 1, (int) $next_promoted['is_enabled'], ':next must be enabled.' );
		$this->assertSame( 0, (int) $next_promoted['attempts'] );

		// Verify :next is now claimable by a worker.
		$token_w3 = 'worker_3_tok_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $next_id, $token_w3, 300, false, $t_stale ) );
	}

	public function test_re_enqueue_during_primary_followed_by_retryable_failure_preserves_newer_request(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();
		$now       = Date::now();

		// 1. Primary enqueued and claimed (attempt 1).
		$primary_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$token_w1   = 'token_retry_prim_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $primary_id, $token_w1, 300, false, $now ) );

		// 2. Enqueue while primary is running -> puts newer request into :next slot (waiting).
		$next_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "{$primary_id}:next", $next_id );

		$next_row_before = $repo->get_job( $next_id );
		$this->assertSame( 'waiting', $next_row_before['status'] );
		$this->assertSame( 0, (int) $next_row_before['is_enabled'] );

		// 3. Primary fails with a retryable failure (attempt 1/3, is_terminal = false).
		$run_id_primary = $repo->record_run_start( $primary_id, 1, $now );
		$retry_time     = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$repo->record_failure(
			$primary_id,
			$run_id_primary,
			$token_w1,
			$now,
			$retry_time,
			false,
			50,
			'Temporary curl connect timeout (cURL error 7)'
		);

		// Primary lock is released, history is retrying.
		$primary_row = $repo->get_job( $primary_id );
		$this->assertNull( $primary_row['locked_at'] );
		$this->assertSame( 'idle', $primary_row['status'] );
		$runs_primary = $repo->get_job_runs( $primary_id, 1 );
		$this->assertSame( 'retrying', $runs_primary[0]['status'] );

		// :next follow-up MUST be promoted to runnable (idle, enabled, attempts = 0).
		$next_row_after = $repo->get_job( $next_id );
		$this->assertNotNull( $next_row_after );
		$this->assertSame( 'idle', $next_row_after['status'], ':next must become idle when primary finishes retryably.' );
		$this->assertSame( 1, (int) $next_row_after['is_enabled'], ':next must be enabled.' );
		$this->assertSame( 0, (int) $next_row_after['attempts'], ':next attempts must be 0.' );

		// Verify :next is immediately claimable and runnable by a worker.
		$token_w2 = 'token_w2_' . Str::random_id( 8 );
		$this->assertTrue( $repo->claim_job( $next_id, $token_w2, 300, false, $now ), ':next must be claimable immediately.' );

		// 4. Test re-enqueue while :next is running followed by retryable failure.
		// Worker 2 has claimed :next above (it is currently running).
		// Re-enqueuing while :next is running updates :next in-place (attempts reset to 0).
		$scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );

		// :next fails retryably.
		$run_id_next = $repo->record_run_start( $next_id, 1, $now );
		$repo->record_failure(
			$next_id,
			$run_id_next,
			$token_w2,
			$now,
			$retry_time,
			false,
			30,
			'Temporary network failure'
		);

		$next_row = $repo->get_job( $next_id );
		$this->assertNotNull( $next_row );
		$this->assertSame( 'idle', $next_row['status'], 'Re-enqueued job must reset to idle.' );
		$this->assertSame( 1, (int) $next_row['is_enabled'], 'Re-enqueued job must remain enabled.' );
		$this->assertSame( 0, (int) $next_row['attempts'], 'Re-enqueued job must reset attempts for new run.' );
	}

	public function test_completed_one_off_jobs_are_disabled_retained_in_cron_jobs_and_preserve_history(): void {
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();
		$now       = Date::now();

		// 1. One-off job completes with success.
		$job_success = 'test_success_' . Str::random_id( 6 );
		$repo->enqueue_job( $job_success, 'Success Test Job', 0, $now );
		$run_id_success = $repo->record_run_start( $job_success, 1, $now );
		$repo->claim_job( $job_success, 'token_succ', 300, true, $now );
		$repo->record_success( $job_success, $run_id_success, 'token_succ', $now, $now, 45, 'Completed successfully.' );

		// cron_jobs row must be retained, disabled (is_enabled = 0), and status = success.
		$row_success = $repo->get_job( $job_success );
		$this->assertNotNull( $row_success, 'Completed one-off job must remain in cron_jobs.' );
		$this->assertSame( 0, (int) $row_success['is_enabled'], 'Completed one-off job must be disabled.' );
		$this->assertSame( 'success', $row_success['status'] );
		$runs_success = $repo->get_job_runs( $job_success, 5 );
		$this->assertCount( 1, $runs_success );
		$this->assertSame( 'success', $runs_success[0]['status'] );

		// 2. One-off job completes with skipped.
		$job_skipped = 'test_skipped_' . Str::random_id( 6 );
		$repo->enqueue_job( $job_skipped, 'Skipped Test Job', 0, $now );
		$run_id_skipped = $repo->record_run_start( $job_skipped, 1, $now );
		$repo->claim_job( $job_skipped, 'token_skip', 300, true, $now );
		$repo->record_skipped( $job_skipped, $run_id_skipped, 'token_skip', $now, $now, 20, 'Skipped reason.' );

		// cron_jobs row must be retained, disabled (is_enabled = 0), and status = skipped.
		$row_skipped = $repo->get_job( $job_skipped );
		$this->assertNotNull( $row_skipped, 'Skipped one-off job must remain in cron_jobs.' );
		$this->assertSame( 0, (int) $row_skipped['is_enabled'], 'Skipped one-off job must be disabled.' );
		$this->assertSame( 'skipped', $row_skipped['status'] );
		$runs_skipped = $repo->get_job_runs( $job_skipped, 5 );
		$this->assertCount( 1, $runs_skipped );
		$this->assertSame( 'skipped', $runs_skipped[0]['status'] );

		// 3. One-off job fails terminally.
		$job_failed = 'test_failed_' . Str::random_id( 6 );
		$repo->enqueue_job( $job_failed, 'Failed Test Job', 0, $now, 1 );
		$run_id_failed = $repo->record_run_start( $job_failed, 1, $now );
		$repo->claim_job( $job_failed, 'token_fail', 300, true, $now );
		$repo->record_failure( $job_failed, $run_id_failed, 'token_fail', $now, $now, true, 30, 'Fatal failure.' );

		// cron_jobs row must be retained, disabled (is_enabled = 0), and status = failed.
		$row_failed = $repo->get_job( $job_failed );
		$this->assertNotNull( $row_failed, 'Terminally failed one-off job must remain in cron_jobs.' );
		$this->assertSame( 0, (int) $row_failed['is_enabled'], 'Terminally failed one-off job must be disabled.' );
		$this->assertSame( 'failed', $row_failed['status'] );
		$runs_failed = $repo->get_job_runs( $job_failed, 5 );
		$this->assertCount( 1, $runs_failed );
		$this->assertSame( 'failed', $runs_failed[0]['status'] );

		// 4. Re-enqueuing completed disabled job re-enables it without duplicate row creation.
		$re_enqueued_id = $repo->enqueue_job( $job_success, 'Success Test Job', 0, $now );
		$this->assertSame( $job_success, $re_enqueued_id );
		$row_re_enabled = $repo->get_job( $job_success );
		$this->assertSame( 1, (int) $row_re_enabled['is_enabled'], 'Re-enqueued job must be re-enabled.' );
		$this->assertSame( 'idle', $row_re_enabled['status'], 'Re-enqueued job status must be idle.' );
		$this->assertSame( 0, (int) $row_re_enabled['attempts'], 'Attempts must be reset on re-enqueue.' );
	}

	public function test_targeted_enqueue_coalescing_and_bounded_growth_under_repeated_enqueues(): void {
		$link_id   = Str::random_id( 16 );
		$checker   = $this->create_checker();
		$app       = $this->create_app_with_checker( $checker );
		$scheduler = $app->get_scheduler();
		$repo      = $scheduler->get_repository();

		// 1. 100 repeated enqueues while pending do not produce 100 rows.
		for ( $i = 0; $i < 100; $i++ ) {
			$enqueued_id = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
			$this->assertSame( "peakurl_link_health_check:{$link_id}", $enqueued_id );
		}

		$rows_pending = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 1, $rows_pending, '100 repeated enqueues while pending must yield exactly 1 cron_jobs row.' );

		// 2. Simulate worker claiming primary.
		$now        = Date::now();
		$future     = gmdate( 'Y-m-d H:i:s', time() + 300 );
		$lock_token = Str::random_id( 16 );
		$this->pdo->exec(
			"UPDATE {$this->table_prefix}cron_jobs
			SET status = 'running', locked_at = '{$now}', lock_token = '{$lock_token}', lock_expires_at = '{$future}'
			WHERE id = 'peakurl_link_health_check:{$link_id}'"
		);

		// 3. 100 repeated enqueues while primary is running route to :next and do NOT produce 100 rows.
		for ( $i = 0; $i < 100; $i++ ) {
			$enqueued_secondary = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
			$this->assertSame( "peakurl_link_health_check:{$link_id}:next", $enqueued_secondary );
		}

		$rows_running_primary = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 2, $rows_running_primary, '100 repeated enqueues while primary is running must yield exactly 2 cron_jobs rows (primary + :next).' );

		// 4. Simulate both primary and :next running.
		$this->pdo->exec(
			"UPDATE {$this->table_prefix}cron_jobs
			SET status = 'running', locked_at = '{$now}', lock_token = 'tok_next', lock_expires_at = '{$future}'
			WHERE id = 'peakurl_link_health_check:{$link_id}:next'"
		);

		// Enqueue again when both are running: must update :next without creating a 3rd row.
		$enqueued_both_running = $scheduler->enqueue_job( 'peakurl_link_health_check', array( 'link_id' => $link_id ) );
		$this->assertSame( "peakurl_link_health_check:{$link_id}:next", $enqueued_both_running );

		$rows_both_running = $this->db->get_results(
			"SELECT * FROM {$this->table_prefix}cron_jobs WHERE id LIKE :id",
			array( 'id' => "peakurl_link_health_check:{$link_id}%" )
		);
		$this->assertCount( 2, $rows_both_running, 'Enqueue when both are running must never create random third row.' );

		// 5. When :next completes, because updated_at was bumped after locked_at, it resets to idle for follow-up.
		$run_id_next = $repo->record_run_start( "peakurl_link_health_check:{$link_id}:next", 1, $now );
		$repo->record_skipped( "peakurl_link_health_check:{$link_id}:next", $run_id_next, 'tok_next', $now, $now, 10, 'Skipped stale probe.' );

		$next_row_after = $repo->get_job( "peakurl_link_health_check:{$link_id}:next" );
		$this->assertNotNull( $next_row_after );
		$this->assertSame( 'idle', $next_row_after['status'], 'Job re-enqueued while running must reset to idle upon completion.' );
		$this->assertSame( 1, (int) $next_row_after['is_enabled'], 'Job re-enqueued while running must remain enabled.' );
	}

	public function test_link_create_and_update_succeeds_when_scheduler_enqueue_throws_and_dispatches_failure_hook(): void {
		$checker = $this->create_checker();
		$app     = $this->create_app_with_checker( $checker );
		$router  = $app->get_router();

		// Extract the LinksService instance wired into the router routes.
		$ref_router      = new \ReflectionProperty( $router, 'routes' );
		$routes          = $ref_router->getValue( $router );
		$urls_controller = null;
		foreach ( $routes['POST'] ?? array() as $entry ) {
			if ( is_array( $entry['handler'] ?? null ) && $entry['handler'][0] instanceof \PeakURL\Features\Links\Controller ) {
				$urls_controller = $entry['handler'][0];
				break;
			}
		}
		$this->assertNotNull( $urls_controller, 'LinksController must be registered on POST routes.' );

		$ref_controller = new \ReflectionProperty( $urls_controller, 'links_service' );
		$links_service  = $ref_controller->getValue( $urls_controller );

		// Replace the scheduler on links service with a subclass that throws on enqueue_job.
		$real_sched        = $app->get_scheduler();
		$failing_scheduler = new class( $real_sched->get_registry(), $real_sched->get_repository() ) extends \PeakURL\Core\Scheduler\Scheduler {
			public function enqueue_job( string $job_id, array $payload = array(), ?string $run_at = null ): string {
				throw new \RuntimeException( 'Simulated scheduler database connection lost.' );
			}
		};

		$ref_service        = new \ReflectionProperty( $links_service, 'scheduler' );
		$original_scheduler = $ref_service->getValue( $links_service );
		$ref_service->setValue( $links_service, $failing_scheduler );

		$captured_events = array();
		$hook_callback   = function ( string $link_id, string $error_message ) use ( &$captured_events ): void {
			$captured_events[] = array(
				'link_id' => $link_id,
				'error'   => $error_message,
			);
		};
		\add_action( 'peakurl_link_health_scheduling_failed', $hook_callback, 10, 2 );

		try {
			// 1. Create link: must succeed despite scheduler failure.
			$code    = $this->test_prefix . 'fail_sched';
			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => 'https://93.184.216.34/created-link',
					'title'          => 'Scheduling Failure Test',
					'customAlias'    => $code,
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertTrue( $data['success'], 'Link creation must succeed even if background scheduling fails.' );
			$this->assertSame( 201, $data['status'] );
			$link_id = $data['data']['id'];

			// Ensure failure event was captured.
			$this->assertNotEmpty( $captured_events );
			$this->assertSame( $link_id, $captured_events[0]['link_id'] );
			$this->assertStringContainsString( 'Simulated scheduler database connection lost', $captured_events[0]['error'] );

			// 2. Update link: must also succeed despite scheduler failure.
			$update_request = $this->admin_request(
				'PUT',
				"/api/v1/urls/{$link_id}",
				array(),
				array(
					'destinationUrl' => 'https://93.184.216.34/updated-link',
				)
			);
			$update_data    = $this->dispatch( $update_request, $router );

			$this->assertTrue( $update_data['success'], 'Link update must succeed even if background scheduling fails.' );
			$this->assertSame( 200, $update_data['status'] );

			// Ensure second failure event was captured for the link update.
			$this->assertCount( 2, $captured_events );
			$this->assertSame( $link_id, $captured_events[1]['link_id'] );
		} finally {
			$ref_service->setValue( $links_service, $original_scheduler );
			\PeakURL\Core\Hooks\Hooks::remove_all( 'peakurl_link_health_scheduling_failed' );
		}
	}
}
