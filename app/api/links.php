<?php
/**
 * Links data API.
 *
 * Centralises common links-table lookups so URL and analytics code can rely
 * on one small module for raw row access and duplicate-short-code checks.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Api;

use PeakURL\Includes\Constants;
use PeakURL\Includes\PeakURL_DB;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Cache\CacheKey;
use PeakURL\Utils\Query;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * LinksApi — low-level URL row query helper.
 *
 * @since 1.0.0
 */
class LinksApi {

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Optional transient/object cache driver.
	 *
	 * @var CacheInterface|null
	 * @since 1.6.0
	 */
	private ?CacheInterface $cache = null;

	/**
	 * Create a new links API.
	 *
	 * @param PeakURL_DB          $db    Shared database wrapper.
	 * @param CacheInterface|null $cache Optional cache driver instance.
	 * @since 1.0.0
	 */
	public function __construct( PeakURL_DB $db, ?CacheInterface $cache = null ) {
		$this->db    = $db;
		$this->cache = $cache;
	}

	/**
	 * Find a URL row by ID, short code, or alias, including click stats.
	 *
	 * @param string $identifier URL row ID, short code, or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function get_link_by_identifier( string $identifier ): ?array {
		return $this->db->get_row(
			$this->get_links_select_sql() .
				' WHERE u.id = :url_id OR u.short_code = :short_code OR u.alias = :alias
				LIMIT 1',
			array(
				'url_id'     => $identifier,
				'short_code' => $identifier,
				'alias'      => $identifier,
			),
		);
	}

	/**
	 * Count URL rows for a prepared listing query.
	 *
	 * @param string               $where  Prepared WHERE clause.
	 * @param array<string, mixed> $params Query parameters.
	 * @return int Total matching rows.
	 * @since 1.1.1
	 */
	public function count_links_for_listing(
		string $where,
		array $params
	): int {
		return (int) $this->db->get_var(
			'SELECT COUNT(*)
			FROM urls u
			' . $where,
			$params,
		);
	}

