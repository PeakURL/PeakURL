<?php
/**
 * Integration tests for database security hardening and PeakURL_DB boundaries.
 *
 * @package PeakURL\Tests\Integration\Database
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Links\Repository as LinkRepository;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

class DatabaseSecurityHardeningTest extends TestCase {

	private Connection $connection;
	private PeakURL_DB $db;
	private WebhooksService $webhooks_service;
	private LinkRepository $link_repository;

	protected function setUp(): void {
		parent::setUp();

		$config           = Configuration::get_current();
		$this->connection = Connection::get_instance( $config );
		$this->db         = new PeakURL_DB( $this->connection );

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$crypto        = new Crypto( $config );

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => 1,
					'username' => 'admin',
					'role'     => 'admin',
				)
			);

		$validator              = new WebhooksValidator();
		$this->webhooks_service = new WebhooksService(
			$this->db,
			$validator,
			$auth_service,
			$roles,
			$authorization,
			$config,
			$crypto
		);

		$links_api             = new \PeakURL\Api\LinksApi( $this->db );
		$this->link_repository = new LinkRepository( $this->db, $links_api, $authorization );

		$this->db->begin_transaction();

		$now = Date::now();
		$this->db->upsert(
			'users',
			array(
				'id'                => 1,
				'username'          => 'sec_admin',
				'email'             => 'sec_admin@example.com',
				'first_name'        => 'Sec',
				'last_name'         => 'Admin',
				'password_hash'     => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'role'              => 'admin',
				'is_email_verified' => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( 'role', 'updated_at' )
		);
	}

	protected function tearDown(): void {
		if ( isset( $this->db ) && $this->db->in_transaction() ) {
			$this->db->roll_back();
		}
		parent::tearDown();
	}

	public function test_runtime_values_with_sql_metacharacters_remain_literal_data(): void {
		$malicious_key = "sec_test_' OR '1'='1";
		$malicious_val = "value'; DROP TABLE peakurl_settings; --";

		$this->db->upsert(
			'settings',
			array(
				'setting_key'   => $malicious_key,
				'setting_value' => $malicious_val,
				'updated_at'    => Date::now(),
			),
			array( 'setting_value', 'updated_at' )
		);

		$fetched_val = $this->db->get_var_by(
			'settings',
			'setting_value',
			array( 'setting_key' => $malicious_key )
		);

		$this->assertSame(
			$malicious_val,
			$fetched_val,
			'Value containing SQL metacharacters must be persisted and retrieved as literal data.'
		);

		$this->db->delete( 'settings', array( 'setting_key' => $malicious_key ) );
	}

	public function test_dynamic_in_placeholders_with_injection_strings_executes_safely(): void {
		$injection_ids = array(
			"' OR '1'='1",
			"nonexistent_id'; SELECT * FROM users; --",
			'normal_prefix_' . Str::random_id( 8 ),
		);

		$in_clause = $this->db->in_placeholders( $injection_ids, 'test_sec' );

		$rows = $this->db->get_results(
			'SELECT * FROM cron_jobs WHERE id IN (' . $in_clause['sql'] . ')',
			$in_clause['params']
		);

		$this->assertSame(
			array(),
			$rows,
			'Dynamic IN query with injection strings must not match all rows or trigger SQL errors.'
		);
	}

	public function test_batch_load_webhook_health_uses_bound_parameters_safely(): void {
		$clean_hook_id = Str::random_id( 16 );
		$now           = Date::now();

		$this->db->insert(
			'webhooks',
			array(
				'id'         => $clean_hook_id,
				'user_id'    => 1,
				'label'      => 'Sec Hook',
				'url'        => 'https://example.com/sec',
				'secret'     => 'secret',
				'events'     => '["link.created"]',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$deliv_id = Str::random_id( 16 );
		$this->db->insert(
			'webhook_deliveries',
			array(
				'id'              => $deliv_id,
				'webhook_id'      => $clean_hook_id,
				'event_id'        => 'evt_1',
				'event'           => 'link.created',
				'payload'         => '{}',
				'status'          => 'delivered',
				'attempts'        => 1,
				'max_attempts'    => 3,
				'next_attempt_at' => $now,
				'completed_at'    => $now,
				'response_code'   => 200,
				'last_error'      => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$ids_to_query = array( $clean_hook_id, "' OR 1=1 --" );
		$method       = new \ReflectionMethod( WebhooksService::class, 'batch_load_webhook_health' );
		$health_map   = $method->invoke( $this->webhooks_service, $ids_to_query );

		$this->assertArrayHasKey( $clean_hook_id, $health_map );
		$this->assertSame( 1, $health_map[ $clean_hook_id ]['total24h'] );
		$this->assertSame( 0, $health_map[ $clean_hook_id ]['failed24h'] );
		$this->assertSame( 'delivered', $health_map[ $clean_hook_id ]['lastStatus'] );
		$this->assertSame( 200, $health_map[ $clean_hook_id ]['lastResponseCode'] );

		$this->db->delete( 'webhook_deliveries', array( 'webhook_id' => $clean_hook_id ) );
		$this->db->delete( 'webhooks', array( 'id' => $clean_hook_id ) );
	}

	public function test_link_health_persistence_preserves_error_message_metacharacters(): void {
		$link_id           = Str::random_id( 16 );
		$short_code        = 'sec_' . substr( Str::random_id( 6 ), 0, 6 );
		$now               = Date::now();
		$malicious_message = "HTTP 500: syntax error near 'SELECT * FROM users WHERE id=''";

		$this->db->insert(
			'urls',
			array(
				'id'              => $link_id,
				'user_id'         => 1,
				'short_code'      => $short_code,
				'alias'           => $short_code,
				'destination_url' => 'https://example.com/target',
				'title'           => 'Target',
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$saved = $this->link_repository->save_link_health(
			$link_id,
			array(
				'status'           => 'http_error',
				'checked_at'       => $now,
				'response_code'    => 500,
				'response_time_ms' => 120,
				'error_message'    => $malicious_message,
				'redirect_count'   => 0,
			)
		);

		$this->assertTrue( $saved );

		$health_map = $this->link_repository->get_link_health_by_ids( array( $link_id, "' OR 1=1 --" ) );
		$this->assertArrayHasKey( $link_id, $health_map );
		$this->assertSame( $malicious_message, $health_map[ $link_id ]['error_message'] );

		$this->link_repository->delete_link_health( $link_id );
		$this->db->delete( 'urls', array( 'id' => $link_id ) );
	}

	public function test_scheduler_atomic_claim_locking_behavior_intact(): void {
		$repo       = new SchedulerRepository( $this->db );
		$job_id     = 'sec_test_job_' . Str::random_id( 8 );
		$lock_token = Str::random_id( 16 );

		$scheduled_id = $repo->enqueue_job( $job_id, 'Security Test Job', 60 );
		$this->assertSame( $job_id, $scheduled_id );

		$claimed = $repo->claim_job( $job_id, $lock_token, 300, true );
		$this->assertTrue( $claimed, 'First claim must succeed.' );

		$second_token = Str::random_id( 16 );
		$claimed_2    = $repo->claim_job( $job_id, $second_token, 300, true );
		$this->assertFalse( $claimed_2, 'Second claim while lock is held must fail.' );

		$repo->record_success( $job_id, 'run_1', $lock_token, Date::now(), Date::now(), 50 );

		$job_row = $repo->get_job( $job_id );
		$this->assertNotNull( $job_row );
		$this->assertNull( $job_row['lock_token'] );
		$this->assertSame( 'idle', $job_row['status'] );

		$this->db->delete( 'cron_jobs', array( 'id' => $job_id ) );
	}

	/**
	 * Verify production Webhooks methods safely normalize and bound numeric limits on live database.
	 */
	public function test_production_webhook_methods_safely_bound_numeric_limits_on_live_db(): void {
		$clean_hook_id = Str::random_id( 16 );
		$now           = Date::now();

		$this->db->insert(
			'webhooks',
			array(
				'id'         => $clean_hook_id,
				'user_id'    => 1,
				'label'      => 'Limits Test Hook',
				'url'        => 'https://example.com/limits-test',
				'secret'     => 'secret',
				'events'     => '["link.created"]',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->db->insert(
				'webhook_deliveries',
				array(
					'id'              => Str::random_id( 16 ),
					'webhook_id'      => $clean_hook_id,
					'event_id'        => 'evt_' . $i,
					'event'           => 'link.created',
					'payload'         => '{}',
					'status'          => 'delivered',
					'attempts'        => 1,
					'max_attempts'    => 3,
					'next_attempt_at' => $now,
					'completed_at'    => $now,
					'response_code'   => 200,
					'last_error'      => null,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		}

		// 1. Test list_deliveries pagination normalization and bounds via real Request against live DB
		$cases = array(
			// SQL-looking input: must cast safely to integer, execute valid SQL, not inject syntax
			array(
				'query'             => array(
					'per_page' => '1; DROP TABLE webhook_deliveries; --',
					'page'     => '1 UNION SELECT 1',
				),
				'expected_per_page' => 1,
				'expected_page'     => 1,
			),
			// Negative inputs: must bound to minimum limit of 1
			array(
				'query'             => array(
					'per_page' => '-50',
					'page'     => '-10',
				),
				'expected_per_page' => 1,
				'expected_page'     => 1,
			),
			// Zero inputs: must bound to minimum limit of 1
			array(
				'query'             => array(
					'per_page' => '0',
					'page'     => '0',
				),
				'expected_per_page' => 1,
				'expected_page'     => 1,
			),
			// Excessively large inputs: must bound to maximum limit of 50
			array(
				'query'             => array(
					'per_page' => '99999999',
					'page'     => '1',
				),
				'expected_per_page' => 50,
				'expected_page'     => 1,
			),
			// Normal value within bounds
			array(
				'query'             => array(
					'per_page' => '2',
					'page'     => '1',
				),
				'expected_per_page' => 2,
				'expected_page'     => 1,
			),
		);

		foreach ( $cases as $case ) {
			$request = new Request(
				'GET',
				'/api/v1/webhooks/' . $clean_hook_id . '/deliveries',
				$case['query'],
				array()
			);

			$result = $this->webhooks_service->list_deliveries( $request, $clean_hook_id );

			$this->assertSame( $case['expected_per_page'], $result['meta']['perPage'] );
			$this->assertSame( $case['expected_page'], $result['meta']['page'] );
			$this->assertLessThanOrEqual( $case['expected_per_page'], count( $result['items'] ) );
		}

		// 2. Test process_pending_deliveries with negative, zero, oversized, and normal batch limits
		$processed_neg = $this->webhooks_service->process_pending_deliveries( -50 );
		$this->assertIsArray( $processed_neg );
		$this->assertArrayHasKey( 'processed', $processed_neg );

		$processed_zero = $this->webhooks_service->process_pending_deliveries( 0 );
		$this->assertIsArray( $processed_zero );
		$this->assertArrayHasKey( 'processed', $processed_zero );

		$processed_large = $this->webhooks_service->process_pending_deliveries( 99999 );
		$this->assertIsArray( $processed_large );
		$this->assertArrayHasKey( 'processed', $processed_large );

		$processed_normal = $this->webhooks_service->process_pending_deliveries( 10 );
		$this->assertIsArray( $processed_normal );
		$this->assertArrayHasKey( 'processed', $processed_normal );

		// 3. Test cleanup_delivery_history with negative, zero, oversized, and normal limits
		$cleaned_neg = $this->webhooks_service->cleanup_delivery_history( -10, -50 );
		$this->assertIsInt( $cleaned_neg );

		$cleaned_zero = $this->webhooks_service->cleanup_delivery_history( 0, 0 );
		$this->assertIsInt( $cleaned_zero );

		$cleaned_large = $this->webhooks_service->cleanup_delivery_history( 30, 99999 );
		$this->assertIsInt( $cleaned_large );

		$cleaned_normal = $this->webhooks_service->cleanup_delivery_history( 30, 100 );
		$this->assertIsInt( $cleaned_normal );

		// 4. Verify outer transaction remains active and unbroken by production execution
		$this->assertTrue( $this->db->in_transaction(), 'Production webhook methods must not commit or break the outer transaction.' );

		// Clean up
		$this->db->delete( 'webhook_deliveries', array( 'webhook_id' => $clean_hook_id ) );
		$this->db->delete( 'webhooks', array( 'id' => $clean_hook_id ) );
	}
}
