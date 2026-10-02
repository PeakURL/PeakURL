<?php
/**
 * Destination health URL and address resolver.
 *
 * Owns destination address resolution, SSRF network validation, and RFC 3986
 * redirect reference resolution for the link health domain.
 *
 * @package PeakURL\Features\Links\Health
 * @since 1.7.1
 */

declare(strict_types=1);

namespace PeakURL\Features\Links\Health;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Resolver — resolves destination addresses and safely normalizes redirect targets.
 *
 * Enforces strict SSRF protections: blocks internal network targets, private IP
 * ranges (RFC 1918), loopback (127.0.0.1/::1), link-local, and cloud metadata services
 * (169.254.169.254) on both initial destination and each followed redirect.
 *
 * Single authoritative DNS path via dns_get_record() without fallback transports.
 *
 * @since 1.7.1
 */
class Resolver {

	/**
	 * Optional custom DNS resolver callable for deterministic testing.
	 *
	 * @var callable|null
	 * @since 1.7.1
	 */
	private $dns_resolver;

	/**
	 * Create a new destination resolver.
	 *
	 * @param callable|null $dns_resolver Optional DNS resolver callback for testing.
	 * @since 1.7.1
	 */
	public function __construct( ?callable $dns_resolver = null ) {
		$this->dns_resolver = $dns_resolver;
	}

	/**
	 * Return all validated public addresses for a destination host or IP.
	 *
	 * Normalizes bracketed IPv6 literals, evaluates raw IP literals without DNS,
	 * queries authoritative system DNS for hostnames, and filters out all
	 * private, loopback, link-local, and reserved addresses.
	 *
	 * @param string $host Hostname, IPv4 literal, or bracketed IPv6 literal.
	 * @return array<int, string> List of validated public IP addresses.
	 * @since 1.7.1
	 */
	public function public_addresses( string $host ): array {
		$host = strtolower( trim( $host ) );

		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
			return array();
		}

