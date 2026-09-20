<?php
/**
 * Unit tests for generator meta tag presence in public link HTML pages.
 *
 * @package PeakURL\Tests\Unit\Links
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Links;

use PHPUnit\Framework\TestCase;
use PeakURL\Features\Links\Controller as LinksController;
use PeakURL\Features\Links\Service as LinksService;
use PeakURL\Http\Request;

class LinksPublicGeneratorTagTest extends TestCase {

	public function test_unavailable_link_404_html_contains_generator_tag(): void {
		$service = $this->createMock( LinksService::class );
		$service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'not_found',
				'message' => 'The short link you requested is not available right now.',
			)
		);

		$controller = new LinksController( $service );
		$request    = new Request( 'GET', '/nonexistent', array(), array(), array(), array( 'id' => 'nonexistent' ) );

		$response = $controller->redirect( $request );
		$this->assertSame( 404, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertSame( 1, substr_count( (string) $response['body'], '<meta name="generator"' ) );
		$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', (string) $response['body'] );
	}

	public function test_expired_link_410_html_contains_generator_tag(): void {
		$service = $this->createMock( LinksService::class );
		$service->method( 'get_link_access' )->willReturn(
			array(
				'status' => 'expired',
			)
		);

		$controller = new LinksController( $service );
		$request    = new Request( 'GET', '/expired-link', array(), array(), array(), array( 'id' => 'expired-link' ) );

		$response = $controller->redirect( $request );
		$this->assertSame( 410, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertSame( 1, substr_count( (string) $response['body'], '<meta name="generator"' ) );
		$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', (string) $response['body'] );
	}

	public function test_password_protected_link_html_contains_generator_tag(): void {
		$service = $this->createMock( LinksService::class );
		$service->method( 'get_link_access' )->willReturn(
			array(
				'status'  => 'password_required',
				'message' => 'Enter password',
			)
		);

		$controller = new LinksController( $service );
		$request    = new Request( 'GET', '/secret-link', array(), array(), array(), array( 'id' => 'secret-link' ) );

		$response = $controller->redirect( $request );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertSame( 1, substr_count( (string) $response['body'], '<meta name="generator"' ) );
		$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', (string) $response['body'] );
	}

	public function test_social_preview_html_contains_generator_tag(): void {
		$service = $this->createMock( LinksService::class );
		$service->method( 'get_link_social_preview' )->willReturn(
			array(
				'preview' => array(
					'title'       => 'Test Preview Title',
					'description' => 'Test Preview Description',
					'siteName'    => 'PeakURL',
					'url'         => 'https://peakurl.dev/preview-link',
				),
			)
		);

		$controller = new LinksController( $service );
		$headers    = array( 'User-Agent' => 'Twitterbot/1.0' );
		$server     = array( 'HTTP_USER_AGENT' => 'Twitterbot/1.0' );
		$request    = new Request( 'GET', '/preview-link', array(), array(), $headers, array( 'id' => 'preview-link' ), $server );

		$response = $controller->redirect( $request );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertSame( 1, substr_count( (string) $response['body'], '<meta name="generator"' ) );
		$this->assertMatchesRegularExpression( '/<meta name="generator" content="PeakURL [^"]+">/', (string) $response['body'] );
	}
}
