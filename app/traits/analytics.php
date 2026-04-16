<?php
/**
 * Data store analytics trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Utils\Query;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AnalyticsTrait — dashboard and link analytics methods for Store.
 *
 * @since 1.0.0
 */
trait AnalyticsTrait {

	/**
	 * Dashboard analytics summary over a time window.
	 *
	 * Returns total clicks, unique visitors, top links, traffic series,
	 * and browser/device/referrer breakdowns.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param int     $days    Number of days to look back (default 7).
	 * @return array<string, mixed> Analytics summary payload.
	 * @since 1.0.0
	 */
	public function analytics_summary( Request $request, int $days = 7 ): array {
		$user            = $this->get_current_user( $request );
		$days            = max( 1, $days );
		$window          = $this->build_time_window( $days );
		$link_conditions = array();
		$link_params     = array();
		$this->add_link_visibility_scope(
			$user,
			$link_conditions,
			$link_params,
			'u',
		);
		$total_links      = (int) $this->query_value(
			'SELECT COUNT(*) FROM urls u ' .
			( ! empty( $link_conditions )
				? 'WHERE ' . implode( ' AND ', $link_conditions )
				: '' ),
			$link_params,
		);
		$click_join       = '';
		$click_conditions = array( 'c.clicked_at >= :start_at' );
		$click_params     = array( 'start_at' => $window['start_at'] );
		$this->add_click_analytics_scope(
			$user,
			$click_join,
			$click_conditions,
			$click_params,
			'c',
			'u',
		);
		$stats =
			$this->query_one(
				'SELECT
	                COUNT(*) AS total_clicks,
	                COUNT(DISTINCT COALESCE(NULLIF(c.visitor_hash, \'\'), c.id)) AS unique_clicks
	            FROM clicks c' .
				$click_join .
				' WHERE ' .
				implode( ' AND ', $click_conditions ),
				$click_params,
			) ?? array();

		$unique_click_rate = $this->calculate_unique_click_rate(
			(int) ( $stats['total_clicks'] ?? 0 ),
			(int) ( $stats['unique_clicks'] ?? 0 ),
		);

		return array(
			'totalClicks'      => (int) ( $stats['total_clicks'] ?? 0 ),
			'totalLinks'       => $total_links,
			'uniqueClicks'     => (int) ( $stats['unique_clicks'] ?? 0 ),
			'uniqueClickRate'  => $unique_click_rate,
			'conversionRate'   => $unique_click_rate,
			'devices'          => $this->group_click_metrics(
				'device',
				'name',
				$window['start_at'],
				null,
				null,
				$user,
			),
			'browsers'         => $this->group_click_metrics(
				'browser',
				'name',
				$window['start_at'],
				null,
				null,
				$user,
			),
			'operatingSystems' => $this->group_click_metrics(
				'operating_system',
				'name',
				$window['start_at'],
				null,
				null,
				$user,
			),
			'countries'        => $this->group_click_metrics(
				'country_name',
				'name',
				$window['start_at'],
				'country_code',
				null,
				$user,
			),
			'traffic'          => $this->build_traffic_series(
				null,
				$days,
				$user,
			),
		);
	}

	/**
	 * Recent activity feed.
	 *
	 * Returns the last 12 activity events.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<int, array<string, mixed>> Activity event list.
	 * @since 1.0.0
	 */
	public function activity( Request $request ): array {
		$query = $this->build_activity_listing_query( $request );
		$sql   =
			$this->activity_select_sql() . ' ' .
			$query['from'] .
			$query['where'] .
			' ORDER BY a.created_at DESC LIMIT 12';

		return array_map(
			fn( array $row ): array => $this->hydrate_activity_row( $row ),
			$this->query_all( $sql, $query['params'] ),
		);
	}

	/**
	 * Paginated activity history for the dedicated dashboard page.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Query parameters for pagination.
	 * @return array<string, mixed> Paginated activity items with meta.
	 * @since 1.0.0
	 */
	public function activity_history( Request $request, array $query ): array {
		$pagination = Query::pagination( $query, 25, 250 );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];
		$listing    = $this->build_activity_listing_query( $request, $query );
		$total      = (int) $this->query_value(
			'SELECT COUNT(*) ' . $listing['from'] . $listing['where'],
			$listing['params'],
		);
		$rows       = $this->query_all(
			$this->activity_select_sql() . ' ' .
			$listing['from'] .
			$listing['where'] .
			' ORDER BY a.created_at DESC' .
			Query::limit_offset_clause( $limit, $offset ),
			$listing['params'],
		);

		return array(
			'items' => array_map(
				fn( array $row ): array => $this->hydrate_activity_row( $row ),
				$rows,
			),
			'meta'  => array(
				'page'       => $page,
				'limit'      => $limit,
				'totalItems' => $total,
				'totalPages' => max( 1, (int) ceil( $total / $limit ) ),
			),
		);
	}

	/**
	 * Delete a single audit-log row (admin only).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Audit-log row ID.
	 * @return bool True when the row was deleted.
	 *
	 * @throws ApiException When the ID is empty (422).
	 * @since 1.0.6
	 */
	public function delete_activity_log(
		Request $request,
		string $id
	): bool {
		$id = trim( $id );

		if ( '' === $id ) {
			throw new ApiException(
				__( 'Activity log ID is required.', 'peakurl' ),
				422,
			);
		}

		$this->assert_admin_request( $request );

		return $this->db->delete(
			'audit_logs',
			array(
				'id' => $id,
			),
		) > 0;
	}

	/**
	 * Delete multiple audit-log rows (admin only).
	 *
	 * @param Request            $request Incoming HTTP request.
	 * @param array<int, string> $ids     Audit-log row IDs.
	 * @return int Number of deleted rows.
	 *
	 * @since 1.0.6
	 */
	public function bulk_delete_activity_logs(
		Request $request,
		array $ids
	): int {
		$this->assert_admin_request( $request );
		$ids = Query::string_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		return $this->db->delete_where_in(
			'audit_logs',
			'id',
			$ids,
		);
	}

	/**
	 * Build the scoped FROM / WHERE clauses used by activity queries.
	 *
	 * Editors only see activity tied to their own user ID or links. Admins
	 * receive the full site-wide audit log.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Optional activity filters.
	 * @return array{from: string, where: string, params: array<string, string>}
	 * @since 1.0.0
	 */
	private function build_activity_listing_query(
		Request $request,
		array $query = array()
	): array {
		$user       = $this->get_current_user( $request );
		$category   = trim( (string) ( $query['category'] ?? '' ) );
		$from       = 'FROM audit_logs a LEFT JOIN users actor ON actor.id = a.user_id';
		$conditions = array();
		$params     = array();

		if ( 'links' === $category ) {
			$conditions[] = "(LEFT(a.type, 5) = 'link_' OR a.type = 'click')";
		} elseif ( 'users' === $category ) {
			$conditions[] = "LEFT(a.type, 5) = 'user_'";
		}

		if ( ! $this->roles->user_can( $user, 'view_site_analytics' ) ) {
			if ( ! $this->roles->user_can( $user, 'view_own_analytics' ) ) {
				throw new ApiException(
					'You do not have permission to view activity.',
					403,
				);
			}

			$from                            .= ' LEFT JOIN urls u ON u.id = a.link_id';
			$conditions[]                     = '(a.user_id = :scope_user_id_activity OR u.user_id = :scope_user_id_link)';
			$params['scope_user_id_activity'] = (string) $user['id'];
			$params['scope_user_id_link']     = (string) $user['id'];
		}

		return array(
			'from'   => $from,
			'where'  => ! empty( $conditions )
				? ' WHERE ' . implode( ' AND ', $conditions )
				: '',
			'params' => $params,
		);
	}

	/**
	 * Build the shared SELECT clause for activity queries.
	 *
	 * @return string
	 * @since 1.0.4
	 */
	private function activity_select_sql(): string {
		return 'SELECT a.*,
			actor.first_name AS actor_first_name,
			actor.last_name AS actor_last_name,
			actor.username AS actor_username,
			actor.email AS actor_email,
			actor.role AS actor_role';
	}

	/**
	 * Per-link time-series click statistics.
	 *
	 * Includes daily click counts, traffic series, and metric breakdowns.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @param int     $days    Number of days to look back.
	 * @return array<string, mixed>|null Link stats or null if not found.
	 * @since 1.0.0
	 */
	public function link_stats(
		Request $request,
		string $id,
		int $days = 7
	): ?array {
		$user = $this->get_current_user( $request );
		$url  = $this->find_url_row( $id );

		if ( ! $url ) {
			return null;
		}

		$this->assert_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$days   = max( 1, $days );
		$window = $this->build_time_window( $days );
		$totals =
			$this->query_one(
				'SELECT
	                COUNT(*) AS total_clicks,
	                COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
	            FROM clicks
	            WHERE url_id = :url_id
	            AND clicked_at >= :start_at',
				array(
					'url_id'   => $url['id'],
					'start_at' => $window['start_at'],
				),
			) ?? array();

		$unique_click_rate = $this->calculate_unique_click_rate(
			(int) ( $totals['total_clicks'] ?? 0 ),
			(int) ( $totals['unique_clicks'] ?? 0 ),
		);

		return array(
			'totalClicks'        => (int) ( $totals['total_clicks'] ?? 0 ),
			'uniqueClicks'       => (int) ( $totals['unique_clicks'] ?? 0 ),
			'uniqueClickRate'    => $unique_click_rate,
			'conversionRate'     => $unique_click_rate,
			'traffic'            => $this->build_traffic_series(
				(string) $url['id'],
				$days,
			),
			'devices'            => $this->group_click_metrics(
				'device',
				'name',
				$window['start_at'],
				null,
				(string) $url['id'],
			),
			'browsers'           => $this->group_click_metrics(
				'browser',
				'name',
				$window['start_at'],
				null,
				(string) $url['id'],
			),
			'operatingSystems'   => $this->group_click_metrics(
				'operating_system',
				'name',
				$window['start_at'],
				null,
				(string) $url['id'],
			),
			'referrers'          => $this->group_referrers(
				(string) $url['id'],
				$window['start_at'],
			),
			'referrerCategories' => $this->group_referrer_categories(
				(string) $url['id'],
				$window['start_at'],
			),
			'utmCampaigns'       => $this->group_utm_campaigns(
				(string) $url['id'],
				$window['start_at'],
			),
		);
	}

	/**
	 * Per-link geographic location analytics.
	 *
	 * Returns country and city breakdowns for clicks on a specific short link.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @return array<string, mixed>|null Location data or null if not found.
	 * @since 1.0.0
	 */
	public function link_location( Request $request, string $id ): ?array {
		$user = $this->get_current_user( $request );
		$url  = $this->query_one(
			'SELECT id, user_id FROM urls
	            WHERE id = :url_id OR short_code = :short_code OR alias = :alias
	            LIMIT 1',
			array(
				'url_id'     => $id,
				'short_code' => $id,
				'alias'      => $id,
			),
		);

		if ( ! $url ) {
			return null;
		}

		$this->assert_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$private_network_condition = $this->private_network_ip_sql(
			'ip_address',
		);

		$countries = $this->query_all(
			sprintf(
				'SELECT
	                CASE
	                    WHEN NULLIF(country_code, \'\') IS NOT NULL THEN country_code
	                    WHEN %1$s THEN \'LOCAL\'
	                    ELSE \'??\'
	                END AS code,
	                CASE
	                    WHEN NULLIF(country_name, \'\') IS NOT NULL THEN country_name
	                    WHEN NULLIF(country_code, \'\') IS NOT NULL THEN country_code
	                    WHEN %1$s THEN \'Local / Private Network\'
	                    ELSE \'Unknown\'
	                END AS name,
	                COUNT(*) AS count
	            FROM clicks
	            WHERE url_id = :url_id
	            GROUP BY code, name
	            ORDER BY count DESC, name ASC',
				$private_network_condition,
			),
			array( 'url_id' => $url['id'] ),
		);
		$cities    = $this->query_all(
			sprintf(
				'SELECT
	                CASE
	                    WHEN NULLIF(city_name, \'\') IS NOT NULL THEN city_name
	                    WHEN %1$s THEN \'Local / Private Network\'
	                    ELSE \'Unknown\'
	                END AS name,
	                CASE
	                    WHEN NULLIF(country_name, \'\') IS NOT NULL THEN country_name
	                    WHEN NULLIF(country_code, \'\') IS NOT NULL THEN country_code
	                    WHEN %1$s THEN \'Local / Private Network\'
	                    ELSE \'Unknown\'
	                END AS country,
	                COUNT(*) AS count
	            FROM clicks
	            WHERE url_id = :url_id
	            GROUP BY name, country
	            ORDER BY count DESC, name ASC
	            LIMIT 20',
				$private_network_condition,
			),
			array( 'url_id' => $url['id'] ),
		);
		$total     = (int) $this->query_value(
			'SELECT COUNT(*) FROM clicks WHERE url_id = :url_id',
			array( 'url_id' => $url['id'] ),
		);

		return array(
			'countries'   => array_map(
				static fn( array $row ): array => array(
					'code'  => (string) $row['code'],
					'name'  => (string) $row['name'],
					'count' => (int) $row['count'],
				),
				$countries,
			),
			'cities'      => array_map(
				static fn( array $row ): array => array(
					'name'    => (string) $row['name'],
					'country' => (string) $row['country'],
					'count'   => (int) $row['count'],
				),
				$cities,
			),
			'totalClicks' => $total,
		);
	}
}
