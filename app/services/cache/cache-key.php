<?php
/**
 * Standardised cache key generator for PeakURL.
 *
 * Provides version-namespaced, collision-free cache keys for link resolution,
 * dashboard queries, and transients.
 *
 * @package PeakURL\Services\Cache
 * @since 1.6.0
 */

declare(strict_types=1);

namespace PeakURL\Services\Cache;

use PeakURL\Includes\Constants;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * CacheKey — central key factory for all cache entries.
 *
 * @since 1.6.0
 */
final class CacheKey {

	/**
	 * Cache version prefix (e.g. 'peakurl:v1').
	 *
	 * @var string
	 * @since 1.6.0
	 */
	public const PREFIX = 'peakurl:v' . Constants::CACHE_VERSION;

	/**
	 * Prevent instantiation.
	 *
	 * @since 1.6.0
	 */
	private function __construct() {}

	/**
	 * Normalise a route identifier (short code or alias) for cache lookups.
	 *
	 * @param string $identifier Candidate short code or alias.
	 * @return string Normalised lowercase string.
	 * @since 1.6.0
	 */
	public static function normalize_identifier( string $identifier ): string {
		return strtolower( trim( $identifier ) );
	}

	/**
	 * Build public link resolution lookup key.
	 *
	 * @param string $code_or_alias Short code or custom alias.
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function link_lookup( string $code_or_alias ): string {
		return self::PREFIX . ':link:lookup:' . self::normalize_identifier( $code_or_alias );
	}

	/**
	 * Build canonical link record key by database ID.
	 *
	 * @param string $id URL UUID/hex identifier.
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function link_id( string $id ): string {
		return self::PREFIX . ':link:id:' . trim( $id );
	}

	/**
	 * Build negative cache key for non-existent public codes.
	 *
	 * @param string $code_or_alias Candidate short code or alias.
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function link_missing( string $code_or_alias ): string {
		return self::PREFIX . ':link:missing:' . self::normalize_identifier( $code_or_alias );
	}

	/**
	 * Build cache key for dashboard links listing queries.
	 *
	 * @param array<string, mixed> $query_params Listing query parameters.
	 * @param string|null          $user_id      Authenticated user ID (null for admin view).
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function dashboard_links( array $query_params, ?string $user_id = null ): string {
		ksort( $query_params );
		$serialized = ( $user_id ? "user:{$user_id}:" : 'all:' ) . http_build_query( $query_params );
		return self::PREFIX . ':dashboard:links:' . md5( $serialized );
	}

	/**
	 * Build cache key for single URL dashboard details.
	 *
	 * @param string $id URL record ID.
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function dashboard_url( string $id ): string {
		return self::PREFIX . ':dashboard:url:' . trim( $id );
	}

	/**
	 * Build cache key for WordPress-style transients.
	 *
	 * @param string $name Transient identifier.
	 * @return string Cache key.
	 * @since 1.6.0
	 */
	public static function transient( string $name ): string {
		return self::PREFIX . ':transient:' . trim( $name );
	}
}
