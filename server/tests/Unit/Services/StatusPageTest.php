<?php
/**
 * Public recovery documents preserve safe content and standalone branding.
 *
 * @package PeakURL\Tests\Unit\Services
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Links\Controller;
use PeakURL\Features\Links\Service;
use PeakURL\Http\Request;
use PeakURL\Services\Brand;
use PeakURL\Services\StatusPage;

class StatusPageTest extends TestCase {

	public function test_public_access_states_keep_status_codes_and_post_forms(): void {
		$states = array(
			'not_found'         => array( 404, 'missing-link' ),
			'unavailable'       => array( 404, 'missing-link' ),
			'expired'           => array( 410, 'expired' ),
			'password_required' => array( 200, 'password' ),
			'password_invalid'  => array( 401, 'password' ),
			'captcha_invalid'   => array( 403, 'verification' ),
		);

		foreach ( $states as $state => list( $status, $illustration ) ) {
			$service = $this->createMock( Service::class );
			$service->method( 'get_link_access' )->willReturn(
				array(
					'status'    => $state,
					'message'   => '<img src=x onerror=alert(1)>',
					'challenge' => array(
						'provider' => 'turnstile',
						'siteKey'  => 'test-key',
					),
				)
			);
			$request  = new Request( 'GET', '/folder/protected', array(), array(), array(), array( 'id' => 'protected' ) );
			$response = ( new Controller( $service ) )->redirect( $request );
			$html     = (string) $response['body'];

			$this->assertSame( $status, $response['status'], $state );
			$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
			$this->assertStringContainsString( 'data:image/svg+xml;base64,', $html );
			$this->assertStringContainsString( 'class="powered-by"', $html );
			$this->assertStringContainsString( Brand::get_tagline(), $html );
			$this->assertStringNotContainsString( '<img src=x', $html );
			$this->assertStringContainsString( 'data-status-illustration="' . $illustration . '"', $html );

			if ( str_starts_with( $state, 'password_' ) || 'captcha_invalid' === $state ) {
				$this->assertStringContainsString( 'method="post" action="/folder/protected"', $html );
			}
			if ( str_starts_with( $state, 'password_' ) ) {
				$this->assertStringContainsString( 'name="link_password" type="password"', $html );
			}
		}
	}

	public function test_http_error_pages_choose_their_own_illustrations(): void {
		$states = array(
			404 => 'missing-page',
			403 => 'forbidden',
			429 => 'rate-limit',
			500 => 'server-error',
			400 => 'request-error',
		);
		foreach ( $states as $status => $illustration ) {
			$this->assertStringContainsString( 'data-status-illustration="' . $illustration . '"', StatusPage::render_http_error( $status ) );
		}
	}

	public function test_illustration_names_reject_unregistered_paths(): void {
		$this->expectException( \InvalidArgumentException::class );
		StatusPage::render( 'Error', 'Check the address.', illustration: '../../config' );
	}

	public function test_status_text_and_development_details_are_escaped(): void {
		$html = StatusPage::render( '<script>alert(1)</script>', '<b>unsafe</b>', '404' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;b&gt;unsafe&lt;/b&gt;', $html );

		$this->assertStringNotContainsString( 'Error details', StatusPage::render_http_error( 500 ) );
		$html = StatusPage::render_http_error( 500, '/installation', '<script>debug</script>' );
		$this->assertStringContainsString( 'href="/installation/"', $html );
		$this->assertStringContainsString( '&lt;script&gt;debug&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	public function test_status_actions_reject_external_or_injected_urls(): void {
		foreach ( array( 'javascript:alert(1)', '//evil.example', '/\\evil.example', "/\r\nLocation: evil" ) as $url ) {
			$html = StatusPage::render( 'Not found', 'Check the address.', '404', '', $url, 'Continue' );
			$this->assertStringNotContainsString( 'class="status-button"', $html );
		}
	}

	public function test_document_preserves_rtl_metadata_and_generator_version(): void {
		$html = StatusPage::render_document(
			'Missing page',
			'<h1>Missing page</h1>',
			array(
				'htmlLang'      => 'ar',
				'textDirection' => 'rtl',
				'version'       => '9.9.9',
			)
		);
		$this->assertStringContainsString( '<html lang="ar" dir="rtl"', $html );
		$this->assertSame( 1, substr_count( $html, '<meta name="generator"' ) );
		$this->assertStringContainsString( 'content="PeakURL 9.9.9"', $html );
	}
}
