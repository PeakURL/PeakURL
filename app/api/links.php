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

use PeakURL\Includes\PeakURL_DB;
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
	 * Create a new links API.
	 *
	 * @param PeakURL_DB $db Shared database wrapper.
	 * @since 1.0.0
	 */
	public function __construct( PeakURL_DB $db ) {
		$this->db = $db;
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
	 * Query URL rows with click stats for listing and export surfaces.
	 *
	 * @param string               $where      Prepared WHERE clause.
	 * @param array<string, mixed> $params     Query parameters.
	 * @param string               $sort_by    Safe SQL sort column.
	 * @param string               $sort_order Safe SQL sort direction.
	 * @param int|null             $limit      Optional LIMIT value.
	 * @param int|null             $offset     Optional OFFSET value.
	 * @return array<int, array<string, mixed>> Raw URL rows with click stats.
	 * @since 1.1.1
	 */
	public function query_link_rows(
		string $where,
		array $params,
		string $sort_by,
		string $sort_order,
		?int $limit = null,
		?int $offset = null
	): array {
		$sql = $this->get_links_select_sql() .
			' ' .
			$where .
			Query::order_by_clause( $sort_by, $sort_order );

		if ( null !== $limit && null !== $offset ) {
			$sql .= Query::limit_offset_clause( $limit, $offset );
		}

		return $this->db->get_results( $sql, $params );
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
	 * Used by the public redirect flow so password protection and expiry can be
	 * enforced after the row is loaded.
	 *
	 * @param string $short_code Sanitised short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function get_link_access_row( string $short_code ): ?array {
		$row = $this->db->get_row(
			'SELECT * FROM urls
			WHERE short_code = :short_code OR alias = :alias
			LIMIT 1',
			array(
				'short_code' => $short_code,
				'alias'      => $short_code,
			),
		);

		return $row ? $row : null;
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
	 * @return string SQL SELECT fragment ending before WHERE/ORDER clauses.
	 * @since 1.1.1
	 */
	private function get_links_select_sql(): string {
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
				GROUP BY url_id
			) stats ON stats.url_id = u.id';
	}
}
