<?php
/**
 * Unit tests for session creation and User-Agent metadata storage.
 *
 * @package PeakURL\Tests\Unit\Auth
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PeakURL\Api\UsersApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Features\Auth\Credentials;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Features\Auth\Validator as AuthValidator;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Notifications;
use PeakURL\Services\Totp;

class SessionCreationTest extends TestCase {

	public function test_create_session_stores_raw_user_agent_and_normalized_metadata(): void {
		$db            = $this->createMock( PeakURL_DB::class );
		$users_api     = $this->createMock( UsersApi::class );
		$credentials   = $this->createMock( Credentials::class );
		$validator     = new AuthValidator();
		$totp          = $this->createMock( Totp::class );
		$notifications = $this->createMock( Notifications::class );
		$crypto        = $this->createMock( Crypto::class );
		$roles         = new Roles();
		$authorization = new Authorization( $roles );

		$crypto->method( 'is_configured' )->willReturn( true );
		$crypto->method( 'sign_session_token' )->willReturn( 'signed_mock_token' );

		$captured_session = null;
		$db->expects( $this->once() )
			->method( 'insert' )
			->with(
				'sessions',
				$this->callback(
					function ( array $row ) use ( &$captured_session ): bool {
						$captured_session = $row;
						return true;
					}
				)
			);

		$service = new AuthService(
			$db,
			$users_api,
			$credentials,
			$validator,
			$totp,
			$notifications,
			$crypto,
			$roles,
			$authorization,
			null,
			array()
		);

		$raw_ua  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
		$request = new Request(
			'POST',
			'/login',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_USER_AGENT' => $raw_ua,
				'REMOTE_ADDR'     => '192.0.2.50',
			)
		);

		$service->create_session_for_user( $request, 'user-123', false );

		$this->assertNotNull( $captured_session );
		$this->assertSame( 'user-123', $captured_session['user_id'] );
		$this->assertSame( $raw_ua, $captured_session['user_agent'] );
		$this->assertSame( 'Safari', $captured_session['browser'] );
		$this->assertSame( 'iOS', $captured_session['operating_system'] );
		$this->assertSame( 'Mobile', $captured_session['device'] );
		$this->assertSame( '192.0.2.50', $captured_session['ip_address'] );
	}
}
