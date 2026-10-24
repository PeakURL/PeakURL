<?php
/**
 * Unit tests for the link_captcha_required policy filter.
 *
 * Verifies that:
 * 1. CAPTCHA is required by default when no callback is registered.
 * 2. CAPTCHA exemption works for a specifically selected link.
 * 3. Normal CAPTCHA enforcement applies when the callback returns true.
 * 4. Invalid filter return types fail closed (enforce CAPTCHA).
 * 5. Callback exceptions fail closed with sanitized error logging.
 * 6. Password-protected links remain protected after CAPTCHA exemption.
 * 7. Expired, disabled, and nonexistent links remain inaccessible.
 * 8. Existing CAPTCHA verification cookies work unchanged.
 * 9. No forged CAPTCHA verification events or cookies occur on exemption.
 * 10. Exemption behavior is consistent across reCAPTCHA and Turnstile providers.
 * 11. Click recording and redirects are preserved for GET and HEAD requests.
 * 12. No duplicate observer dispatches or unintended response structure changes occur.
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PeakURL\Features\Links\Access;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\Captcha;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;

class LinkCaptchaPolicyFilterTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private LinksValidator $validator;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&Captcha $captcha;
	private Access $access;
	private array $config;
	private array $turnstile_challenge;
	private array $recaptcha_challenge;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->validator         = new LinksValidator();
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->captcha           = $this->createMock( Captcha::class );

		$this->config = array(
			'PEAKURL_AUTH_KEY'  => 'test_key_01234567890123456789012345678901',
			'PEAKURL_AUTH_SALT' => 'test_salt_01234567890123456789012345678901',
		);

		$this->turnstile_challenge = array(
			'provider'      => 'turnstile',
			'siteKey'       => 'turnstile-site-key',
			'responseField' => 'cf-turnstile-response',
		);

		$this->recaptcha_challenge = array(
			'provider'      => 'recaptcha',
			'siteKey'       => 'recaptcha-site-key',
			'action'        => 'peakurl_redirect',
			'responseField' => 'g-recaptcha-response',
		);

		$this->access = new Access(
			$this->repository,
			$this->validator,
			$this->analytics_service,
			$this->webhooks_service,
			$this->captcha,
			$this->config,
			fn( array $row ): array => array(
				'id'             => (string) ( $row['id'] ?? '' ),
				'shortCode'      => (string) ( $row['short_code'] ?? '' ),
				'destinationUrl' => (string) ( $row['destination_url'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? '' ),
			)
		);
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	/**
	 * Helper to create a standard active link row.
	 *
	 * @param string      $code           Short code.
	 * @param string|null $password_value Optional password hash.
	 * @param string      $status         Status string.
	 * @param string|null $expires_at     Expiration datetime.
	 * @return array<string, mixed>
	 */
	private function create_link_row(
		string $code,
		?string $password_value = null,
		string $status = 'active',
		?string $expires_at = null
	): array {
		return array(
			'id'              => 'link_' . $code,
			'short_code'      => $code,
			'alias'           => 'alias_' . $code,
			'title'           => 'Title ' . $code,
			'destination_url' => 'https://example.com/dest/' . $code,
			'status'          => $status,
			'password_value'  => $password_value,
			'expires_at'      => $expires_at,
		);
	}

	/**
	 * 1. CAPTCHA is required with no callback registered.
	 */
	public function test_captcha_required_with_no_callback(): void {
		$row = $this->create_link_row( 'standard' );
		$this->repository->method( 'find_link_access_row' )->with( 'standard' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$request = new Request( 'GET', '/standard', array(), array() );
		$result  = $this->access->get_link_access( 'standard', $request );

		$this->assertSame( 'captcha_required', $result['status'] );
		$this->assertTrue( $result['protected'] );
		$this->assertSame( $this->turnstile_challenge, $result['challenge'] );
		$this->assertSame( $row, $result['url'] );
	}

	/**
	 * 2. CAPTCHA exemption works for a specifically selected link.
	 */
	public function test_captcha_exemption_for_specifically_selected_link(): void {
		$exempt_row = $this->create_link_row( 'exempt-link' );
		$normal_row = $this->create_link_row( 'normal-link' );

		$this->repository->method( 'find_link_access_row' )->willReturnCallback(
			fn( string $code ) => match ( $code ) {
				'exempt-link' => $exempt_row,
				'normal-link' => $normal_row,
				default       => null,
			}
		);
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$captured_safe_link = null;
		$exempt_invoked     = false;

		\add_filter(
			'link_captcha_required',
			function ( bool $required, array $safe_link, Request $request ) use ( &$captured_safe_link, &$exempt_invoked ): bool {
				$exempt_invoked = true;
				if ( 'exempt-link' === ( $safe_link['shortCode'] ?? '' ) ) {
					$captured_safe_link = $safe_link;
					return false;
				}
				return true;
			},
			10,
			3
		);

		$request = new Request( 'GET', '/exempt-link', array(), array() );
		$result  = $this->access->get_link_access( 'exempt-link', $request );

		$this->assertTrue( $exempt_invoked, 'Filter callback was not executed for exempt link.' );
		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 'https://example.com/dest/exempt-link', $result['location'] );
		$this->assertFalse( $result['captchaProtected'] );

		// Verify $safe_link structure, canonical naming, and privacy boundaries:
		$this->assertIsArray( $captured_safe_link );
		$this->assertSame(
			array(
				'id'        => 'link_exempt-link',
				'shortCode' => 'exempt-link',
				'alias'     => 'alias_exempt-link',
				'title'     => 'Title exempt-link',
				'status'    => 'active',
			),
			$captured_safe_link
		);
		$this->assertArrayNotHasKey( 'destination_url', $captured_safe_link, 'Destination URL must not be exposed to filter callbacks.' );
		$this->assertArrayNotHasKey( 'destinationUrl', $captured_safe_link, 'Destination URL must not be exposed to filter callbacks.' );
		$this->assertArrayNotHasKey( 'password_value', $captured_safe_link, 'Password hash must not be exposed to filter callbacks.' );
		$this->assertArrayNotHasKey( 'password', $captured_safe_link, 'Password must not be exposed to filter callbacks.' );
		$this->assertArrayNotHasKey( 'short_code', $captured_safe_link, 'Legacy short_code must not duplicate canonical shortCode.' );
		$this->assertArrayNotHasKey( 'user_id', $captured_safe_link, 'User ID must not be exposed.' );
		$this->assertArrayNotHasKey( 'userId', $captured_safe_link, 'User ID must not be exposed.' );

		// Normal link still requires CAPTCHA:
		$normal_request = new Request( 'GET', '/normal-link', array(), array() );
		$normal_result  = $this->access->get_link_access( 'normal-link', $normal_request );
		$this->assertSame( 'captcha_required', $normal_result['status'] );
	}

	/**
	 * 3. Normal CAPTCHA enforcement applies when the callback returns true.
	 */
	public function test_normal_captcha_enforcement_when_callback_returns_true(): void {
		$row = $this->create_link_row( 'normal-true' );
		$this->repository->method( 'find_link_access_row' )->with( 'normal-true' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$callback_invoked = false;
		\add_filter(
			'link_captcha_required',
			function ( bool $required, array $safe_link, Request $request ) use ( &$callback_invoked ): bool {
				$callback_invoked = true;
				return true;
			},
			10,
			3
		);

		$request = new Request( 'GET', '/normal-true', array(), array() );
		$result  = $this->access->get_link_access( 'normal-true', $request );

		$this->assertTrue( $callback_invoked, 'Filter callback was not executed.' );
		$this->assertSame( 'captcha_required', $result['status'] );
		$this->assertTrue( $result['protected'] );
	}

	/**
	 * Data provider for invalid filter return values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_filter_returns_provider(): array {
		return array(
			'null return'     => array( null ),
			'integer zero'    => array( 0 ),
			'integer one'     => array( 1 ),
			'empty string'    => array( '' ),
			'string false'    => array( 'false' ),
			'empty array'     => array( array() ),
			'non-empty array' => array( array( 'exempt' => true ) ),
			'stdClass object' => array( new \stdClass() ),
		);
	}

	/**
	 * 4. Invalid filter return types fail closed (strict boolean comparison).
	 */
	#[DataProvider( 'invalid_filter_returns_provider' )]
	public function test_invalid_filter_return_types_fail_closed( mixed $invalid_return ): void {
		$row = $this->create_link_row( 'invalid-return' );
		$this->repository->method( 'find_link_access_row' )->with( 'invalid-return' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$callback_invoked = false;
		\add_filter(
			'link_captcha_required',
			function () use ( $invalid_return, &$callback_invoked ): mixed {
				$callback_invoked = true;
				return $invalid_return;
			},
			10,
			3
		);

		$request = new Request( 'GET', '/invalid-return', array(), array() );
		$result  = $this->access->get_link_access( 'invalid-return', $request );

		$this->assertTrue( $callback_invoked, 'Filter callback was not executed.' );
		$this->assertSame( 'captcha_required', $result['status'], 'Invalid return type must fail closed.' );
	}

	/**
	 * 5. Callback exceptions fail closed with sanitized error logging.
	 */
	public function test_callback_exception_fails_closed_with_sanitized_logs(): void {
		$row = $this->create_link_row( 'throw-test' );
		$this->repository->method( 'find_link_access_row' )->with( 'throw-test' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$log_file = tempnam( sys_get_temp_dir(), 'peakurl_captcha_test_' );
		$this->assertIsString( $log_file );
		$this->assertFileExists( $log_file );

		$prev_log         = ini_get( 'error_log' );
		$callback_invoked = false;

		try {
			$ini_result = ini_set( 'error_log', $log_file );
			$this->assertNotFalse( $ini_result, 'Failed to redirect error_log to temporary file.' );

			\add_filter(
				'link_captcha_required',
				function () use ( &$callback_invoked ): bool {
					$callback_invoked = true;
					throw new \RuntimeException( 'SECRET_CANARY_TOKEN_XYZ_888' );
				},
				10,
				3
			);

			$request = new Request( 'GET', '/throw-test', array(), array() );
			$result  = $this->access->get_link_access( 'throw-test', $request );

			$this->assertTrue( $callback_invoked, 'Throwing filter callback was not executed.' );
			$this->assertSame( 'captcha_required', $result['status'], 'Exceptions must fail closed.' );

			$log_content = file_get_contents( $log_file );
			$this->assertIsString( $log_content );
			$this->assertStringContainsString( 'PeakURL link_captcha_required filter error: RuntimeException', $log_content );
			$this->assertStringNotContainsString( 'SECRET_CANARY_TOKEN_XYZ_888', $log_content, 'Sensitive exception message must not be logged.' );
		} finally {
			if ( is_string( $prev_log ) ) {
				ini_set( 'error_log', $prev_log );
			}
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	/**
	 * 6. Password-protected links remain protected after CAPTCHA exemption.
	 */
	public function test_password_protected_links_remain_protected_after_captcha_exemption(): void {
		$password_hash = password_hash( 'mypassword', PASSWORD_DEFAULT );
		$row           = $this->create_link_row( 'pass-protected', $password_hash );

		$this->repository->method( 'find_link_access_row' )->with( 'pass-protected' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		// Exempt the link from CAPTCHA:
		\add_filter(
			'link_captcha_required',
			fn(): bool => false,
			10,
			3
		);

		// GET request: CAPTCHA is bypassed, but password_required is enforced:
		$get_request = new Request( 'GET', '/pass-protected', array(), array() );
		$get_result  = $this->access->get_link_access( 'pass-protected', $get_request );
		$this->assertSame( 'password_required', $get_result['status'] );

		// POST request without password:
		$empty_post   = new Request( 'POST', '/pass-protected', array(), array() );
		$empty_result = $this->access->get_link_access( 'pass-protected', $empty_post );
		$this->assertSame( 'password_required', $empty_result['status'] );

		// POST request with incorrect password:
		$bad_post   = new Request( 'POST', '/pass-protected', array(), array( 'link_password' => 'wrongpass' ) );
		$bad_result = $this->access->get_link_access( 'pass-protected', $bad_post );
		$this->assertSame( 'password_invalid', $bad_result['status'] );

		// POST request with correct password:
		$good_post   = new Request( 'POST', '/pass-protected', array(), array( 'link_password' => 'mypassword' ) );
		$good_result = $this->access->get_link_access( 'pass-protected', $good_post );
		$this->assertSame( 'redirect', $good_result['status'] );
		$this->assertSame( 'https://example.com/dest/pass-protected', $good_result['location'] );
		$this->assertNotEmpty( $good_post->get_response_cookies() );
	}

	/**
	 * 7. Expired, disabled, and nonexistent links remain inaccessible.
	 */
	public function test_expired_disabled_and_nonexistent_links_remain_inaccessible(): void {
		$expired_row  = $this->create_link_row( 'expired-link', null, 'expired', '2020-01-01 00:00:00' );
		$inactive_row = $this->create_link_row( 'inactive-link', null, 'inactive' );
		$trashed_row  = $this->create_link_row( 'trashed-link', null, 'trashed' );

		$this->repository->method( 'find_link_access_row' )->willReturnCallback(
			fn( string $code ) => match ( $code ) {
				'expired-link'  => $expired_row,
				'inactive-link' => $inactive_row,
				'trashed-link'  => $trashed_row,
				default         => null,
			}
		);
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		// Blanket exemption filter:
		\add_filter(
			'link_captcha_required',
			fn(): bool => false,
			10,
			3
		);

		// Nonexistent link:
		$req_404 = new Request( 'GET', '/nonexistent', array(), array() );
		$res_404 = $this->access->get_link_access( 'nonexistent', $req_404 );
		$this->assertSame( 'not_found', $res_404['status'] );

		// Expired link:
		$req_exp = new Request( 'GET', '/expired-link', array(), array() );
		$res_exp = $this->access->get_link_access( 'expired-link', $req_exp );
		$this->assertSame( 'expired', $res_exp['status'] );

		// Inactive link:
		$req_inact = new Request( 'GET', '/inactive-link', array(), array() );
		$res_inact = $this->access->get_link_access( 'inactive-link', $req_inact );
		$this->assertSame( 'unavailable', $res_inact['status'] );

		// Trashed link:
		$req_trash = new Request( 'GET', '/trashed-link', array(), array() );
		$res_trash = $this->access->get_link_access( 'trashed-link', $req_trash );
		$this->assertSame( 'unavailable', $res_trash['status'] );
	}

	/**
	 * 8. Existing CAPTCHA verification cookies work unchanged.
	 */
	public function test_existing_captcha_verification_cookies_work_unchanged(): void {
		$row = $this->create_link_row( 'cookie-link' );
		$this->repository->method( 'find_link_access_row' )->with( 'cookie-link' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		// Calculate expected signed cookie using internal link_captcha_cookie_name/value logic:
		$cookie_name  = 'peakurl_link_captcha_' . (string) $row['id'];
		$payload      = implode(
			'|',
			array(
				(string) $row['id'],
				(string) ( $row['updated_at'] ?? '' ),
				(string) $this->turnstile_challenge['provider'],
				(string) $this->turnstile_challenge['siteKey'],
			)
		);
		$cookie_value = hash_hmac( 'sha256', $payload, $this->config['PEAKURL_AUTH_SALT'] );

		// Request with existing valid cookie in parameter 5 ($cookie_params):
		$request = new Request( 'GET', '/cookie-link', array(), array(), array( $cookie_name => $cookie_value ) );
		$result  = $this->access->get_link_access( 'cookie-link', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertTrue( $result['captchaProtected'] );
	}

	/**
	 * 9. No forged CAPTCHA verification events or cookies occur on exemption.
	 */
	public function test_no_forged_captcha_verification_events_or_cookies_on_exemption(): void {
		$row = $this->create_link_row( 'clean-exempt' );
		$this->repository->method( 'find_link_access_row' )->with( 'clean-exempt' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$verified_invoked = false;
		$failed_invoked   = false;

		\add_action(
			'link_captcha_verified',
			function () use ( &$verified_invoked ): void {
				$verified_invoked = true;
			}
		);
		\add_action(
			'link_captcha_failed',
			function () use ( &$failed_invoked ): void {
				$failed_invoked = true;
			}
		);

		\add_filter(
			'link_captcha_required',
			fn(): bool => false,
			10,
			3
		);

		$request = new Request( 'GET', '/clean-exempt', array(), array() );
		$result  = $this->access->get_link_access( 'clean-exempt', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertFalse( $verified_invoked, 'link_captcha_verified must not fire on exemption.' );
		$this->assertFalse( $failed_invoked, 'link_captcha_failed must not fire on exemption.' );
		$this->assertEmpty( $request->get_response_cookies(), 'No CAPTCHA cookie should be queued on exemption.' );
	}

	/**
	 * 10. Exemption behavior is consistent across reCAPTCHA and Turnstile providers.
	 */
	public function test_correct_behavior_for_recaptcha_and_turnstile(): void {
		$turnstile_row = $this->create_link_row( 'turnstile-link' );
		$recaptcha_row = $this->create_link_row( 'recaptcha-link' );

		$this->repository->method( 'find_link_access_row' )->willReturnCallback(
			fn( string $code ) => match ( $code ) {
				'turnstile-link' => $turnstile_row,
				'recaptcha-link' => $recaptcha_row,
				default          => null,
			}
		);

		\add_filter(
			'link_captcha_required',
			fn( bool $req, array $safe_link ): bool => 'turnstile-link' !== ( $safe_link['shortCode'] ?? '' ),
			10,
			3
		);

		// Turnstile link is exempt:
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );
		$turn_req = new Request( 'GET', '/turnstile-link', array(), array() );
		$turn_res = $this->access->get_link_access( 'turnstile-link', $turn_req );
		$this->assertSame( 'redirect', $turn_res['status'] );

		// reCAPTCHA link is NOT exempt:
		$this->captcha = $this->createMock( Captcha::class );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->recaptcha_challenge );
		$access_recaptcha = new Access(
			$this->repository,
			$this->validator,
			$this->analytics_service,
			$this->webhooks_service,
			$this->captcha,
			$this->config
		);

		$recap_req = new Request( 'GET', '/recaptcha-link', array(), array() );
		$recap_res = $access_recaptcha->get_link_access( 'recaptcha-link', $recap_req );
		$this->assertSame( 'captcha_required', $recap_res['status'] );
	}

	/**
	 * 11. Preserved click recording and redirects for GET and HEAD requests.
	 */
	public function test_preserved_click_recording_and_get_and_head_redirects(): void {
		$row = $this->create_link_row( 'click-preserve' );
		$this->repository->method( 'find_link_access_row' )->with( 'click-preserve' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		\add_filter(
			'link_captcha_required',
			fn(): bool => false,
			10,
			3
		);

		// Expect click recording for both GET and HEAD:
		$this->analytics_service->expects( $this->exactly( 2 ) )
			->method( 'record_click' )
			->with( $row, $this->isInstanceOf( Request::class ), false );

		$get_request = new Request( 'GET', '/click-preserve', array(), array() );
		$get_result  = $this->access->get_link_access( 'click-preserve', $get_request );
		$this->assertSame( 'redirect', $get_result['status'] );
		$this->assertSame( 'https://example.com/dest/click-preserve', $get_result['location'] );

		$head_request = new Request( 'HEAD', '/click-preserve', array(), array() );
		$head_result  = $this->access->get_link_access( 'click-preserve', $head_request );
		$this->assertSame( 'redirect', $head_result['status'] );
		$this->assertSame( 'https://example.com/dest/click-preserve', $head_result['location'] );
	}

	/**
	 * 12. No duplicate observer dispatches or unintended response structure changes occur.
	 */
	public function test_no_duplicate_observer_dispatches_or_unintended_response_changes(): void {
		$row = $this->create_link_row( 'single-pass' );
		$this->repository->method( 'find_link_access_row' )->with( 'single-pass' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$filter_call_count = 0;
		\add_filter(
			'link_captcha_required',
			function () use ( &$filter_call_count ): bool {
				++$filter_call_count;
				return false;
			},
			10,
			3
		);

		$request = new Request( 'GET', '/single-pass', array(), array() );
		$result  = $this->access->get_link_access( 'single-pass', $request );

		$this->assertSame( 1, $filter_call_count, 'Filter should be evaluated exactly once per access check.' );
		$this->assertSame(
			array(
				'status'           => 'redirect',
				'url'              => $row,
				'location'         => 'https://example.com/dest/single-pass',
				'captchaProtected' => false,
			),
			$result
		);
	}
}
