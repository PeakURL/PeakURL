<?php
/**
 * Unit tests for public link access domain service.
 *
 * @package PeakURL\Tests\Unit\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Links\Access;
use PeakURL\Features\Links\Repository as LinksRepository;
use PeakURL\Features\Links\Validator as LinksValidator;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Captcha;

class AccessTest extends TestCase {

	private MockObject&LinksRepository $repository;
	private LinksValidator $validator;
	private MockObject&AnalyticsService $analytics_service;
	private MockObject&WebhooksService $webhooks_service;
	private MockObject&Captcha $captcha;
	private array $config;
	private Access $access;

	protected function setUp(): void {
		parent::setUp();

		$this->repository        = $this->createMock( LinksRepository::class );
		$this->validator         = new LinksValidator();
		$this->analytics_service = $this->createMock( AnalyticsService::class );
		$this->webhooks_service  = $this->createMock( WebhooksService::class );
		$this->captcha           = $this->createMock( Captcha::class );
		$this->config            = array(
			'PEAKURL_AUTH_KEY'  => 'test_key_01234567890123456789012345678901',
			'PEAKURL_AUTH_SALT' => 'test_salt_01234567890123456789012345678901',
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

	public function test_returns_not_found_when_short_code_is_empty(): void {
		$request = new Request( 'GET', '/', array(), array() );
		$result  = $this->access->get_link_access( '', $request );

		$this->assertSame( 'not_found', $result['status'] );
		$this->assertNull( $result['url'] );
	}

	public function test_returns_not_found_when_link_not_found_in_repository(): void {
		$this->repository->method( 'find_link_access_row' )->with( 'missing' )->willReturn( null );

		$request = new Request( 'GET', '/missing', array(), array() );
		$result  = $this->access->get_link_access( 'missing', $request );

		$this->assertSame( 'not_found', $result['status'] );
		$this->assertNull( $result['url'] );
	}

	public function test_returns_expired_when_link_status_is_already_expired(): void {
		$row = array(
			'id'              => 'link_1',
			'short_code'      => 'exp',
			'destination_url' => 'https://example.com',
			'status'          => 'expired',
		);
		$this->repository->method( 'find_link_access_row' )->with( 'exp' )->willReturn( $row );

		$request = new Request( 'GET', '/exp', array(), array() );
		$result  = $this->access->get_link_access( 'exp', $request );

		$this->assertSame( 'expired', $result['status'] );
		$this->assertSame( $row, $result['url'] );
	}

	public function test_marks_link_expired_and_dispatches_events_when_due_active_link_accessed(): void {
		$row = array(
			'id'              => 'link_due_1',
			'short_code'      => 'due',
			'destination_url' => 'https://example.com',
			'status'          => 'active',
			'expires_at'      => '2020-01-01 00:00:00',
		);
		$this->repository->method( 'find_link_access_row' )->with( 'due' )->willReturn( $row );

		$links_api = $this->createMock( \PeakURL\Api\LinksApi::class );
		$links_api->expects( $this->once() )->method( 'invalidate_link_cache' );
		$this->repository->method( 'get_links_api' )->willReturn( $links_api );

		$this->repository->expects( $this->once() )
			->method( 'expire_link' )
			->with( 'link_due_1' );

		$this->webhooks_service->expects( $this->once() )
			->method( 'dispatch_link_event' )
			->with( 'link.expired', $this->isType( 'array' ) );

		$request = new Request( 'GET', '/due', array(), array() );
		$result  = $this->access->get_link_access( 'due', $request );

		$this->assertSame( 'expired', $result['status'] );
	}

	public function test_returns_unavailable_when_status_is_not_active(): void {
		$row = array(
			'id'              => 'link_inactive_1',
			'short_code'      => 'inact',
			'destination_url' => 'https://example.com',
			'status'          => 'inactive',
		);
		$this->repository->method( 'find_link_access_row' )->with( 'inact' )->willReturn( $row );

		$request = new Request( 'GET', '/inact', array(), array() );
		$result  = $this->access->get_link_access( 'inact', $request );

		$this->assertSame( 'unavailable', $result['status'] );
	}

	public function test_redirects_active_public_link_and_records_click(): void {
		$row = array(
			'id'              => 'link_act_1',
			'short_code'      => 'active1',
			'destination_url' => 'https://example.com/dest',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'active1' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_click' )
			->with( $row, $this->isInstanceOf( Request::class ), false );

		$request = new Request( 'GET', '/active1', array(), array() );
		$result  = $this->access->get_link_access( 'active1', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 'https://example.com/dest', $result['location'] );
		$this->assertFalse( $result['captchaProtected'] );
	}

	public function test_get_redirect_url_returns_destination_or_null(): void {
		$row = array(
			'id'              => 'link_redir_1',
			'short_code'      => 'redir1',
			'destination_url' => 'https://example.com/redir',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )
			->willReturnCallback(
				fn( string $code ) => 'redir1' === $code ? $row : null
			);
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$request = new Request( 'GET', '/redir1', array(), array() );
		$this->assertSame( 'https://example.com/redir', $this->access->get_redirect_url( 'redir1', $request ) );

		$this->assertNull( $this->access->get_redirect_url( 'missing', $request ) );
	}

	public function test_requires_password_for_protected_link_without_cookie(): void {
		$hashed_pass = password_hash( 'secret123', PASSWORD_DEFAULT );
		$row         = array(
			'id'              => 'link_pass_1',
			'short_code'      => 'pass1',
			'destination_url' => 'https://example.com/secret',
			'status'          => 'active',
			'password_value'  => $hashed_pass,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'pass1' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$request = new Request( 'GET', '/pass1', array(), array() );
		$result  = $this->access->get_link_access( 'pass1', $request );

		$this->assertSame( 'password_required', $result['status'] );
	}

	public function test_rejects_empty_password_submission(): void {
		$hashed_pass = password_hash( 'secret123', PASSWORD_DEFAULT );
		$row         = array(
			'id'              => 'link_pass_2',
			'short_code'      => 'pass2',
			'destination_url' => 'https://example.com/secret',
			'status'          => 'active',
			'password_value'  => $hashed_pass,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'pass2' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$request = new Request( 'POST', '/pass2', array(), array( 'link_password' => '' ) );
		$result  = $this->access->get_link_access( 'pass2', $request );

		$this->assertSame( 'password_required', $result['status'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_rejects_incorrect_password_submission(): void {
		$hashed_pass = password_hash( 'secret123', PASSWORD_DEFAULT );
		$row         = array(
			'id'              => 'link_pass_3',
			'short_code'      => 'pass3',
			'destination_url' => 'https://example.com/secret',
			'status'          => 'active',
			'password_value'  => $hashed_pass,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'pass3' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$request = new Request( 'POST', '/pass3', array(), array( 'link_password' => 'wrong' ) );
		$result  = $this->access->get_link_access( 'pass3', $request );

		$this->assertSame( 'password_invalid', $result['status'] );
	}

	public function test_accepts_valid_password_queues_cookie_and_redirects(): void {
		$hashed_pass = password_hash( 'secret123', PASSWORD_DEFAULT );
		$row         = array(
			'id'              => 'link_pass_4',
			'short_code'      => 'pass4',
			'destination_url' => 'https://example.com/secret',
			'status'          => 'active',
			'password_value'  => $hashed_pass,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'pass4' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_click' )
			->with( $row, $this->isInstanceOf( Request::class ), true );

		$request = new Request( 'POST', '/pass4', array(), array( 'link_password' => 'secret123' ) );
		$result  = $this->access->get_link_access( 'pass4', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 'https://example.com/secret', $result['location'] );
		$this->assertNotEmpty( $request->get_response_cookies() );
	}

	public function test_redirects_password_protected_link_with_valid_access_cookie(): void {
		$hashed_pass     = password_hash( 'secret123', PASSWORD_DEFAULT );
		$row             = array(
			'id'              => 'link_pass_5',
			'short_code'      => 'pass5',
			'destination_url' => 'https://example.com/secret',
			'status'          => 'active',
			'password_value'  => $hashed_pass,
		);
		$cookie_name     = 'peakurl_link_access_link_pass_5';
		$expected_cookie = hash( 'sha256', 'link_pass_5|' . $hashed_pass );

		$this->repository->method( 'find_link_access_row' )->with( 'pass5' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( null );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_click' )
			->with( $row, $this->isInstanceOf( Request::class ), false );

		$request = new Request( 'GET', '/pass5', array(), array(), array( $cookie_name => $expected_cookie ) );
		$result  = $this->access->get_link_access( 'pass5', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertSame( 'https://example.com/secret', $result['location'] );
	}

	public function test_enforces_captcha_challenge_when_captcha_enabled(): void {
		$challenge = array(
			'provider'      => 'turnstile',
			'siteKey'       => 'test-site-key',
			'responseField' => 'cf-turnstile-response',
		);
		$row       = array(
			'id'              => 'link_cap_1',
			'short_code'      => 'cap1',
			'destination_url' => 'https://example.com/captcha',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'cap1' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $challenge );

		$request = new Request( 'GET', '/cap1', array(), array() );
		$result  = $this->access->get_link_access( 'cap1', $request );

		$this->assertSame( 'captcha_required', $result['status'] );
		$this->assertTrue( $result['protected'] );
		$this->assertSame( $challenge, $result['challenge'] );
	}

	public function test_rejects_invalid_captcha_token(): void {
		$challenge = array(
			'provider'      => 'turnstile',
			'siteKey'       => 'test-site-key',
			'responseField' => 'cf-turnstile-response',
		);
		$row       = array(
			'id'              => 'link_cap_2',
			'short_code'      => 'cap2',
			'destination_url' => 'https://example.com/captcha',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'cap2' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $challenge );
		$this->captcha->method( 'verify_token' )->willReturn( false );

		$request = new Request( 'POST', '/cap2', array(), array( 'cf-turnstile-response' => 'bad-token' ) );
		$result  = $this->access->get_link_access( 'cap2', $request );

		$this->assertSame( 'captcha_invalid', $result['status'] );
	}

	public function test_accepts_valid_captcha_queues_cookie_and_proceeds(): void {
		$challenge = array(
			'provider'      => 'turnstile',
			'siteKey'       => 'test-site-key',
			'responseField' => 'cf-turnstile-response',
		);
		$row       = array(
			'id'              => 'link_cap_3',
			'short_code'      => 'cap3',
			'destination_url' => 'https://example.com/captcha',
			'status'          => 'active',
			'password_value'  => null,
		);
		$this->repository->method( 'find_link_access_row' )->with( 'cap3' )->willReturn( $row );
		$this->captcha->method( 'get_challenge' )->willReturn( $challenge );
		$this->captcha->method( 'verify_token' )->willReturn( true );

		$this->analytics_service->expects( $this->once() )
			->method( 'record_click' )
			->with( $row, $this->isInstanceOf( Request::class ), true );

		$request = new Request( 'POST', '/cap3', array(), array( 'cf-turnstile-response' => 'valid-token' ) );
		$result  = $this->access->get_link_access( 'cap3', $request );

		$this->assertSame( 'redirect', $result['status'] );
		$this->assertNotEmpty( $request->get_response_cookies() );
	}
}
