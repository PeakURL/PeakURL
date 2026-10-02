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

	private function create_router_with_checker( Checker $checker ): Router {
		$config = Configuration::get_current();
		$app    = new Application( $this->connection, $config, $checker );
		return $app->get_router();
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
		$code    = $this->test_prefix . 'crt_ok';
		$dest    = 'https://93.184.216.34/healthy-dest';
		$checker = $this->create_checker(
			static fn() => array(
				'response_code' => 200,
				'duration_ms'   => 120,
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
				'title'          => 'Healthy Link',
				'customAlias'    => $code,
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 201, $data['status'] );
		$link_id = $data['data']['id'];

		$this->assertNotNull( $data['data']['health'] );
		$this->assertSame( Checker::STATUS_HEALTHY, $data['data']['health']['status'] );
		$this->assertSame( 200, $data['data']['health']['responseCode'] );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_row['status'] );
	}

	public function test_create_url_rejects_definitive_failures_and_creates_no_row(): void {
		$scenarios = array(
			'ssrf'        => array(
				'url'      => 'http://127.0.0.1:8080/blocked',
				'response' => array(),
			),
			'not_found'   => array(
				'url'      => 'https://93.184.216.34/missing',
				'response' => array(
					'response_code' => 404,
					'duration_ms'   => 80,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				),
			),
			'gone'        => array(
				'url'      => 'https://93.184.216.34/gone',
				'response' => array(
					'response_code' => 410,
					'duration_ms'   => 80,
					'error_code'    => 0,
					'error_message' => '',
					'redirect_url'  => null,
				),
			),
			'timeout'     => array(
				'url'      => 'https://93.184.216.34/timed-out',
				'response' => array(
					'response_code' => null,
					'duration_ms'   => 3000,
					'error_code'    => 28,
					'error_message' => 'Operation timed out',
					'redirect_url'  => null,
				),
			),
			'tls_error'   => array(
				'url'      => 'https://93.184.216.34/tls-broken',
				'response' => array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 35,
					'error_message' => 'SSL connect error',
					'redirect_url'  => null,
				),
			),
			'unreachable' => array(
				'url'      => 'https://93.184.216.34/unreachable',
				'response' => array(
					'response_code' => null,
					'duration_ms'   => 50,
					'error_code'    => 7,
					'error_message' => 'Failed to connect',
					'redirect_url'  => null,
				),
			),
		);

		foreach ( $scenarios as $type => $case ) {
			$prober  = ! empty( $case['response'] ) ? static fn() => $case['response'] : null;
			$checker = $this->create_checker( $prober );
			$router  = $this->create_router_with_checker( $checker );
			$code    = $this->test_prefix . 'rej_' . $type;

			$request = $this->admin_request(
				'POST',
				'/api/v1/urls',
				array(),
				array(
					'destinationUrl' => $case['url'],
					'title'          => 'Broken Link ' . $type,
					'customAlias'    => $code,
				)
			);
			$data    = $this->dispatch( $request, $router );

			$this->assertFalse( $data['success'], "Creation should fail for {$type}" );
			$this->assertSame( 422, $data['status'], "Status should be 422 for {$type}" );
			$this->assertNotEmpty( $data['message'] );

			$url_row = $this->db->get_row_by( 'urls', array( 'alias' => $code ) );
			$this->assertNull( $url_row, "No URL row should exist for rejected {$type}" );
		}
	}

	public function test_create_url_with_non_definitive_http_status_saves_and_records_health(): void {
		$code    = $this->test_prefix . 'non_def';
		$dest    = 'https://93.184.216.34/auth-required';
		$checker = $this->create_checker(
			static fn() => array(
				'response_code' => 403,
				'duration_ms'   => 90,
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
				'title'          => 'Protected Link',
				'customAlias'    => $code,
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertTrue( $data['success'] );
		$this->assertSame( 201, $data['status'] );
		$link_id = $data['data']['id'];

		$this->assertNotNull( $data['data']['health'] );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $data['data']['health']['status'] );
		$this->assertSame( 403, $data['data']['health']['responseCode'] );

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HTTP_ERROR, $health_row['status'] );
		$this->assertSame( 403, (int) $health_row['response_code'] );
	}

	public function test_bulk_create_preserves_per_item_health_validation_errors(): void {
		$code_ok  = $this->test_prefix . 'blkok';
		$code_bad = $this->test_prefix . 'blkbad';

		$checker = $this->create_checker(
			static function ( string $url ): array {
				if ( str_contains( $url, 'bad' ) ) {
					return array(
						'response_code' => 404,
						'duration_ms'   => 50,
						'error_code'    => 0,
						'error_message' => '',
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
		$router  = $this->create_router_with_checker( $checker );

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
		$this->assertCount( 1, $data['data']['results'] );
		$this->assertCount( 1, $data['data']['errors'] );
		$this->assertSame( $code_bad, $data['data']['errors'][0]['alias'] );

		$ok_row = $this->db->get_row_by( 'urls', array( 'alias' => $code_ok ) );
		$this->assertNotNull( $ok_row );
		$ok_health = $this->db->get_row_by( 'link_health', array( 'link_id' => $ok_row['id'] ) );
		$this->assertNotNull( $ok_health );

		$bad_row = $this->db->get_row_by( 'urls', array( 'alias' => $code_bad ) );
		$this->assertNull( $bad_row );
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

		$health_row = $this->db->get_row_by( 'link_health', array( 'link_id' => $link_id ) );
		$this->assertNotNull( $health_row );
		$this->assertSame( Checker::STATUS_HEALTHY, $health_row['status'] );
		$this->assertSame( 100, (int) $health_row['response_time_ms'] );
	}

	public function test_update_url_rejects_broken_destination_and_preserves_original(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = $this->test_prefix . 'upd_rej';

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Keep Link', 'https://93.184.216.34/keep', 'active', '{$now}', '{$now}')"
		);

		$checker = $this->create_checker(
			static fn() => array(
				'response_code' => 404,
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
				'destinationUrl' => 'https://93.184.216.34/broken',
			)
		);
		$data    = $this->dispatch( $request, $router );

		$this->assertFalse( $data['success'] );
		$this->assertSame( 422, $data['status'] );

		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
		$this->assertSame( 'https://93.184.216.34/keep', $url_row['destination_url'] );
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

	public function test_existing_version_10_installation_reconciles_link_health_table_without_schema_bump(): void {
		$settings_api = new \PeakURL\Api\SettingsApi( $this->db );
		$settings_api->update_option( \PeakURL\Core\Config\Constants::SETTING_DB_SCHEMA_VERSION, '10', Date::now() );

		// Ensure schema version remains 10
		$this->assertSame( 10, \PeakURL\Core\Config\Constants::DB_SCHEMA_VERSION );

		// Temporarily drop link_health table to represent an existing v10 install that had not yet run table creation
		$this->pdo->exec( "DROP TABLE IF EXISTS {$this->table_prefix}link_health" );

		// Run schema reconciliation/upgrade
		$context = new \PeakURL\Services\Database\Context( $this->connection, $settings_api );
		$upgrade = new \PeakURL\Services\Database\Upgrade(
			$context,
			dirname( __DIR__, 3 ) . '/database/schema.sql'
		);
		$changes = array();
		$upgrade->upgrade( $changes );

		// link_health must be present and queryable
		$tables = $context->get_pdo()->query( "SHOW TABLES LIKE '{$this->table_prefix}link_health'" )->fetchAll();
		$this->assertNotEmpty( $tables, 'link_health table must be restored by reconciliation.' );

		// Verify schema version in settings is still 10 without bump
		$this->assertSame( '10', (string) $settings_api->get_option( \PeakURL\Core\Config\Constants::SETTING_DB_SCHEMA_VERSION ) );
	}
}
