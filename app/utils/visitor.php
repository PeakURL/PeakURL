<?php
/**
 * Visitor and referrer parsing helpers.
 *
 * @package PeakURL\Utils
 * @since 1.0.0
 */

declare(strict_types=1);

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Static visitor helpers used by click tracking and session metadata.
 *
 * @since 1.0.0
 */
class Visitor_Utils {

	/**
	 * Extract browser, operating system, and device type from a user-agent.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return array{browser: string, os: string, device: string}
	 * @since 1.0.0
	 */
	public static function parse_user_agent( string $user_agent ): array {
		$agent   = strtolower( $user_agent );
		$browser = 'Browser';
		$os      = 'Unknown OS';
		$device  = 'Desktop';

		foreach (
			array(
				'edge'    => 'Edge',
				'opr/'    => 'Opera',
				'chrome'  => 'Chrome',
				'firefox' => 'Firefox',
				'safari'  => 'Safari',
			)
			as $needle => $label
		) {
			if ( String_Utils::contains( $agent, $needle ) ) {
				$browser = $label;
				break;
			}
		}

		foreach (
			array(
				'windows'   => 'Windows',
				'mac os'    => 'macOS',
				'macintosh' => 'macOS',
				'iphone'    => 'iOS',
				'ipad'      => 'iPadOS',
				'android'   => 'Android',
				'linux'     => 'Linux',
			)
			as $needle => $label
		) {
			if ( String_Utils::contains( $agent, $needle ) ) {
				$os = $label;
				break;
			}
		}

		if ( String_Utils::contains( $agent, 'tablet' ) || String_Utils::contains( $agent, 'ipad' ) ) {
			$device = 'Tablet';
		} elseif (
			String_Utils::contains( $agent, 'mobile' ) ||
			String_Utils::contains( $agent, 'iphone' ) ||
			String_Utils::contains( $agent, 'android' )
		) {
			$device = 'Mobile';
		}

		return array(
			'browser' => $browser,
			'os'      => $os,
			'device'  => $device,
		);
	}

	/**
	 * Parse a referrer URL into a name, domain, and traffic category.
	 *
	 * @param string $referrer Raw Referer header value.
	 * @return array{name: string|null, domain: string|null, category: string|null}
	 * @since 1.0.0
	 */
	public static function parse_referrer( string $referrer ): array {
		$referrer = trim( $referrer );

		if ( '' === $referrer ) {
			return array(
				'name'     => null,
				'domain'   => null,
				'category' => null,
			);
		}

		$host = parse_url( $referrer, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return array(
				'name'     => null,
				'domain'   => null,
				'category' => null,
			);
		}

		$normalized_host = strtolower( preg_replace( '/^www\./i', '', $host ) );
		$category        = 'Website';

		if (
			String_Utils::contains( $normalized_host, 'google.' ) ||
			String_Utils::contains( $normalized_host, 'bing.' ) ||
			String_Utils::contains( $normalized_host, 'duckduckgo.' ) ||
			String_Utils::contains( $normalized_host, 'search.yahoo.' )
		) {
			$category = 'Search';
		} elseif (
			String_Utils::contains( $normalized_host, 'facebook.' ) ||
			String_Utils::contains( $normalized_host, 'instagram.' ) ||
			String_Utils::contains( $normalized_host, 'linkedin.' ) ||
			String_Utils::contains( $normalized_host, 'twitter.' ) ||
			String_Utils::contains( $normalized_host, 'x.com' ) ||
			String_Utils::contains( $normalized_host, 't.co' )
		) {
			$category = 'Social';
		}

		return array(
			'name'     => ucfirst( $normalized_host ),
			'domain'   => $normalized_host,
			'category' => $category,
		);
	}

	/**
	 * Build a privacy-safe visitor hash from request IP and user-agent.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return string|null SHA-256 hash or null when both inputs are empty.
	 * @since 1.0.0
	 */
	public static function build_hash( Request $request ): ?string {
		$ip_address = trim( $request->get_ip_address() );
		$user_agent = trim( $request->get_user_agent() );

		if ( '' === $ip_address && '' === $user_agent ) {
			return null;
		}

		return hash( 'sha256', $ip_address . '|' . $user_agent );
	}

	/**
	 * Skip click tracking for explicit prefetch and preview requests.
	 *
	 * @param Request $request       Incoming HTTP request.
	 * @param bool    $allow_non_get Whether non-GET requests should be allowed.
	 * @return bool True when the request should not increment analytics.
	 * @since 1.0.0
	 */
	public static function should_skip_click_tracking(
		Request $request,
		bool $allow_non_get = false
	): bool {
		if ( ! $allow_non_get && 'GET' !== $request->get_method() ) {
			return true;
		}

		foreach (
			array( 'Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz' ) as $header
		) {
			$value = strtolower(
				trim( (string) $request->get_header( $header, '' ) ),
			);

			if (
				'' !== $value &&
				(
					false !== strpos( $value, 'prefetch' ) ||
					false !== strpos( $value, 'preview' ) ||
					false !== strpos( $value, 'prerender' )
				)
			) {
				return true;
			}
		}

		return false;
	}
}
