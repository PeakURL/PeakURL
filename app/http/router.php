<?php
/**
 * Small route matcher for the self-hosted API.
 *
 * Supports parameterised paths like `/api/v1/urls/{id}` and dispatches
 * to callable handlers registered per HTTP verb.
 *
 * @package PeakURL\Http
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Http;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Lightweight HTTP router with path-parameter extraction.
 *
 * @since 1.0.0
 */
class Router {

	/**
	 * Registered route definitions.
	 *
	 * @var array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	private array $routes = array();

	/**
	 * Register a GET route.
	 *
	 * @param string   $path    URI pattern (may include {param} placeholders).
	 * @param callable $handler Handler that receives a Request and returns an array.
	 * @return void
	 * @since 1.0.0
	 */
	public function get( string $path, callable $handler ): void {
		$this->add( 'GET', $path, $handler );
	}

	/**
	 * Register a POST route.
	 *
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 * @since 1.0.0
	 */
	public function post( string $path, callable $handler ): void {
		$this->add( 'POST', $path, $handler );
	}

	/**
	 * Register a PUT route.
	 *
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 * @since 1.0.0
	 */
	public function put( string $path, callable $handler ): void {
		$this->add( 'PUT', $path, $handler );
	}

	/**
	 * Register a PATCH route.
	 *
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 * @since 1.0.0
	 */
	public function patch( string $path, callable $handler ): void {
		$this->add( 'PATCH', $path, $handler );
	}

	/**
	 * Register a DELETE route.
	 *
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 * @since 1.0.0
	 */
	public function delete( string $path, callable $handler ): void {
		$this->add( 'DELETE', $path, $handler );
	}

	/**
	 * Match the incoming request against registered routes and invoke the handler.
	 *
	 * Path parameters extracted from the URI are set on the Request before
	 * the handler is called. Returns a 404 response when no route matches.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> Structured response array.
	 * @since 1.0.0
	 */
	public function dispatch( Request $request ): array {
		foreach ( $this->routes as $route ) {
			if ( $route['method'] !== $request->get_method() ) {
				continue;
			}

			if ( ! preg_match( $route['regex'], $request->get_path(), $matches ) ) {
				continue;
			}

			$params = array();

			foreach ( $route['params'] as $index => $name ) {
				$params[ $name ] = rawurldecode( $matches[ $index + 1 ] ?? '' );
			}

			$request->set_route_params( $params );

			return (array) call_user_func( $route['handler'], $request );
		}

		return JsonResponse::error(
			'Route not found.',
			404,
			array(
				'path'   => $request->get_path(),
				'method' => $request->get_method(),
			)
		);
	}

	/**
	 * Add a route definition to the internal registry.
	 *
	 * Converts `{param}` placeholders to regex capture groups and records
	 * the parameter names in order for later extraction.
	 *
	 * @param string   $method  HTTP method (GET, POST, etc.).
	 * @param string   $path    URI pattern with optional {param} placeholders.
	 * @param callable $handler The handler to invoke on match.
	 * @return void
	 * @since 1.0.0
	 */
	private function add( string $method, string $path, callable $handler ): void {
		$params = array();
		$regex  = preg_replace_callback(
			'/\{([^}]+)\}/',
			static function ( array $matches ) use ( &$params ): string {
				$params[] = $matches[1];
				return '([^/]+)';
			},
			$path,
		);

		$this->routes[] = array(
			'method'  => strtoupper( $method ),
			'path'    => $path,
			'regex'   => '#^' . $regex . '$#',
			'params'  => $params,
			'handler' => $handler,
		);
	}
}
