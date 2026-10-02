<?php
/**
 * Integration tests for Link Health webhook event expansion.
 *
 * Verifies that health events adhere to the transition matrix, enforce
 * owner/subscription filtering, generate distinct event IDs, and omit events
 * on stale or failed checks.
 *
 * @package PeakURL\Tests\Integration\Webhooks
 * @since 1.7.1
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
use PeakURL\Features\Links\Health\Checker;
use PeakURL\Features\Links\Health\Context as HealthContext;
use PeakURL\Features\Links\Health\Probe;
use PeakURL\Features\Links\Health\Resolver;
use PeakURL\Features\Links\Jobs\LinkHealthCheckJob;
use PeakURL\Features\Links\Repository as LinkRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

class LinkHealthWebhooksIntegrationTest extends TestCase {

	private Connection $connection;
	private PeakURL_DB $db;
	private WebhooksService $webhooks_service;
	private LinkRepository $link_repository;

	protected function setUp(): void {
		parent::setUp();

		$config           = Configuration::get_current();
		$this->connection = Connection::get_instance( $config );
		$this->db         = new PeakURL_DB( $this->connection );

		$this->db->begin_transaction();

		$now = Date::now();
		$this->db->upsert(
			'users',
			array(
				'id'                => 1,
				'username'          => 'hlth_admin',
				'email'             => 'hlth_admin@example.com',
				'first_name'        => 'Health',
				'last_name'         => 'Admin',
				'password_hash'     => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'role'              => 'admin',
				'is_email_verified' => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( 'role', 'updated_at' )
		);

		$this->db->upsert(
			'users',
			array(
				'id'                => 2,
				'username'          => 'hlth_editor',
				'email'             => 'hlth_editor@example.com',
				'first_name'        => 'Health',
				'last_name'         => 'Editor',
				'password_hash'     => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
				'role'              => 'editor',
				'is_email_verified' => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( 'role', 'updated_at' )
		);

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$crypto        = new Crypto( $config );

		$auth_service = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => 1,
					'username' => 'hlth_admin',
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
	}

	protected function tearDown(): void {
		if ( isset( $this->db ) && $this->db->in_transaction() ) {
			$this->db->roll_back();
		}
		parent::tearDown();
	}

	public function test_health_event_dispatch_respects_subscriptions_and_owner_filtering(): void {
		$now = Date::now();

		// Webhook A: owned by admin (user 1), subscribed to checked and broken
		$hook_a = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_a,
				'user_id'    => 1,
				'label'      => 'Admin Hook A',
				'url'        => 'https://example.com/webhook-a',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// Webhook B: owned by admin (user 1), subscribed only to unreachable
		$hook_b = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_b,
				'user_id'    => 1,
				'label'      => 'Admin Hook B',
				'url'        => 'https://example.com/webhook-b',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.unreachable' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// Webhook C: owned by editor (user 2), subscribed to checked
		$hook_c = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_c,
				'user_id'    => 2,
				'label'      => 'Editor Hook C',
				'url'        => 'https://example.com/webhook-c',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'hlth_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/target',
			'title'           => 'Health Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$current_health = array(
			'status'           => 'timeout',
			'checked_at'       => $now,
			'response_code'    => null,
			'response_time_ms' => 3000,
			'error_message'    => 'Operation timed out.',
			'redirect_count'   => 0,
		);

		$prev_health = array(
			'status'           => 'healthy',
			'checked_at'       => $now,
			'response_code'    => 200,
			'response_time_ms' => 120,
			'error_message'    => null,
			'redirect_count'   => 0,
		);

		$results = $this->webhooks_service->dispatch_link_health_check( $link, $current_health, $prev_health );

		// Hook A subscribed to checked and broken: must receive deliveries for both
		$this->assertArrayHasKey( 'link.health.checked', $results );
		$this->assertArrayHasKey( 'link.health.broken', $results );
		$this->assertNotEmpty( $results['link.health.checked'] );
		$this->assertNotEmpty( $results['link.health.broken'] );

		$delivs_a_checked = array_filter( $results['link.health.checked'], fn( $d ) => $d['webhookId'] === $hook_a );
		$delivs_a_broken  = array_filter( $results['link.health.broken'], fn( $d ) => $d['webhookId'] === $hook_a );
		$this->assertCount( 1, $delivs_a_checked );
		$this->assertCount( 1, $delivs_a_broken );

		// Hook B only subscribed to unreachable: must NOT receive deliveries for timeout/broken/checked
		$delivs_b_checked = array_filter( $results['link.health.checked'], fn( $d ) => $d['webhookId'] === $hook_b );
		$delivs_b_broken  = array_filter( $results['link.health.broken'], fn( $d ) => $d['webhookId'] === $hook_b );
		$this->assertEmpty( $delivs_b_checked );
		$this->assertEmpty( $delivs_b_broken );

		// Hook C owned by editor (user 2): must NOT receive events for admin (user 1) link
		$delivs_c_checked = array_filter( $results['link.health.checked'], fn( $d ) => $d['webhookId'] === $hook_c );
		$this->assertEmpty( $delivs_c_checked );
	}

	public function test_different_event_types_generate_different_event_ids(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Multi Sub Hook',
				'url'        => 'https://example.com/multi',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.timeout' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link = array(
			'id'              => Str::random_id( 16 ),
			'user_id'         => 1,
			'short_code'      => 'code_' . substr( Str::random_id( 6 ), 0, 6 ),
			'alias'           => 'alias_' . substr( Str::random_id( 6 ), 0, 6 ),
			'destination_url' => 'https://example.com/target',
			'title'           => 'Multi Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$results = $this->webhooks_service->dispatch_link_health_check(
			$link,
			array(
				'status'           => 'timeout',
				'checked_at'       => $now,
				'response_code'    => null,
				'response_time_ms' => 3000,
				'error_message'    => 'Timed out.',
				'redirect_count'   => 0,
			),
			array(
				'status'           => 'healthy',
				'checked_at'       => $now,
				'response_code'    => 200,
				'response_time_ms' => 100,
				'error_message'    => null,
				'redirect_count'   => 0,
			)
		);

		// 4 events produced: checked, changed, broken, timeout
		$this->assertArrayHasKey( 'link.health.checked', $results );
		$this->assertArrayHasKey( 'link.health.changed', $results );
		$this->assertArrayHasKey( 'link.health.broken', $results );
		$this->assertArrayHasKey( 'link.health.timeout', $results );

		$evt_checked = $results['link.health.checked'][0]['eventId'];
		$evt_changed = $results['link.health.changed'][0]['eventId'];
		$evt_broken  = $results['link.health.broken'][0]['eventId'];
		$evt_timeout = $results['link.health.timeout'][0]['eventId'];

		// Verify event IDs start with standard prefix
		$this->assertStringStartsWith( 'peakurl_evt_', $evt_checked );
		$this->assertStringStartsWith( 'peakurl_evt_', $evt_changed );
		$this->assertStringStartsWith( 'peakurl_evt_', $evt_broken );
		$this->assertStringStartsWith( 'peakurl_evt_', $evt_timeout );

		// Verify every event type gets a distinct event ID
		$event_ids = array( $evt_checked, $evt_changed, $evt_broken, $evt_timeout );
		$this->assertCount( 4, array_unique( $event_ids ), 'Different event types must have distinct event IDs.' );
	}

	public function test_link_health_check_job_integration_with_webhooks(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Job Integration Hook',
				'url'        => 'https://example.com/job-hook',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.degraded' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'job_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/dest',
			'title'           => 'Job Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		// Seed initial healthy status in link_health
		$this->db->insert(
			'link_health',
			array(
				'link_id'          => $link_id,
				'status'           => 'healthy',
				'checked_at'       => $now,
				'response_code'    => 200,
				'response_time_ms' => 100,
				'error_message'    => null,
				'redirect_count'   => 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		// Mock checker that returns slow (degraded)
		$mock_checker = $this->createMock( Checker::class );
		$mock_checker->method( 'check' )
			->willReturn(
				array(
					'status'           => 'slow',
					'response_code'    => 200,
					'response_time_ms' => 1800,
					'error_message'    => null,
					'redirect_count'   => 0,
				)
			);

		$job    = new LinkHealthCheckJob( $this->db, $mock_checker, 10, $this->webhooks_service );
		$result = $job->execute_targeted( $link_id );

		$this->assertTrue( $result->is_success() );

		// Verify deliveries queued in database
		$deliveries = $this->db->get_results(
			'SELECT * FROM webhook_deliveries WHERE webhook_id = :id ORDER BY event ASC',
			array( 'id' => $hook_id )
		);

		$events_queued = array_column( $deliveries, 'event' );
		$this->assertContains( 'link.health.checked', $events_queued );
		$this->assertContains( 'link.health.changed', $events_queued );
		$this->assertContains( 'link.health.degraded', $events_queued );

		// Verify payload data of degraded delivery
		$degraded_deliv = current( array_filter( $deliveries, fn( $d ) => 'link.health.degraded' === $d['event'] ) );
		$this->assertNotEmpty( $degraded_deliv );
		$payload = json_decode( (string) $degraded_deliv['payload'], true );

		$this->assertSame( 'slow', $payload['data']['health']['status'] );
		$this->assertSame( 1800, $payload['data']['health']['responseTimeMs'] );
		$this->assertSame( 'healthy', $payload['data']['previousHealth']['status'] );
		$this->assertSame( 100, $payload['data']['previousHealth']['responseTimeMs'] );
	}

	public function test_stale_health_checks_emit_zero_webhook_events(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Stale Test Hook',
				'url'        => 'https://example.com/stale-hook',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'stale_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/original',
			'title'           => 'Stale Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		// Case 1: Destination changed while check was in progress
		$mock_checker = $this->createMock( Checker::class );
		$mock_checker->method( 'check' )
			->willReturnCallback(
				function () use ( $link_id ) {
					$this->db->update( 'urls', array( 'destination_url' => 'https://example.com/new-dest' ), array( 'id' => $link_id ) );
					return array(
						'status'           => 'healthy',
						'response_code'    => 200,
						'response_time_ms' => 100,
						'error_message'    => null,
						'redirect_count'   => 0,
					);
				}
			);

		$job    = new LinkHealthCheckJob( $this->db, $mock_checker, 10, $this->webhooks_service );
		$result = $job->execute_targeted( $link_id );

		$this->assertTrue( $result->is_skipped() );

		$delivs = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs, 'Stale destination change must emit zero webhook events.' );

		// Case 2: Link deactivated while check was in progress
		$mock_checker_deact = $this->createMock( Checker::class );
		$mock_checker_deact->method( 'check' )
			->willReturnCallback(
				function () use ( $link_id ) {
					$this->db->update( 'urls', array( 'status' => 'inactive' ), array( 'id' => $link_id ) );
					return array(
						'status'           => 'healthy',
						'response_code'    => 200,
						'response_time_ms' => 100,
						'error_message'    => null,
						'redirect_count'   => 0,
					);
				}
			);

		$job_deact    = new LinkHealthCheckJob( $this->db, $mock_checker_deact, 10, $this->webhooks_service );
		$result_deact = $job_deact->execute_targeted( $link_id );

		$this->assertTrue( $result_deact->is_skipped() );

		$delivs_deact = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs_deact, 'Deactivated link check must emit zero webhook events.' );
	}

	public function test_repeated_identical_status_emits_only_checked_event(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Identical Status Hook',
				'url'        => 'https://example.com/identical-hook',
				'secret'     => 'secret',
				'events'     => json_encode(
					array(
						'link.health.checked',
						'link.health.changed',
						'link.health.recovered',
						'link.health.broken',
						'link.health.http_error',
					)
				),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link = array(
			'id'              => Str::random_id( 16 ),
			'user_id'         => 1,
			'short_code'      => 'code_' . substr( Str::random_id( 6 ), 0, 6 ),
			'alias'           => 'alias_' . substr( Str::random_id( 6 ), 0, 6 ),
			'destination_url' => 'https://example.com/target',
			'title'           => 'Repeat Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		// 1. Previous = healthy, new = healthy (duration changed: 100ms -> 300ms)
		$results_healthy = $this->webhooks_service->dispatch_link_health_check(
			$link,
			array(
				'status'           => 'healthy',
				'checked_at'       => $now,
				'response_code'    => 200,
				'response_time_ms' => 300,
				'error_message'    => null,
				'redirect_count'   => 0,
			),
			array(
				'status'           => 'healthy',
				'checked_at'       => $now,
				'response_code'    => 200,
				'response_time_ms' => 100,
				'error_message'    => null,
				'redirect_count'   => 0,
			)
		);

		// Must ONLY emit link.health.checked
		$this->assertArrayHasKey( 'link.health.checked', $results_healthy );
		$this->assertArrayNotHasKey( 'link.health.changed', $results_healthy );
		$this->assertArrayNotHasKey( 'link.health.recovered', $results_healthy );

		// 2. Previous = http_error (500), new = http_error (502) -> status remained http_error
		$results_error = $this->webhooks_service->dispatch_link_health_check(
			$link,
			array(
				'status'           => 'http_error',
				'checked_at'       => $now,
				'response_code'    => 502,
				'response_time_ms' => 200,
				'error_message'    => 'HTTP 502 Bad Gateway',
				'redirect_count'   => 0,
			),
			array(
				'status'           => 'http_error',
				'checked_at'       => $now,
				'response_code'    => 500,
				'response_time_ms' => 200,
				'error_message'    => 'HTTP 500 Internal Server Error',
				'redirect_count'   => 0,
			)
		);

		// Must ONLY emit link.health.checked
		$this->assertArrayHasKey( 'link.health.checked', $results_error );
		$this->assertArrayNotHasKey( 'link.health.changed', $results_error );
		$this->assertArrayNotHasKey( 'link.health.broken', $results_error );
		$this->assertArrayNotHasKey( 'link.health.http_error', $results_error );
	}

	public function test_link_health_check_job_constructor_requires_webhooks_service(): void {
		$reflector   = new \ReflectionClass( LinkHealthCheckJob::class );
		$constructor = $reflector->getConstructor();
		$this->assertNotNull( $constructor );

		$params = $constructor->getParameters();
		$this->assertCount( 4, $params );

		$wh_param = $params[3];
		$this->assertSame( 'webhooks_service', $wh_param->getName() );
		$this->assertSame( WebhooksService::class, (string) $wh_param->getType() );
		$this->assertFalse( $wh_param->isOptional(), 'WebhooksService must be a required parameter.' );
		$this->assertFalse( $wh_param->allowsNull(), 'WebhooksService must not allow null.' );
	}

	public function test_previous_health_read_failure_fails_closed(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Read Failure Hook',
				'url'        => 'https://example.com/read-fail-hook',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'rdfail_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/dest',
			'title'           => 'Read Fail Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$mock_db = $this->createMock( PeakURL_DB::class );
		$mock_db->method( 'get_row_by' )
			->willReturnCallback(
				function ( string $table, array $where ) use ( $link ) {
					if ( 'urls' === $table ) {
						return $link;
					}
					if ( 'link_health' === $table ) {
						throw new \PDOException( 'Database connection dropped reading previous health' );
					}
					return null;
				}
			);

		$checker = $this->createMock( Checker::class );
		$checker->method( 'check' )
			->willReturn(
				array(
					'status'           => 'healthy',
					'response_code'    => 200,
					'response_time_ms' => 120,
					'error_message'    => null,
					'redirect_count'   => 0,
				)
			);

		$job    = new LinkHealthCheckJob( $mock_db, $checker, 10, $this->webhooks_service );
		$result = $job->execute_targeted( $link_id );

		$this->assertTrue( $result->is_failure(), 'Previous-health read failure must fail closed.' );

		$delivs = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs, 'Previous-health read failure must emit zero webhook events.' );
	}

	public function test_scheduled_rotating_check_stale_results_do_not_increment_persistence_failures(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Stale Sweep Hook',
				'url'        => 'https://example.com/stale-sweep',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// Link 1: Will have destination changed in-flight
		$link1_id = Str::random_id( 16 );
		$code1    = 'swp1_' . substr( Str::random_id( 6 ), 0, 6 );
		$this->db->insert(
			'urls',
			array(
				'id'              => $link1_id,
				'user_id'         => 1,
				'short_code'      => $code1,
				'alias'           => $code1,
				'destination_url' => 'https://example.com/orig-1',
				'title'           => 'Sweep 1',
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		// Link 2: Will be deactivated in-flight
		$link2_id = Str::random_id( 16 );
		$code2    = 'swp2_' . substr( Str::random_id( 6 ), 0, 6 );
		$this->db->insert(
			'urls',
			array(
				'id'              => $link2_id,
				'user_id'         => 1,
				'short_code'      => $code2,
				'alias'           => $code2,
				'destination_url' => 'https://example.com/orig-2',
				'title'           => 'Sweep 2',
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		// Link 3: Normal active link
		$link3_id = Str::random_id( 16 );
		$code3    = 'swp3_' . substr( Str::random_id( 6 ), 0, 6 );
		$this->db->insert(
			'urls',
			array(
				'id'              => $link3_id,
				'user_id'         => 1,
				'short_code'      => $code3,
				'alias'           => $code3,
				'destination_url' => 'https://example.com/orig-3',
				'title'           => 'Sweep 3',
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$mock_checker = $this->createMock( Checker::class );
		$mock_checker->method( 'check' )
			->willReturnCallback(
				function ( string $url ) use ( $link1_id, $link2_id ) {
					if ( 'https://example.com/orig-1' === $url ) {
						$this->db->update( 'urls', array( 'destination_url' => 'https://example.com/new-dest-1' ), array( 'id' => $link1_id ) );
					}
					if ( 'https://example.com/orig-2' === $url ) {
						$this->db->update( 'urls', array( 'status' => 'inactive' ), array( 'id' => $link2_id ) );
					}
					return array(
						'status'           => 'healthy',
						'response_code'    => 200,
						'response_time_ms' => 150,
						'error_message'    => null,
						'redirect_count'   => 0,
					);
				}
			);

		$job     = new LinkHealthCheckJob( $this->db, $mock_checker, 10, $this->webhooks_service );
		$context = new \PeakURL\Core\Scheduler\ExecutionContext( 'peakurl_link_health_check', 'run_stale_test', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$meta = $result->get_metadata();
		$this->assertSame( 0, $meta['persistenceFailures'], 'Stale skips must not increment persistenceFailures.' );
		$this->assertSame( 1, $meta['healthy'] );

		// Only Link 3 should have emitted events; 0 events for Link 1 and Link 2.
		$delivs = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertCount( 1, $delivs );
		$payload = json_decode( (string) $delivs[0]['payload'], true );
		$this->assertSame( $link3_id, $payload['data']['id'] );
	}

	public function test_failed_persistence_produces_no_webhook_events(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Persist Fail Hook',
				'url'        => 'https://example.com/persist-fail-hook',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'pfail_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/dest',
			'title'           => 'Persist Fail Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$mock_db = $this->createMock( PeakURL_DB::class );
		$mock_db->method( 'get_row_by' )
			->willReturnCallback(
				function ( string $table, array $where ) use ( $link ) {
					if ( 'urls' === $table ) {
						return $link;
					}
					return null;
				}
			);
		$mock_db->method( 'upsert' )
			->willThrowException( new \PDOException( 'Disk full or persistence failed' ) );

		$checker = $this->createMock( Checker::class );
		$checker->method( 'check' )
			->willReturn(
				array(
					'status'           => 'healthy',
					'response_code'    => 200,
					'response_time_ms' => 120,
					'error_message'    => null,
					'redirect_count'   => 0,
				)
			);

		$job    = new LinkHealthCheckJob( $mock_db, $checker, 10, $this->webhooks_service );
		$result = $job->execute_targeted( $link_id );

		$this->assertTrue( $result->is_failure(), 'Failed persistence must return failure.' );

		$delivs = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs, 'Failed persistence must emit zero webhook events.' );
	}

	public function test_event_and_delivery_identity_and_retries(): void {
		$now      = Date::now();
		$hook1_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook1_id,
				'user_id'    => 1,
				'label'      => 'Identity Hook 1',
				'url'        => 'https://example.com/hook1',
				'secret'     => 'secret1',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$hook2_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook2_id,
				'user_id'    => 1,
				'label'      => 'Identity Hook 2',
				'url'        => 'https://example.com/hook2',
				'secret'     => 'secret2',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.broken' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'ident_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/target',
			'title'           => 'Identity Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$current_health = array(
			'status'           => 'timeout',
			'checked_at'       => $now,
			'response_code'    => null,
			'response_time_ms' => 3000,
			'error_message'    => 'Timed out.',
			'redirect_count'   => 0,
		);

		$prev_health = array(
			'status'           => 'healthy',
			'checked_at'       => $now,
			'response_code'    => 200,
			'response_time_ms' => 100,
			'error_message'    => null,
			'redirect_count'   => 0,
		);

		$results = $this->webhooks_service->dispatch_link_health_check( $link, $current_health, $prev_health );

		// 1. Multiple subscribed webhooks for the same health event share one peakurl_evt_... event ID
		$broken_delivs = $results['link.health.broken'];
		$this->assertCount( 2, $broken_delivs );
		$this->assertSame( $broken_delivs[0]['eventId'], $broken_delivs[1]['eventId'], 'Subscribed webhooks for the same event must share eventId.' );

		// 2. Each delivery has its own unique delivery ID
		$this->assertNotSame( $broken_delivs[0]['deliveryId'], $broken_delivs[1]['deliveryId'], 'Each delivery must have a distinct deliveryId.' );
		$this->assertStringStartsWith( 'peakurl_del_', $broken_delivs[0]['deliveryId'] );
		$this->assertStringStartsWith( 'peakurl_del_', $broken_delivs[1]['deliveryId'] );

		// 3. Different health event types receive different event IDs
		$checked_delivs = $results['link.health.checked'];
		$this->assertNotSame( $broken_delivs[0]['eventId'], $checked_delivs[0]['eventId'], 'Different event types must receive distinct event IDs.' );

		// 4. Retries preserve original event ID and delivery ID
		$delivery_row_id = $broken_delivs[0]['deliveryId'];
		$original_evt_id = $broken_delivs[0]['eventId'];

		// Mark delivery as failed/retry attempt
		$this->db->update(
			'webhook_deliveries',
			array(
				'status'          => 'retry',
				'attempts'        => 1,
				'next_attempt_at' => Date::now(),
			),
			array( 'id' => $delivery_row_id )
		);

		$reloaded = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_row_id ) );
		$this->assertSame( $delivery_row_id, $reloaded['id'] );
		$this->assertSame( $original_evt_id, $reloaded['event_id'] );
	}

	public function test_manual_check_link_health_full_lifecycle_and_safety(): void {
		$now     = Date::now();
		$hook_id = Str::random_id( 16 );
		$this->db->insert(
			'webhooks',
			array(
				'id'         => $hook_id,
				'user_id'    => 1,
				'label'      => 'Manual Lifecycle Hook',
				'url'        => 'https://example.com/manual-hook',
				'secret'     => 'secret',
				'events'     => json_encode( array( 'link.health.checked', 'link.health.changed', 'link.health.broken', 'link.health.timeout' ) ),
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$link_id = Str::random_id( 16 );
		$code    = 'man_' . substr( Str::random_id( 6 ), 0, 6 );
		$link    = array(
			'id'              => $link_id,
			'user_id'         => 1,
			'short_code'      => $code,
			'alias'           => $code,
			'destination_url' => 'https://example.com/target',
			'title'           => 'Manual Test',
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$this->db->insert( 'urls', $link );

		$request = new \PeakURL\Http\Request( 'POST', "/api/v1/urls/{$link_id}/health-check", array(), array() );

		$config        = Configuration::get_current();
		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$auth_service  = $this->createMock( AuthService::class );
		$auth_service->method( 'get_current_user' )
			->willReturn(
				array(
					'id'       => 1,
					'username' => 'hlth_admin',
					'role'     => 'admin',
				)
			);
		$analytics_service = $this->createMock( \PeakURL\Features\Analytics\Service::class );
		$preview_service   = $this->createMock( \PeakURL\Services\SocialPreview::class );
		$captcha_service   = $this->createMock( \PeakURL\Services\Captcha::class );
		$settings_api      = new \PeakURL\Api\SettingsApi( $this->db );

		// 1. First check: healthy -> emits link.health.checked only
		$mock_checker1 = $this->createMock( Checker::class );
		$mock_checker1->method( 'check' )
			->willReturn(
				array(
					'status'           => 'healthy',
					'response_code'    => 200,
					'response_time_ms' => 120,
					'error_message'    => null,
					'redirect_count'   => 0,
				)
			);

		$links_service1 = new LinksService(
			$this->link_repository,
			new LinksValidator(),
			$settings_api,
			$auth_service,
			$analytics_service,
			$this->webhooks_service,
			$preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config,
			$mock_checker1
		);

		$health1 = $links_service1->check_link_health( $request, $link_id );
		$this->assertSame( 'healthy', $health1['status'] );

		$delivs1 = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertCount( 1, $delivs1 );
		$this->assertSame( 'link.health.checked', $delivs1[0]['event'] );

		// 2. Transition check: healthy -> timeout -> emits checked, changed, broken, timeout
		$this->db->delete( 'webhook_deliveries', array( 'webhook_id' => $hook_id ) );

		$mock_checker2 = $this->createMock( Checker::class );
		$mock_checker2->method( 'check' )
			->willReturn(
				array(
					'status'           => 'timeout',
					'response_code'    => null,
					'response_time_ms' => 3000,
					'error_message'    => 'Timed out.',
					'redirect_count'   => 0,
				)
			);

		$links_service2 = new LinksService(
			$this->link_repository,
			new LinksValidator(),
			$settings_api,
			$auth_service,
			$analytics_service,
			$this->webhooks_service,
			$preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config,
			$mock_checker2
		);

		$health2 = $links_service2->check_link_health( $request, $link_id );
		$this->assertSame( 'timeout', $health2['status'] );

		$delivs2 = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id ORDER BY id ASC', array( 'id' => $hook_id ) );
		$this->assertCount( 4, $delivs2 );
		$events2 = array_column( $delivs2, 'event' );
		$this->assertContains( 'link.health.checked', $events2 );
		$this->assertContains( 'link.health.changed', $events2 );
		$this->assertContains( 'link.health.broken', $events2 );
		$this->assertContains( 'link.health.timeout', $events2 );

		// 3. Unchanged status: timeout -> timeout -> emits checked only
		$this->db->delete( 'webhook_deliveries', array( 'webhook_id' => $hook_id ) );

		$mock_checker3 = $this->createMock( Checker::class );
		$mock_checker3->method( 'check' )
			->willReturn(
				array(
					'status'           => 'timeout',
					'response_code'    => null,
					'response_time_ms' => 3100,
					'error_message'    => 'Timed out again.',
					'redirect_count'   => 0,
				)
			);

		$links_service3 = new LinksService(
			$this->link_repository,
			new LinksValidator(),
			$settings_api,
			$auth_service,
			$analytics_service,
			$this->webhooks_service,
			$preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config,
			$mock_checker3
		);

		$health3 = $links_service3->check_link_health( $request, $link_id );
		$this->assertSame( 'timeout', $health3['status'] );

		$delivs3 = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertCount( 1, $delivs3 );
		$this->assertSame( 'link.health.checked', $delivs3[0]['event'] );

		// 4. Stale destination change during check: throws 409, 0 webhooks
		$this->db->delete( 'webhook_deliveries', array( 'webhook_id' => $hook_id ) );

		$mock_checker_stale = $this->createMock( Checker::class );
		$mock_checker_stale->method( 'check' )
			->willReturnCallback(
				function () use ( $link_id ) {
					$this->db->update( 'urls', array( 'destination_url' => 'https://example.com/modified' ), array( 'id' => $link_id ) );
					return array(
						'status'           => 'healthy',
						'response_code'    => 200,
						'response_time_ms' => 100,
						'error_message'    => null,
						'redirect_count'   => 0,
					);
				}
			);

		$links_service_stale = new LinksService(
			$this->link_repository,
			new LinksValidator(),
			$settings_api,
			$auth_service,
			$analytics_service,
			$this->webhooks_service,
			$preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config,
			$mock_checker_stale
		);

		try {
			$links_service_stale->check_link_health( $request, $link_id );
			$this->fail( 'Expected 409 ApiException on destination change' );
		} catch ( \PeakURL\Core\Errors\ApiException $e ) {
			$this->assertSame( 409, $e->get_status() );
		}

		$delivs_stale = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs_stale, 'Stale destination change must emit zero webhook events.' );

		// 5. Deactivation during check: throws 404, 0 webhooks
		$mock_checker_deact = $this->createMock( Checker::class );
		$mock_checker_deact->method( 'check' )
			->willReturnCallback(
				function () use ( $link_id ) {
					$this->db->update( 'urls', array( 'status' => 'inactive' ), array( 'id' => $link_id ) );
					return array(
						'status'           => 'healthy',
						'response_code'    => 200,
						'response_time_ms' => 100,
						'error_message'    => null,
						'redirect_count'   => 0,
					);
				}
			);

		$links_service_deact = new LinksService(
			$this->link_repository,
			new LinksValidator(),
			$settings_api,
			$auth_service,
			$analytics_service,
			$this->webhooks_service,
			$preview_service,
			$captcha_service,
			$roles,
			$authorization,
			$config,
			$mock_checker_deact
		);

		try {
			$links_service_deact->check_link_health( $request, $link_id );
			$this->fail( 'Expected 404 ApiException on deactivation' );
		} catch ( \PeakURL\Core\Errors\ApiException $e ) {
			$this->assertSame( 404, $e->get_status() );
		}

		$delivs_deact = $this->db->get_results( 'SELECT * FROM webhook_deliveries WHERE webhook_id = :id', array( 'id' => $hook_id ) );
		$this->assertEmpty( $delivs_deact, 'Deactivated link check must emit zero webhook events.' );
	}
}
