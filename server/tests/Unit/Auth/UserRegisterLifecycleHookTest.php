<?php
/**
 * Unit tests for user_register lifecycle hook.
 *
 * Verifies that:
 * - user_register fires with formatted user and request on successful registration
 * - webhook user.created is dispatched on successful registration
 * - observer exceptions on user_register are safely isolated and do not break registration
 * - observer exceptions do not prevent subsequent webhook dispatch
 * - webhook failures are isolated and do not disrupt registration or response delivery
 * - simultaneous observer and webhook failures remain isolated
 * - validation failures (duplicate email/username, invalid password) suppress hook and webhook
 * - database exceptions propagate and prevent hook and webhook execution
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
use PeakURL\Core\Config\Constants;
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

class UserRegisterLifecycleHookTest extends TestCase {

	private MockObject&PeakURL_DB $db;
	private MockObject&UsersApi $users_api;
	private MockObject&Credentials $credentials;
	private MockObject&Notifications $notifications;
	private MockObject&Geoip $geoip;
	private MockObject&WebhooksService $webhooks_service;
	private AuthValidator $validator;
	private Totp $totp;
	private Crypto $crypto;
	private Roles $roles;
	private Authorization $authorization;
	private AuthService $service;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->db               = $this->createMock( PeakURL_DB::class );
		$this->users_api        = $this->createMock( UsersApi::class );
		$this->credentials      = $this->createMock( Credentials::class );
		$this->notifications    = $this->createMock( Notifications::class );
		$this->geoip            = $this->createMock( Geoip::class );
		$this->webhooks_service = $this->createMock( WebhooksService::class );
		$this->validator        = new AuthValidator();
		$this->totp             = new Totp();
		$this->roles            = new Roles();
		$this->authorization    = new Authorization( $this->roles );

		$config = array(
			Constants::SESSION_COOKIE_NAME => 'peakurl_session',
			Constants::SESSION_LIFETIME    => 2592000,
			Constants::AUTH_KEY            => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
			Constants::AUTH_SALT           => 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
		);

		$this->crypto = new Crypto( $config );

		$this->service = new AuthService(
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
			$config
		);

		$this->service->set_webhooks_service( $this->webhooks_service );
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	private function sample_user_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                            => '101',
				'username'                      => 'newuser',
				'email'                         => 'newuser@example.com',
				'first_name'                    => 'New',
				'last_name'                     => 'User',
				'display_name'                  => 'New User',
				'role'                          => 'editor',
				'is_email_verified'             => 0,
				'email_verification_token'      => 'token_hash',
				'email_verification_expires_at' => '2026-12-31 23:59:59',
				'email_verified_at'             => null,
				'created_at'                    => '2026-10-24 12:00:00',
				'updated_at'                    => '2026-10-24 12:00:00',
			),
			$overrides
		);
	}

	private function make_request(): Request {
		return new Request(
			'POST',
			'/register',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_USER_AGENT' => 'PeakURL-TestAgent/1.0',
				'REMOTE_ADDR'     => '192.0.2.1',
			)
		);
	}

	private function valid_payload(): array {
		return array(
			'username'  => 'newuser',
			'email'     => 'newuser@example.com',
			'password'  => 'SecurePassword123!',
			'firstName' => 'New',
			'lastName'  => 'User',
		);
	}

	public function test_user_register_fires_with_formatted_user_and_request_on_successful_registration(): void {
		$request  = $this->make_request();
		$user_row = $this->sample_user_row();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )->willReturn( null );
		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'insert_id' )->willReturn( '101' );
		$this->db->method( 'query' )->willReturn( 0 );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user_row );

		$hook_calls = new ArrayObject();
		\add_action(
			'user_register',
			function ( $user, $req ) use ( $hook_calls ): void {
				$hook_calls->append(
					array(
						'user'    => $user,
						'request' => $req,
					)
				);
			},
			10,
			2
		);

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.created', $user_row );

		$response = $this->service->register( $request, $this->valid_payload() );

		$this->assertCount( 1, $hook_calls );
		$this->assertSame( '101', $hook_calls[0]['user']['id'] );
		$this->assertSame( 'newuser', $hook_calls[0]['user']['username'] );
		$this->assertSame( 'newuser@example.com', $hook_calls[0]['user']['email'] );
		$this->assertSame( 'New', $hook_calls[0]['user']['firstName'] );
		$this->assertSame( 'User', $hook_calls[0]['user']['lastName'] );
		$this->assertSame( 'editor', $hook_calls[0]['user']['role'] );
		$this->assertSame( $request, $hook_calls[0]['request'] );

		$this->assertSame( '101', $response['id'] );
		$this->assertSame( 'newuser', $response['username'] );
		$this->assertSame( 'newuser@example.com', $response['email'] );
	}

	public function test_user_register_observer_exception_is_isolated_and_registration_succeeds(): void {
		$request  = $this->make_request();
		$user_row = $this->sample_user_row();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )->willReturn( null );
		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'insert_id' )->willReturn( '101' );
		$this->db->method( 'query' )->willReturn( 0 );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user_row );

		$log_file = tempnam( sys_get_temp_dir(), 'peakurl_reg_test_' );
		$this->assertIsString( $log_file, 'Failed to create temporary log file.' );
		$this->assertFileExists( $log_file, 'Temporary log file was not created.' );

		$prev_log        = ini_get( 'error_log' );
		$observer_called = false;

		try {
			$ini_result = ini_set( 'error_log', $log_file );
			$this->assertNotFalse( $ini_result, 'Failed to redirect error_log to temporary file.' );

			\add_action(
				'user_register',
				function () use ( &$observer_called ): void {
					$observer_called = true;
					throw new RuntimeException( 'LEAKED_OBSERVER_SECRET_TOKEN_XYZ' );
				}
			);

			$this->webhooks_service->expects( $this->once() )
				->method( 'dispatch_user_event' )
				->with( 'user.created', $user_row );

			$response = $this->service->register( $request, $this->valid_payload() );

			$this->assertTrue( $observer_called, 'Observer callback was not executed.' );
			$this->assertSame( '101', $response['id'] );
			$this->assertSame( 'newuser', $response['username'] );

			$log_content = file_get_contents( $log_file );
			$this->assertIsString( $log_content );
			$this->assertStringContainsString( 'PeakURL user_register observer error: RuntimeException', $log_content );
			$this->assertStringNotContainsString( 'LEAKED_OBSERVER_SECRET_TOKEN_XYZ', $log_content );
		} finally {
			if ( is_string( $prev_log ) ) {
				ini_set( 'error_log', $prev_log );
			}
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	public function test_user_created_webhook_exception_is_isolated_and_registration_succeeds(): void {
		$request  = $this->make_request();
		$user_row = $this->sample_user_row();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )->willReturn( null );
		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'insert_id' )->willReturn( '101' );
		$this->db->method( 'query' )->willReturn( 0 );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user_row );

		$hook_called = false;
		\add_action(
			'user_register',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.created', $user_row )
			->willThrowException( new RuntimeException( 'Webhook endpoint unreachable' ) );

		$response = $this->service->register( $request, $this->valid_payload() );

		$this->assertTrue( $hook_called );
		$this->assertSame( '101', $response['id'] );
		$this->assertSame( 'newuser', $response['username'] );
	}

	public function test_simultaneous_observer_and_webhook_exceptions_are_isolated(): void {
		$request  = $this->make_request();
		$user_row = $this->sample_user_row();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )->willReturn( null );
		$this->db->method( 'insert' )->willReturn( 1 );
		$this->db->method( 'insert_id' )->willReturn( '101' );
		$this->db->method( 'query' )->willReturn( 0 );
		$this->users_api->method( 'get_user' )->with( '101' )->willReturn( $user_row );

		$observer_called = false;
		\add_action(
			'user_register',
			function () use ( &$observer_called ): void {
				$observer_called = true;
				throw new RuntimeException( 'Observer failure' );
			}
		);

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_user_event' )
			->with( 'user.created', $user_row )
			->willThrowException( new RuntimeException( 'Webhook failure' ) );

		$response = $this->service->register( $request, $this->valid_payload() );

		$this->assertTrue( $observer_called );
		$this->assertSame( '101', $response['id'] );
		$this->assertSame( 'newuser', $response['username'] );
	}

	public function test_user_register_and_webhook_suppressed_on_duplicate_email(): void {
		$request = $this->make_request();

		$this->users_api->method( 'get_user_by_email' )
			->willReturn(
				$this->sample_user_row(
					array(
						'id'    => '99',
						'email' => 'newuser@example.com',
					)
				)
			);

		$hook_called = false;
		\add_action(
			'user_register',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->webhooks_service->expects( $this->never() )
			->method( 'dispatch_user_event' );

		$this->db->expects( $this->never() )
			->method( 'insert' );

		$exception_caught = false;
		try {
			$this->service->register( $request, $this->valid_payload() );
		} catch ( ApiException $e ) {
			$exception_caught = true;
			$this->assertSame( 422, $e->getCode() );
		}

		$this->assertTrue( $exception_caught, 'Expected ApiException(422) was not thrown.' );
		$this->assertFalse( $hook_called, 'user_register hook was dispatched despite duplicate email.' );
	}

	public function test_user_register_and_webhook_suppressed_on_duplicate_username(): void {
		$request = $this->make_request();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )
			->willReturn(
				$this->sample_user_row(
					array(
						'id'       => '99',
						'username' => 'newuser',
					)
				)
			);

		$hook_called = false;
		\add_action(
			'user_register',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->webhooks_service->expects( $this->never() )
			->method( 'dispatch_user_event' );

		$this->db->expects( $this->never() )
			->method( 'insert' );

		$exception_caught = false;
		try {
			$this->service->register( $request, $this->valid_payload() );
		} catch ( ApiException $e ) {
			$exception_caught = true;
			$this->assertSame( 422, $e->getCode() );
		}

		$this->assertTrue( $exception_caught, 'Expected ApiException(422) was not thrown.' );
		$this->assertFalse( $hook_called, 'user_register hook was dispatched despite duplicate username.' );
	}

	public function test_user_register_and_webhook_suppressed_on_invalid_password(): void {
		$request = $this->make_request();

		$hook_called = false;
		\add_action(
			'user_register',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->webhooks_service->expects( $this->never() )
			->method( 'dispatch_user_event' );

		$this->db->expects( $this->never() )
			->method( 'insert' );

		$payload             = $this->valid_payload();
		$payload['password'] = 'short';

		$exception_caught = false;
		try {
			$this->service->register( $request, $payload );
		} catch ( ApiException $e ) {
			$exception_caught = true;
			$this->assertSame( 422, $e->getCode() );
		}

		$this->assertTrue( $exception_caught, 'Expected ApiException(422) was not thrown.' );
		$this->assertFalse( $hook_called, 'user_register hook was dispatched despite invalid password.' );
	}

	public function test_user_register_and_webhook_suppressed_when_database_insert_fails(): void {
		$request = $this->make_request();

		$this->users_api->method( 'get_user_by_email' )->willReturn( null );
		$this->users_api->method( 'get_user_by_username' )->willReturn( null );

		$this->db->expects( $this->once() )
			->method( 'insert' )
			->with( 'users', $this->isType( 'array' ) )
			->willThrowException( new PDOException( 'Database connection dropped' ) );

		$hook_called = false;
		\add_action(
			'user_register',
			function () use ( &$hook_called ): void {
				$hook_called = true;
			}
		);

		$this->webhooks_service->expects( $this->never() )
			->method( 'dispatch_user_event' );

		$exception_caught = false;
		try {
			$this->service->register( $request, $this->valid_payload() );
		} catch ( PDOException $e ) {
			$exception_caught = true;
		}

		$this->assertTrue( $exception_caught, 'Expected PDOException was not thrown.' );
		$this->assertFalse( $hook_called, 'user_register hook was dispatched despite database failure.' );
	}
}
