<?php
/**
 * Integration Tests for Built-in Jobs Domain Integration.
 *
 * @package PeakURL\Tests\Integration\Scheduler
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Scheduler;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Features\Analytics\Jobs\AnalyticsRetentionJob;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Jobs\SessionCleanupJob;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Links\Jobs\ExpiredLinksJob;
use PeakURL\Features\Links\Jobs\ImportExportJob;
use PeakURL\Features\Links\Jobs\LinkHealthCheckJob;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\System\Jobs\CacheCleanupJob;
use PeakURL\Features\Webhooks\Jobs\WebhookDeliveryJob;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Services\Cache\Drivers\NullCache;
use PeakURL\Services\Captcha;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;
use PDO;

class JobsDomainIntegrationTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private SettingsApi $settings_api;
	private AuthService $auth_service;
	private LinksService $links_service;
	private AnalyticsService $analytics_service;
	private WebhooksService $webhooks_service;
	private string $table_prefix;

	protected function setUp(): void {
		parent::setUp();

		$config             = Configuration::get_current();
		$this->connection   = Connection::get_instance( $config );
		$this->pdo          = $this->connection->get_connection();
		$this->db           = new PeakURL_DB( $this->connection );
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->settings_api = new SettingsApi( $this->db );
		$crypto             = new Crypto( $config );
		$geoip              = new Geoip( $config, $this->settings_api, $crypto );
		$roles              = new Roles();
		$authorization      = new Authorization( $roles );
		$cache              = new NullCache();
		$links_api          = new LinksApi( $this->db, $cache );

		$this->auth_service = new AuthService(
			$this->db,
			new \PeakURL\Api\UsersApi( $this->db ),
			new AuthCredentials( $this->db ),
			new AuthValidator(),
			new Totp(),
			new Notifications(),
			$crypto,
			$roles,
			$authorization,
			$geoip,
			$config
		);

		$this->webhooks_service = new WebhooksService(
			$this->db,
			new WebhooksValidator(),
			$this->auth_service,
			$roles,
			$authorization,
			$config
		);

		$analytics_repo = new AnalyticsRepository(
			$this->db,
			$this->settings_api,
			$geoip,
			$roles,
			$authorization,
			$this->webhooks_service,
			null,
			$config
		);

		$this->analytics_service = new AnalyticsService(
			$analytics_repo,
			$this->db,
			$this->auth_service,
			$roles,
			$authorization,
			$config,
			$links_api
		);

		$links_repo = new LinksRepository(
			$this->db,
			$links_api,
			$authorization
		);

		$this->links_service = new LinksService(
			$links_repo,
			new LinksValidator(),
			$this->settings_api,
			$this->auth_service,
			$this->analytics_service,
			$this->webhooks_service,
			new SocialPreview( $config, $this->settings_api ),
			new Captcha( $config, $this->settings_api, $crypto ),
			$roles,
			$authorization,
			$config
		);

		$this->cleanup_test_data();
	}

	protected function tearDown(): void {
		$this->cleanup_test_data();
		parent::tearDown();
	}

	private function cleanup_test_data(): void {
		$this->webhooks_service->set_http_sender( null );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE url LIKE '%invalid-non-existent%' OR url LIKE '%example.com%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE short_code LIKE 'exp_%' OR short_code LIKE 'ret_%' OR short_code LIKE 'hlth_%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}clicks WHERE id LIKE 'clk_test_%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}sessions WHERE token_hash LIKE 'th_%'" );
	}

	public function test_expired_links_job_delegates_and_transitions_status(): void {
		$now     = Date::now();
		$past    = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$link_id = Str::random_id( 16 );
		$code    = 'exp_' . bin2hex( random_bytes( 4 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, expires_at, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Expired Test', 'https://example.com', 'active', '{$past}', '{$now}', '{$now}')"
		);

		$job     = new ExpiredLinksJob( $this->db, null, 100, null, $this->links_service );
		$context = new ExecutionContext( 'peakurl_expired_links', 'run_test_1', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		$row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ), array( 'status' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'expired', $row['status'] );

		// Clean up.
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}urls WHERE id = '{$link_id}'" );
	}

	public function test_session_cleanup_job_delegates_to_auth_service(): void {
		$past  = gmdate( 'Y-m-d H:i:s', time() - 86400 * 35 );
		$sid   = Str::random_id( 16 );
		$thash = 'th_' . bin2hex( random_bytes( 16 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}sessions (id, user_id, token_hash, last_active_at, created_at)
			VALUES ('{$sid}', 1, '{$thash}', '{$past}', '{$past}')"
		);

		$job     = new SessionCleanupJob( $this->db, array(), $this->auth_service );
		$context = new ExecutionContext( 'peakurl_session_cleanup', 'run_test_2', 1, false, Date::now() );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		$row = $this->db->get_row_by( 'sessions', array( 'id' => $sid ), array( 'id' ) );
		$this->assertNull( $row );
	}

	public function test_webhook_delivery_job_processes_deliveries_and_records_results(): void {
		$webhook_id = Str::random_id( 16 );
		$user_id    = 1;
		$now        = Date::now();

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, url, secret, events, is_active, created_at, updated_at)
			VALUES ('{$webhook_id}', {$user_id}, 'https://example.com/webhook', 'sec123', '[\"link.created\"]', 1, '{$now}', '{$now}')"
		);

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		// Use mock HTTP sender to simulate instantaneous successful remote delivery.
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ): array {
				return array(
					'statusCode' => 200,
					'error'      => null,
					'response'   => '{"status":"ok"}',
				);
			}
		);

		$job     = new WebhookDeliveryJob( $this->db, $this->webhooks_service );
		$context = new ExecutionContext( 'peakurl_webhook_delivery', 'run_test_3', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts', 'response_code' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( 200, (int) $row['response_code'] );
	}

	public function test_analytics_retention_job_purges_stale_trashed_links_and_old_clicks(): void {
		$old_time = gmdate( 'Y-m-d H:i:s', time() - ( 40 * 86400 ) );
		$now      = Date::now();
		$link_id  = Str::random_id( 16 );
		$code     = 'ret_' . bin2hex( random_bytes( 4 ) );

		// Insert trashed link older than 30-day retention default.
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'Retention Test', 'https://example.com', 'trashed', '{$old_time}', '{$old_time}')"
		);

		// Insert old click.
		$click_id = 'clk_test_' . bin2hex( random_bytes( 8 ) );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}clicks (id, url_id, clicked_at)
			VALUES ('{$click_id}', '{$link_id}', '{$old_time}')"
		);

		// Configure retention to purge clicks older than 30 days.
		$this->settings_api->update_option( 'analytics_retention_days', '30', $now, false );

		$job     = new AnalyticsRetentionJob( $this->db, $this->settings_api, $this->links_service, $this->analytics_service );
		$context = new ExecutionContext( 'peakurl_analytics_retention', 'run_test_4', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		// Verify trashed link was purged.
		$url_row = $this->db->get_row_by( 'urls', array( 'id' => $link_id ), array( 'id' ) );
		$this->assertNull( $url_row );

		// Verify old click was purged.
		$clk_row = $this->db->get_row_by( 'clicks', array( 'id' => $click_id ), array( 'id' ) );
		$this->assertNull( $clk_row );

		// Restore settings.
		$this->settings_api->delete_options( array( 'analytics_retention_days' ) );
	}

	public function test_cache_cleanup_job_executes_safely_on_null_cache(): void {
		$job     = new CacheCleanupJob( new NullCache() );
		$context = new ExecutionContext( 'peakurl_cache_cleanup', 'run_test_5', 1, false, Date::now() );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'no filesystem sweep required', (string) $result->get_summary() );
	}

	public function test_import_export_job_cleans_scratch_files_safely(): void {
		$scratch_base = sys_get_temp_dir() . '/peakurl_test_content_' . bin2hex( random_bytes( 4 ) );
		$export_dir   = $scratch_base . '/exports';
		mkdir( $export_dir, 0777, true );

		$stale_file = $export_dir . '/stale_export.csv';
		file_put_contents( $stale_file, 'test,data' );
		touch( $stale_file, time() - ( 48 * 3600 ) );

		$fresh_file = $export_dir . '/fresh_export.csv';
		file_put_contents( $fresh_file, 'test,data' );

		$job     = new ImportExportJob( array( \PeakURL\Core\Config\Constants::CONTENT_DIR => $scratch_base ) );
		$context = new ExecutionContext( 'peakurl_import_export', 'run_test_6', 1, false, Date::now() );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertFileDoesNotExist( $stale_file );
		$this->assertFileExists( $fresh_file );

		@unlink( $fresh_file );
		@rmdir( $export_dir );
		@rmdir( $scratch_base );
	}

	public function test_link_health_check_job_evaluates_links_and_filters_ssrf(): void {
		$now     = Date::now();
		$link_id = Str::random_id( 16 );
		$code    = 'hlth_' . bin2hex( random_bytes( 4 ) );

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}urls (id, user_id, short_code, alias, title, destination_url, status, created_at, updated_at)
			VALUES ('{$link_id}', 1, '{$code}', '{$code}', 'SSRF Test', 'http://127.0.0.1:8080/admin', 'active', '{$now}', '{$now}')"
		);

		$job     = new LinkHealthCheckJob( $this->db, 10, 1.0 );
		$context = new ExecutionContext( 'peakurl_link_health_check', 'run_test_7', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$metadata = $result->get_metadata();
		$this->assertGreaterThanOrEqual( 1, (int) ( $metadata['blockedSsrf'] ?? 0 ) );
	}
}
