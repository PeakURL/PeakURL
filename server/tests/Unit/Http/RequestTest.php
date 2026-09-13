<?php
/**
 * Unit tests for PeakURL HTTP Request.
 *
 * @package PeakURL\Tests\Unit\Http
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PeakURL\Http\Request;

class RequestTest extends TestCase {

	public function test_basic_getters_and_fallbacks(): void {
		$request = new Request(
			'post',
			'/api/v1/urls',
			array(
				'sort'  => 'clicks',
				'order' => 'desc',
			),
			array(
				'url'   => 'https://example.com',
				'title' => 'Example',
			),
			array( 'session' => 'xyz123' ),
			array(),
			array( 'HTTP_USER_AGENT' => 'PeakTest/1.0' )
		);

		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/api/v1/urls', $request->get_path() );
		$this->assertSame( 'clicks', $request->get_query_param( 'sort' ) );
		$this->assertSame( 'default', $request->get_query_param( 'nonexistent', 'default' ) );
		$this->assertSame( 'https://example.com', $request->get_body_param( 'url' ) );
		$this->assertSame( 'default', $request->get_body_param( 'nonexistent', 'default' ) );
		$this->assertSame(
			array(
				'url'   => 'https://example.com',
				'title' => 'Example',
			),
			$request->get_body_params()
		);
		$this->assertSame( 'xyz123', $request->get_cookie( 'session' ) );
		$this->assertSame( 'PeakTest/1.0', $request->get_user_agent() );
	}

	public function test_route_params_management(): void {
		$request = new Request( 'GET', '/api/v1/urls/42', array(), array() );
		$this->assertNull( $request->get_route_param( 'id' ) );
		$this->assertSame( 'fallback', $request->get_route_param( 'id', 'fallback' ) );

		$request->set_route_params(
			array(
				'id'   => '42',
				'slug' => 'custom-slug',
			)
		);
		$this->assertSame( '42', $request->get_route_param( 'id' ) );
		$this->assertSame( 'custom-slug', $request->get_route_param( 'slug' ) );
	}

	public function test_header_normalization_and_apache_authorization_fallback(): void {
		$request = new Request(
			'GET',
			'/api/v1/test',
			array(),
			array(),
			array(),
			array(),
			array(
				'CONTENT_TYPE'                => 'application/json',
				'HTTP_X_CUSTOM_HEADER'        => 'custom-value',
				'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer fallback-token',
			)
		);

		$this->assertSame( 'application/json', $request->get_header( 'Content-Type' ) );
		$this->assertSame( 'custom-value', $request->get_header( 'X-Custom-Header' ) );
		$this->assertSame( 'Bearer fallback-token', $request->get_header( 'Authorization' ) );
		$this->assertNull( $request->get_header( 'Non-Existent' ) );
		$this->assertSame( 'default', $request->get_header( 'Non-Existent', 'default' ) );
	}

	public function test_ip_address_detection_precedence(): void {
		// 1. HTTP_X_REAL_IP priority.
		$request1 = new Request(
			'GET',
			'/',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_X_REAL_IP'       => '203.0.113.10',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
				'REMOTE_ADDR'          => '127.0.0.1',
			)
		);
		$this->assertSame( '203.0.113.10', $request1->get_ip_address() );

		// 2. HTTP_X_FORWARDED_FOR with public IP preference.
		$request2 = new Request(
			'GET',
			'/',
			array(),
			array(),
			array(),
			array(),
			array(
				'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 198.51.100.25, 172.16.0.1',
				'REMOTE_ADDR'          => '127.0.0.1',
			)
		);
		$this->assertSame( '198.51.100.25', $request2->get_ip_address() );

		// 3. REMOTE_ADDR fallback.
		$request3 = new Request(
			'GET',
			'/',
			array(),
			array(),
			array(),
			array(),
			array(
				'REMOTE_ADDR' => '192.168.1.1',
			)
		);
		$this->assertSame( '192.168.1.1', $request3->get_ip_address() );
	}

	public function test_is_secure_detection(): void {
		$req_http = new Request( 'GET', '/', array(), array(), array(), array(), array() );
		$this->assertFalse( $req_http->is_secure() );

		$req_https_on = new Request( 'GET', '/', array(), array(), array(), array(), array( 'HTTPS' => 'on' ) );
		$this->assertTrue( $req_https_on->is_secure() );

		$req_https_one = new Request( 'GET', '/', array(), array(), array(), array(), array( 'HTTPS' => '1' ) );
		$this->assertTrue( $req_https_one->is_secure() );

		$req_forwarded_proto = new Request(
			'GET',
			'/',
			array(),
			array(),
			array(),
			array(),
			array( 'HTTP_X_FORWARDED_PROTO' => 'https' )
		);
		$this->assertTrue( $req_forwarded_proto->is_secure() );
	}

	public function test_cookie_queueing_and_expiration(): void {
		$request = new Request(
			'GET',
			'/',
			array(),
			array(),
			array( 'existing_cookie' => 'val' ),
			array(),
			array( 'HTTPS' => 'on' )
		);

		$request->queue_cookie( 'token', 'abc123secret' );
		$request->expire_cookie( 'existing_cookie' );

		$cookies = $request->get_response_cookies();
		$this->assertCount( 2, $cookies );

		// token cookie assertions
		$this->assertStringContainsString( 'token=abc123secret', $cookies[0] );
		$this->assertStringContainsString( 'Path=/', $cookies[0] );
		$this->assertStringContainsString( 'HttpOnly', $cookies[0] );
		$this->assertStringContainsString( 'SameSite=Lax', $cookies[0] );
		$this->assertStringContainsString( 'Secure', $cookies[0] );

		// expired cookie assertions
		$this->assertStringContainsString( 'existing_cookie=', $cookies[1] );
		$this->assertStringContainsString( 'Max-Age=0', $cookies[1] );
	}

	public function test_base_path_calculation_for_subdirectories(): void {
		$root_req = new Request(
			'GET',
			'/api/v1/urls',
			array(),
			array(),
			array(),
			array(),
			array( 'SCRIPT_NAME' => '/index.php' )
		);
		$this->assertSame( '', $root_req->get_base_path() );

		$sub_req = new Request(
			'GET',
			'/api/v1/urls',
			array(),
			array(),
			array(),
			array(),
			array( 'SCRIPT_NAME' => '/peakurl/index.php' )
		);
		$this->assertSame( '/peakurl', $sub_req->get_base_path() );
	}
}
