<?php
/**
 * Request abstraction.
 *
 * Wraps PHP super-globals into an immutable object that is passed to every
 * route handler. Also manages outbound response cookies.
 *
 * @package PeakURL\Http
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * HTTP request value object.
 *
 * @since 1.0.0
 */
class Request {

	/** @var string HTTP method (GET, POST, PUT, DELETE, …). */
	private string $method;

	/** @var string Request path without the query string. */
	private string $path;

	/** @var array<string, mixed> Parsed query-string parameters. */
	private array $query_params;

	/** @var array<string, mixed> Parsed request body (JSON or form data). */
	private array $body_params;

	/** @var array<string, string> Cookie values from the request. */
	private array $cookie_params;

	/** @var array<string, string> $_SERVER super-global snapshot. */
	private array $server_params;

	/** @var array<string, string> Route parameters extracted by the Router. */
	private array $route_params = array();

	/** @var array<int, string> Set-Cookie header strings to send. */
	private array $response_cookies = array();

	/**
	 * Create a new Request instance.
	 *
	 * @param string               $method        HTTP method.
	 * @param string               $path          Request path.
	 * @param array<string, mixed> $query_params  Query-string parameters.
	 * @param array<string, mixed> $body_params   Parsed body parameters.
	 * @param array<string, string> $cookie_params Cookie values.
	 * @param array<string, string> $server_params Server super-global.
	 * @since 1.0.0
	 */
	public function __construct(
		string $method,
		string $path,
		array $query_params,
		array $body_params,
		array $cookie_params = array(),
		array $server_params = array()
	) {
		$this->method        = strtoupper( $method );
		$this->path          = $path;
		$this->query_params  = $query_params;
		$this->body_params   = $body_params;
		$this->cookie_params = $cookie_params;
		$this->server_params = $server_params;
	}

