<?php
/**
 * Unit tests for API key lifecycle hooks (api_key_created, api_key_revoked).
 *
 * Verifies that:
 * - api_key_created fires strictly after confirmed database persistence of a newly generated key
 * - plaintext secret key is strictly excluded from api_key_created observer arguments
 * - plaintext secret key is preserved in the authorized API response
 * - api_key_created observer receives bounded metadata and actor context
 * - api_key_revoked fires strictly after confirmed deletion of an existing key
 * - api_key_revoked observer receives bounded metadata including completed revocation timestamp
 * - unauthorized creation or revocation attempts are rejected without firing actions
 * - missing, non-existent, or concurrently revoked keys do not fire revocation actions
 * - outbound webhooks (api_key.created, api_key.revoked) fire alongside actions
 * - outbound webhook failures are isolated and do not prevent lifecycle action dispatch
 * - observer exceptions are isolated and do not fail API responses or persistence
 *
 * @package PeakURL\Tests\Unit\Auth
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use ArrayObject;
use PDOException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;
use RuntimeException;

class ApiKeyLifecycleHooksTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks_service;
	private AuthValidator $validator;
	private Totp $totp;
	private Crypto $crypto;
	private Roles $roles;
	private Authorization $authorization;
	private MockObject&AuthService $service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db               = $this->createMock( PeakURL_DB::class );
		$this->users_api        = $this->createMock( UsersApi::class );
		$this->credentials      = new Credentials( $this->db );
		$this->notifications    = $this->createMock( Notifications::class );
		$this->geoip            = $this->createMock( Geoip::class );
		$this->webhooks_service = $this->createMock( WebhooksService::class );
		$this->validator        = new AuthValidator();
		$this->totp             = new Totp();
		$this->roles            = new Roles();
		$this->authorization    = new Authorization( $this->roles );
		$this->crypto           = new Crypto( array() );

		// Use a mock of AuthService only overriding get_current_user to exercise real service methods
		$this->service = $this->getMockBuilder( AuthService::class )
			->setConstructorArgs(
				array(
					$this->db,
					$this->users_api,
					$this->credentials,
					$this->validator,
					$this->totp,
					$this->notifications,
					$this->crypto,
					$this->roles,
					$this->authorization,
					$this->geoip,
					array(),
				)
			)
			->onlyMethods( array( 'get_current_user' ) )
			->getMock();

		$this->service->set_webhooks_service( $this->webhooks_service );
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	private function sample_admin_user( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                            => '1',
				'username'                      => 'admin_user',
				'email'                         => 'admin@example.com',
				'role'                          => 'admin',
				'password_hash'                 => '$2y$10$supersecretadminpasswordhash',
				'two_factor_secret'             => 'BASE32SECRETVALUEDOESNOTLEAK',
				'two_factor_pending_secret'     => null,
				'backup_codes_json'             => '["code1"]',
				'email_verification_token'      => null,
				'email_verification_expires_at' => null,
				'password_reset_token'          => null,
				'password_reset_expires_at'     => null,
				'created_at'                    => '2026-01-01 00:00:00',
				'updated_at'                    => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	private function sample_editor_user( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                            => '102',
				'username'                      => 'editor_user',
				'email'                         => 'editor@example.com',
				'role'                          => 'editor',
				'password_hash'                 => '$2y$10$supersecreteditorpasswordhash',
				'two_factor_secret'             => null,
				'two_factor_pending_secret'     => null,
				'backup_codes_json'             => null,
				'email_verification_token'      => null,
				'email_verification_expires_at' => null,
				'password_reset_token'          => null,
				'password_reset_expires_at'     => null,
				'created_at'                    => '2026-01-01 00:00:00',
				'updated_at'                    => '2026-01-01 00:00:00',
			),
			$overrides
		);
	}

	private function create_request( string $method, string $path, array $body_params = array() ): Request {
		return new Request( $method, $path, array(), $body_params );
	}

	private function record_action( string $hook_name, int $accepted_args = 2 ): ArrayObject {
		$calls = new ArrayObject();
		\add_action(
			$hook_name,
			function ( ...$args ) use ( $calls ): void {
				$calls->append( $args );
			},
			10,
			$accepted_args
		);
		return $calls;
	}

	// =========================================================================
	// API Key Creation Tests
	// =========================================================================

	public function test_successful_api_key_creation_dispatches_api_key_created_with_bounded_metadata_and_user_context(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys', array( 'label' => 'Production Pipeline' ) );

		$this->service->method( 'get_current_user' )->willReturn( $user );

		$captured_insert = null;
		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'api_keys',
				$this->callback(
					function ( array $row ) use ( &$captured_insert ): bool {
						$captured_insert = $row;
						return true;
					}
				)
			)
			->willReturn( 1 );

		$calls = $this->record_action( 'api_key_created', 2 );

		$result = $this->service->add_api_key( $request, 'Production Pipeline' );

		// Confirm observer called exactly once
		$this->assertCount( 1, $calls );
		list( $payload, $context ) = $calls[0];

		// Check payload properties
		$this->assertSame( $captured_insert['id'], $payload['id'] );
		$this->assertSame( 'Production Pipeline', $payload['label'] );
		$this->assertSame( $captured_insert['key_prefix'], $payload['prefix'] );
		$this->assertSame( $captured_insert['key_last_four'], $payload['lastFour'] );
		$this->assertNotEmpty( $payload['createdAt'] );

		// Check user context
		$this->assertSame( '1', $context['id'] );
		$this->assertSame( 'admin_user', $context['username'] );
		$this->assertSame( 'admin', $context['role'] );

		// Confirm exact allowlisted keys in payload and context
		$this->assertSame(
			array( 'id', 'label', 'prefix', 'lastFour', 'createdAt' ),
			array_keys( $payload )
		);
		$this->assertSame(
			array( 'id', 'username', 'role' ),
			array_keys( $context )
		);

		// Confirm plaintext key is returned to creator
		$this->assertNotEmpty( $result['key'] );
		$this->assertSame( $captured_insert['id'], $result['id'] );
	}

	public function test_api_key_created_strictly_excludes_plaintext_secret_key_and_hash(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willReturn( 1 );

		$calls = $this->record_action( 'api_key_created', 2 );

		$result = $this->service->add_api_key( $request, 'Secret Test' );

		$this->assertCount( 1, $calls );
		list( $payload, $context ) = $calls[0];

		$plaintext_key = $result['key'];
		$this->assertNotEmpty( $plaintext_key );

		// Verify plaintext key does not exist anywhere in payload or context
		$this->assertArrayNotHasKey( 'key', $payload );
		$this->assertArrayNotHasKey( 'key_hash', $payload );
		$this->assertArrayNotHasKey( 'maskedKey', $payload );
		$this->assertFalse( in_array( $plaintext_key, $payload, true ) );
		$this->assertFalse( in_array( $plaintext_key, $context, true ) );

		// User context must not contain credentials
		$this->assertArrayNotHasKey( 'password_hash', $context );
		$this->assertArrayNotHasKey( 'two_factor_secret', $context );
	}

	public function test_api_key_creation_dispatches_outbound_webhook(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willReturn( 1 );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_api_key_event' )
			->with(
				'api_key.created',
				$this->callback(
					function ( array $data ): bool {
						return isset( $data['id'], $data['label'], $data['prefix'], $data['last_four'] );
					}
				),
				'1'
			);

		$calls = $this->record_action( 'api_key_created', 2 );

		$result = $this->service->add_api_key( $request, 'Webhook Key' );

		$this->assertCount( 1, $calls );
		$this->assertNotEmpty( $result['key'] );
	}

	public function test_outbound_webhook_failure_does_not_prevent_api_key_created_action_or_return_value(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willReturn( 1 );

		// Webhook throws network error
		$this->webhooks_service->method( 'dispatch_api_key_event' )
			->willThrowException( new RuntimeException( 'Webhook endpoint timeout' ) );

		$calls = $this->record_action( 'api_key_created', 2 );

		$result = $this->service->add_api_key( $request, 'Webhook Key' );

		// Action still dispatches and method still returns the generated key
		$this->assertCount( 1, $calls );
		$this->assertNotEmpty( $result['key'] );
	}

	public function test_observer_exception_on_creation_is_isolated_and_does_not_fail_response(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willReturn( 1 );

		$observer_called = false;
		\add_action(
			'api_key_created',
			function () use ( &$observer_called ): void {
				$observer_called = true;
				throw new RuntimeException( 'Plugin listener crashed on key creation' );
			}
		);

		// Must complete successfully without throwing
		$result = $this->service->add_api_key( $request, 'Fault Tolerant Key' );
		$this->assertTrue( $observer_called );
		$this->assertNotEmpty( $result['key'] );
	}

	public function test_unauthorized_user_cannot_create_api_key_and_hook_does_not_fire(): void {
		// Editor without manage_api_keys capability
		$user    = $this->sample_editor_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'insert' );

		$calls = $this->record_action( 'api_key_created', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->service->add_api_key( $request, 'Unauthorized Key' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_database_insertion_failure_throws_and_suppresses_api_key_created_action(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		// Database insert produces 0 affected rows
		$this->db->method( 'insert' )->willReturn( 0 );

		$calls = $this->record_action( 'api_key_created', 2 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to persist API key.' );

		try {
			$this->service->add_api_key( $request, 'Failed Key' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_database_pdo_exception_suppresses_api_key_created_action(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willThrowException(
			new PDOException( 'Disk full or table lock failure' )
		);

		$calls = $this->record_action( 'api_key_created', 2 );

		$this->expectException( PDOException::class );

		try {
			$this->service->add_api_key( $request, 'Failed Key' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_creation_hook_skipped_when_no_observers_registered(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'POST', '/api-keys' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'insert' )->willReturn( 1 );

		$this->assertFalse( \has_action( 'api_key_created' ) );

		$result = $this->service->add_api_key( $request, 'No Observers Key' );
		$this->assertNotEmpty( $result['key'] );
	}

	// =========================================================================
	// API Key Revocation Tests
	// =========================================================================

	public function test_successful_api_key_revocation_dispatches_api_key_revoked_with_bounded_metadata_and_user(): void {
		$user     = $this->sample_admin_user();
		$request  = $this->create_request( 'DELETE', '/api-keys/key_abc123' );
		$key_data = array(
			'id'            => 'key_abc123',
			'user_id'       => '1',
			'label'         => 'Decommissioned Pipeline',
			'key_prefix'    => 'pk_live_12345678',
			'key_last_four' => '9abc',
			'created_at'    => '2026-10-24 10:00:00',
		);

		$this->service->method( 'get_current_user' )->willReturn( $user );

		// get_api_key looks up the key by user_id and key_id
		$this->db->method( 'get_row_by' )
			->with(
				'api_keys',
				array(
					'id'      => 'key_abc123',
					'user_id' => '1',
				)
			)
			->willReturn( $key_data );

		// revoke_api_key deletes from api_keys table
		$this->db->method( 'delete' )
			->with(
				'api_keys',
				array(
					'id'      => 'key_abc123',
					'user_id' => '1',
				)
			)
			->willReturn( 1 );

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$result = $this->service->delete_api_key( $request, 'key_abc123' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );

		list( $payload, $context ) = $calls[0];

		// Check payload properties
		$this->assertSame( 'key_abc123', $payload['id'] );
		$this->assertSame( 'Decommissioned Pipeline', $payload['label'] );
		$this->assertSame( 'pk_live_12345678', $payload['prefix'] );
		$this->assertSame( '9abc', $payload['lastFour'] );
		$this->assertNotEmpty( $payload['createdAt'] );
		$this->assertNotEmpty( $payload['revokedAt'] );

		// Check user context
		$this->assertSame( '1', $context['id'] );
		$this->assertSame( 'admin_user', $context['username'] );
		$this->assertSame( 'admin', $context['role'] );

		// Confirm exact allowlisted keys in payload and context
		$this->assertSame(
			array( 'id', 'label', 'prefix', 'lastFour', 'createdAt', 'revokedAt' ),
			array_keys( $payload )
		);
		$this->assertSame(
			array( 'id', 'username', 'role' ),
			array_keys( $context )
		);
	}

	public function test_api_key_revocation_dispatches_outbound_webhook(): void {
		$user     = $this->sample_admin_user();
		$request  = $this->create_request( 'DELETE', '/api-keys/key_abc123' );
		$key_data = array(
			'id'            => 'key_abc123',
			'user_id'       => '1',
			'label'         => 'Decommissioned Pipeline',
			'key_prefix'    => 'pk_live_12345678',
			'key_last_four' => '9abc',
			'created_at'    => '2026-10-24 10:00:00',
		);

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'get_row_by' )->willReturn( $key_data );
		$this->db->method( 'delete' )->willReturn( 1 );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_api_key_event' )
			->with(
				'api_key.revoked',
				$this->callback(
					function ( array $data ): bool {
						return isset( $data['id'], $data['label'], $data['prefix'], $data['last_four'], $data['revoked_at'] );
					}
				),
				'1'
			);

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$result = $this->service->delete_api_key( $request, 'key_abc123' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );
	}

	public function test_outbound_webhook_failure_on_revocation_does_not_prevent_api_key_revoked_action(): void {
		$user     = $this->sample_admin_user();
		$request  = $this->create_request( 'DELETE', '/api-keys/key_abc123' );
		$key_data = array(
			'id'            => 'key_abc123',
			'user_id'       => '1',
			'label'         => 'Decommissioned Pipeline',
			'key_prefix'    => 'pk_live_12345678',
			'key_last_four' => '9abc',
			'created_at'    => '2026-10-24 10:00:00',
		);

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'get_row_by' )->willReturn( $key_data );
		$this->db->method( 'delete' )->willReturn( 1 );

		$this->webhooks_service->method( 'dispatch_api_key_event' )
			->willThrowException( new RuntimeException( 'Webhook dispatch connection failed' ) );

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$result = $this->service->delete_api_key( $request, 'key_abc123' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $calls );
	}

	public function test_observer_exception_on_revocation_is_isolated_and_does_not_fail_return(): void {
		$user     = $this->sample_admin_user();
		$request  = $this->create_request( 'DELETE', '/api-keys/key_abc123' );
		$key_data = array(
			'id'            => 'key_abc123',
			'user_id'       => '1',
			'label'         => 'Decommissioned Pipeline',
			'key_prefix'    => 'pk_live_12345678',
			'key_last_four' => '9abc',
			'created_at'    => '2026-10-24 10:00:00',
		);

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'get_row_by' )->willReturn( $key_data );
		$this->db->method( 'delete' )->willReturn( 1 );

		$observer_called = false;
		\add_action(
			'api_key_revoked',
			function () use ( &$observer_called ): void {
				$observer_called = true;
				throw new RuntimeException( 'Audit logger hook crashed on revocation' );
			}
		);

		// Must complete successfully without throwing
		$result = $this->service->delete_api_key( $request, 'key_abc123' );
		$this->assertTrue( $observer_called );
		$this->assertTrue( $result );
	}

	public function test_revocation_of_nonexistent_or_already_revoked_key_does_not_fire_hook(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'DELETE', '/api-keys/key_nonexistent' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		// get_api_key returns null (key not found)
		$this->db->method( 'get_row_by' )->willReturn( null );
		// delete returns 0 (0 rows affected)
		$this->db->method( 'delete' )->willReturn( 0 );

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$result = $this->service->delete_api_key( $request, 'key_nonexistent' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $calls );
	}

	public function test_concurrent_revocation_missing_metadata_does_not_fire_hook(): void {
		$user    = $this->sample_admin_user();
		$request = $this->create_request( 'DELETE', '/api-keys/key_race' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		// Key was deleted between get_api_key and delete
		$this->db->method( 'get_row_by' )->willReturn( null );
		$this->db->method( 'delete' )->willReturn( 0 );

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$result = $this->service->delete_api_key( $request, 'key_race' );

		$this->assertFalse( $result );
		$this->assertCount( 0, $calls );
	}

	public function test_unauthorized_user_cannot_revoke_api_key_and_hook_does_not_fire(): void {
		$user    = $this->sample_editor_user();
		$request = $this->create_request( 'DELETE', '/api-keys/key_abc123' );

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->expects( $this->never() )->method( 'delete' );

		$calls = $this->record_action( 'api_key_revoked', 2 );

		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 403 );

		try {
			$this->service->delete_api_key( $request, 'key_abc123' );
		} finally {
			$this->assertCount( 0, $calls );
		}
	}

	public function test_revocation_hook_skipped_when_no_observers_registered(): void {
		$user     = $this->sample_admin_user();
		$request  = $this->create_request( 'DELETE', '/api-keys/key_abc123' );
		$key_data = array(
			'id'            => 'key_abc123',
			'user_id'       => '1',
			'label'         => 'Decommissioned Pipeline',
			'key_prefix'    => 'pk_live_12345678',
			'key_last_four' => '9abc',
			'created_at'    => '2026-10-24 10:00:00',
		);

		$this->service->method( 'get_current_user' )->willReturn( $user );
		$this->db->method( 'get_row_by' )->willReturn( $key_data );
		$this->db->method( 'delete' )->willReturn( 1 );

		$this->assertFalse( \has_action( 'api_key_revoked' ) );

		$result = $this->service->delete_api_key( $request, 'key_abc123' );
		$this->assertTrue( $result );
	}
}
