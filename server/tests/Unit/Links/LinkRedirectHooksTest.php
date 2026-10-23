<?php
/**
 * Unit tests for link redirect and access denial hooks (Phase 3).
 *
 * Verifies that:
 * - link_redirect_prepared fires when a valid redirect response is prepared
 * - link_access_denied fires on terminal verification failures (invalid password or CAPTCHA)
 * - Safe payloads are emitted with raw passwords, tokens, and secrets excluded
 * - Minimal safe context (ip, userAgent, method) is passed without sensitive referrers, bodies, or cookies
 * - Routine challenge prompts (password_required, captcha_required) do not emit denial actions
 * - No-listener execution does not invoke payload formatting (performance guard)
 * - Observer exceptions do not break redirects or access denial responses
 *
 * @package PeakURL\Tests\Unit\Links
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Core\Hooks\Hooks;
use PeakURL\Http\Request;
use RuntimeException;

class LinkRedirectHooksTest extends TestCase {

	private MockObject&LinksService $links_service;
	private LinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		Hooks::remove_all();
		Hooks::reset_actions();

		$this->links_service = $this->createMock( LinksService::class );
		$this->controller    = new LinksController( $this->links_service );
	}

	protected function tearDown(): void {
		Hooks::remove_all();
		Hooks::reset_actions();
		parent::tearDown();
	}

	public function test_link_redirect_prepared_fires_on_successful_redirect(): void {
		$link_row = array(
			'id'              => 'redir_link_1',
			'short_code'      => 'go',
			'destination_url' => 'https://example.com/target',
			'password_value'  => 'secret_hash_not_to_leak',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'   => 'redirect',
				'location' => 'https://example.com/target',
				'url'      => $link_row,
			)
		);

		$this->links_service->method( 'format_url' )->willReturn(
			array(
				'id'             => 'redir_link_1',
				'shortCode'      => 'go',
				'destinationUrl' => 'https://example.com/target',
				'hasPassword'    => true,
			)
		);

		$captured = null;
		add_action(
			'link_redirect_prepared',
			function ( string $location, array $link, array $context ) use ( &$captured ): void {
				$captured = array(
					'location' => $location,
					'link'     => $link,
					'context'  => $context,
				);
			},
			10,
			3
		);

		$request = new Request(
			'GET',
			'/go',
			array(),
			array(),
			array(),
			array(),
			array(
				'REMOTE_ADDR'     => '198.51.100.42',
				'HTTP_USER_AGENT' => 'PeakTestBrowser/1.0',
				'HTTP_REFERER'    => 'https://referrer.example.org',
			)
		);
		$request->set_route_params( array( 'id' => 'go' ) );

		$response = $this->controller->redirect( $request );

		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://example.com/target', $response['headers']['Location'] );

		$this->assertNotNull( $captured );
		$this->assertSame( 'https://example.com/target', $captured['location'] );
		$this->assertSame( 'redir_link_1', $captured['link']['id'] );
		$this->assertTrue( $captured['link']['hasPassword'] );
		$this->assertArrayNotHasKey( 'password_value', $captured['link'] );

		// Context verification: safe headers and IP present, sensitive referrers, body, and cookies omitted.
		$this->assertSame( '198.51.100.42', $captured['context']['ip'] );
		$this->assertSame( 'PeakTestBrowser/1.0', $captured['context']['userAgent'] );
		$this->assertSame( 'GET', $captured['context']['method'] );
		$this->assertArrayNotHasKey( 'referer', $captured['context'], 'Raw Referer header must be excluded for privacy' );
	}

	public function test_no_listener_execution_does_not_format_payload(): void {
		$link_row = array(
			'id'              => 'redir_perf_1',
			'short_code'      => 'fast',
			'destination_url' => 'https://example.com/fast',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'   => 'redirect',
				'location' => 'https://example.com/fast',
				'url'      => $link_row,
			)
		);

		// With no listener registered, format_url must NOT be called (zero formatting overhead).
		$this->links_service->expects( $this->never() )->method( 'format_url' );

		$request = new Request( 'GET', '/fast', array(), array() );
		$request->set_route_params( array( 'id' => 'fast' ) );

		$response = $this->controller->redirect( $request );

		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://example.com/fast', $response['headers']['Location'] );
	}

	public function test_observer_exception_does_not_break_successful_redirect(): void {
		$link_row = array(
			'id'              => 'redir_fault_1',
			'short_code'      => 'fault',
			'destination_url' => 'https://example.com/resilient',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'   => 'redirect',
				'location' => 'https://example.com/resilient',
				'url'      => $link_row,
			)
		);

		$this->links_service->method( 'format_url' )->willReturn(
			array(
				'id'             => 'redir_fault_1',
				'destinationUrl' => 'https://example.com/resilient',
			)
		);

		add_action(
			'link_redirect_prepared',
			function (): void {
				throw new RuntimeException( 'Simulated buggy plugin listener exception' );
			}
		);

		$request = new Request( 'GET', '/fault', array(), array() );
		$request->set_route_params( array( 'id' => 'fault' ) );

		$response = $this->controller->redirect( $request );

		// The visitor still receives the 302 redirect despite observer failure.
		$this->assertSame( 302, $response['status'] );
		$this->assertSame( 'https://example.com/resilient', $response['headers']['Location'] );
	}

	public function test_link_access_denied_fires_on_invalid_password(): void {
		$link_row = array(
			'id'             => 'pass_link_1',
			'short_code'     => 'protected',
			'password_value' => 'stored_hash',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'password_invalid',
				'message' => 'The password for this link is incorrect.',
				'url'     => $link_row,
			)
		);

		$this->links_service->method( 'format_url' )->willReturn(
			array(
				'id'          => 'pass_link_1',
				'shortCode'   => 'protected',
				'hasPassword' => true,
			)
		);

		$captured = null;
		add_action(
			'link_access_denied',
			function ( string $reason, array $link, array $context ) use ( &$captured ): void {
				$captured = array(
					'reason'  => $reason,
					'link'    => $link,
					'context' => $context,
				);
			},
			10,
			3
		);

		$headers = array(
			'HTTP_AUTHORIZATION' => 'Bearer secret_admin_token',
			'HTTP_REFERER'       => 'https://victim.example.org/checkout?token=sensitive123',
		);
		$request = new Request(
			'POST',
			'/protected',
			array(),
			array( 'link_password' => 'attempted_secret_pw' ),
			array( 'session_cookie' => 'secret_val' ),
			$headers
		);
		$request->set_route_params( array( 'id' => 'protected' ) );

		$response = $this->controller->redirect( $request );

		$this->assertSame( 401, $response['status'] );
		$this->assertNotNull( $captured );
		$this->assertSame( 'password_invalid', $captured['reason'] );
		$this->assertSame( 'pass_link_1', $captured['link']['id'] );
		$this->assertArrayNotHasKey( 'password_value', $captured['link'] );

		// Verify sensitive POST body password, session cookies, auth headers, and raw referrers are NOT exposed in context.
		$this->assertArrayNotHasKey( 'link_password', $captured['context'] );
		$this->assertArrayNotHasKey( 'session_cookie', $captured['context'] );
		$this->assertArrayNotHasKey( 'authorization', $captured['context'] );
		$this->assertArrayNotHasKey( 'referer', $captured['context'] );
	}

	public function test_observer_exception_does_not_break_access_denial_response(): void {
		$link_row = array(
			'id'         => 'pass_link_err',
			'short_code' => 'protected-err',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'password_invalid',
				'message' => 'The password is incorrect.',
				'url'     => $link_row,
			)
		);

		$this->links_service->method( 'format_url' )->willReturn(
			array(
				'id' => 'pass_link_err',
			)
		);

		add_action(
			'link_access_denied',
			function (): void {
				throw new RuntimeException( 'Plugin listener crashed on denial hook' );
			}
		);

		$request = new Request( 'POST', '/protected-err', array(), array() );
		$request->set_route_params( array( 'id' => 'protected-err' ) );

		$response = $this->controller->redirect( $request );

		// Still renders the password page with 401 status.
		$this->assertSame( 401, $response['status'] );
	}

	public function test_link_access_denied_fires_on_invalid_captcha(): void {
		$link_row = array(
			'id'         => 'cap_link_1',
			'short_code' => 'captcha-link',
		);

		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'captcha_invalid',
				'message' => 'CAPTCHA verification failed.',
				'url'     => $link_row,
			)
		);

		$this->links_service->method( 'format_url' )->willReturn(
			array(
				'id'        => 'cap_link_1',
				'shortCode' => 'captcha-link',
			)
		);

		$captured = null;
		add_action(
			'link_access_denied',
			function ( string $reason, array $link, array $context ) use ( &$captured ): void {
				$captured = array(
					'reason'  => $reason,
					'link'    => $link,
					'context' => $context,
				);
			},
			10,
			3
		);

		$request = new Request( 'POST', '/captcha-link', array(), array( 'cf-turnstile-response' => 'invalid_tok' ) );
		$request->set_route_params( array( 'id' => 'captcha-link' ) );

		$response = $this->controller->redirect( $request );

		$this->assertSame( 403, $response['status'] );
		$this->assertNotNull( $captured );
		$this->assertSame( 'captcha_invalid', $captured['reason'] );
		$this->assertSame( 'cap_link_1', $captured['link']['id'] );
		$this->assertArrayNotHasKey( 'cf-turnstile-response', $captured['context'] );
		$this->assertArrayNotHasKey( 'referer', $captured['context'] );
	}

	public function test_routine_challenge_prompts_do_not_fire_link_access_denied(): void {
		$this->links_service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'password_required',
				'message' => 'Enter password.',
				'url'     => array( 'id' => 'p_link' ),
			)
		);

		$fired = false;
		add_action(
			'link_access_denied',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$request = new Request( 'GET', '/p_link', array(), array() );
		$request->set_route_params( array( 'id' => 'p_link' ) );

		$response = $this->controller->redirect( $request );

		$this->assertSame( 200, $response['status'] );
		$this->assertFalse( $fired, 'link_access_denied must not fire on routine password prompt' );
	}
}
