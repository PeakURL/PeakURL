<?php
/**
 * Integration tests for database schema, index optimizations, and query regression coverage.
 *
 * @package PeakURL\Tests\Integration\Database
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;

class DatabaseOptimizationTest extends TestCase {


	private array $config;
	private \PDO $pdo;
	private string $isolated_prefix;
	private Connection $connection;
	private PeakURL_DB $db;
	private array $prefixes_to_clean = array();

	protected function setUp(): void {
		parent::setUp();
		$this->isolated_prefix     = 'test_perf_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $this->isolated_prefix;
		$base_config               = Configuration::get_current();
		$this->config              = array_merge(
			$base_config,
			array(
				Constants::DB_PREFIX => $this->isolated_prefix,
			)
		);

		$this->connection = new Connection( $this->config );
		$this->db         = new PeakURL_DB( $this->connection );
		$this->pdo        = $this->connection->get_connection();

		// Create isolated test tables from schema.sql
		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		$schema_sql  = (string) file_get_contents( $schema_file );
		$statements  = array_filter( array_map( 'trim', explode( ';', $schema_sql ) ) );
		foreach ( $statements as $stmt ) {
			if ( '' !== $stmt ) {
				$prefixed_stmt = preg_replace(
					'/(CREATE TABLE IF NOT EXISTS\s+)(`?)([a-zA-Z0-9_]+)(`?)/i',
					'${1}`' . $this->isolated_prefix . '${3}`',
					$stmt
				);
				$prefixed_stmt = preg_replace(
					'/(REFERENCES\s+)(`?)([a-zA-Z0-9_]+)(`?)/i',
					'${1}`' . $this->isolated_prefix . '${3}`',
					$prefixed_stmt
				);
				$prefixed_stmt = preg_replace(
					'/(CONSTRAINT\s+`?)([a-zA-Z0-9_]+)(`?)/i',
					'${1}' . $this->isolated_prefix . '${2}${3}',
					$prefixed_stmt
				);
				$this->pdo->exec( $prefixed_stmt );
			}
		}
	}

	protected function tearDown(): void {
		try {
			if ( isset( $this->pdo ) ) {
				$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
				try {
					foreach ( array_unique( $this->prefixes_to_clean ) as $pfx ) {
						$stmt   = $this->pdo->query( "SHOW TABLES LIKE '{$pfx}%'" );
						$tables = $stmt ? $stmt->fetchAll( \PDO::FETCH_COLUMN ) : array();
						foreach ( $tables as $table ) {
							$this->pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
						}
					}
				} finally {
					$this->pdo->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
				}
			}
		} finally {
			parent::tearDown();
		}
	}

	public function test_schema_contains_optimized_indexes_and_no_redundant_duplicates(): void {
		// 1. Verify Sessions indexes
		$sess_indexes = $this->get_table_indexes( $this->isolated_prefix . 'sessions' );
		$this->assertArrayHasKey( 'idx_sessions_revoked', $sess_indexes, 'sessions must have index on revoked_at.' );
		$this->assertArrayHasKey( 'idx_sessions_last_active', $sess_indexes, 'sessions must have index on last_active_at.' );
		$this->assertArrayHasKey( 'idx_sessions_user_active', $sess_indexes, 'sessions must have index on user_active.' );
		$this->assertArrayNotHasKey( 'idx_sessions_token_hash', $sess_indexes, 'sessions duplicate token_hash index must not exist.' );
		$this->assertArrayNotHasKey( 'idx_sessions_user_id', $sess_indexes, 'sessions redundant user_id index must not exist.' );

		// 2. Verify URLs indexes
		$urls_indexes = $this->get_table_indexes( $this->isolated_prefix . 'urls' );
		$this->assertArrayHasKey( 'idx_urls_user_status_created', $urls_indexes, 'urls must have composite index on (user_id, status, created_at).' );
		$this->assertArrayHasKey( 'idx_urls_expires_at', $urls_indexes, 'urls must have index on expires_at.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_id', $urls_indexes, 'urls redundant user_id index must not exist.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_status', $urls_indexes, 'urls redundant user_status index must not exist.' );

		// 3. Verify Clicks indexes
		$clicks_indexes = $this->get_table_indexes( $this->isolated_prefix . 'clicks' );
		$this->assertArrayHasKey( 'idx_clicks_url_clicked_at', $clicks_indexes, 'clicks must have composite index on (url_id, clicked_at).' );
		$this->assertArrayHasKey( 'idx_clicks_clicked_at', $clicks_indexes, 'clicks must have index on clicked_at.' );
		$this->assertArrayNotHasKey( 'idx_clicks_url_id', $clicks_indexes, 'clicks redundant url_id index must not exist.' );

		// 4. Verify Audit Logs indexes
		$audit_indexes = $this->get_table_indexes( $this->isolated_prefix . 'audit_logs' );
		$this->assertArrayHasKey( 'idx_audit_logs_type_created', $audit_indexes, 'audit_logs must have composite index on (type, created_at).' );

		// 5. Verify Webhooks indexes
		$wh_indexes = $this->get_table_indexes( $this->isolated_prefix . 'webhooks' );
		$this->assertArrayHasKey( 'idx_webhooks_user_active', $wh_indexes, 'webhooks must have composite index on (user_id, is_active).' );
		$this->assertArrayNotHasKey( 'idx_webhooks_user_id', $wh_indexes, 'webhooks redundant user_id index must not exist.' );
		$this->assertArrayNotHasKey( 'idx_webhooks_is_active', $wh_indexes, 'webhooks low-selectivity is_active boolean index must not exist.' );

		// 6. Verify Webhook Deliveries indexes
		$del_indexes = $this->get_table_indexes( $this->isolated_prefix . 'webhook_deliveries' );
		$this->assertArrayHasKey( 'idx_webhook_deliveries_webhook_created', $del_indexes, 'webhook_deliveries must have index on (webhook_id, created_at, id).' );
		$this->assertArrayHasKey( 'idx_webhook_deliveries_webhook_id', $del_indexes, 'webhook_deliveries narrow prefix index must be retained for foreign key lookups.' );
	}



	public function test_recent_clicks_batched_result_parity_and_ordering(): void {

		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'perf_user', 'perf@example.test', 'Perf', 'User', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}urls` (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES
				('url_a', 1, 'code_a', 'code_a', 'Link A', 'https://example.com/a', 'active', NOW(), NOW()),
				('url_b', 1, 'code_b', 'code_b', 'Link B', 'https://example.com/b', 'active', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}clicks` (id, url_id, clicked_at, visitor_hash, ip_address, country_name)
			VALUES
				('clk_1', 'url_a', '2026-10-09 10:00:00', 'vis_1', '127.0.0.1', 'US'),
				('clk_2', 'url_a', '2026-10-09 10:05:00', 'vis_2', '127.0.0.1', 'US'),
				('clk_3', 'url_b', '2026-10-09 10:10:00', 'vis_3', '127.0.0.1', 'US')"
		);

		$auth_service = $this->createMock( \PeakURL\Features\Auth\Service::class );
		$auth_service->method( 'get_current_user' )->willReturn(
			array(
				'id'   => 1,
				'role' => 'admin',
			)
		);
		$roles          = new \PeakURL\Core\Auth\Roles();
		$authorization  = new \PeakURL\Core\Auth\Authorization( $roles );
		$settings_api   = new \PeakURL\Api\SettingsApi( $this->db );
		$geoip          = $this->createMock( \PeakURL\Services\Geoip::class );
		$link_formatter = fn( array $row ): array => array(
			'id'     => $row['id'] ?? '',
			'title'  => $row['title'] ?? '',
			'clicks' => $row['click_count'] ?? 0,
		);
		$analytics_repo = new \PeakURL\Features\Analytics\Repository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$authorization,
			null,
			$link_formatter
		);

		$links_api         = new \PeakURL\Api\LinksApi( $this->db );
		$analytics_service = new \PeakURL\Features\Analytics\Service(
			$analytics_repo,
			$this->db,
			$auth_service,
			$roles,
			$authorization,
			$this->config,
			$links_api
		);

		$request = new \PeakURL\Http\Request( 'GET', '/api/v1/analytics/recent-clicks', array(), array() );
		$recent  = $analytics_service->recent_clicks( $request, 8 );

		$this->assertCount( 3, $recent );
		// Newest click is clk_3 for url_b
		$this->assertSame( 'clk_3', $recent[0]['id'] );
		$this->assertSame( 'Link B', $recent[0]['link']['title'] );
		$this->assertSame( 1, $recent[0]['link']['clicks'] );

		// clk_2 for url_a
		$this->assertSame( 'clk_2', $recent[1]['id'] );
		$this->assertSame( 'Link A', $recent[1]['link']['title'] );
		$this->assertSame( 2, $recent[1]['link']['clicks'] );
	}

	public function test_get_link_period_summaries_combines_total_and_unique_counts(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'perf_user2', 'perf2@example.test', 'Perf', 'User', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}urls` (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('url_combo', 1, 'combo', 'combo', 'Link Combo', 'https://example.com/c', 'active', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}clicks` (id, url_id, clicked_at, visitor_hash, ip_address)
			VALUES
				('clk_c1', 'url_combo', '2026-10-09 10:00:00', 'vis_x', '127.0.0.1'),
				('clk_c2', 'url_combo', '2026-10-09 10:01:00', 'vis_x', '127.0.0.1'),
				('clk_c3', 'url_combo', '2026-10-09 10:02:00', 'vis_y', '127.0.0.1')"
		);

		$roles          = new \PeakURL\Core\Auth\Roles();
		$authorization  = new \PeakURL\Core\Auth\Authorization( $roles );
		$settings_api   = new \PeakURL\Api\SettingsApi( $this->db );
		$geoip          = $this->createMock( \PeakURL\Services\Geoip::class );
		$analytics_repo = new \PeakURL\Features\Analytics\Repository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$authorization
		);

		$period    = $analytics_repo->get_analytics_period( 7 );
		$summaries = $analytics_repo->get_link_period_summaries( 'url_combo', $period, 7, '2026-10-01 00:00:00' );

		$this->assertSame( 3, $summaries['allTime']['totalClicks'] );
		$this->assertSame( 2, $summaries['allTime']['uniqueClicks'] );
	}

	public function test_get_link_by_identifier_aggregates_stats_for_single_url_only(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'perf_user3', 'perf3@example.test', 'Perf', 'User', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}urls` (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES
				('url_one', 1, 'code_one', 'code_one', 'Link One', 'https://example.com/one', 'active', NOW(), NOW()),
				('url_two', 1, 'code_two', 'code_two', 'Link Two', 'https://example.com/two', 'active', NOW(), NOW()),
				('url_three', 1, 'code_three', 'my-custom-alias', 'Link Three', 'https://example.com/three', 'active', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}clicks` (id, url_id, clicked_at, visitor_hash, ip_address)
			VALUES
				('clk_o1', 'url_one', '2026-10-09 10:00:00', 'vis_a', '127.0.0.1'),
				('clk_o2', 'url_one', '2026-10-09 10:01:00', 'vis_a', '127.0.0.1'),
				('clk_t1', 'url_two', '2026-10-09 10:02:00', 'vis_b', '127.0.0.1')"
		);

		$links_api = new \PeakURL\Api\LinksApi( $this->db );

		// 1. Lookup by short code
		$row_code = $links_api->get_link_by_identifier( 'code_one' );
		$this->assertNotNull( $row_code );
		$this->assertSame( 'Link One', $row_code['title'] );
		$this->assertSame( 2, $row_code['click_count'] );
		$this->assertSame( 1, $row_code['unique_click_count'] );

		// 2. Lookup by primary ID
		$row_id = $links_api->get_link_by_identifier( 'url_two' );
		$this->assertNotNull( $row_id );
		$this->assertSame( 'Link Two', $row_id['title'] );
		$this->assertSame( 1, $row_id['click_count'] );
		$this->assertSame( 1, $row_id['unique_click_count'] );

		// 3. Lookup by custom alias (with zero clicks)
		$row_alias = $links_api->get_link_by_identifier( 'my-custom-alias' );
		$this->assertNotNull( $row_alias );
		$this->assertSame( 'Link Three', $row_alias['title'] );
		$this->assertSame( 0, $row_alias['click_count'] );
		$this->assertSame( 0, $row_alias['unique_click_count'] );

		// 4. Unknown identifier returns null
		$this->assertNull( $links_api->get_link_by_identifier( 'non-existent-link' ) );

		// 5. Clicks with blank visitor_hash fall back to click ID for distinct uniqueness
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}clicks` (id, url_id, clicked_at, visitor_hash, ip_address)
			VALUES
				('clk_anon1', 'url_three', '2026-10-09 11:00:00', '', '127.0.0.1'),
				('clk_anon2', 'url_three', '2026-10-09 11:01:00', NULL, '127.0.0.1'),
				('clk_known1', 'url_three', '2026-10-09 11:02:00', 'vis_x', '127.0.0.1'),
				('clk_known2', 'url_three', '2026-10-09 11:03:00', 'vis_x', '127.0.0.1')"
		);
		$refreshed_three = $links_api->get_link_by_identifier( 'url_three' );
		$this->assertNotNull( $refreshed_three );
		$this->assertSame( 4, $refreshed_three['click_count'] );
		$this->assertSame( 3, $refreshed_three['unique_click_count'] );
	}


	public function test_bounded_delivery_cleanup_respects_batch_limit(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'perf_wh', 'perf_wh@example.test', 'Perf', 'User', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}webhooks` (id, user_id, label, url, events, secret, is_active, created_at, updated_at)
			VALUES ('wh_clean', 1, 'Hook Clean', 'https://example.com/hook', '[\"link.created\"]', 'enc:v1:sec', 1, NOW(), NOW())"
		);

		// Seed terminal deliveries past 30-day cutoff
		$old_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 35 * 86400 ) );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->pdo->exec(
				"INSERT INTO `{$this->isolated_prefix}webhook_deliveries` (id, webhook_id, event, payload, status, next_attempt_at, completed_at, created_at, updated_at)
				VALUES ('del_old_{$i}', 'wh_clean', 'link.created', '{}', 'delivered', NOW(), '{$old_cutoff}', NOW(), NOW())"
			);
		}

		$subscriptions = new \PeakURL\Features\Webhooks\Subscriptions(
			$this->db,
			new \PeakURL\Features\Webhooks\Validator(),
			$this->createMock( \PeakURL\Features\Auth\Service::class ),
			new \PeakURL\Core\Auth\Roles(),
			new \PeakURL\Core\Auth\Authorization( new \PeakURL\Core\Auth\Roles() ),
			$this->createMock( \PeakURL\Services\Crypto::class )
		);
		$delivery      = new \PeakURL\Features\Webhooks\Delivery(
			$this->db,
			new \PeakURL\Features\Webhooks\Validator(),
			$subscriptions,
			array()
		);

		// Bounded cleanup with batch limit 5 should delete exactly 5 rows
		$deleted = $delivery->cleanup_delivery_history( 30, 5 );
		$this->assertSame( 5, $deleted );

		// 5 remain
		$remaining = (int) $this->pdo->query( "SELECT COUNT(*) FROM `{$this->isolated_prefix}webhook_deliveries`" )->fetchColumn();
		$this->assertSame( 5, $remaining );
	}

	public function test_pending_delivery_claiming_batch_isolation_and_claim_token_ownership(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'claim_user', 'claim@example.test', 'Claim', 'User', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}webhooks` (id, user_id, label, url, events, secret, is_active, created_at, updated_at)
			VALUES ('wh_claim', 1, 'Hook Claim', 'https://example.com/claim', '[\"link.created\"]', 'enc:v1:sec', 1, NOW(), NOW())"
		);

		// Seed 8 pending deliveries due now
		$now = \PeakURL\Utils\Date::now();
		for ( $i = 1; $i <= 8; $i++ ) {
			$this->pdo->exec(
				"INSERT INTO `{$this->isolated_prefix}webhook_deliveries` (id, webhook_id, event, payload, status, next_attempt_at, created_at, updated_at)
				VALUES ('del_pending_{$i}', 'wh_claim', 'link.created', '{}', 'pending', '{$now}', NOW(), NOW())"
			);
		}

		$subscriptions    = new \PeakURL\Features\Webhooks\Subscriptions(
			$this->db,
			new \PeakURL\Features\Webhooks\Validator(),
			$this->createMock( \PeakURL\Features\Auth\Service::class ),
			new \PeakURL\Core\Auth\Roles(),
			new \PeakURL\Core\Auth\Authorization( new \PeakURL\Core\Auth\Roles() ),
			$this->createMock( \PeakURL\Services\Crypto::class )
		);
		$delivery_worker1 = new \PeakURL\Features\Webhooks\Delivery(
			$this->db,
			new \PeakURL\Features\Webhooks\Validator(),
			$subscriptions,
			array()
		);
		$delivery_worker2 = new \PeakURL\Features\Webhooks\Delivery(
			$this->db,
			new \PeakURL\Features\Webhooks\Validator(),
			$subscriptions,
			array()
		);

		// Worker 1 claims batch of 5
		$claimed_worker1 = $delivery_worker1->claim_pending_deliveries( 5 );
		$this->assertCount( 5, $claimed_worker1 );

		// Worker 2 claims next batch: must get remaining 3, with zero overlap
		$claimed_worker2 = $delivery_worker2->claim_pending_deliveries( 5 );
		$this->assertCount( 3, $claimed_worker2 );

		$ids1    = array_column( $claimed_worker1, 'id' );
		$ids2    = array_column( $claimed_worker2, 'id' );
		$overlap = array_intersect( $ids1, $ids2 );
		$this->assertEmpty( $overlap, 'Sequential batch claims must never double-claim delivery records across workers.' );

		// Worker 3 claims: no pending rows left
		$claimed_worker3 = $delivery_worker1->claim_pending_deliveries( 5 );
		$this->assertEmpty( $claimed_worker3 );

		// Verify claim token ownership is strictly enforced on status mutations
		$w1_delivery = $claimed_worker1[0];
		$w1_id       = (string) $w1_delivery['id'];
		$w1_token    = (string) $w1_delivery['claim_token'];
		$w2_token    = (string) $claimed_worker2[0]['claim_token'];

		// Exercise actual delivery update path: attempting update with a mismatched/stale token affects 0 rows
		$stale_affected = $this->db->update(
			'webhook_deliveries',
			array(
				'status'     => 'failed',
				'updated_at' => \PeakURL\Utils\Date::now(),
			),
			array(
				'id'          => $w1_id,
				'claim_token' => $w2_token,
			)
		);
		$this->assertSame( 0, $stale_affected, 'Stale worker with mismatched claim token must not mutate claimed delivery.' );

		// Exercise actual delivery update path: update with correct token succeeds
		$valid_affected = $this->db->update(
			'webhook_deliveries',
			array(
				'status'     => 'delivered',
				'updated_at' => \PeakURL\Utils\Date::now(),
			),
			array(
				'id'          => $w1_id,
				'claim_token' => $w1_token,
			)
		);
		$this->assertSame( 1, $valid_affected, 'Owner worker with valid claim token mutates claimed delivery.' );
	}

	public function test_delivery_processing_production_path_rejects_stale_claim_token_mutation(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (99, 'stale_worker', 'stale@example.test', 'Stale', 'Worker', 'hash', 'admin', NOW(), NOW())"
		);
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}webhooks` (id, user_id, label, url, events, secret, is_active, created_at, updated_at)
			VALUES ('wh_stale_test', 99, 'Hook Stale', 'https://example.com/stale', '[\"link.created\"]', 'enc:v1:sec', 1, NOW(), NOW())"
		);

		$now = \PeakURL\Utils\Date::now();
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}webhook_deliveries` (id, webhook_id, event, payload, status, next_attempt_at, created_at, updated_at)
			VALUES ('del_stale_test', 'wh_stale_test', 'link.created', '{\"test\":true}', 'pending', '{$now}', NOW(), NOW())"
		);

		$validator = $this->createMock( \PeakURL\Features\Webhooks\Validator::class );
		$validator->method( 'validate_destination' )->willReturn( '93.184.215.14' );

		$crypto = $this->createMock( \PeakURL\Services\Crypto::class );
		$crypto->method( 'decrypt' )->willReturn( 'my_raw_secret' );

		$subscriptions = new \PeakURL\Features\Webhooks\Subscriptions(
			$this->db,
			$validator,
			$this->createMock( \PeakURL\Features\Auth\Service::class ),
			new \PeakURL\Core\Auth\Roles(),
			new \PeakURL\Core\Auth\Authorization( new \PeakURL\Core\Auth\Roles() ),
			$crypto
		);
		$delivery      = new \PeakURL\Features\Webhooks\Delivery(
			$this->db,
			$validator,
			$subscriptions,
			array()
		);

		// Configure http_sender to simulate a second worker reclaiming the row while worker 1 is executing
		$reclaimed_token = 'token_reclaimed_by_worker2';
		$delivery->set_http_sender(
			function ( $webhook, $payload, $timeout, $headers ) use ( $reclaimed_token ) {
				// While worker 1 is in-flight, simulate worker 2 reclaiming the delivery
				$this->pdo->exec(
					"UPDATE `{$this->isolated_prefix}webhook_deliveries`
					SET claim_token = '{$reclaimed_token}',
						status = 'processing',
						updated_at = NOW()
					WHERE id = 'del_stale_test'"
				);

				return array(
					'statusCode' => 200,
					'success'    => true,
					'error'      => null,
					'retryAfter' => null,
					'durationMs' => 15,
				);
			}
		);

		// Worker 1 executes the real production process_pending_deliveries() method
		$result = $delivery->process_pending_deliveries( 5 );

		// Because worker 1's claim token was superseded, the status update affected 0 rows
		// and worker 1's report reflects 0 delivered
		$this->assertSame( 0, $result['delivered'], 'Stale worker must not count delivery as delivered when claim token ownership was lost.' );

		// The database record must STILL retain worker 2's claim token and status, and payload must not be wiped
		$stmt = $this->pdo->query(
			"SELECT claim_token, status, payload FROM `{$this->isolated_prefix}webhook_deliveries` WHERE id = 'del_stale_test'"
		);
		$row  = $stmt->fetch( \PDO::FETCH_ASSOC );
		$this->assertSame( $reclaimed_token, $row['claim_token'], 'Newer owner claim token must remain intact in database.' );
		$this->assertSame( 'processing', $row['status'], 'Status must not be overwritten by stale worker.' );
		$this->assertSame( '{"test":true}', $row['payload'], 'Payload must not be cleared by stale worker.' );
	}



	public function test_activity_category_filtering_prefix_semantics_and_near_misses(): void {
		$this->pdo->exec(
			"INSERT INTO `{$this->isolated_prefix}users` (id, username, email, first_name, last_name, password_hash, role, created_at, updated_at)
			VALUES (1, 'act_user', 'act@example.test', 'Act', 'User', 'hash', 'admin', NOW(), NOW())"
		);

		$events = array(
			// Valid link category events
			'link_created',
			'link_updated',
			'link_deleted',
			'click',
			// Near-miss non-link events (MUST NOT match)
			'linkXcreated',
			'link9deleted',
			'linking_page',
			// Valid user category events
			'user_created',
			'user_updated',
			'user_deleted',
			'user_login',
			// Near-miss non-user events (MUST NOT match)
			'userXcreated',
			'user9deleted',
			'username_changed',
			'useragent_parse',
		);

		foreach ( $events as $idx => $evt ) {
			$this->pdo->exec(
				"INSERT INTO `{$this->isolated_prefix}audit_logs` (id, user_id, type, message, created_at)
				VALUES ('log_{$idx}', 1, '{$evt}', 'Test {$evt}', NOW())"
			);
		}

		$roles          = new \PeakURL\Core\Auth\Roles();
		$authorization  = new \PeakURL\Core\Auth\Authorization( $roles );
		$settings_api   = new \PeakURL\Api\SettingsApi( $this->db );
		$geoip          = $this->createMock( \PeakURL\Services\Geoip::class );
		$analytics_repo = new \PeakURL\Features\Analytics\Repository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$authorization
		);

		// 1. Query 'links' category
		$links_query   = $analytics_repo->prepare_activity_query(
			array(
				'id'   => 1,
				'role' => 'admin',
			),
			array( 'category' => 'links' )
		);
		$matched_links = $this->db->get_col(
			"SELECT a.type {$links_query['from']} {$links_query['where']}",
			$links_query['params']
		);
		sort( $matched_links );

		$expected_links = array( 'click', 'link_created', 'link_deleted', 'link_updated' );
		sort( $expected_links );
		$this->assertSame( $expected_links, $matched_links, 'Links category must match only exact link_ prefix and click, excluding near-misses.' );

		// 2. Query 'users' category
		$users_query   = $analytics_repo->prepare_activity_query(
			array(
				'id'   => 1,
				'role' => 'admin',
			),
			array( 'category' => 'users' )
		);
		$matched_users = $this->db->get_col(
			"SELECT a.type {$users_query['from']} {$users_query['where']}",
			$users_query['params']
		);
		sort( $matched_users );

		$expected_users = array( 'user_created', 'user_deleted', 'user_login', 'user_updated' );
		sort( $expected_users );
		$this->assertSame( $expected_users, $matched_users, 'Users category must match only exact user_ prefix, excluding near-misses.' );
	}
	public function test_redundant_index_drop_safeguards_require_replacement(): void {
		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		$context     = new \PeakURL\Services\Database\Context( $this->connection );
		$upgrade     = new \PeakURL\Services\Database\Upgrade( $context, $schema_file );

		$ref    = new \ReflectionClass( $upgrade );
		$method = $ref->getMethod( 'is_index_safely_covered' );

		// In our isolated test database, replacement indexes exist:
		$this->assertTrue( $method->invoke( $upgrade, 'urls', 'idx_urls_user_id', $this->connection ) );
		$this->assertTrue( $method->invoke( $upgrade, 'urls', 'idx_urls_user_status', $this->connection ) );
		$this->assertTrue( $method->invoke( $upgrade, 'clicks', 'idx_clicks_url_id', $this->connection ) );
		$this->assertTrue( $method->invoke( $upgrade, 'sessions', 'idx_sessions_user_id', $this->connection ) );
		$this->assertTrue( $method->invoke( $upgrade, 'webhooks', 'idx_webhooks_user_id', $this->connection ) );

		// Unknown index/table must return false and prevent dropping
		$this->assertFalse( $method->invoke( $upgrade, 'unknown_table', 'unknown_index', $this->connection ) );
	}

	public function test_token_hash_standalone_unique_requirement_and_safeguards(): void {
		$table_name = $this->isolated_prefix . 'test_comp_sess';
		$this->pdo->exec(
			"CREATE TABLE `{$table_name}` (
				id VARCHAR(40) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				token_hash CHAR(64) NOT NULL,
				UNIQUE KEY uniq_comp_hash (token_hash, user_id),
				KEY idx_sessions_token_hash (token_hash)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$schema_file = dirname( __DIR__, 3 ) . '/database/schema.sql';
		$context     = new \PeakURL\Services\Database\Context( $this->connection );
		$upgrade     = new \PeakURL\Services\Database\Upgrade( $context, $schema_file );

		$ref    = new \ReflectionClass( $upgrade );
		$method = $ref->getMethod( 'is_index_safely_covered' );

		// 1. Composite unique index (token_hash, user_id) MUST NOT satisfy standalone requirement
		$is_covered_comp = $method->invoke( $upgrade, 'test_comp_sess', 'idx_sessions_token_hash', $this->connection );
		$this->assertFalse( $is_covered_comp, 'Composite unique index must NOT be treated as a standalone unique index.' );

		// 2. Add a standalone single-column unique index on token_hash
		$this->pdo->exec( "ALTER TABLE `{$table_name}` ADD UNIQUE KEY uniq_standalone (token_hash)" );
		$is_covered_standalone = $method->invoke( $upgrade, 'test_comp_sess', 'idx_sessions_token_hash', $this->connection );
		$this->assertTrue( $is_covered_standalone, 'Standalone single-column unique index satisfies covering requirement.' );

		// 3. Verify urls legacy indexes are NOT covered when replacement is absent
		$legacy_urls = $this->isolated_prefix . 'test_missing_urls';
		$this->pdo->exec(
			"CREATE TABLE `{$legacy_urls}` (
				id VARCHAR(20) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL,
				KEY idx_urls_user_id (user_id),
				KEY idx_urls_user_status (user_id, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$this->assertFalse( $method->invoke( $upgrade, 'test_missing_urls', 'idx_urls_user_id', $this->connection ) );
		$this->assertFalse( $method->invoke( $upgrade, 'test_missing_urls', 'idx_urls_user_status', $this->connection ) );
	}

	public function test_replacement_index_with_wrong_columns_prevents_redundant_index_drop(): void {
		$drop_prefix               = 'test_drop_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $drop_prefix;

		$drop_config     = array_merge( $this->config, array( Constants::DB_PREFIX => $drop_prefix ) );
		$drop_connection = new Connection( $drop_config );
		$drop_context    = new \PeakURL\Services\Database\Context( $drop_connection );
		$schema_file     = dirname( __DIR__, 3 ) . '/database/schema.sql';

		// 1. Create urls table with legacy indexes and a replacement index having WRONG columns (status, created_at)
		$this->pdo->exec(
			"CREATE TABLE `{$drop_prefix}urls` (
				id VARCHAR(20) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL,
				created_at DATETIME NOT NULL,
				KEY idx_urls_user_id (user_id),
				KEY idx_urls_user_status (user_id, status),
				KEY idx_urls_user_status_created (status, created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$upgrade = new \PeakURL\Services\Database\Upgrade( $drop_context, $schema_file );

		$ref         = new \ReflectionClass( $upgrade );
		$drop_method = $ref->getMethod( 'drop_redundant_indexes' );
		$is_covered  = $ref->getMethod( 'is_index_safely_covered' );

		// Predicate check: replacement index with wrong column definition does not safely cover legacy indexes
		$this->assertFalse(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_id', $drop_connection ),
			'Replacement index with wrong column definition must not qualify as safe coverage for user_id.'
		);
		$this->assertFalse(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_status', $drop_connection ),
			'Replacement index with wrong column definition must not qualify as safe coverage for user_status.'
		);

		// Execute production removal method: it must NOT drop legacy indexes when replacement columns are wrong
		$changes = array();
		$drop_method->invokeArgs( $upgrade, array( &$changes ) );
		$this->assertEmpty( $changes, 'Production drop_redundant_indexes must record 0 drops when replacement columns are wrong.' );

		$indexes_before = $this->get_table_indexes( "{$drop_prefix}urls" );
		$this->assertArrayHasKey( 'idx_urls_user_id', $indexes_before, 'Legacy idx_urls_user_id must remain when coverage is invalid.' );
		$this->assertArrayHasKey( 'idx_urls_user_status', $indexes_before, 'Legacy idx_urls_user_status must remain when coverage is invalid.' );

		// 2. Correct the replacement index to the exact expected ordered columns: (user_id, status, created_at)
		$this->pdo->exec( "ALTER TABLE `{$drop_prefix}urls` DROP INDEX idx_urls_user_status_created" );
		$this->pdo->exec( "ALTER TABLE `{$drop_prefix}urls` ADD INDEX idx_urls_user_status_created (user_id, status, created_at)" );

		// Predicate check: now covered
		$this->assertTrue(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_id', $drop_connection ),
			'Replacement index with correct columns safely covers legacy user_id index.'
		);
		$this->assertTrue(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_status', $drop_connection ),
			'Replacement index with correct columns safely covers legacy user_status index.'
		);

		// Execute production removal method again: now legacy indexes MUST be dropped
		$changes = array();
		$drop_method->invokeArgs( $upgrade, array( &$changes ) );
		$this->assertCount( 2, $changes, 'Production drop_redundant_indexes must drop covered legacy indexes.' );

		$indexes_after = $this->get_table_indexes( "{$drop_prefix}urls" );
		$this->assertArrayNotHasKey( 'idx_urls_user_id', $indexes_after, 'Legacy idx_urls_user_id must be removed.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_status', $indexes_after, 'Legacy idx_urls_user_status must be removed.' );
		$this->assertArrayHasKey( 'idx_urls_user_status_created', $indexes_after, 'Replacement idx_urls_user_status_created must remain.' );
	}

	public function test_prefix_indexed_replacement_prevents_redundant_index_drop(): void {
		$prefix                    = 'test_pfx_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $prefix;

		$pfx_config     = array_merge( $this->config, array( Constants::DB_PREFIX => $prefix ) );
		$pfx_connection = new Connection( $pfx_config );
		$pfx_context    = new \PeakURL\Services\Database\Context( $pfx_connection );
		$schema_file    = dirname( __DIR__, 3 ) . '/database/schema.sql';

		// 1. URLs table with a prefix-indexed replacement index on status(5)
		$this->pdo->exec(
			"CREATE TABLE `{$prefix}urls` (
				id VARCHAR(20) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL,
				created_at DATETIME NOT NULL,
				KEY idx_urls_user_id (user_id),
				KEY idx_urls_user_status (user_id, status),
				KEY idx_urls_user_status_created (user_id, status(5), created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		// 2. Sessions table with a prefix-indexed unique index on token_hash(32)
		$this->pdo->exec(
			"CREATE TABLE `{$prefix}sessions` (
				id VARCHAR(40) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				token_hash CHAR(64) NOT NULL,
				UNIQUE KEY uniq_prefix_hash (token_hash(32)),
				KEY idx_sessions_token_hash (token_hash)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$upgrade = new \PeakURL\Services\Database\Upgrade( $pfx_context, $schema_file );

		$ref         = new \ReflectionClass( $upgrade );
		$drop_method = $ref->getMethod( 'drop_redundant_indexes' );
		$is_covered  = $ref->getMethod( 'is_index_safely_covered' );

		// Predicate check: prefix-indexed replacements must NOT safely cover legacy indexes
		$this->assertFalse(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_id', $pfx_connection ),
			'Prefix-indexed replacement must not qualify as safe coverage for user_id.'
		);
		$this->assertFalse(
			$is_covered->invoke( $upgrade, 'urls', 'idx_urls_user_status', $pfx_connection ),
			'Prefix-indexed replacement must not qualify as safe coverage for user_status.'
		);
		$this->assertFalse(
			$is_covered->invoke( $upgrade, 'sessions', 'idx_sessions_token_hash', $pfx_connection ),
			'Prefix-indexed unique key must not qualify as standalone unique coverage.'
		);

		// Production drop method must NOT drop legacy indexes when replacement is prefix-indexed
		$changes = array();
		$drop_method->invokeArgs( $upgrade, array( &$changes ) );
		$this->assertEmpty( $changes, 'drop_redundant_indexes must record 0 drops when replacements use partial prefix lengths.' );

		$urls_indexes = $this->get_table_indexes( "{$prefix}urls" );
		$this->assertArrayHasKey( 'idx_urls_user_id', $urls_indexes, 'Legacy idx_urls_user_id must remain.' );
		$this->assertArrayHasKey( 'idx_urls_user_status', $urls_indexes, 'Legacy idx_urls_user_status must remain.' );

		$sess_indexes = $this->get_table_indexes( "{$prefix}sessions" );
		$this->assertArrayHasKey( 'idx_sessions_token_hash', $sess_indexes, 'Legacy idx_sessions_token_hash must remain.' );
	}

	public function test_schema_reconciliation_ordering_adds_replacement_before_dropping_redundant_indexes(): void {
		$recon_prefix              = 'test_rec_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $recon_prefix;

		$recon_config     = array_merge( $this->config, array( Constants::DB_PREFIX => $recon_prefix ) );
		$recon_connection = new Connection( $recon_config );
		$schema_file      = dirname( __DIR__, 3 ) . '/database/schema.sql';

		// Seed table with only legacy indexes (replacement index missing entirely, as in an interrupted/un-upgraded state)
		$this->pdo->exec(
			"CREATE TABLE `{$recon_prefix}urls` (
				id VARCHAR(20) NOT NULL PRIMARY KEY,
				user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				KEY idx_urls_user_id (user_id),
				KEY idx_urls_user_status (user_id, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		// Execute real schema repair: add_missing_indexes must run first, adding idx_urls_user_status_created,
		// allowing drop_redundant_indexes to safely remove the legacy redundant indexes in the same upgrade run.
		$schema = new \PeakURL\Services\Database\Schema( $recon_connection, $schema_file );
		$result = $schema->repair_schema();

		$this->assertSame( 0, $result['errorCount'], 'Schema repair must succeed without errors.' );

		$indexes = $this->get_table_indexes( "{$recon_prefix}urls" );
		$this->assertArrayHasKey( 'idx_urls_user_status_created', $indexes, 'Replacement index must have been created.' );
		$this->assertSame( array( 'user_id', 'status', 'created_at' ), $indexes['idx_urls_user_status_created'], 'Created replacement index must have exact expected columns.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_id', $indexes, 'Legacy idx_urls_user_id must be removed in same pass.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_status', $indexes, 'Legacy idx_urls_user_status must be removed in same pass.' );
	}

	public function test_genuine_schema_version_10_to_11_upgrade_and_idempotence(): void {
		$v10_prefix                = 'test_v10_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';
		$this->prefixes_to_clean[] = $v10_prefix;

		$legacy_config     = array_merge( $this->config, array( Constants::DB_PREFIX => $v10_prefix ) );
		$legacy_connection = new Connection( $legacy_config );
		$legacy_context    = new \PeakURL\Services\Database\Context( $legacy_connection );
		$schema_file       = dirname( __DIR__, 3 ) . '/database/schema.sql';

		// 1. Create full table definitions with all foreign key relationships from schema.sql
		$schema_sql = (string) file_get_contents( $schema_file );
		$this->pdo->exec( $legacy_connection->prefix_schema( $schema_sql ) );

		// 2. Adjust fixture to accurately represent genuine version 10 state:
		// - Set stored db_schema_version to 10
		$this->pdo->exec(
			"INSERT INTO `{$v10_prefix}settings` (setting_key, setting_value, autoload, updated_at)
			VALUES ('db_schema_version', '10', 1, NOW()), ('site_url', 'https://example.test', 1, NOW())
			ON DUPLICATE KEY UPDATE setting_value = '10', updated_at = NOW()"
		);

		// - Add redundant legacy v10 indexes that v11 drops
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}sessions` ADD KEY idx_sessions_user_id (user_id), ADD KEY idx_sessions_token_hash (token_hash)" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}urls` ADD KEY idx_urls_user_id (user_id), ADD KEY idx_urls_user_status (user_id, status)" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}clicks` ADD KEY idx_clicks_url_id (url_id)" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}webhooks` ADD KEY idx_webhooks_user_id (user_id)" );

		// - Drop only the new indexes introduced in version 11 (preserve v10 indexes like idx_clicks_url_clicked_at and idx_webhooks_user_active)
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}sessions` DROP INDEX idx_sessions_revoked, DROP INDEX idx_sessions_last_active" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}urls` DROP INDEX idx_urls_user_status_created, DROP INDEX idx_urls_expires_at" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}audit_logs` DROP INDEX idx_audit_logs_type_created" );
		$this->pdo->exec( "ALTER TABLE `{$v10_prefix}webhook_deliveries` DROP INDEX idx_webhook_deliveries_webhook_created" );

		// 3. Execute migration using the application's real Schema upgrade/repair path
		$schema = new \PeakURL\Services\Database\Schema( $legacy_connection, $schema_file );

		// Verify that schema is detected as needing repair before execution
		$this->assertFalse( $schema->is_current(), 'Schema must not be current before version 10 to 11 upgrade.' );
		$this->assertTrue( $schema->needs_repair(), 'Schema must indicate repair is required when version is 10.' );

		$first_result = $schema->repair_schema();
		$this->assertTrue( $first_result['upgraded'], 'Real application schema repair must execute and report upgraded = true.' );
		$this->assertNotEmpty( $first_result['changes'], 'First upgrade run must record structural changes.' );
		$this->assertSame( 0, $first_result['errorCount'], 'Repaired schema must have 0 errors.' );
		$this->assertSame( 0, $first_result['warningCount'], 'Repaired schema must have 0 warnings.' );
		$this->assertTrue( $first_result['upToDate'], 'Repaired schema must be reported as up to date.' );

		// 4. Assert that the stored db_schema_version becomes 11
		$stored_version = $legacy_context->get_option( Constants::SETTING_DB_SCHEMA_VERSION );
		$this->assertSame( '11', (string) $stored_version, 'Stored db_schema_version must become 11 after upgrade.' );

		// 5. Verify resulting schema converges to version 11 with required indexes present, correct ordered columns, and redundant ones removed
		$urls_idx = $this->get_table_indexes( "{$v10_prefix}urls" );
		$this->assertArrayHasKey( 'idx_urls_user_status_created', $urls_idx, 'urls must have idx_urls_user_status_created.' );
		$this->assertSame( array( 'user_id', 'status', 'created_at' ), $urls_idx['idx_urls_user_status_created'], 'idx_urls_user_status_created must have exact ordered columns.' );
		$this->assertArrayHasKey( 'idx_urls_expires_at', $urls_idx, 'urls must have idx_urls_expires_at.' );
		$this->assertSame( array( 'expires_at' ), $urls_idx['idx_urls_expires_at'], 'idx_urls_expires_at must have exact ordered columns.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_id', $urls_idx, 'Redundant idx_urls_user_id must be removed.' );
		$this->assertArrayNotHasKey( 'idx_urls_user_status', $urls_idx, 'Redundant idx_urls_user_status must be removed.' );

		$clicks_idx = $this->get_table_indexes( "{$v10_prefix}clicks" );
		$this->assertArrayNotHasKey( 'idx_clicks_url_id', $clicks_idx, 'Redundant idx_clicks_url_id must be removed.' );
		$this->assertArrayHasKey( 'idx_clicks_url_clicked_at', $clicks_idx, 'clicks must retain idx_clicks_url_clicked_at.' );
		$this->assertSame( array( 'url_id', 'clicked_at' ), $clicks_idx['idx_clicks_url_clicked_at'], 'idx_clicks_url_clicked_at must have exact ordered columns.' );

		$sess_idx = $this->get_table_indexes( "{$v10_prefix}sessions" );
		$this->assertArrayNotHasKey( 'idx_sessions_token_hash', $sess_idx, 'Redundant idx_sessions_token_hash must be removed.' );
		$this->assertArrayNotHasKey( 'idx_sessions_user_id', $sess_idx, 'Redundant idx_sessions_user_id must be removed.' );
		$this->assertArrayHasKey( 'idx_sessions_user_active', $sess_idx, 'sessions must retain idx_sessions_user_active.' );
		$this->assertSame( array( 'user_id', 'revoked_at', 'last_active_at' ), $sess_idx['idx_sessions_user_active'], 'idx_sessions_user_active must have exact ordered columns.' );
		$this->assertArrayHasKey( 'idx_sessions_revoked', $sess_idx, 'sessions must have idx_sessions_revoked.' );
		$this->assertSame( array( 'revoked_at' ), $sess_idx['idx_sessions_revoked'], 'idx_sessions_revoked must have exact ordered columns.' );
		$this->assertArrayHasKey( 'idx_sessions_last_active', $sess_idx, 'sessions must have idx_sessions_last_active.' );
		$this->assertSame( array( 'last_active_at' ), $sess_idx['idx_sessions_last_active'], 'idx_sessions_last_active must have exact ordered columns.' );

		$audit_idx = $this->get_table_indexes( "{$v10_prefix}audit_logs" );
		$this->assertArrayHasKey( 'idx_audit_logs_type_created', $audit_idx, 'audit_logs must have idx_audit_logs_type_created.' );
		$this->assertSame( array( 'type', 'created_at' ), $audit_idx['idx_audit_logs_type_created'], 'idx_audit_logs_type_created must have exact ordered columns.' );

		$wh_idx = $this->get_table_indexes( "{$v10_prefix}webhooks" );
		$this->assertArrayNotHasKey( 'idx_webhooks_user_id', $wh_idx, 'Redundant idx_webhooks_user_id must be removed.' );
		$this->assertArrayHasKey( 'idx_webhooks_user_active', $wh_idx, 'webhooks must retain idx_webhooks_user_active.' );
		$this->assertSame( array( 'user_id', 'is_active' ), $wh_idx['idx_webhooks_user_active'], 'idx_webhooks_user_active must have exact ordered columns.' );

		$del_idx = $this->get_table_indexes( "{$v10_prefix}webhook_deliveries" );
		$this->assertArrayHasKey( 'idx_webhook_deliveries_webhook_created', $del_idx, 'webhook_deliveries must have idx_webhook_deliveries_webhook_created.' );
		$this->assertSame( array( 'webhook_id', 'created_at', 'id' ), $del_idx['idx_webhook_deliveries_webhook_created'], 'idx_webhook_deliveries_webhook_created must have exact ordered columns.' );
		$this->assertArrayHasKey( 'idx_webhook_deliveries_webhook_id', $del_idx, 'webhook_deliveries must retain idx_webhook_deliveries_webhook_id.' );

		// 6. Verify idempotent second run produces no further changes or errors
		$second_result = $schema->repair_schema();
		$this->assertFalse( $second_result['upgraded'], 'Second run must not perform an upgrade.' );
		$this->assertEmpty( $second_result['changes'], 'Subsequent upgrade/repair execution must be idempotent with 0 changes.' );
		$this->assertSame( 0, $second_result['errorCount'], 'Subsequent run must have 0 errors.' );
		$this->assertSame( 0, $second_result['warningCount'], 'Subsequent run must have 0 warnings.' );
		$this->assertTrue( $second_result['upToDate'], 'Subsequent run must remain upToDate = true.' );
		$this->assertTrue( $schema->is_current(), 'Schema must be current after upgrade.' );
	}





	private function get_table_indexes( string $full_table_name ): array {
		$stmt    = $this->pdo->query( "SHOW INDEX FROM `{$full_table_name}`" );
		$indexes = array();
		foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
			$indexes[ $row['Key_name'] ][] = $row['Column_name'];
		}
		return $indexes;
	}
}
