<?php
/**
 * Integration tests for Webhooks domain lifecycle events, security, and contracts.
 *
 * @package PeakURL\Tests\Integration\Webhooks
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Tests\Integration\Webhooks;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\SettingsApi;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Repository as AnalyticsRepository;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Credentials as AuthCredentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Users\Service as UsersService;
use PeakURL\Features\Users\Validator as UsersValidator;
use PeakURL\Features\Webhooks\Controller as WebhooksController;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Features\Webhooks\Validator as WebhooksValidator;
use PeakURL\Core\Scheduler\ExecutionContext;
use PeakURL\Features\Webhooks\Jobs\WebhookDeliveryJob;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\Connection;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Environment;
use PeakURL\Services\Notifications;
use PeakURL\Services\SocialPreview;
use PeakURL\Services\Totp;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;
use PDO;

class WebhooksTest extends TestCase {

	private PDO $pdo;
	private Connection $connection;
	private PeakURL_DB $db;
	private array $config;
	private string $table_prefix;
	private WebhooksService $webhooks_service;
	private AuthService $auth_service;
	private UsersService $users_service;
	private array $admin_user;

	protected function setUp(): void {
		parent::setUp();

		$this->config       = Configuration::get_current();
		$this->connection   = Connection::get_instance( $this->config );
		$this->pdo          = $this->connection->get_connection();
		$this->table_prefix = $this->connection->get_table_prefix();
		$this->db           = new PeakURL_DB( $this->connection );

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$crypto        = new Crypto( $this->config );
		$users_api     = new UsersApi( $this->db );
		$credentials   = new AuthCredentials( $this->db );

		$this->auth_service = new AuthService(
			$this->db,
			$users_api,
			$credentials,
			new AuthValidator(),
			new Totp(),
			new Notifications(),
			$crypto,
			$roles,
			$authorization,
			null,
			$this->config
		);

		$validator = new class() extends WebhooksValidator {
			protected function get_destination_ip( string $host ): ?string {
				if ( in_array( $host, array( 'example.com', 'test.destination.org', 'destination.org', 'safe.destination.org' ), true ) ) {
					return '93.184.216.34';
				}
				return parent::get_destination_ip( $host );
			}
		};

		$this->webhooks_service = new WebhooksService(
			$this->db,
			$validator,
			$this->auth_service,
			$roles,
			$authorization,
			$this->config,
			$crypto
		);
		$this->auth_service->set_webhooks_service( $this->webhooks_service );

		$settings_api         = new SettingsApi( $this->db );
		$geoip                = new \PeakURL\Services\Geoip( $this->config, $settings_api, $crypto );
		$analytics_repository = new AnalyticsRepository(
			$this->db,
			$settings_api,
			$geoip,
			$roles,
			$authorization,
			$this->webhooks_service,
			null,
			$this->config
		);
		$analytics_service    = new AnalyticsService(
			$analytics_repository,
			$this->db,
			$this->auth_service,
			$roles,
			$authorization,
			$this->config,
			new \PeakURL\Api\LinksApi( $this->db )
		);
		$social_preview       = new SocialPreview( $this->config, $settings_api );

		$this->users_service = new UsersService(
			$this->db,
			$users_api,
			$this->auth_service,
			$analytics_service,
			new UsersValidator(),
			$roles,
			$authorization,
			$social_preview,
			$this->webhooks_service
		);

		$this->cleanup_test_data();

		// Ensure admin user exists for tests.
		$now              = Date::now();
		$this->admin_user = array(
			'id'                => 99991,
			'username'          => 'webhookadmin',
			'email'             => 'webhookadmin@example.com',
			'first_name'        => 'Webhook',
			'last_name'         => 'Admin',
			'display_name'      => 'Webhook Admin',
			'password_hash'     => password_hash( 'password123', PASSWORD_DEFAULT ),
			'role'              => 'admin',
			'is_email_verified' => 1,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}users (id, username, email, first_name, last_name, display_name, password_hash, role, is_email_verified, created_at, updated_at)
			VALUES (99991, 'webhookadmin', 'webhookadmin@example.com', 'Webhook', 'Admin', 'Webhook Admin', '{$this->admin_user['password_hash']}', 'admin', 1, '{$now}', '{$now}')
			ON DUPLICATE KEY UPDATE username = VALUES(username), role = 'admin'"
		);
	}

	protected function tearDown(): void {
		$this->cleanup_test_data();
		parent::tearDown();
	}

	private function cleanup_test_data(): void {
		$this->webhooks_service->set_http_sender( null );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhooks WHERE url LIKE '%test.destination%' OR url LIKE '%example.com%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}api_keys WHERE label LIKE 'Test%'" );
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}users WHERE username LIKE 'wh_test_%'" );
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

	public function test_webhooks_controller_accepts_id_from_body_for_test_endpoint(): void {
		$mock_service = $this->createMock( WebhooksService::class );
		$mock_service->expects( $this->once() )
			->method( 'test_webhook' )
			->with(
				$this->isInstanceOf( Request::class ),
				$this->equalTo( 'whk_12345' )
			)
			->willReturn(
				array(
					'webhookId'  => 'whk_12345',
					'statusCode' => 200,
					'success'    => true,
				)
			);

		$controller = new WebhooksController( $mock_service );
		$request    = new Request(
			'POST',
			'/api/v1/webhooks/test',
			array(),
			array( 'id' => 'whk_12345' )
		);

		$response = $controller->test( $request );
		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['body']['success'] );
	}

	public function test_api_key_creation_dispatches_webhook_with_safe_metadata_only(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/users/me/api-keys' );

		// Register webhook subscribed to api_key.created.
		$this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'API Key Webhook',
				'url'    => 'https://test.destination.org/hook',
				'events' => array( 'api_key.created' ),
			)
		);

		$captured_payloads = array();
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ) use ( &$captured_payloads ): array {
				$captured_payloads[] = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$new_key = $this->auth_service->add_api_key( $request, 'Test Production Key' );
		$this->assertNotEmpty( $new_key['id'] );
		$this->assertNotEmpty( $new_key['key'] ); // Plain text key returned to creating caller only once.

		// Process queued background webhook deliveries.
		$this->webhooks_service->process_pending_deliveries();

		$this->assertCount( 1, $captured_payloads, 'api_key.created webhook must be dispatched.' );
		$envelope = $captured_payloads[0];
		$this->assertSame( 'api_key.created', $envelope['type'] );

		$data = $envelope['data'];
		$this->assertSame( $new_key['id'], $data['id'] );
		$this->assertSame( 'Test Production Key', $data['label'] );
		$this->assertSame( $new_key['prefix'], $data['prefix'] );
		$this->assertSame( $new_key['lastFour'], $data['last_four'] );
		$this->assertNotEmpty( $data['created_at'] );

		// CRITICAL SECURITY ASSERTION: Webhook payload must NEVER contain secret credential material.
		$this->assertArrayNotHasKey( 'key', $data );
		$this->assertArrayNotHasKey( 'key_hash', $data );
		$this->assertArrayNotHasKey( 'token', $data );
		$this->assertArrayNotHasKey( 'secret', $data );
	}

	public function test_api_key_revocation_dispatches_webhook_with_safe_metadata_captured_before_delete(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'DELETE', '/api/v1/users/me/api-keys' );

		// Create an API key first.
		$created_key = $this->auth_service->add_api_key( $request, 'Test Revocation Key' );

		// Register webhook subscribed to api_key.revoked.
		$this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'API Key Revocation Webhook',
				'url'    => 'https://test.destination.org/hook',
				'events' => array( 'api_key.revoked' ),
			)
		);

		$captured_payloads = array();
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ) use ( &$captured_payloads ): array {
				$captured_payloads[] = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$revoked = $this->auth_service->delete_api_key( $request, (string) $created_key['id'] );
		$this->assertTrue( $revoked );

		// Process queued background webhook deliveries.
		$this->webhooks_service->process_pending_deliveries();

		$this->assertCount( 1, $captured_payloads, 'api_key.revoked webhook must be dispatched.' );
		$envelope = $captured_payloads[0];
		$this->assertSame( 'api_key.revoked', $envelope['type'] );

		$data = $envelope['data'];
		$this->assertSame( $created_key['id'], $data['id'] );
		$this->assertSame( 'Test Revocation Key', $data['label'] );
		$this->assertSame( $created_key['prefix'], $data['prefix'] );
		$this->assertSame( $created_key['lastFour'], $data['last_four'] );
		$this->assertNotEmpty( $data['revoked_at'] );

		// Credentials must never leak.
		$this->assertArrayNotHasKey( 'key', $data );
		$this->assertArrayNotHasKey( 'key_hash', $data );
	}

	public function test_user_creation_dispatches_webhook_with_safe_profile_metadata_only(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/users' );

		// Register webhook subscribed to user.created.
		$this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'User Creation Webhook',
				'url'    => 'https://test.destination.org/hook',
				'events' => array( 'user.created' ),
			)
		);

		$captured_payloads = array();
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ) use ( &$captured_payloads ): array {
				$captured_payloads[] = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$new_user = $this->users_service->create_user(
			$request,
			array(
				'username'    => 'wh_test_newuser',
				'email'       => 'wh_test_newuser@example.com',
				'password'    => 'SecurePass123!',
				'firstName'   => 'New',
				'lastName'    => 'Member',
				'displayName' => 'New Member',
				'role'        => 'editor',
			)
		);
		$this->assertNotEmpty( $new_user['id'] );

		// Process queued background webhook deliveries.
		$this->webhooks_service->process_pending_deliveries();

		$this->assertCount( 1, $captured_payloads, 'user.created webhook must be dispatched.' );
		$envelope = $captured_payloads[0];
		$this->assertSame( 'user.created', $envelope['type'] );

		$data = $envelope['data'];
		$this->assertSame( (string) $new_user['id'], $data['id'] );
		$this->assertSame( 'wh_test_newuser', $data['username'] );
		$this->assertSame( 'wh_test_newuser@example.com', $data['email'] );
		$this->assertSame( 'New', $data['first_name'] );
		$this->assertSame( 'Member', $data['last_name'] );
		$this->assertSame( 'New Member', $data['display_name'] );
		$this->assertSame( 'editor', $data['role'] );

		// CRITICAL SECURITY ASSERTION: Passwords, tokens, or credential material must NEVER leak.
		$this->assertArrayNotHasKey( 'password', $data );
		$this->assertArrayNotHasKey( 'password_hash', $data );
		$this->assertArrayNotHasKey( 'password_reset_token', $data );
		$this->assertArrayNotHasKey( 'email_verification_token', $data );
		$this->assertArrayNotHasKey( 'backup_codes', $data );
		$this->assertArrayNotHasKey( 'two_factor_secret', $data );
	}

	public function test_user_update_dispatches_webhook_with_safe_metadata_and_previous(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/users' );

		// Create user first.
		$created_user = $this->users_service->create_user(
			$request,
			array(
				'username'    => 'wh_test_edituser',
				'email'       => 'wh_test_edituser@example.com',
				'password'    => 'SecurePass123!',
				'firstName'   => 'Initial',
				'lastName'    => 'Name',
				'displayName' => 'Initial Name',
				'role'        => 'editor',
			)
		);

		// Register webhook subscribed to user.updated.
		$this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'User Update Webhook',
				'url'    => 'https://test.destination.org/hook',
				'events' => array( 'user.updated' ),
			)
		);

		$captured_payloads = array();
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ) use ( &$captured_payloads ): array {
				$captured_payloads[] = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$updated = $this->users_service->update_user_by_username(
			$request,
			'wh_test_edituser',
			array(
				'firstName'   => 'UpdatedFirst',
				'lastName'    => 'UpdatedLast',
				'displayName' => 'Updated Display',
			)
		);
		$this->assertNotNull( $updated );

		// Process queued background webhook deliveries.
		$this->webhooks_service->process_pending_deliveries();

		$this->assertCount( 1, $captured_payloads, 'user.updated webhook must be dispatched.' );
		$envelope = $captured_payloads[0];
		$this->assertSame( 'user.updated', $envelope['type'] );

		$data = $envelope['data'];
		$this->assertSame( 'UpdatedFirst', $data['first_name'] );
		$this->assertSame( 'UpdatedLast', $data['last_name'] );
		$this->assertSame( 'Updated Display', $data['display_name'] );

		// Check previous state capture.
		$this->assertArrayHasKey( 'previous', $data );
		$this->assertSame( 'Initial', $data['previous']['first_name'] );
		$this->assertSame( 'Name', $data['previous']['last_name'] );
		$this->assertSame( 'Initial Name', $data['previous']['display_name'] );

		// No credentials.
		$this->assertArrayNotHasKey( 'password_hash', $data );
		$this->assertArrayNotHasKey( 'password_hash', $data['previous'] );
	}

	public function test_user_deletion_dispatches_webhook_with_metadata_captured_before_delete(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/users' );

		// Create user first.
		$user_to_delete = $this->users_service->create_user(
			$request,
			array(
				'username'    => 'wh_test_deleteuser',
				'email'       => 'wh_test_deleteuser@example.com',
				'password'    => 'SecurePass123!',
				'firstName'   => 'Delete',
				'lastName'    => 'Me',
				'displayName' => 'Delete Me',
				'role'        => 'editor',
			)
		);

		// Register webhook subscribed to user.deleted.
		$this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'User Deletion Webhook',
				'url'    => 'https://test.destination.org/hook',
				'events' => array( 'user.deleted' ),
			)
		);

		$captured_payloads = array();
		$this->webhooks_service->set_http_sender(
			function ( array $webhook, array $payload, float $timeout ) use ( &$captured_payloads ): array {
				$captured_payloads[] = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		$deleted = $this->users_service->delete_user_by_username( $request, 'wh_test_deleteuser' );
		$this->assertTrue( $deleted );

		// Process queued background webhook deliveries.
		$this->webhooks_service->process_pending_deliveries();

		$this->assertCount( 1, $captured_payloads, 'user.deleted webhook must be dispatched.' );
		$envelope = $captured_payloads[0];
		$this->assertSame( 'user.deleted', $envelope['type'] );

		$data = $envelope['data'];
		$this->assertSame( 'wh_test_deleteuser', $data['username'] );
		$this->assertSame( 'wh_test_deleteuser@example.com', $data['email'] );
		$this->assertNotEmpty( $data['deleted_at'] );

		// Verify record was actually deleted from database.
		$db_check = $this->db->get_row_by( 'users', array( 'username' => 'wh_test_deleteuser' ) );
		$this->assertNull( $db_check );
	}

	public function test_crypto_encryption_and_unencrypted_secret_rejection(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );

		// 1. Created webhook must store the secret in the database with the enc:v1: prefix.
		$created = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'Crypto Test Webhook',
				'url'    => 'https://test.destination.org/crypto-test',
				'events' => array( 'link.created' ),
			)
		);

		$row = $this->db->get_row_by( 'webhooks', array( 'id' => $created['id'] ) );
		$this->assertNotNull( $row );
		$this->assertStringStartsWith( 'enc:v1:', $row['secret'] );

		// 2. Explicit failure on plaintext or unencrypted secrets: no fallback accepted.
		$this->pdo->exec(
			"UPDATE {$this->table_prefix}webhooks
			SET secret = 'plaintext_secret_without_enc_prefix'
			WHERE id = '{$created['id']}'"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Webhook signing secret is not properly encrypted.' );
		$this->webhooks_service->test_webhook( $request, $created['id'] );
	}


	public function test_create_and_rotate_webhook_returns_raw_secret_once_only(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );

		// 1. Create webhook: returns raw secret.
		$created = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'     => 'Secure Webhook',
				'url'       => 'https://test.destination.org/secure',
				'events'    => array( 'link.created', 'api_key.created' ),
				'verifySsl' => true,
			)
		);

		$this->assertArrayHasKey( 'secret', $created );
		$this->assertStringStartsWith( 'peakurl_whsec_', $created['secret'] );
		$this->assertNotEmpty( $created['secretHint'] );
		$wh_id = $created['id'];

		// Verify database stores encrypted value, never plaintext.
		$db_row = $this->db->get_row_by( 'webhooks', array( 'id' => $wh_id ) );
		$this->assertStringStartsWith( 'enc:v1:', $db_row['secret'] );
		$this->assertNotSame( $created['secret'], $db_row['secret'] );

		// 2. Listing webhooks: never returns raw secret.
		$list  = $this->webhooks_service->list_webhooks( $request );
		$found = null;
		foreach ( $list as $item ) {
			if ( $item['id'] === $wh_id ) {
				$found = $item;
				break;
			}
		}
		$this->assertNotNull( $found );
		$this->assertArrayNotHasKey( 'secret', $found );
		$this->assertNotEmpty( $found['secretHint'] );

		// 3. Rotate secret: returns new raw secret once.
		$rotated = $this->webhooks_service->rotate_secret( $request, $wh_id );
		$this->assertArrayHasKey( 'secret', $rotated );
		$this->assertStringStartsWith( 'peakurl_whsec_', $rotated['secret'] );
		$this->assertNotSame( $created['secret'], $rotated['secret'] );
	}

	public function test_public_request_contract_accepts_only_camel_case_and_rejects_http(): void {
		$validator = new WebhooksValidator();

		// Rejects HTTP protocol.
		try {
			$validator->validate_create(
				array(
					'label'  => 'Test Webhook',
					'url'    => 'http://example.com/hook',
					'events' => array( 'link.created' ),
				)
			);
			$this->fail( 'Expected ApiException for HTTP protocol.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
			$this->assertStringContainsString( 'HTTPS', $e->getMessage() );
		}

		// Accepts camelCase verifySsl and isActive.
		$updates = $validator->validate_update(
			array(
				'verifySsl' => false,
				'isActive'  => false,
			)
		);
		$this->assertSame( 0, $updates['verify_ssl'] );
		$this->assertSame( 0, $updates['is_active'] );

		// Snake case aliases are NOT recognized in validate_update.
		$ignored_updates = $validator->validate_update(
			array(
				'verify_ssl' => 0,
				'is_active'  => 0,
			)
		);
		$this->assertArrayNotHasKey( 'verify_ssl', $ignored_updates );
		$this->assertArrayNotHasKey( 'is_active', $ignored_updates );
	}

	public function test_link_click_only_queues_delivery_and_does_not_invoke_http_or_dns_during_redirect(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );

		$dns_lookups    = array();
		$validator_mock = new class( $dns_lookups ) extends WebhooksValidator {
			/** @var array<int, string> */
			private array $lookups;
			public function __construct( array &$lookups ) {
				$this->lookups = &$lookups;
			}
			protected function get_destination_ip( string $host ): ?string {
				$this->lookups[] = $host;
				return '93.184.216.34';
			}
		};

		// Create dedicated service instance with monitored validator.
		$service = new WebhooksService(
			$this->db,
			$validator_mock,
			$this->auth_service,
			new Roles(),
			new Authorization( new Roles() ),
			$this->config,
			new Crypto( $this->config )
		);

		// Register webhook subscribed exclusively to link.clicked.
		$created = $service->create_webhook(
			$request,
			array(
				'label'  => 'Click Webhook',
				'url'    => 'https://example.com/click-webhook',
				'events' => array( 'link.clicked' ),
			)
		);
		$this->assertNotEmpty( $created['id'] );

		// Reset DNS lookup history after webhook creation.
		$dns_lookups = array();

		$http_called = false;
		$service->set_http_sender(
			function () use ( &$http_called ): array {
				$http_called = true;
				return array(
					'statusCode' => 200,
					'error'      => null,
				);
			}
		);

		// Simulate link click event on the redirect hot path.
		$link_row      = array(
			'id'              => Str::random_id( 16 ),
			'alias'           => 'fastclick',
			'destination_url' => 'https://target.destination.com/page',
			'title'           => 'Redirect Target',
			'user_id'         => $this->admin_user['id'],
		);
		$click_payload = array(
			'visitor_hash' => 'hash_test_123',
			'ip_address'   => '1.1.1.1',
			'country_code' => 'US',
		);

		$results = $service->dispatch_link_event( 'link.clicked', $link_row, null, null, $click_payload );

		// ASSERTION 1: Immediate dispatch returns queued marker without sending HTTP.
		$this->assertCount( 1, $results );
		$this->assertTrue( $results[0]['queued'] );
		$this->assertSame( 0, $results[0]['statusCode'] );

		// ASSERTION 2: External HTTP sender was NOT invoked during the click request.
		$this->assertFalse( $http_called, 'External webhook HTTP request must NEVER execute on redirect hot path.' );

		// ASSERTION 3: No DNS lookups were executed during the click request.
		$this->assertEmpty( $dns_lookups, 'No DNS lookups must occur during click redirect resolution.' );

		// ASSERTION 4: Durable delivery was persisted to database with status 'pending'.
		$delivery_id = $results[0]['deliveryId'];
		$delivery    = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $delivery );
		$this->assertSame( 'pending', $delivery['status'] );
		$this->assertSame( 0, (int) $delivery['attempts'] );
		$this->assertNull( $delivery['claim_token'] );
		$this->assertNotEmpty( $delivery['payload'] );

		// Now simulate scheduled job worker execution.
		$worker_result = $service->process_pending_deliveries( 10 );
		$this->assertSame( 1, $worker_result['processed'] );
		$this->assertSame( 1, $worker_result['delivered'] );
		$this->assertTrue( $http_called, 'HTTP request must execute in background worker.' );

		// Verify terminal state.
		$completed = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'delivered', $completed['status'] );
		$this->assertSame( 1, (int) $completed['attempts'] );
		$this->assertSame( '', $completed['payload'] );
	}

	public function test_scheduler_five_minute_contract_full_lifecycle(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );

		// 1. Create webhook.
		$webhook = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'Scheduler Contract Webhook',
				'url'    => 'https://example.com/scheduler-contract',
				'events' => array( 'link.created' ),
			)
		);
		$wh_id   = (string) $webhook['id'];

		$http_call_count  = 0;
		$next_status_code = 200;
		$this->webhooks_service->set_http_sender(
			function () use ( &$http_call_count, &$next_status_code ): array {
				++$http_call_count;
				return array(
					'statusCode' => $next_status_code,
					'error'      => 200 === $next_status_code ? null : 'Simulated failure',
				);
			}
		);

		// 2. Queue pending delivery due right now.
		$delivery_id = $this->webhooks_service->queue_delivery(
			$wh_id,
			'link.created',
			array( 'test' => 'contract' ),
			0
		);

		// 3. Run scheduled job through the job handler.
		$job     = new WebhookDeliveryJob( $this->db, $this->webhooks_service );
		$context = new ExecutionContext( 'peakurl_webhook_delivery', 'run_test_scheduler', 1, false, Date::now() );
		$result  = $job->execute( $context );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $http_call_count );

		// 4. Confirm delivery status is 'delivered' and claim is released.
		$row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertNull( $row['claim_token'] );
		$this->assertSame( '', $row['payload'] );

		// 5. Run job again immediately — confirm no duplicate deliveries sent.
		$result_empty = $job->execute( $context );
		$this->assertTrue( $result_empty->is_success() );
		$this->assertSame( 'No pending webhook deliveries.', $result_empty->get_summary() );
		$this->assertSame( 1, $http_call_count );

		// 6. Test transient failure: schedules next attempt and does NOT retry immediately.
		$next_status_code  = 503;
		$retry_delivery_id = $this->webhooks_service->queue_delivery(
			$wh_id,
			'link.created',
			array( 'test' => 'retryable' ),
			0
		);

		$result_retry = $job->execute( $context );
		$this->assertTrue( $result_retry->is_success() );
		$this->assertSame( 2, $http_call_count );

		$retry_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $retry_delivery_id ) );
		$this->assertSame( 'pending', $retry_row['status'] );
		$this->assertSame( 1, (int) $retry_row['attempts'] );
		$this->assertNull( $retry_row['claim_token'] );
		$this->assertNull( $retry_row['completed_at'] );
		$this->assertNotEmpty( $retry_row['payload'] );
		$this->assertGreaterThan( time(), strtotime( (string) $retry_row['next_attempt_at'] ) );

		// Running immediately does not process it because next_attempt_at is in the future.
		$result_no_retry = $job->execute( $context );
		$this->assertSame( 'No pending webhook deliveries.', $result_no_retry->get_summary() );
		$this->assertSame( 2, $http_call_count );

		// 7. Permanent failure: marked failed, completed_at set, payload cleared, claim released.
		$next_status_code      = 404;
		$permanent_delivery_id = $this->webhooks_service->queue_delivery(
			$wh_id,
			'link.created',
			array( 'test' => 'perm' ),
			0
		);

		$result_perm = $job->execute( $context );
		$this->assertTrue( $result_perm->is_success() );
		$this->assertSame( 3, $http_call_count );

		$perm_row = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $permanent_delivery_id ) );
		$this->assertSame( 'failed', $perm_row['status'] );
		$this->assertSame( 1, (int) $perm_row['attempts'] );
		$this->assertNull( $perm_row['claim_token'] );
		$this->assertNotNull( $perm_row['completed_at'] );
		$this->assertNotEmpty( $perm_row['payload'], 'Payload must be preserved on terminal failure for manual replay.' );
	}

	public function test_security_ssrf_rejects_unresolvable_private_and_rebinding_destinations(): void {
		$validator = new WebhooksValidator();

		// 1. Unresolvable domain fails validation.
		$this->assertNull( $validator->validate_destination( 'https://unresolvable.destination.invalid' ) );

		// 2. Localhost and internal domains fail validation.
		$this->assertNull( $validator->validate_destination( 'https://localhost/webhook' ) );
		$this->assertNull( $validator->validate_destination( 'https://app.local/webhook' ) );
		$this->assertNull( $validator->validate_destination( 'https://service.internal/webhook' ) );

		// 3. Private IP literals fail validation.
		$this->assertNull( $validator->validate_destination( 'https://127.0.0.1/hook' ) );
		$this->assertNull( $validator->validate_destination( 'https://10.0.0.1/hook' ) );
		$this->assertNull( $validator->validate_destination( 'https://192.168.1.1/hook' ) );
		$this->assertNull( $validator->validate_destination( 'https://169.254.169.254/hook' ) );
		$this->assertNull( $validator->validate_destination( 'https://[::1]/hook' ) );

		// 4. Public IP literal succeeds and returns destination IP.
		$this->assertSame( '93.184.216.34', $validator->validate_destination( 'https://93.184.216.34/hook' ) );

		// 5. Host resolution via subclass correctly validates public vs private/empty destinations.
		$mock_validator = new class() extends WebhooksValidator {
			public ?string $mocked_ip = null;
			protected function get_destination_ip( string $host ): ?string {
				return $this->mocked_ip;
			}
		};

		$mock_validator->mocked_ip = '10.0.0.5';
		$this->assertNull( $mock_validator->validate_destination( 'https://rebinding.destination.org/hook' ) );

		$mock_validator->mocked_ip = null;
		$this->assertNull( $mock_validator->validate_destination( 'https://nxdomain.destination.org/hook' ) );

		$mock_validator->mocked_ip = '93.184.216.34';
		$this->assertSame( '93.184.216.34', $mock_validator->validate_destination( 'https://safe.destination.org/hook' ) );
	}

	public function test_schema_upgrade_populates_missing_webhook_labels_from_host(): void {
		$crypto = new Crypto( $this->config );

		// 1. Insert an existing webhook with empty label.
		$unlabeled_wh_id = Str::random_id( 16 );
		$plain_secret    = 'peakurl_whsec_' . Str::random_id( 18 );
		$enc_secret      = $crypto->encrypt( $plain_secret );
		$hint            = substr( $plain_secret, 0, 18 ) . str_repeat( '•', 18 );
		$now             = Date::now();

		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, label, url, events, secret, secret_hint, verify_ssl, is_active, created_at, updated_at)
			VALUES ('{$unlabeled_wh_id}', {$this->admin_user['id']}, '', 'https://n8n.example.com/webhook/test', '[\"link.created\"]', '{$enc_secret}', '{$hint}', 1, 1, '{$now}', '{$now}')"
		);

		// 2. Insert a webhook with an existing custom label.
		$labeled_wh_id = Str::random_id( 16 );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhooks (id, user_id, label, url, events, secret, secret_hint, verify_ssl, is_active, created_at, updated_at)
			VALUES ('{$labeled_wh_id}', {$this->admin_user['id']}, 'Custom Label', 'https://example.com/custom', '[\"link.created\"]', '{$enc_secret}', '{$hint}', 1, 1, '{$now}', '{$now}')"
		);

		// 3. Run schema upgrade.
		$schema_path = \PeakURL\Core\Config\Environment::get_instance()->get_database_schema_path();
		$schema      = new \PeakURL\Services\Database\Schema( $this->connection, $schema_path );
		$changes     = array();
		$schema->upgrade( $changes );

		// 4. Verify unlabeled webhook received hostname as label.
		$migrated_row = $this->db->get_row_by( 'webhooks', array( 'id' => $unlabeled_wh_id ) );
		$this->assertNotNull( $migrated_row );
		$this->assertSame( 'n8n.example.com', $migrated_row['label'] );

		// 5. Verify already labeled webhook kept its label.
		$custom_row = $this->db->get_row_by( 'webhooks', array( 'id' => $labeled_wh_id ) );
		$this->assertNotNull( $custom_row );
		$this->assertSame( 'Custom Label', $custom_row['label'] );
	}

	public function test_webhook_label_is_required_persisted_and_updateable(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );

		// 1. Missing label throws validation error.
		try {
			$this->webhooks_service->create_webhook(
				$request,
				array(
					'url'    => 'https://example.com/label-test',
					'events' => array( 'link.created' ),
				)
			);
			$this->fail( 'Expected ApiException for missing label.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
			$this->assertStringContainsString( 'Webhook name', $e->getMessage() );
		}

		// 2. Whitespace-only label throws validation error.
		try {
			$this->webhooks_service->create_webhook(
				$request,
				array(
					'label'  => '   ',
					'url'    => 'https://example.com/label-test',
					'events' => array( 'link.created' ),
				)
			);
			$this->fail( 'Expected ApiException for empty whitespace label.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->get_status() );
			$this->assertStringContainsString( 'Webhook name', $e->getMessage() );
		}

		// 3. Valid label is trimmed and persisted.
		$created = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => '  Production Alerts  ',
				'url'    => 'https://example.com/label-test',
				'events' => array( 'link.created' ),
			)
		);
		$this->assertSame( 'Production Alerts', $created['label'] );

		// Persisted in database.
		$row = $this->db->get_row_by( 'webhooks', array( 'id' => $created['id'] ) );
		$this->assertSame( 'Production Alerts', $row['label'] );

		// Returned in list.
		$list  = $this->webhooks_service->list_webhooks( $request );
		$found = null;
		foreach ( $list as $item ) {
			if ( $item['id'] === $created['id'] ) {
				$found = $item;
				break;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 'Production Alerts', $found['label'] );

		// 4. Update label.
		$updated = $this->webhooks_service->update_webhook(
			$request,
			$created['id'],
			array(
				'label' => 'Updated Production Alerts',
			)
		);
		$this->assertSame( 'Updated Production Alerts', $updated['label'] );

		$refreshed_row = $this->db->get_row_by( 'webhooks', array( 'id' => $created['id'] ) );
		$this->assertSame( 'Updated Production Alerts', $refreshed_row['label'] );
	}

	public function test_webhook_delivery_headers_include_canonical_replay_safe_signature(): void {
		$request      = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );
		$webhook      = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'Sig Test Webhook',
				'url'    => 'https://example.com/sig-test',
				'events' => array( 'link.created' ),
			)
		);
		$wh_id        = (string) $webhook['id'];
		$wh_row       = $this->db->get_row_by( 'webhooks', array( 'id' => $wh_id ) );
		$crypto       = new Crypto( $this->config );
		$plain_secret = $crypto->decrypt( (string) $wh_row['secret'] );

		$captured_headers = array();
		$captured_payload = array();
		$this->webhooks_service->set_http_sender(
			function ( array $wh, array $payload, float $timeout, array $headers ) use ( &$captured_headers, &$captured_payload ): array {
				$captured_headers = $headers;
				$captured_payload = $payload;
				return array(
					'statusCode' => 200,
					'error'      => null,
					'retryAfter' => null,
				);
			}
		);

		try {
			$this->webhooks_service->test_webhook( $request, $wh_id );
		} finally {
			$this->webhooks_service->set_http_sender( null );
		}

		$this->assertNotEmpty( $captured_headers );
		$this->assertContains( 'Content-Type: application/json; charset=utf-8', $captured_headers );
		$this->assertContains( 'X-PeakURL-Event: webhook.test', $captured_headers );
		$this->assertContains( 'User-Agent: PeakURL-Webhook/1.7.1 (+https://peakurl.org)', $captured_headers );

		$headers_map = array();
		foreach ( $captured_headers as $header_line ) {
			$parts = explode( ':', $header_line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers_map[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		$this->assertArrayHasKey( 'X-PeakURL-Delivery', $headers_map );
		$this->assertArrayHasKey( 'X-PeakURL-Timestamp', $headers_map );
		$this->assertArrayHasKey( 'X-PeakURL-Signature', $headers_map );

		$timestamp = (int) $headers_map['X-PeakURL-Timestamp'];
		$this->assertGreaterThan( 0, $timestamp );
		$this->assertLessThanOrEqual( time(), $timestamp );

		$payload_json = json_encode( $captured_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$expected_sig = hash_hmac( 'sha256', $timestamp . '.' . $payload_json, $plain_secret );
		$this->assertSame( $expected_sig, $headers_map['X-PeakURL-Signature'] );

		// Legacy X-Hub-Signature-256 header must NOT be emitted.
		$this->assertArrayNotHasKey( 'X-Hub-Signature-256', $headers_map, 'Legacy X-Hub-Signature-256 header must not be emitted.' );
	}

	public function test_curl_post_webhook_options_and_output_suppression(): void {
		// 1. Invalid or unroutable destination returns error without network calls.
		$invalid_result = $this->webhooks_service->send_webhook_payload(
			array(
				'id'         => 'wh_invalid',
				'url'        => '',
				'secret'     => 'enc:v1:fake',
				'verify_ssl' => true,
			),
			array( 'test' => 1 ),
			5.0
		);

		$this->assertSame( 0, $invalid_result['statusCode'] );
		$this->assertSame( 'Invalid or unroutable webhook destination URL.', $invalid_result['error'] );
		$this->assertNull( $invalid_result['retryAfter'] ?? null );

		// 2. Start a local temporary HTTP server returning a small JSON response body.
		$tmp_dir = sys_get_temp_dir() . '/peakurl_curl_' . uniqid();
		mkdir( $tmp_dir );
		file_put_contents(
			$tmp_dir . '/index.php',
			'<?php header("Content-Type: application/json"); echo json_encode(["suppressed_secret_token" => "12345678"]);'
		);

		$sock = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$addr = stream_socket_get_name( $sock, false );
		$port = (int) substr( strrchr( $addr, ':' ), 1 );
		fclose( $sock );

		$proc = proc_open(
			array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $tmp_dir ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		usleep( 150000 );

		$mock_validator = new class() extends WebhooksValidator {
			public function validate_destination( string $url ): ?string {
				return '127.0.0.1';
			}
		};

		$roles         = new Roles();
		$authorization = new Authorization( $roles );
		$crypto        = new Crypto( $this->config );
		$test_service  = new WebhooksService(
			$this->db,
			$mock_validator,
			$this->auth_service,
			$roles,
			$authorization,
			$this->config,
			$crypto
		);

		$enc_secret = $crypto->encrypt( 'whsec_curl_suppression_test_key' );
		$webhook    = array(
			'id'         => 'wh_curl_test',
			'url'        => 'http://127.0.0.1:' . $port . '/index.php',
			'secret'     => $enc_secret,
			'verify_ssl' => false,
		);
		$payload    = array(
			'id'   => 'evt_suppression_test',
			'type' => 'link.created',
			'data' => array(),
		);

		ob_start();
		try {
			$result = $test_service->send_webhook_payload( $webhook, $payload, 2.0 );
		} finally {
			$captured_output = ob_get_clean();
			proc_terminate( $proc );
			proc_close( $proc );
			@unlink( $tmp_dir . '/index.php' );
			@rmdir( $tmp_dir );
		}

		$this->assertSame( 200, $result['statusCode'], 'cURL must successfully connect and receive HTTP 200.' );
		$this->assertSame( '', $captured_output, 'cURL execution must never output response bodies or errors to standard output.' );
		$this->assertArrayNotHasKey( 'body', $result, 'Response body must not be returned in sender result.' );
		$this->assertFalse( in_array( '12345678', $result, true ), 'Response body content must not be present in sender result.' );
	}

	public function test_webhook_health_metrics_semantics_and_deterministic_tie_breaking(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );
		$webhook = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'Health Test Webhook',
				'url'    => 'https://example.com/health-test',
				'events' => array( 'link.created' ),
			)
		);
		$wh_id   = (string) $webhook['id'];
		$now     = time();

		// 1. Pending delivery (in-flight, not completed)
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
			VALUES ('del_pending', '{$wh_id}', 'evt_1', 'link.created', '{}', 'pending', 0, 5, NOW(), NOW(), NOW())"
		);

		// 2. Retrying delivery (in-flight, 1 attempt, not completed)
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
			VALUES ('del_retrying', '{$wh_id}', 'evt_2', 'link.created', '{}', 'pending', 1, 5, NOW(), NOW(), NOW())"
		);

		// 3. Processing delivery (in-flight, not completed)
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
			VALUES ('del_processing', '{$wh_id}', 'evt_3', 'link.created', '{}', 'processing', 1, 5, NOW(), NOW(), NOW())"
		);

		// 4. Delivered within 24h
		$completed_2h = gmdate( 'Y-m-d H:i:s', $now - 7200 );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, completed_at, response_code, created_at, updated_at)
			VALUES ('del_ok_2h', '{$wh_id}', 'evt_4', 'link.created', '', 'delivered', 1, 5, NOW(), '{$completed_2h}', 200, '{$completed_2h}', '{$completed_2h}')"
		);

		// 5. Failed within 24h
		$completed_1h = gmdate( 'Y-m-d H:i:s', $now - 3600 );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, completed_at, response_code, last_error, created_at, updated_at)
			VALUES ('del_fail_1h', '{$wh_id}', 'evt_5', 'link.created', '', 'failed', 5, 5, NOW(), '{$completed_1h}', 500, 'Server Error', '{$completed_1h}', '{$completed_1h}')"
		);

		// 6. Delivered 30 hours ago (outside 24h window)
		$completed_30h = gmdate( 'Y-m-d H:i:s', $now - ( 30 * 3600 ) );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, completed_at, response_code, created_at, updated_at)
			VALUES ('del_ok_30h', '{$wh_id}', 'evt_6', 'link.created', '', 'delivered', 1, 5, NOW(), '{$completed_30h}', 200, '{$completed_30h}', '{$completed_30h}')"
		);

		// Check health summary via public list_webhooks
		$webhooks = $this->webhooks_service->list_webhooks( $request );
		$matched  = array_filter( $webhooks, static fn( array $item ): bool => $item['id'] === $wh_id );
		$this->assertNotEmpty( $matched );
		$webhook_item = reset( $matched );
		$health       = $webhook_item['health'];

		// Pending, retrying, and processing must be EXCLUDED from total24h and failed24h!
		// Total 24h must be 2 (del_ok_2h + del_fail_1h). del_ok_30h is outside 24h.
		$this->assertSame( 2, $health['total24h'], 'total24h must only count completed terminal records in 24h' );
		$this->assertSame( 1, $health['failed24h'], 'failed24h must only count terminal failures in 24h' );

		// 7. Test deterministic tie-breaking:
		// Clear deliveries and insert two records with identical created_at timestamps.
		$this->pdo->exec( "DELETE FROM {$this->table_prefix}webhook_deliveries WHERE webhook_id = '{$wh_id}'" );
		$tie_time = gmdate( 'Y-m-d H:i:s', $now - 60 );
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, completed_at, response_code, created_at, updated_at)
			VALUES ('del_tie_a', '{$wh_id}', 'evt_a', 'link.created', '', 'delivered', 1, 5, NOW(), '{$tie_time}', 200, '{$tie_time}', '{$tie_time}')"
		);
		$this->pdo->exec(
			"INSERT INTO {$this->table_prefix}webhook_deliveries (id, webhook_id, event_id, event, payload, status, attempts, max_attempts, next_attempt_at, completed_at, response_code, last_error, created_at, updated_at)
			VALUES ('del_tie_z', '{$wh_id}', 'evt_z', 'link.created', '', 'failed', 1, 5, NOW(), '{$tie_time}', 500, 'Tie failure', '{$tie_time}', '{$tie_time}')"
		);

		$tied_webhooks = $this->webhooks_service->list_webhooks( $request );
		$tied_matched  = array_filter( $tied_webhooks, static fn( array $item ): bool => $item['id'] === $wh_id );
		$this->assertNotEmpty( $tied_matched );
		$tied_item = reset( $tied_matched );

		// Resolves deterministically to del_tie_z (higher ID)
		$this->assertSame( 'failed', $tied_item['health']['lastStatus'] );
		$this->assertSame( 500, $tied_item['health']['lastResponseCode'] );
		$this->assertSame( 'Tie failure', $tied_item['health']['lastError'] );
	}

	public function test_link_events_dispatch_canonical_link_shape(): void {
		$request = $this->create_request_for_user( $this->admin_user, 'POST', '/api/v1/webhooks' );
		$webhook = $this->webhooks_service->create_webhook(
			$request,
			array(
				'label'  => 'Link Events Webhook',
				'url'    => 'https://example.com/link-events',
				'events' => array( 'link.updated', 'link.activated', 'link.deactivated', 'link.expired' ),
			)
		);
		$wh_id   = (string) $webhook['id'];

		$formatted_link = array(
			'id'             => 'link_shape_1',
			'userId'         => (string) $this->admin_user['id'],
			'shortCode'      => 'shape-code',
			'alias'          => 'shape-code',
			'shortUrl'       => 'https://peakurl.dev/shape-code',
			'title'          => 'Shape Title',
			'destinationUrl' => 'https://destination.com/target',
			'status'         => 'active',
			'hasPassword'    => false,
			'expiresAt'      => null,
			'createdAt'      => '2026-09-01T10:00:00Z',
			'updatedAt'      => '2026-09-01T10:00:00Z',
		);

		$previous_link = array(
			'id'             => 'link_shape_1',
			'userId'         => (string) $this->admin_user['id'],
			'shortCode'      => 'old-shape-code',
			'alias'          => 'old-shape-code',
			'shortUrl'       => 'https://peakurl.dev/old-shape-code',
			'title'          => 'Old Shape Title',
			'destinationUrl' => 'https://destination.com/old',
			'status'         => 'inactive',
		);

		// 1. Dispatch link.updated with canonical formatted link
		$results = $this->webhooks_service->dispatch_link_event(
			'link.updated',
			$formatted_link,
			$this->admin_user,
			$previous_link
		);
		$this->assertNotEmpty( $results );
		$delivery_id = $results[0]['deliveryId'];
		$delivery    = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $delivery_id ) );
		$this->assertNotNull( $delivery );
		$payload = json_decode( (string) $delivery['payload'], true );

		$this->assertSame( 'link.updated', $payload['type'] );
		$this->assertSame( 'shape-code', $payload['data']['alias'] );
		$this->assertSame( 'https://peakurl.dev/shape-code', $payload['data']['shortUrl'] );
		$this->assertSame( 'https://destination.com/target', $payload['data']['destinationUrl'] );
		$this->assertSame( 'Shape Title', $payload['data']['title'] );
		$this->assertSame( 'active', $payload['data']['status'] );
		$this->assertArrayHasKey( 'previous', $payload['data'] );
		$this->assertSame( 'old-shape-code', $payload['data']['previous']['alias'] );
		$this->assertSame( 'https://destination.com/old', $payload['data']['previous']['destinationUrl'] );
		$this->assertSame( 'inactive', $payload['data']['previous']['status'] );

		// 2. Dispatch link.activated
		$act_results = $this->webhooks_service->dispatch_link_event(
			'link.activated',
			$formatted_link,
			$this->admin_user,
			$previous_link
		);
		$this->assertNotEmpty( $act_results );
		$act_delivery = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $act_results[0]['deliveryId'] ) );
		$act_payload  = json_decode( (string) $act_delivery['payload'], true );
		$this->assertSame( 'link.activated', $act_payload['type'] );
		$this->assertSame( 'https://destination.com/target', $act_payload['data']['destinationUrl'] );

		// 3. Dispatch link.deactivated
		$deact_link    = array_merge( $formatted_link, array( 'status' => 'inactive' ) );
		$deact_prev    = array_merge( $previous_link, array( 'status' => 'active' ) );
		$deact_results = $this->webhooks_service->dispatch_link_event(
			'link.deactivated',
			$deact_link,
			$this->admin_user,
			$deact_prev
		);
		$this->assertNotEmpty( $deact_results );
		$deact_delivery = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $deact_results[0]['deliveryId'] ) );
		$deact_payload  = json_decode( (string) $deact_delivery['payload'], true );
		$this->assertSame( 'link.deactivated', $deact_payload['type'] );
		$this->assertSame( 'inactive', $deact_payload['data']['status'] );
		$this->assertSame( 'active', $deact_payload['data']['previous']['status'] );

		// 4. Dispatch link.expired
		$exp_link    = array_merge( $formatted_link, array( 'status' => 'expired' ) );
		$exp_prev    = array_merge( $previous_link, array( 'status' => 'active' ) );
		$exp_results = $this->webhooks_service->dispatch_link_event(
			'link.expired',
			$exp_link,
			$this->admin_user,
			$exp_prev
		);
		$this->assertNotEmpty( $exp_results );
		$exp_delivery = $this->db->get_row_by( 'webhook_deliveries', array( 'id' => $exp_results[0]['deliveryId'] ) );
		$exp_payload  = json_decode( (string) $exp_delivery['payload'], true );
		$this->assertSame( 'link.expired', $exp_payload['type'] );
		$this->assertSame( 'expired', $exp_payload['data']['status'] );
	}
}
