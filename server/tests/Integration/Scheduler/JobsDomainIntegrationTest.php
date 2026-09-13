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
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
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
			VALUES ('{$webhook_id}', {$user_id}, 'https://invalid-non-existent-webhook.local/hook', 'sec123', '[\"link.created\"]', 1, '{$now}', '{$now}')"
		);

		$delivery_id = $this->webhooks_service->queue_delivery(
			$webhook_id,
			'link.created',
			array( 'test' => true ),
			0
		);

		$job     = new WebhookDeliveryJob( $this->db, $this->webhooks_service );
		$context = new ExecutionContext( 'peakurl_webhook_delivery', 'run_test_3', 1, false, $now );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );

		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ), array( 'status', 'attempts' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 1, (int) $row['attempts'] );

		// Clean up.
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries WHERE id = '{$delivery_id}'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE id = '{$webhook_id}'" );
	}
}