		$unbracketed_host = ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) )
			? substr( $host, 1, -1 )
			: $host;

		if ( filter_var( $unbracketed_host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $unbracketed_host ) ? array( $unbracketed_host ) : array();
		}

		$resolved_ips = $this->addresses_for_host( $host );
		if ( empty( $resolved_ips ) ) {
			return array();
		}

		$public_ips = array_values( array_filter( $resolved_ips, array( self::class, 'is_public_ip' ) ) );

		return array_values( array_unique( $public_ips ) );
	}

	/**
	 * Resolve IP addresses for a hostname using the configured resolver or authoritative DNS.
	 *
	 * Uses dns_get_record(DNS_A + DNS_AAAA) as the single authoritative DNS path.
	 * Note: synchronous operating system DNS resolution is handled by the system resolver
	 * and cannot be cancelled mid-flight via cURL options.
	 *
	 * @param string $host Hostname to resolve.
	 * @return array<int, string> Resolved IPv4 and IPv6 addresses.
	 * @since 1.7.1
	 */
	public function addresses_for_host( string $host ): array {
		if ( null !== $this->dns_resolver && is_callable( $this->dns_resolver ) ) {
			$result = call_user_func( $this->dns_resolver, $host );
			return is_array( $result ) ? array_values( array_unique( $result ) ) : array();
		}

		$resolved_ips = array();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing DNS resolution safely.
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
					$resolved_ips[] = $record['ip'];
				} elseif ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$resolved_ips[] = $record['ipv6'];
				}
			}
		}

		return array_values( array_unique( $resolved_ips ) );
	}

	/**
	 * Determine whether an IP address is a publicly routable Internet address.
	 *
	 * Rejects private (RFC 1918), reserved, loopback, and link-local ranges.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True if public and routable.
	 * @since 1.7.1
	 */
	public static function is_public_ip( string $ip ): bool {
		$valid = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( false === $valid ) {
			return false;
		}

		// Explicit check for cloud metadata (169.254.x.x), loopback, link-local, unspecified.
		if (
			str_starts_with( $ip, '127.' ) ||
			str_starts_with( $ip, '169.254.' ) ||
			str_starts_with( $ip, '0.' ) ||
			'0.0.0.0' === $ip ||
			'::1' === $ip ||
			'::' === $ip ||
			str_starts_with( strtolower( $ip ), 'fe80:' )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Remove dot segments from a path per RFC 3986 section 5.2.4.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 * @since 1.7.1
	 */
	public static function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$input  = $path;
		$output = '';

		while ( '' !== $input ) {
			// A. If the input buffer begins with a prefix of "../" or "./", remove that prefix.
			if ( str_starts_with( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( str_starts_with( $input, './' ) ) {
				$input = substr( $input, 2 );
				// B. If the input buffer begins with a prefix of "/./" or "/.", where "." is a complete path segment,
				// replace that prefix with "/" in the input buffer.
			} elseif ( str_starts_with( $input, '/./' ) ) {
				$input = '/' . substr( $input, 3 );
			} elseif ( '/.' === $input ) {
				$input = '/';
				// C. If the input buffer begins with a prefix of "/../" or "/..", where ".." is a complete path segment,
				// replace that prefix with "/" in the input buffer and remove the last segment and its preceding "/" from output buffer.
			} elseif ( str_starts_with( $input, '/../' ) || '/..' === $input ) {
				$input      = '/' . ( str_starts_with( $input, '/../' ) ? substr( $input, 4 ) : '' );
				$last_slash = strrpos( $output, '/' );
				if ( false !== $last_slash ) {
					$output = substr( $output, 0, $last_slash );
				} else {
					$output = '';
				}
				// D. If the input buffer consists only of "." or "..", remove that from the input buffer.
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
				// E. Move the first path segment in the input buffer to the end of the output buffer.
			} else {
				if ( str_starts_with( $input, '/' ) ) {
					$next_slash = strpos( $input, '/', 1 );
					if ( false !== $next_slash ) {
						$segment = substr( $input, 0, $next_slash );
						$input   = substr( $input, $next_slash );
					} else {
						$segment = $input;
						$input   = '';
					}
				} else {
					$next_slash = strpos( $input, '/' );
					if ( false !== $next_slash ) {
						$segment = substr( $input, 0, $next_slash );
						$input   = substr( $input, $next_slash );
					} else {
						$segment = $input;
						$input   = '';
					}
				}
				$output .= $segment;
			}
		}

		return $output;
	}

	/**
	 * Resolve a redirect Location header safely against the current request URL (RFC 3986).
	 *
	 * Handles absolute, protocol-relative, root-relative, path-relative, query-only,
	 * fragment-only references, dot segments, and rejects unsupported schemes.
	 *
	 * @param string $base_url Current URL.
	 * @param string $location Redirect location from headers.
	 * @return string|null Resolved absolute URL or null on invalid/unsupported URL.
	 * @since 1.7.1
	 */
	public static function redirect_target( string $base_url, string $location ): ?string {
		$location = trim( $location );
		if ( '' === $location ) {
			return null;
		}

		// Check for scheme in location.
		if ( preg_match( '#^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$#s', $location, $scheme_matches ) ) {
			$scheme = strtolower( $scheme_matches[1] );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return null;
			}

			$loc_parts = parse_url( $location );
			if ( false === $loc_parts || empty( $loc_parts['host'] ) ) {
				return null;
			}

			$port     = isset( $loc_parts['port'] ) ? ':' . (int) $loc_parts['port'] : '';
			$path     = self::normalize_path( (string) ( $loc_parts['path'] ?? '/' ) );
			$query    = isset( $loc_parts['query'] ) ? '?' . $loc_parts['query'] : '';
			$fragment = isset( $loc_parts['fragment'] ) ? '#' . $loc_parts['fragment'] : '';

			return $scheme . '://' . (string) $loc_parts['host'] . $port . $path . $query . $fragment;
		}

		// Parse base URL.
		$base_parts = parse_url( $base_url );
		if ( false === $base_parts || empty( $base_parts['host'] ) ) {
			return null;
		}

		$base_scheme = strtolower( (string) ( $base_parts['scheme'] ?? 'http' ) );
		if ( 'http' !== $base_scheme && 'https' !== $base_scheme ) {
			return null;
		}

		// Protocol-relative reference (e.g. //example.com/path).
		if ( str_starts_with( $location, '//' ) ) {
			return self::redirect_target( $base_url, $base_scheme . ':' . $location );
		}

		$base_host = (string) $base_parts['host'];
		$base_port = isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';
		$base_path = (string) ( $base_parts['path'] ?? '/' );
		if ( '' === $base_path ) {
			$base_path = '/';
		}

		$loc_fragment = null;
		$loc_query    = null;
		$loc_path     = $location;

		if ( str_contains( $loc_path, '#' ) ) {
			$frag_pos     = (int) strpos( $loc_path, '#' );
			$loc_fragment = substr( $loc_path, $frag_pos + 1 );
			$loc_path     = substr( $loc_path, 0, $frag_pos );
		}

		if ( str_contains( $loc_path, '?' ) ) {
			$query_pos = (int) strpos( $loc_path, '?' );
			$loc_query = substr( $loc_path, $query_pos + 1 );
			$loc_path  = substr( $loc_path, 0, $query_pos );
		}

		if ( '' === $loc_path ) {
			// Query-only or fragment-only reference.
			$target_path     = $base_path;
			$target_query    = null !== $loc_query ? $loc_query : ( $base_parts['query'] ?? null );
			$target_fragment = $loc_fragment;
		} elseif ( str_starts_with( $loc_path, '/' ) ) {
			// Absolute-path reference.
			$target_path     = self::normalize_path( $loc_path );
			$target_query    = $loc_query;
			$target_fragment = $loc_fragment;
		} else {
			// Relative-path reference.
			$dir = preg_replace( '#/[^/]*$#', '/', $base_path );
			if ( ! is_string( $dir ) || '' === $dir ) {
				$dir = '/';
			}
			$target_path     = self::normalize_path( $dir . $loc_path );
			$target_query    = $loc_query;
			$target_fragment = $loc_fragment;
		}

		$query_str    = null !== $target_query ? '?' . $target_query : '';
		$fragment_str = null !== $target_fragment ? '#' . $target_fragment : '';

		return $base_scheme . '://' . $base_host . $base_port . $target_path . $query_str . $fragment_str;
	}

	/**
	 * Normalize a URL into its request target representation for redirect loop detection.
	 *
	 * Strips URL fragments (#fragment) because fragments are not transmitted in HTTP
	 * requests and do not alter the HTTP request target.
	 *
	 * @param string $url URL to normalize.
	 * @return string Request target without fragment.
	 * @since 1.7.1
	 */
	public static function normalize_target( string $url ): string {
		$parts = parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) ) {
			return $url;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return "{$scheme}://{$host}{$port}{$path}{$query}";
	}
}