	/**
	 * Aggregate URL listing click, unique visitor, and active link totals.
	 *
	 * @param string               $where        Prepared WHERE clause.
	 * @param array<string, mixed> $params       Query parameters.
	 * @param array<string, mixed> $stats_params Optional click-stat query bounds.
	 * @return array{totalClicks: int, uniqueClicks: int, activeLinks: int}
	 * @since 1.5.2
	 */
	public function aggregate_link_stats(
		string $where,
		array $params,
		array $stats_params = array()
	): array {
		$sql = 'SELECT
				COUNT(c.id) AS total_clicks,
				COUNT(DISTINCT COALESCE(NULLIF(c.visitor_hash, \'\'), c.id)) AS unique_clicks
			FROM urls u
			INNER JOIN clicks c ON c.url_id = u.id
			' . $where;

		$conditions = array();
		if ( isset( $stats_params['stats_start_at'] ) ) {
			$conditions[] = 'c.clicked_at >= :stats_start_at';
		}
		if ( isset( $stats_params['stats_end_at'] ) ) {
			$conditions[] = 'c.clicked_at < :stats_end_at';
		}

		if ( ! empty( $conditions ) ) {
			$sql .= ( '' === $where ? ' WHERE ' : ' AND ' ) . implode( ' AND ', $conditions );
		}

		$click_stats  = $this->db->get_row( $sql, array_merge( $params, $stats_params ) ) ?? array();
		$active_sql   = 'SELECT SUM(CASE WHEN u.status = \'active\' THEN 1 ELSE 0 END) AS active_links FROM urls u ' . $where;
		$active_stats = $this->db->get_row( $active_sql, $params ) ?? array();

		return array(
			'totalClicks'  => (int) ( $click_stats['total_clicks'] ?? 0 ),
			'uniqueClicks' => (int) ( $click_stats['unique_clicks'] ?? 0 ),
			'activeLinks'  => (int) ( $active_stats['active_links'] ?? 0 ),
		);
	}

	/**
	 * Aggregate URL listing click and unique visitor totals for a bounded period.
	 *
	 * @param string               $where        Prepared WHERE clause.
	 * @param array<string, mixed> $params       Query parameters.
	 * @param array<string, mixed> $stats_params Bounded period parameters.
	 * @return array{totalClicks: int, uniqueClicks: int}
	 * @since 1.5.2
	 */
	public function aggregate_link_clicks(
		string $where,
		array $params,
		array $stats_params
	): array {
		$sql = 'SELECT
				COUNT(c.id) AS total_clicks,
				COUNT(DISTINCT COALESCE(NULLIF(c.visitor_hash, \'\'), c.id)) AS unique_clicks
			FROM urls u
			INNER JOIN clicks c ON c.url_id = u.id
			' . $where;

		$conditions = array();
		if ( isset( $stats_params['stats_start_at'] ) ) {
			$conditions[] = 'c.clicked_at >= :stats_start_at';
		}
		if ( isset( $stats_params['stats_end_at'] ) ) {
			$conditions[] = 'c.clicked_at < :stats_end_at';
		}

		if ( ! empty( $conditions ) ) {
			$sql .= ( '' === $where ? ' WHERE ' : ' AND ' ) . implode( ' AND ', $conditions );
		}

		$stats = $this->db->get_row( $sql, array_merge( $params, $stats_params ) ) ?? array();

		return array(
			'totalClicks'  => (int) ( $stats['total_clicks'] ?? 0 ),
			'uniqueClicks' => (int) ( $stats['unique_clicks'] ?? 0 ),
		);
	}

	/**
	 * Query URL rows with click stats for listing and export surfaces.
	 *
	 * @param string               $where      Prepared WHERE clause.
	 * @param array<string, mixed> $params     Query parameters.
	 * @param string               $sort_by    Safe SQL sort column.
	 * @param string               $sort_order Safe SQL sort direction.
	 * @param int|null             $limit      Optional LIMIT value.
	 * @param int|null             $offset     Optional OFFSET value.
	 * @param array<string, mixed> $stats_params Optional click-stat query bounds.
	 * @return array<int, array<string, mixed>> Raw URL rows with click stats.
	 * @since 1.1.1
	 */
	public function query_link_rows(
		string $where,
		array $params,
		string $sort_by,
		string $sort_order,
		?int $limit = null,
		?int $offset = null,
		array $stats_params = array()
	): array {
		$sql = $this->get_links_select_sql( $stats_params ) .
			' ' .
			$where .
			Query::order_by_clause( $sort_by, $sort_order );

		if ( null !== $limit && null !== $offset ) {
			$sql .= Query::limit_offset_clause( $limit, $offset );
		}

		return $this->db->get_results(
			$sql,
			array_merge( $params, $stats_params ),
		);
	}

	/**
	 * Find a public-facing active URL row by short code or alias.
	 *
	 * @param string $short_code Sanitised short code or alias.
	 * @param string $now        Current UTC timestamp.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function get_public_link_by_code(
		string $short_code,
		string $now
	): ?array {
		$row = $this->db->get_row(
			'SELECT * FROM urls
			WHERE (short_code = :short_code OR alias = :alias)
			AND status = :status
			AND (expires_at IS NULL OR expires_at > :now)
			LIMIT 1',
			array(
				'short_code' => $short_code,
				'alias'      => $short_code,
				'status'     => 'active',
				'now'        => $now,
			),
		);

		return $row ? $row : null;
	}

	/**
	 * Find a public-facing URL row by short code or alias without access filtering.
	 *
	 * Uses the active cache layer before querying MySQL, with expiration-aware TTL
	 * and brief negative caching for missing codes.
	 *
	 * @param string $short_code Sanitised short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function get_link_access_row( string $short_code ): ?array {
		$normalized = CacheKey::normalize_identifier( $short_code );

		// 1. Check object cache.
		if ( null !== $this->cache ) {
			$lookup_key = CacheKey::link_lookup( $normalized );
			$cached     = $this->cache->get( $lookup_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			// 2. Check negative cache.
			$missing_key = CacheKey::link_missing( $normalized );
			if ( $this->cache->has( $missing_key ) ) {
				return null;
			}
		}

		// 3. Fallback to database lookup.
		$row = $this->db->get_row(
			'SELECT * FROM urls
			WHERE short_code = :short_code OR alias = :alias
			LIMIT 1',
			array(
				'short_code' => $short_code,
				'alias'      => $short_code,
			),
		);

		// 4. Populate cache on result.
		if ( null !== $this->cache ) {
			if ( $row ) {
				$ttl = Constants::CACHE_LINK_TTL;
				if ( ! empty( $row['expires_at'] ) ) {
					$remaining = strtotime( (string) $row['expires_at'] ) - time();
					$ttl       = min( $ttl, max( 1, $remaining ) );
				}

				// Cache by normalized lookup key and canonical identifier keys.
				$this->cache->set( CacheKey::link_lookup( $normalized ), $row, $ttl );
				if ( ! empty( $row['short_code'] ) ) {
					$this->cache->set( CacheKey::link_lookup( (string) $row['short_code'] ), $row, $ttl );
				}
				if ( ! empty( $row['alias'] ) ) {
					$this->cache->set( CacheKey::link_lookup( (string) $row['alias'] ), $row, $ttl );
				}
				if ( ! empty( $row['id'] ) ) {
					$this->cache->set( CacheKey::link_id( (string) $row['id'] ), $row, $ttl );
				}

				// Clear negative cache key if one existed.
				$this->cache->delete( CacheKey::link_missing( $normalized ) );
			} else {
				// Cache negative lookup briefly to mitigate bot scans.
				$this->cache->set( CacheKey::link_missing( $normalized ), true, Constants::CACHE_NEGATIVE_TTL );
			}
		}

		return $row ? $row : null;
	}

	/**
	 * Invalidate all cached entries associated with a link row or identifiers.
	 *
	 * @param array<string, mixed>|string $link_or_id Link record array or single identifier string.
	 * @return void
	 * @since 1.6.0
	 */
	public function invalidate_link_cache( array|string $link_or_id ): void {
		if ( null === $this->cache ) {
			return;
		}

		if ( is_string( $link_or_id ) ) {
			$this->cache->delete( CacheKey::link_lookup( $link_or_id ) );
			$this->cache->delete( CacheKey::link_id( $link_or_id ) );
			$this->cache->delete( CacheKey::link_missing( $link_or_id ) );
			$this->cache->delete( CacheKey::dashboard_url( $link_or_id ) );
			return;
		}

		if ( ! empty( $link_or_id['id'] ) ) {
			$this->cache->delete( CacheKey::link_id( (string) $link_or_id['id'] ) );
			$this->cache->delete( CacheKey::dashboard_url( (string) $link_or_id['id'] ) );
		}

		if ( ! empty( $link_or_id['short_code'] ) ) {
			$this->cache->delete( CacheKey::link_lookup( (string) $link_or_id['short_code'] ) );
			$this->cache->delete( CacheKey::link_missing( (string) $link_or_id['short_code'] ) );
		}

		if ( ! empty( $link_or_id['alias'] ) ) {
			$this->cache->delete( CacheKey::link_lookup( (string) $link_or_id['alias'] ) );
			$this->cache->delete( CacheKey::link_missing( (string) $link_or_id['alias'] ) );
		}
	}

	/**
	 * Determine whether a short code or alias already exists.
	 *
	 * @param string $short_code Candidate short code.
	 * @return bool True when the code is already used.
	 * @since 1.0.0
	 */
	public function short_code_exists( string $short_code ): bool {
		return (int) $this->db->get_var(
			'SELECT COUNT(*)
			FROM urls
			WHERE short_code = :short_code OR alias = :alias',
			array(
				'short_code' => $short_code,
				'alias'      => $short_code,
			),
		) > 0;
	}

	/**
	 * Build the shared URL row + click stats SELECT fragment.
	 *
	 * @param array<string, mixed> $stats_params Optional click-stat query bounds.
	 * @return string SQL SELECT fragment ending before WHERE/ORDER clauses.
	 * @since 1.1.1
	 */
	private function get_links_select_sql( array $stats_params = array() ): string {
		$stats_conditions = array();

		if ( isset( $stats_params['stats_start_at'] ) ) {
			$stats_conditions[] = 'clicked_at >= :stats_start_at';
		}

		if ( isset( $stats_params['stats_end_at'] ) ) {
			$stats_conditions[] = 'clicked_at < :stats_end_at';
		}

		$stats_where = ! empty( $stats_conditions )
			? 'WHERE ' . implode( ' AND ', $stats_conditions )
			: '';

		return 'SELECT
				u.*,
				COALESCE(stats.clicks, 0) AS click_count,
				COALESCE(stats.unique_clicks, 0) AS unique_click_count
			FROM urls u
			LEFT JOIN (
				SELECT
					url_id,
					COUNT(*) AS clicks,
					COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
				FROM clicks
				' . $stats_where . '
				GROUP BY url_id
			) stats ON stats.url_id = u.id';
	}
}
