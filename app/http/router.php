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

use PeakURL\Includes\RouteConfigurationException;

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
	 * HTTP methods accepted by the route registry.
	 *
	 * @var array<string, bool>
	 * @since 1.2.2
	 */
	private const METHODS = array(
		'GET'    => true,
		'HEAD'   => true,
		'POST'   => true,
		'PUT'    => true,
		'PATCH'  => true,
		'DELETE' => true,
	);

	/**
	 * Registered route definitions.
	 *
	 * Grouped by HTTP method so dispatch only scans relevant routes.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
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
		$this->add_route( 'GET', $path, $handler );
	}

	/**
	 * Register a HEAD route.
	 *
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 * @since 1.1.2
	 */
	public function head( string $path, callable $handler ): void {
		$this->add_route( 'HEAD', $path, $handler );
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
		$this->add_route( 'POST', $path, $handler );
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
		$this->add_route( 'PUT', $path, $handler );
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
		$this->add_route( 'PATCH', $path, $handler );
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
		$this->add_route( 'DELETE', $path, $handler );
	}

	/**
	 * Register a route from a normalized route map entry.
	 *
	 * Higher-level route maps can call this method without dispatching through
	 * dynamic method names.
	 *
	 * @param string   $method  HTTP method.
	 * @param string   $path    URI pattern.
	 * @param callable $handler Request handler.
	 * @return void
	 *
	 * @throws RouteConfigurationException If the HTTP method is unsupported.
	 * @since 1.2.2
	 */
	public function add_route( string $method, string $path, callable $handler ): void {
		$method = strtoupper( trim( $method ) );

		if ( ! isset( self::METHODS[ $method ] ) ) {
			throw new RouteConfigurationException(
				'Unsupported route method: ' . $method,
			);
		}

		$this->register( $method, $path, $handler );
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
		$routes = $this->routes[ $request->get_method() ] ?? array();

		foreach ( $routes as $route ) {
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
			__( 'Route not found.', 'peakurl' ),
			404,
			array(
				'path'   => $request->get_path(),
				'method' => $request->get_method(),
			)
		);
	}

	/**
	 * Register a route definition in the internal route table.
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
	private function register( string $method, string $path, callable $handler ): void {
		$params = array();
		$regex  = $this->compile_path( $path, $params );

		if ( ! isset( $this->routes[ $method ] ) ) {
			$this->routes[ $method ] = array();
		}

		$this->routes[ $method ][] = array(
			'path'    => $path,
			'regex'   => $regex,
			'params'  => $params,
			'handler' => $handler,
		);
	}

	/**
	 * Compile a route path into a safe regular expression.
	 *
	 * Literal path text is escaped, while `{param}` placeholders become one
	 * path-segment capture group and are recorded for later extraction.
	 *
	 * @param string             $path   Route path with optional placeholders.
	 * @param array<int, string> $params Placeholder names collected by reference.
	 * @return string Regex pattern ready for `preg_match()`.
	 * @since 1.2.2
	 */
	private function compile_path( string $path, array &$params ): string {
		$parts = preg_split(
			'/(\{[^}]+\})/',
			$path,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			return '#^' . preg_quote( $path, '#' ) . '$#';
		}

		$regex = '';

		foreach ( $parts as $part ) {
			if (
				preg_match( '/^\{([^}]+)\}$/', $part, $matches )
			) {
				$params[] = $matches[1];
				$regex   .= '([^/]+)';
				continue;
			}

			$regex .= preg_quote( $part, '#' );
		}

		return '#^' . $regex . '$#';
	}
}
