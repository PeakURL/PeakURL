<?php
/**
 * Unit tests for link password and CAPTCHA verification hooks.
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Links\Access;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;
use RuntimeException;

class LinkVerificationHooksTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private LinksValidator $validator;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&Captcha $captcha;
	private array $config;
	private Access $access;
	private string $password_hash;
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
		$this->config            = array(
			'PEAKURL_AUTH_KEY'  => 'test_key_01234567890123456789012345678901',
			'PEAKURL_AUTH_SALT' => 'test_salt_01234567890123456789012345678901',
		);

		$this->password_hash       = password_hash( 'secret123', PASSWORD_DEFAULT );
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
	 * Configure repository mock with a password-protected link row.
	 *
	 * @param string      $code          Short code.
	 * @param string|null $password_hash Optional custom password hash.
	 * @return array<string, mixed> The mocked URL database row.
	 */
	private function mock_password_link( string $code = 'passok', ?string $password_hash = null ): array {
		$row = array(
			'id'              => 'link_' . $code,
			'short_code'      => $code,
			'alias'           => 'alias_' . $code,
			'title'           => 'Title ' . $code,
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
			'password_value'  => $password_hash ?? $this->password_hash,
		);
		$this->repository->method( 'find_link_access_row' )->with( $code )->willReturn( $row );
		return $row;
	}

	/**
	 * Configure repository mock with an open (or CAPTCHA-only) link row.
	 *
	 * @param string $code Short code.
	 * @return array<string, mixed> The mocked URL database row.
	 */
	private function mock_open_link( string $code = 'capok' ): array {
		$row = array(
			'id'              => 'link_' . $code,
			'short_code'      => $code,
			'alias'           => 'alias_' . $code,
			'title'           => 'Title ' . $code,
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )->with( $code )->willReturn( $row );
		return $row;
	}

	/**
	 * Helper to create a POST request with minimal environment.
	 *
	 * @param string               $path    Request path.
	 * @param array<string, mixed> $body    Body parameters.
	 * @param array<string, mixed> $cookies Cookie parameters.
	 * @param string               $ip      Client IP address.
	 * @return Request Populated Request instance.
	 */
	private function post_request( string $path, array $body = array(), array $cookies = array(), string $ip = '198.51.100.22' ): Request {
		return new Request(
			'POST',
			$path,
			array(),
			$body,
			$cookies,
			array(),
			array(
				'REMOTE_ADDR'     => $ip,
				'HTTP_USER_AGENT' => 'PeakTestBrowser/1.0',
			)
		);
	}

	public function test_link_password_verified_fires_on_successful_password_submission(): void {
		$this->mock_password_link( 'passok' );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$captured_link    = null;
		$captured_context = null;
		$verified_count   = 0;
		$failed_count     = 0;

		\add_action(
			'link_password_verified',
			function ( array $link, array $context ) use ( &$captured_link, &$captured_context, &$verified_count ): void {
				++$verified_count;
				$captured_link    = $link;
				$captured_context = $context;
			},
			10,
			2
		);

		\add_action(
			'link_password_failed',
			function () use ( &$failed_count ): void {
				++$failed_count;
			}
		);

		$request = $this->post_request( '/passok', array( 'link_password' => 'secret123' ) );
		$result  = $this->access->get_link_access( 'passok', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 1, $verified_count );
		$this->assertSame( 0, $failed_count );

		// Strict verification of allowed link callback fields
		$this->assertSame(
			array(
				'id'        => 'link_passok',
				'shortCode' => 'passok',
				'alias'     => 'alias_passok',
				'title'     => 'Title passok',
				'status'    => 'active',
			),
			$captured_link
		);
		$this->assertSame( array( 'id', 'shortCode', 'alias', 'title', 'status' ), array_keys( $captured_link ) );

		// Strict verification of allowed request context fields
		$this->assertSame(
			array(
				'ip'        => '198.51.100.22',
				'userAgent' => 'PeakTestBrowser/1.0',
				'method'    => 'POST',
			),
			$captured_context
		);

		$this->assertNotEmpty( $request->get_response_cookies() );
	}

	public function test_link_password_failed_fires_on_incorrect_nonempty_password(): void {
		$this->mock_password_link( 'passbad' );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$captured_link    = null;
		$captured_context = null;
		$verified_count   = 0;
		$failed_count     = 0;

		\add_action(
			'link_password_verified',
			function () use ( &$verified_count ): void {
				++$verified_count;
			}
		);

		\add_action(
			'link_password_failed',
			function ( array $link, array $context ) use ( &$captured_link, &$captured_context, &$failed_count ): void {
				++$failed_count;
				$captured_link    = $link;
				$captured_context = $context;
			},
			10,
			2
		);

		$request = $this->post_request( '/passbad', array( 'link_password' => 'wrong_guess' ), array(), '198.51.100.33' );
		$result  = $this->access->get_link_access( 'passbad', $request );

		$this->assertSame( 'password_invalid', $result['status'] );
		$this->assertSame( 0, $verified_count );
		$this->assertSame( 1, $failed_count );

		$this->assertSame( 'link_passbad', $captured_link['id'] );
		$this->assertSame( array( 'id', 'shortCode', 'alias', 'title', 'status' ), array_keys( $captured_link ) );
		$this->assertSame( '198.51.100.33', $captured_context['ip'] );
		$this->assertSame( array( 'ip', 'userAgent', 'method' ), array_keys( $captured_context ) );
	}

	public function test_password_hooks_do_not_fire_on_initial_get_or_empty_submission(): void {
		$this->mock_password_link( 'prompt1' );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$fired = false;
		$track = function () use ( &$fired ): void {
			$fired = true;
		};
		\add_action( 'link_password_verified', $track );
		\add_action( 'link_password_failed', $track );

		// Initial GET
		$get_req = new Request( 'GET', '/prompt1', array(), array() );
		$this->assertSame( 'password_required', $this->access->get_link_access( 'prompt1', $get_req )['status'] );
		$this->assertFalse( $fired );

		// POST with empty password
		$empty_req = $this->post_request( '/prompt1', array( 'link_password' => '' ) );
		$this->assertSame( 'password_required', $this->access->get_link_access( 'prompt1', $empty_req )['status'] );
		$this->assertFalse( $fired );
	}

	public function test_password_hooks_do_not_fire_when_valid_password_cookie_reused(): void {
		$row             = $this->mock_password_link( 'reuse1' );
		$cookie_name     = 'peakurl_link_access_link_reuse1';
		$expected_cookie = hash( 'sha256', 'link_reuse1|' . $row['password_value'] );

		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$fired = false;
		$track = function () use ( &$fired ): void {
			$fired = true;
		};
		\add_action( 'link_password_verified', $track );
		\add_action( 'link_password_failed', $track );

		$request = new Request( 'GET', '/reuse1', array(), array(), array( $cookie_name => $expected_cookie ) );
		$result  = $this->access->get_link_access( 'reuse1', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertFalse( $fired, 'Password verification hooks must not fire for cookie-authorized requests.' );
	}

	public function test_link_captcha_verified_fires_on_successful_token_verification(): void {
		$this->mock_open_link( 'capok' );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );
		$this->captcha->method( 'verify_token' )->with( 'valid-turnstile-token', '198.51.100.44' )->willReturn( true );

		$captured_link    = null;
		$captured_context = null;
		$verified_count   = 0;

		\add_action(
			'link_captcha_verified',
			function ( array $link, array $context ) use ( &$captured_link, &$captured_context, &$verified_count ): void {
				++$verified_count;
				$captured_link    = $link;
				$captured_context = $context;
			},
			10,
			2
		);

		$request = $this->post_request( '/capok', array( 'cf-turnstile-response' => 'valid-turnstile-token' ), array(), '198.51.100.44' );
		$result  = $this->access->get_link_access( 'capok', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 1, $verified_count );

		$this->assertSame( 'link_capok', $captured_link['id'] );
		$this->assertSame( array( 'id', 'shortCode', 'alias', 'title', 'status' ), array_keys( $captured_link ) );

		$this->assertSame(
			array(
				'ip'        => '198.51.100.44',
				'userAgent' => 'PeakTestBrowser/1.0',
				'method'    => 'POST',
				'provider'  => 'turnstile',
			),
			$captured_context
		);
		$this->assertSame( array( 'ip', 'userAgent', 'method', 'provider' ), array_keys( $captured_context ) );
	}

	public function test_link_captcha_failed_fires_on_failed_nonempty_token(): void {
		$this->mock_open_link( 'capbad' );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->recaptcha_challenge );
		$this->captcha->method( 'verify_token' )->with( 'bad-recaptcha-token', '198.51.100.55' )->willReturn( false );

		$captured_link    = null;
		$captured_context = null;
		$failed_count     = 0;

		\add_action(
			'link_captcha_failed',
			function ( array $link, array $context ) use ( &$captured_link, &$captured_context, &$failed_count ): void {
				++$failed_count;
				$captured_link    = $link;
				$captured_context = $context;
			},
			10,
			2
		);

		$request = $this->post_request( '/capbad', array( 'g-recaptcha-response' => 'bad-recaptcha-token' ), array(), '198.51.100.55' );
		$result  = $this->access->get_link_access( 'capbad', $request );

		$this->assertSame( 'captcha_invalid', $result['status'] );
		$this->assertSame( 1, $failed_count );

		$this->assertSame( 'link_capbad', $captured_link['id'] );
		$this->assertSame( array( 'id', 'shortCode', 'alias', 'title', 'status' ), array_keys( $captured_link ) );

		$this->assertSame(
			array(
				'ip'        => '198.51.100.55',
				'userAgent' => 'PeakTestBrowser/1.0',
				'method'    => 'POST',
				'provider'  => 'recaptcha',
			),
			$captured_context
		);
		$this->assertSame( array( 'ip', 'userAgent', 'method', 'provider' ), array_keys( $captured_context ) );
	}

	public function test_captcha_cookie_reuse_proceeds_without_reverification_or_hook_dispatch(): void {
		$this->mock_open_link( 'capflow' );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		// Step 1: verify_token is expected exactly once during initial token submission
		$this->captcha->expects( $this->once() )
			->method( 'verify_token' )
			->with( 'valid-token', '198.51.100.66' )
			->willReturn( true );

		$verified_fires = 0;
		$failed_fires   = 0;

		\add_action(
			'link_captcha_verified',
			function () use ( &$verified_fires ): void {
				++$verified_fires;
			}
		);
		\add_action(
			'link_captcha_failed',
			function () use ( &$failed_fires ): void {
				++$failed_fires;
			}
		);

		// Step 1: Submit valid CAPTCHA token
		$request1 = $this->post_request( '/capflow', array( 'cf-turnstile-response' => 'valid-token' ), array(), '198.51.100.66' );
		$result1  = $this->access->get_link_access( 'capflow', $request1 );

		// Step 2: Confirm link_captcha_verified fires once
		$this->assertSame( 'redirect', $result1['status'] );
		$this->assertSame( 1, $verified_fires );
		$this->assertSame( 0, $failed_fires );

		// Step 3: Capture the cookie queued by the successful request
		$raw_cookies = $request1->get_response_cookies();
		$this->assertNotEmpty( $raw_cookies );

		preg_match( '/^([^=]+)=([^;]+)/', $raw_cookies[0], $matches );
		$cookie_name  = urldecode( $matches[1] );
		$cookie_value = urldecode( $matches[2] );

		// Step 4: Make another request with that valid cookie
		$request2 = new Request(
			'GET',
			'/capflow',
			array(),
			array(),
			array( $cookie_name => $cookie_value ),
			array(),
			array( 'REMOTE_ADDR' => '198.51.100.66' )
		);

		// Step 5 & 6: Access proceeds without calling verify_token again, and neither action fires
		$result2 = $this->access->get_link_access( 'capflow', $request2 );

		$this->assertSame( 'redirect', $result2['status'] );
		$this->assertSame( 1, $verified_fires, 'link_captcha_verified must not fire again on cookie reuse.' );
		$this->assertSame( 0, $failed_fires, 'link_captcha_failed must not fire on cookie reuse.' );
	}

	public function test_captcha_hooks_do_not_fire_on_initial_prompt_or_empty_submission(): void {
		$this->mock_open_link( 'capprompt' );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );

		$fired = false;
		$track = function () use ( &$fired ): void {
			$fired = true;
		};
		\add_action( 'link_captcha_verified', $track );
		\add_action( 'link_captcha_failed', $track );

		// Initial GET
		$get_req = new Request( 'GET', '/capprompt', array(), array() );
		$this->assertSame( 'captcha_required', $this->access->get_link_access( 'capprompt', $get_req )['status'] );
		$this->assertFalse( $fired );

		// POST with empty token
		$empty_req = $this->post_request( '/capprompt', array( 'cf-turnstile-response' => '' ) );
		$this->assertSame( 'captcha_required', $this->access->get_link_access( 'capprompt', $empty_req )['status'] );
		$this->assertFalse( $fired );
	}

	public function test_captcha_hooks_do_not_fire_when_captcha_is_disabled(): void {
		$this->mock_open_link( 'nocap' );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$fired = false;
		$track = function () use ( &$fired ): void {
			$fired = true;
		};
		\add_action( 'link_captcha_verified', $track );
		\add_action( 'link_captcha_failed', $track );

		$request = new Request( 'GET', '/nocap', array(), array() );
		$result  = $this->access->get_link_access( 'nocap', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertFalse( $fired );
	}

	public function test_combined_challenge_flow_captcha_success_and_password_failure(): void {
		$this->mock_password_link( 'combo1' );
		$this->captcha->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );
		$this->captcha->method( 'verify_token' )->willReturn( true );

		$fired_events = array();

		\add_action(
			'link_captcha_verified',
			function () use ( &$fired_events ): void {
				$fired_events[] = 'captcha_verified';
			}
		);
		\add_action(
			'link_captcha_failed',
			function () use ( &$fired_events ): void {
				$fired_events[] = 'captcha_failed';
			}
		);
		\add_action(
			'link_password_verified',
			function () use ( &$fired_events ): void {
				$fired_events[] = 'password_verified';
			}
		);
		\add_action(
			'link_password_failed',
			function () use ( &$fired_events ): void {
				$fired_events[] = 'password_failed';
			}
		);

		// Post with valid captcha token but wrong password
		$request = $this->post_request(
			'/combo1',
			array(
				'cf-turnstile-response' => 'valid-captcha-token',
				'link_password'         => 'incorrect-password',
			)
		);

		$result = $this->access->get_link_access( 'combo1', $request );

		// Captcha verified, but password failed!
		$this->assertSame( 'password_invalid', $result['status'] );
		$this->assertSame( array( 'captcha_verified', 'password_failed' ), $fired_events );
	}

	public function test_verification_hooks_do_not_fire_for_missing_inactive_or_expired_links(): void {
		$fired    = false;
		$listener = function () use ( &$fired ): void {
			$fired = true;
		};

		\add_action( 'link_password_verified', $listener );
		\add_action( 'link_password_failed', $listener );
		\add_action( 'link_captcha_verified', $listener );
		\add_action( 'link_captcha_failed', $listener );

		$this->repository->method( 'find_link_access_row' )->willReturnCallback(
			function ( string $code ): ?array {
				if ( 'inact' === $code ) {
					return array(
						'id'              => 'l_inact',
						'status'          => 'inactive',
						'password_value'  => 'hash',
						'destination_url' => 'https://example.com',
					);
				}
				if ( 'exp' === $code ) {
					return array(
						'id'              => 'l_exp',
						'status'          => 'expired',
						'password_value'  => 'hash',
						'destination_url' => 'https://example.com',
					);
				}
				return null;
			}
		);

		// Missing link
		$res1 = $this->access->get_link_access( 'nonexistent', $this->post_request( '/nonexistent', array( 'link_password' => 'secret' ) ) );
		$this->assertSame( 'not_found', $res1['status'] );
		$this->assertFalse( $fired );

		// Inactive link
		$res2 = $this->access->get_link_access( 'inact', $this->post_request( '/inact', array( 'link_password' => 'secret' ) ) );
		$this->assertSame( 'unavailable', $res2['status'] );
		$this->assertFalse( $fired );

		// Expired link
		$res3 = $this->access->get_link_access( 'exp', $this->post_request( '/exp', array( 'link_password' => 'secret' ) ) );
		$this->assertSame( 'expired', $res3['status'] );
		$this->assertFalse( $fired );
	}

	public function test_observer_exceptions_are_isolated_for_all_four_hooks(): void {
		$this->mock_password_link( 'iso' );

		$executed_hooks = array();

		\add_action(
			'link_password_verified',
			function () use ( &$executed_hooks ): void {
				$executed_hooks[] = 'link_password_verified';
				throw new RuntimeException( 'Observer crash on password_verified' );
			}
		);
		\add_action(
			'link_password_failed',
			function () use ( &$executed_hooks ): void {
				$executed_hooks[] = 'link_password_failed';
				throw new RuntimeException( 'Observer crash on password_failed' );
			}
		);
		\add_action(
			'link_captcha_verified',
			function () use ( &$executed_hooks ): void {
				$executed_hooks[] = 'link_captcha_verified';
				throw new RuntimeException( 'Observer crash on captcha_verified' );
			}
		);
		\add_action(
			'link_captcha_failed',
			function () use ( &$executed_hooks ): void {
				$executed_hooks[] = 'link_captcha_failed';
				throw new RuntimeException( 'Observer crash on captcha_failed' );
			}
		);

		// 1. Password verified with crashing observer
		$this->captcha->method( 'get_challenge' )->willReturn( null );
		$req1 = $this->post_request( '/iso', array( 'link_password' => 'secret123' ) );
		$res1 = $this->access->get_link_access( 'iso', $req1 );
		$this->assertSame( 'redirect', $res1['status'] );
		$this->assertContains( 'link_password_verified', $executed_hooks );

		// 2. Password failed with crashing observer
		$req2 = $this->post_request( '/iso', array( 'link_password' => 'wrong' ) );
		$res2 = $this->access->get_link_access( 'iso', $req2 );
		$this->assertSame( 'password_invalid', $res2['status'] );
		$this->assertContains( 'link_password_failed', $executed_hooks );

		// 3. Captcha verified with crashing observer
		$captcha_mock = $this->createMock( Captcha::class );
		$captcha_mock->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );
		$captcha_mock->method( 'verify_token' )->willReturn( true );
		$access_with_cap = new Access(
			$this->repository,
			$this->validator,
			$this->analytics_service,
			$this->webhooks_service,
			$captcha_mock,
			$this->config
		);
		$req3            = $this->post_request( '/iso', array( 'cf-turnstile-response' => 'valid-tok' ) );
		$res3            = $access_with_cap->get_link_access( 'iso', $req3 );
		$this->assertSame( 'password_required', $res3['status'] );
		$this->assertContains( 'link_captcha_verified', $executed_hooks );

		// 4. Captcha failed with crashing observer
		$captcha_fail_mock = $this->createMock( Captcha::class );
		$captcha_fail_mock->method( 'get_challenge' )->willReturn( $this->turnstile_challenge );
		$captcha_fail_mock->method( 'verify_token' )->willReturn( false );
		$access_with_cap_fail = new Access(
			$this->repository,
			$this->validator,
			$this->analytics_service,
			$this->webhooks_service,
			$captcha_fail_mock,
			$this->config
		);
		$req4                 = $this->post_request( '/iso', array( 'cf-turnstile-response' => 'invalid-tok' ) );
		$res4                 = $access_with_cap_fail->get_link_access( 'iso', $req4 );
		$this->assertSame( 'captcha_invalid', $res4['status'] );
		$this->assertContains( 'link_captcha_failed', $executed_hooks );

		// Confirm that each of the four callbacks was genuinely executed and threw
		$this->assertSame(
			array( 'link_password_verified', 'link_password_failed', 'link_captcha_verified', 'link_captcha_failed' ),
			$executed_hooks
		);
	}

	public function test_interaction_with_controller_link_access_denied(): void {
		$links_service = $this->createMock( LinksService::class );
		$controller    = new LinksController( $links_service );

		$this->mock_password_link( 'denied1' );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$request = $this->post_request( '/denied1', array( 'link_password' => 'wrong_pw' ), array(), '198.51.100.99' );
		$request->set_route_params( array( 'id' => 'denied1' ) );

		$links_service->method( 'get_link_access' )->willReturnCallback(
			fn( string $code, Request $req ) => $this->access->get_link_access( $code, $req )
		);
		$links_service->method( 'format_url' )->willReturn(
			array(
				'id'        => 'link_denied1',
				'shortCode' => 'denied1',
				'title'     => 'Title denied1',
			)
		);

		$events_fired = array();

		\add_action(
			'link_password_failed',
			function ( array $link, array $ctx ) use ( &$events_fired ): void {
				$events_fired[] = array(
					'event'  => 'link_password_failed',
					'linkId' => $link['id'],
					'ip'     => $ctx['ip'],
				);
			},
			10,
			2
		);

		\add_action(
			'link_access_denied',
			function ( string $status, array $link, array $ctx ) use ( &$events_fired ): void {
				$events_fired[] = array(
					'event'  => 'link_access_denied',
					'status' => $status,
					'linkId' => $link['id'],
					'ip'     => $ctx['ip'],
				);
			},
			10,
			3
		);

		$response = $controller->redirect( $request );

		$this->assertSame( 401, $response['status'] );
		$this->assertCount( 2, $events_fired );

		// 1st: domain verification hook in Access
		$this->assertSame( 'link_password_failed', $events_fired[0]['event'] );
		$this->assertSame( 'link_denied1', $events_fired[0]['linkId'] );
		$this->assertSame( '198.51.100.99', $events_fired[0]['ip'] );

		// 2nd: terminal HTTP access denial hook in Controller
		$this->assertSame( 'link_access_denied', $events_fired[1]['event'] );
		$this->assertSame( 'password_invalid', $events_fired[1]['status'] );
		$this->assertSame( 'link_denied1', $events_fired[1]['linkId'] );
		$this->assertSame( '198.51.100.99', $events_fired[1]['ip'] );
	}
}