	/**
	 * Build a Request from PHP super-globals.
	 *
	 * Automatically strips the script base-path from the URI, parses
	 * JSON or form-encoded request bodies, and snapshots cookies and
	 * server variables.
	 *
	 * @return self Populated Request instance.
	 * @since 1.0.0
	 */
	public static function from_globals(): self {
		$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$uri          = $_SERVER['REQUEST_URI'] ?? '/';
		$path         = parse_url( $uri, PHP_URL_PATH );
		$script_name  = (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' );
		$query_params = $_GET;

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$base_path = str_replace( '\\', '/', dirname( $script_name ) );

		if ( '.' === $base_path || '/' === $base_path ) {
			$base_path = '';
		} else {
			$base_path = rtrim( $base_path, '/' );
		}

		if (
			'' !== $base_path &&
			String_Utils::starts_with( $path, $base_path . '/' )
		) {
			$trimmed_path = substr( $path, strlen( $base_path ) );
			$path         =
				false !== $trimmed_path && '' !== $trimmed_path
					? $trimmed_path
					: '/';
		} elseif ( $path === $base_path ) {
			$path = '/';
		}

		$body = file_get_contents( 'php://input' );

		if ( false === $body ) {
			$body = '';
		}

		$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
		$body_params  = array();

		if ( '' !== $body ) {
			if ( false !== stripos( $content_type, 'application/json' ) ) {
				$decoded     = json_decode( $body, true );
				$body_params = is_array( $decoded ) ? $decoded : array();
			} else {
				parse_str( $body, $body_params );
				if ( ! is_array( $body_params ) ) {
					$body_params = array();
				}
			}
		}

		return new self(
			$method,
			$path,
			$query_params,
			$body_params,
			$_COOKIE,
			$_SERVER,
		);
	}

	/**
	 * Get the HTTP method (GET, POST, PUT, DELETE, …).
	 *
	 * @return string Uppercase HTTP method.
	 * @since 1.0.0
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Get the request path (without query string or base-path prefix).
	 *
	 * @return string Normalised request path.
	 * @since 1.0.0
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Retrieve a single query-string parameter.
	 *
	 * @param string $key      Parameter name.
	 * @param mixed  $fallback Default when key is absent.
	 * @return mixed Parameter value or fallback.
	 * @since 1.0.0
	 */
	public function get_query_param( string $key, $fallback = null ) {
		return $this->query_params[ $key ] ?? $fallback;
	}

	/**
	 * Retrieve a single request-body parameter.
	 *
	 * @param string $key      Parameter name.
	 * @param mixed  $fallback Default when key is absent.
	 * @return mixed Parameter value or fallback.
	 * @since 1.0.0
	 */
	public function get_body_param( string $key, $fallback = null ) {
		return $this->body_params[ $key ] ?? $fallback;
	}

	/**
	 * Get all parsed request-body parameters.
	 *
	 * @return array<string, mixed> Body parameter map.
	 * @since 1.0.0
	 */
	public function get_body_params(): array {
		return $this->body_params;
	}

	/**
	 * Retrieve a route parameter extracted by the Router.
	 *
	 * @param string $key      Parameter name from the route pattern.
	 * @param mixed  $fallback Default when key is absent.
	 * @return mixed Parameter value or fallback.
	 * @since 1.0.0
	 */
	public function get_route_param( string $key, $fallback = null ) {
		return $this->route_params[ $key ] ?? $fallback;
	}

	/**
	 * Replace the route parameters (called by the Router after matching).
	 *
	 * @param array<string, string> $params Extracted route parameters.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_route_params( array $params ): void {
		$this->route_params = $params;
	}

	/**
	 * Retrieve a cookie value from the incoming request.
	 *
	 * @param string $key      Cookie name.
	 * @param mixed  $fallback Default when the cookie is absent.
	 * @return mixed Cookie value or fallback.
	 * @since 1.0.0
	 */
	public function get_cookie( string $key, $fallback = null ) {
		return $this->cookie_params[ $key ] ?? $fallback;
	}

	/**
	 * Retrieve a $_SERVER parameter.
	 *
	 * @param string $key      Server variable name.
	 * @param mixed  $fallback Default when key is absent.
	 * @return mixed Server value or fallback.
	 * @since 1.0.0
	 */
	public function get_server_param( string $key, $fallback = null ) {
		return $this->server_params[ $key ] ?? $fallback;
	}

	/**
	 * Retrieve an HTTP request header by its canonical name.
	 *
	 * Converts the header name to the `HTTP_*` / `CONTENT_TYPE` convention
	 * used in $_SERVER.
	 *
	 * @param string $key      Header name (e.g. 'Content-Type').
	 * @param mixed  $fallback Default when the header is absent.
	 * @return mixed Header value or fallback.
	 * @since 1.0.0
	 */
	public function get_header( string $key, $fallback = null ) {
		$normalized = 'HTTP_' . strtoupper( str_replace( '-', '_', $key ) );

		if ( 'CONTENT_TYPE' === strtoupper( str_replace( '-', '_', $key ) ) ) {
			$normalized = 'CONTENT_TYPE';
		}

		return $this->server_params[ $normalized ] ?? $fallback;
	}

	/**
	 * Get the User-Agent header string.
	 *
	 * @return string User-Agent value, or '' when absent.
	 * @since 1.0.0
	 */
	public function get_user_agent(): string {
		return (string) ( $this->server_params['HTTP_USER_AGENT'] ?? '' );
	}

	/**
	 * Determine the client IP address.
	 *
	 * Prefers generic reverse-proxy real-IP headers when present, then falls
	 * back to the first valid X-Forwarded-For entry, then REMOTE_ADDR.
	 *
	 * @return string Client IP address string.
	 * @since 1.0.0
	 */
	public function get_ip_address(): string {
		$direct_headers = array(
			(string) ( $this->server_params['HTTP_X_REAL_IP'] ?? '' ),
		);

		foreach ( $direct_headers as $header_value ) {
			$ip_address = $this->normalize_ip( $header_value );

			if ( '' !== $ip_address ) {
				return $ip_address;
			}
		}

		$forwarded    =
			(string) ( $this->server_params['HTTP_X_FORWARDED_FOR'] ?? '' );
		$forwarded_ip = $this->extract_forwarded_ip( $forwarded );

		if ( '' !== $forwarded_ip ) {
			return $forwarded_ip;
		}

		return $this->normalize_ip(
			(string) ( $this->server_params['REMOTE_ADDR'] ?? '' ),
		);
	}

	/**
	 * Pick the best client IP from an X-Forwarded-For header value.
	 *
	 * Prefers the first public IP in the list, otherwise falls back
	 * to the first valid IP value found.
	 *
	 * @param string $header_value Raw X-Forwarded-For header string.
	 * @return string Best-match IP address, or '' when none are valid.
	 * @since 1.0.0
	 */
	private function extract_forwarded_ip( string $header_value ): string {
		$fallback_ip = '';

		foreach ( explode( ',', $header_value ) as $part ) {
			$ip_address = $this->normalize_ip( $part );

			if ( '' === $ip_address ) {
				continue;
			}

			if ( $this->is_public_ip( $ip_address ) ) {
				return $ip_address;
			}

			if ( '' === $fallback_ip ) {
				$fallback_ip = $ip_address;
			}
		}

		return $fallback_ip;
	}

	/**
	 * Normalize a raw IP string into a validated IP address.
	 *
	 * @param string $value Raw IP string.
	 * @return string Validated IP address, or '' when invalid.
	 * @since 1.0.0
	 */
	private function normalize_ip( string $value ): string {
		$ip_address = trim( $value );

		if ( false === filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $ip_address;
	}

	/**
	 * Check whether an IP address is publicly routable.
	 *
	 * @param string $ip_address Candidate IP address.
	 * @return bool True when the address is not private or reserved.
	 * @since 1.0.0
	 */
	private function is_public_ip( string $ip_address ): bool {
		return false !== filter_var(
			$ip_address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		);
	}

	/**
	 * Check whether the request was made over HTTPS.
	 *
	 * Inspects both the HTTPS server variable and the X-Forwarded-Proto
	 * header for proxy-terminated TLS.
	 *
	 * @return bool True when the connection is secure.
	 * @since 1.0.0
	 */
	public function is_secure(): bool {
		$https           = strtolower( (string) ( $this->server_params['HTTPS'] ?? '' ) );
		$forwarded_proto = strtolower(
			(string) ( $this->server_params['HTTP_X_FORWARDED_PROTO'] ?? '' ),
		);

		return 'on' === $https ||
			'1' === $https ||
			'https' === $forwarded_proto;
	}

	/**
	 * Queue a Set-Cookie header to be sent with the response.
	 *
	 * @param string               $name    Cookie name.
	 * @param string               $value   Cookie value.
	 * @param array<string, mixed> $options Cookie attributes (path, httponly, etc.).
	 * @return void
	 * @since 1.0.0
	 */
	public function queue_cookie(
		string $name,
		string $value,
		array $options = array()
	): void {
		$defaults = array(
			'path'     => '/',
			'httponly' => true,
			'samesite' => 'Lax',
			'secure'   => $this->is_secure(),
		);

		$this->response_cookies[]     = $this->build_cookie_header(
			$name,
			$value,
			array_merge( $defaults, $options ),
		);
		$this->cookie_params[ $name ] = $value;
	}

	/**
	 * Queue an expired Set-Cookie header to clear a cookie in the client.
	 *
	 * @param string               $name    Cookie name to expire.
	 * @param array<string, mixed> $options Additional cookie attributes.
	 * @return void
	 * @since 1.0.0
	 */
	public function expire_cookie( string $name, array $options = array() ): void {
		$options['expires'] = gmdate( 'D, d M Y H:i:s T', strtotime( '-1 day' ) );
		$options['max-age'] = 0;
		$this->queue_cookie( $name, '', $options );
		unset( $this->cookie_params[ $name ] );
	}

	/**
	 * Return all queued Set-Cookie header strings.
	 *
	 * @return array<int, string> Raw Set-Cookie header values.
	 * @since 1.0.0
	 */
	public function get_response_cookies(): array {
		return $this->response_cookies;
	}

	/**
	 * Assemble a raw Set-Cookie header value from name, value, and options.
	 *
	 * @param string               $name    Cookie name.
	 * @param string               $value   Cookie value.
	 * @param array<string, mixed> $options Cookie attributes.
	 * @return string Complete Set-Cookie header value.
	 * @since 1.0.0
	 */
	private function build_cookie_header(
		string $name,
		string $value,
		array $options
	): string {
		$parts = array( rawurlencode( $name ) . '=' . rawurlencode( $value ) );

		if ( ! empty( $options['expires'] ) ) {
			$parts[] = 'Expires=' . $options['expires'];
		}

		if ( isset( $options['max-age'] ) ) {
			$parts[] = 'Max-Age=' . (int) $options['max-age'];
		}

		if ( ! empty( $options['path'] ) ) {
			$parts[] = 'Path=' . (string) $options['path'];
		}

		if ( ! empty( $options['domain'] ) ) {
			$parts[] = 'Domain=' . (string) $options['domain'];
		}

		if ( ! empty( $options['secure'] ) ) {
			$parts[] = 'Secure';
		}

		if ( ! empty( $options['httponly'] ) ) {
			$parts[] = 'HttpOnly';
		}

		if ( ! empty( $options['samesite'] ) ) {
			$parts[] = 'SameSite=' . (string) $options['samesite'];
		}

		return implode( '; ', $parts );
	}
}
