<?php
/**
 * Unit tests for PeakURL HTTP Router.
 *
 * @package PeakURL\Tests\Unit\Http
 */

declare(strict_types=1);

namespace PeakURL\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PeakURL\Http\Router;
use PeakURL\Http\Request;
use PeakURL\Core\Errors\RouteConfigurationException;

class RouterTest extends TestCase {

	private Router $router;

	protected function setUp(): void {
		parent::setUp();
		$this->router = new Router();
	}

	public function test_get_route_matches_and_invokes_handler(): void {
		$invoked = false;
		$this->router->get(
			'/api/v1/test',
			function ( Request $request ) use ( &$invoked ): array {
				$invoked = true;
				return array(
					'success' => true,
					'data'    => 'test-data',
				);
			}
		);

		$request  = new Request( 'GET', '/api/v1/test', array(), array() );
		$response = $this->router->dispatch( $request );

		$this->assertTrue( $invoked );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'test-data', $response['data'] );
	}

	public function test_route_extracts_path_parameters(): void {
		$extracted_id = null;
		$this->router->get(
			'/api/v1/urls/{id}',
			function ( Request $request ) use ( &$extracted_id ): array {
				$extracted_id = $request->get_route_param( 'id' );
				return array( 'success' => true );
			}
		);

		$request = new Request( 'GET', '/api/v1/urls/abc123xyz', array(), array() );
		$this->router->dispatch( $request );

		$this->assertSame( 'abc123xyz', $extracted_id );
	}

	public function test_route_extracts_multiple_parameters_with_url_decoding(): void {
		$extracted_params = array();
		$this->router->put(
			'/api/v1/users/{username}/keys/{key_id}',
			function ( Request $request ) use ( &$extracted_params ): array {
				$extracted_params = array(
					'username' => $request->get_route_param( 'username' ),
					'key_id'   => $request->get_route_param( 'key_id' ),
				);
				return array( 'success' => true );
			}
		);

		$request = new Request( 'PUT', '/api/v1/users/john%20doe/keys/k_99', array(), array() );
		$this->router->dispatch( $request );

		$this->assertSame( 'john doe', $extracted_params['username'] );
		$this->assertSame( 'k_99', $extracted_params['key_id'] );
	}

	public function test_all_http_methods_are_dispatched_appropriately(): void {
		$methods = array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE' );

		foreach ( $methods as $method ) {
			$router = new Router();
			$called = false;

			$router->add_route(
				$method,
				'/resource',
				function () use ( &$called ): array {
					$called = true;
					return array( 'success' => true );
				}
			);

			$request = new Request( $method, '/resource', array(), array() );
			$res     = $router->dispatch( $request );

			$this->assertTrue( $called, "Failed to invoke handler for method {$method}" );
			$this->assertTrue( $res['success'] );
		}
	}

	public function test_unsupported_http_method_throws_route_configuration_exception(): void {
		$this->expectException( RouteConfigurationException::class );
		$this->expectExceptionMessage( 'Unsupported route method: OPTIONS' );

		$this->router->add_route(
			'OPTIONS',
			'/api/v1/cors',
			function (): array {
				return array();
			}
		);
	}

	public function test_dispatch_returns_404_when_path_does_not_match(): void {
		$this->router->get(
			'/api/v1/existing',
			function (): array {
				return array( 'success' => true );
			}
		);

		$request  = new Request( 'GET', '/api/v1/non-existent', array(), array() );
		$response = $this->router->dispatch( $request );

		$this->assertSame( 404, $response['status'] );
		$this->assertFalse( $response['body']['success'] );
		$this->assertSame( 'Route not found.', $response['body']['message'] );
		$this->assertSame( '/api/v1/non-existent', $response['body']['data']['path'] );
		$this->assertSame( 'GET', $response['body']['data']['method'] );
	}

	public function test_dispatch_returns_404_when_method_does_not_match(): void {
		$this->router->post(
			'/api/v1/urls',
			function (): array {
				return array( 'success' => true );
			}
		);

		$request  = new Request( 'GET', '/api/v1/urls', array(), array() );
		$response = $this->router->dispatch( $request );

		$this->assertSame( 404, $response['status'] );
		$this->assertFalse( $response['body']['success'] );
	}
}
