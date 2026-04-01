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
			'SELECT
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
            ) stats ON stats.url_id = u.id
            WHERE u.id = :url_id OR u.short_code = :short_code OR u.alias = :alias
            LIMIT 1',
			array(
				'url_id'     => $identifier,
				'short_code' => $identifier,
				'alias'      => $identifier,
			),
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
	 * Used by the public redirect flow so password protection and expiry can be
	 * enforced after the row is loaded.
	 *
	 * @param string $short_code Sanitised short code or alias.
	 * @return array<string, mixed>|null URL row or null.
	 * @since 1.0.0
	 */
	public function get_public_link_access_row( string $short_code ): ?array {
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
}
