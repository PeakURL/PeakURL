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
	 * Dashboard analytics summary over a selected period.
	 *
	 * Returns current totals, last-month totals, traffic series,
	 * and browser/device/referrer breakdowns.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param int     $days    Number of days to look back (default 7).
	 * @return array<string, mixed> Analytics summary payload.
	 * @since 1.0.0
	 */
	public function analytics_summary( Request $request, int $days = 7 ): array {
		$user              = $this->get_current_user( $request );
		$days              = max( 1, $days );
		$period            = $this->get_analytics_period( $days );
		$last_month_period = $this->get_last_month_period( $period, $days );
		$stats             = $this->get_dashboard_click_totals(
			$user,
			$period['start_at'],
		);
		$last_month_stats  = $this->get_dashboard_click_totals(
			$user,
			$last_month_period['start_at'],
			$last_month_period['end_at'],
		);
		$total_links       = $this->get_dashboard_link_count( $user );
		$last_month_links  = $this->get_dashboard_link_count(
			$user,
			$last_month_period['end_at'],
		);

		return array(
			'totalClicks'              => $stats['totalClicks'],
			'lastMonthTotalClicks'     => $last_month_stats['totalClicks'],
			'totalLinks'               => $total_links,
			'lastMonthTotalLinks'      => $last_month_links,
			'uniqueClicks'             => $stats['uniqueClicks'],
			'lastMonthUniqueClicks'    => $last_month_stats['uniqueClicks'],
			'uniqueClickRate'          => $stats['uniqueClickRate'],
			'lastMonthUniqueClickRate' => $last_month_stats['uniqueClickRate'],
			'lastMonth'                => array(
				'type'      => $last_month_period['type'],
				'days'      => $last_month_period['days'],
				'startDate' => $last_month_period['start_date'],
				'endDate'   => $last_month_period['end_date'],
			),
			'devices'                  => $this->group_click_metrics(
				'device',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'browsers'                 => $this->group_click_metrics(
				'browser',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'operatingSystems'         => $this->group_click_metrics(
				'operating_system',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'countries'                => $this->group_click_metrics(
				'country_name',
				'name',
				$period['start_at'],
				null,
				'country_code',
				null,
				$user,
				null,
			),
			'traffic'                  => $this->query_traffic_series(
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
		$query = $this->prepare_activity_query( $request );
		$sql   =
			$this->activity_select_sql() . ' ' .
			$query['from'] .
			$query['where'] .
			' ORDER BY a.created_at DESC LIMIT 12';

		return array_map(
			fn( array $row ): array => $this->format_activity( $row ),
			$this->query_all( $sql, $query['params'] ),
		);
	}

	/**
	 * Recent click feed for the dashboard overview.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param int     $limit   Maximum click rows to return.
	 * @return array<int, array<string, mixed>> Recent click rows.
	 * @since 1.2.1
	 */
	public function recent_clicks( Request $request, int $limit = 8 ): array {
		$user          = $this->get_current_user( $request );
		$default_limit = 8;
		$max_limit     = 20;
		$limit         = max(
			1,
			min( $max_limit, $limit > 0 ? $limit : $default_limit ),
		);
		$conditions    = array();
		$params        = array();

		$this->scope_click_analytics_visibility(
			$user,
			$conditions,
			$params,
			'u',
		);

		$where = ! empty( $conditions )
			? ' WHERE ' . implode( ' AND ', $conditions )
			: '';

		$rows = $this->query_all(
			'SELECT
				c.id AS recent_click_id,
				c.clicked_at AS recent_clicked_at,
				c.country_name AS click_country_name,
				c.city_name AS click_city_name,
				c.device AS click_device,
				c.browser AS click_browser,
				c.operating_system AS click_operating_system,
				c.referrer_name AS click_referrer_name,
				c.referrer_domain AS click_referrer_domain,
				u.*,
				COALESCE(stats.clicks, 0) AS click_count,
				COALESCE(stats.unique_clicks, 0) AS unique_click_count
			FROM clicks c
			INNER JOIN urls u ON u.id = c.url_id
			LEFT JOIN (
				SELECT
					url_id,
					COUNT(*) AS clicks,
					COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
				FROM clicks
				GROUP BY url_id
			) stats ON stats.url_id = u.id' .
			$where .
			' ORDER BY c.clicked_at DESC' .
			Query::limit_offset_clause( $limit, 0 ),
			$params,
		);

		return array_map(
			fn( array $row ): array => $this->format_recent_click( $row ),
			$rows,
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
		$pagination = Query::pagination( $query, 25 );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];
		$listing    = $this->prepare_activity_query( $request, $query );
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
				fn( array $row ): array => $this->format_activity( $row ),
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

		$this->get_admin_user( $request );

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
		$this->get_admin_user( $request );
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
	 * Delete all audit-log rows (admin only).
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Number of deleted rows.
	 * @since 1.5.3
	 */
	public function clear_activity_logs( Request $request ): int {
		$this->get_admin_user( $request );

		return $this->db->truncate( 'audit_logs' );
	}

	/**
	 * Get the scoped FROM / WHERE clauses used by activity queries.
	 *
	 * Editors only see activity tied to their own user ID or links. Admins
	 * receive the full site-wide audit log.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Optional activity filters.
	 * @return array{from: string, where: string, params: array<string, string>}
	 * @since 1.0.0
	 */
	private function prepare_activity_query(
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

		if ( ! $this->roles->has_capability( $user, 'view_site_analytics' ) ) {
			if ( ! $this->roles->has_capability( $user, 'view_own_analytics' ) ) {
				throw new ApiException(
					__(
						'You do not have permission to view activity.',
						'peakurl',
					),
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
	 * Get the shared SELECT clause for activity queries.
	 *
	 * @return string
	 * @since 1.0.4
	 */
	private function activity_select_sql(): string {
		return 'SELECT a.*,
			actor.first_name AS actor_first_name,
			actor.last_name AS actor_last_name,
			actor.display_name AS actor_display_name,
			actor.username AS actor_username,
			actor.email AS actor_email,
			actor.role AS actor_role';
	}

	/**
	 * Format a raw recent-click row for the dashboard.
	 *
	 * @param array<string, mixed> $row Raw click and link row.
	 * @return array<string, mixed> API-ready recent click item.
	 * @since 1.2.1
	 */
	private function format_recent_click( array $row ): array {
		$country = trim( (string) ( $row['click_country_name'] ?? '' ) );
		$city    = trim( (string) ( $row['click_city_name'] ?? '' ) );

		return array(
			'id'              => (string) $row['recent_click_id'],
			'clickedAt'       => $this->to_iso( (string) $row['recent_clicked_at'] ),
			'link'            => $this->format_url( $row ),
			'location'        => array(
				'country' => '' !== $country ? $country : null,
				'city'    => '' !== $city ? $city : null,
			),
			'device'          => trim( (string) ( $row['click_device'] ?? '' ) ),
			'browser'         => trim( (string) ( $row['click_browser'] ?? '' ) ),
			'operatingSystem' => trim( (string) ( $row['click_operating_system'] ?? '' ) ),
			'referrer'        => array(
				'name'   => trim( (string) ( $row['click_referrer_name'] ?? '' ) ),
				'domain' => trim( (string) ( $row['click_referrer_domain'] ?? '' ) ),
			),
		);
	}

	/**
	 * Per-link time-series click statistics.
	 *
	 * Includes daily click counts, traffic series, and metric breakdowns.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param string  $id      Short-URL row ID.
	 * @param string|null $range            Requested dashboard range token.
	 * @param string|null $custom_date_from Custom start date in YYYY-MM-DD format.
	 * @param string|null $custom_date_to   Custom end date in YYYY-MM-DD format.
	 * @return array<string, mixed>|null Link stats or null if not found.
	 * @since 1.0.0
	 */
	public function link_stats(
		Request $request,
		string $id,
		?string $range = null,
		?string $custom_date_from = null,
		?string $custom_date_to = null
	): ?array {
		$user = $this->get_current_user( $request );
		$url  = $this->find_url_row( $id );

		if ( ! $url ) {
			return null;
		}

		$this->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$stats_period     = $this->get_link_stats_period(
			$range,
			$custom_date_from,
			$custom_date_to,
			(string) ( $url['created_at'] ?? '' ),
		);
		$url_id           = (string) $url['id'];
		$period_start_at  = $stats_period['start_at'];
		$period_end_at    = $stats_period['end_at'];
		$metric_start_at  = $period_start_at ?? '1000-01-01 00:00:00';
		$total_conditions = array( 'url_id = :url_id' );
		$total_params     = array( 'url_id' => $url_id );
		// Reuse the active-day history for both best-day and full history output.
		$click_days = $this->get_link_click_history_days( $url_id );

		if ( null !== $period_start_at ) {
			$total_conditions[]       = 'clicked_at >= :start_at';
			$total_params['start_at'] = $period_start_at;
		}

		if ( null !== $period_end_at ) {
			$total_conditions[]     = 'clicked_at < :end_at';
			$total_params['end_at'] = $period_end_at;
		}

		$totals =
			$this->query_one(
				'SELECT
	                COUNT(*) AS total_clicks,
	                COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
	            FROM clicks
	            WHERE ' . implode( ' AND ', $total_conditions ),
				$total_params,
			) ?? array();

		$unique_click_rate = $this->get_unique_click_rate(
			(int) ( $totals['total_clicks'] ?? 0 ),
			(int) ( $totals['unique_clicks'] ?? 0 ),
		);
		$range_payload     = array(
			'key'  => $stats_period['key'],
			'days' => $stats_period['days'],
		);

		if ( 'custom' === $stats_period['key'] ) {
			$range_payload['from'] = $stats_period['start_date'];
			$range_payload['to']   = $stats_period['end_date'];
		}

		return array(
			'totalClicks'        => (int) ( $totals['total_clicks'] ?? 0 ),
			'uniqueClicks'       => (int) ( $totals['unique_clicks'] ?? 0 ),
			'uniqueClickRate'    => $unique_click_rate,
			'range'              => $range_payload,
			'traffic'            => 'custom' === $stats_period['key']
				? $this->query_traffic_series_range(
					$url_id,
					(string) $stats_period['start_date'],
					(int) $stats_period['days'],
					(string) $period_start_at,
					(string) $period_end_at,
					$this->get_analytics_timezone()->getName(),
					null,
					$this->get_traffic_series_granularity(
						(int) $stats_period['days'],
					),
				)
				: ( 'all' === $stats_period['key']
					? $this->query_traffic_series_range(
						$url_id,
						(string) $stats_period['start_date'],
						(int) $stats_period['days'],
						(string) ( $stats_period['series_start_at'] ?? '1000-01-01 00:00:00' ),
						null,
						$this->get_analytics_timezone()->getName(),
						null,
						$this->get_traffic_series_granularity(
							(int) $stats_period['days'],
						),
					)
					: $this->query_traffic_series(
						$url_id,
						$stats_period['days'],
					) ),
			'periodSummaries'    => $this->get_link_period_summaries(
				$url_id,
				(string) ( $url['created_at'] ?? '' ),
			),
			'bestDay'            => $this->get_link_best_day(
				$click_days,
			),
			'clickHistory'       => array(
				'activeDayCount' => count( $click_days ),
				'days'           => $click_days,
			),
			'devices'            => $this->group_click_metrics(
				'device',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'browsers'           => $this->group_click_metrics(
				'browser',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'operatingSystems'   => $this->group_click_metrics(
				'operating_system',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'referrers'          => $this->group_referrers(
				$url_id,
				$metric_start_at,
				$period_end_at,
			),
			'referrerCategories' => $this->group_referrer_categories(
				$url_id,
				$metric_start_at,
				$period_end_at,
			),
			'utmCampaigns'       => $this->group_utm_campaigns(
				$url_id,
				$metric_start_at,
				$period_end_at,
			),
		);
	}

	/**
	 * Per-link geographic location analytics.
	 *
	 * Returns country and city breakdowns for clicks on a specific short link.
	 *
	 * @param Request     $request          Incoming HTTP request.
	 * @param string      $id               Short-URL row ID.
	 * @param string|null $range            Optional timeframe key.
	 * @param string|null $custom_date_from Optional custom start date.
	 * @param string|null $custom_date_to   Optional custom end date.
	 * @return array<string, mixed>|null Location data or null if not found.
	 * @since 1.0.0
	 */
	public function link_location(
		Request $request,
		string $id,
		?string $range = null,
		?string $custom_date_from = null,
		?string $custom_date_to = null
	): ?array {
		$user = $this->get_current_user( $request );
		$url  = $this->query_one(
			'SELECT id, user_id, created_at FROM urls
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

		$this->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$stats_period    = $this->get_link_stats_period(
			$range,
			$custom_date_from,
			$custom_date_to,
			(string) ( $url['created_at'] ?? '' ),
		);
		$period_start_at = $stats_period['start_at'];
		$period_end_at   = $stats_period['end_at'];

		$conditions = array( 'url_id = :url_id' );
		$params     = array( 'url_id' => $url['id'] );

		if ( null !== $period_start_at ) {
			$conditions[]       = 'clicked_at >= :start_at';
			$params['start_at'] = $period_start_at;
		}

		if ( null !== $period_end_at ) {
			$conditions[]     = 'clicked_at < :end_at';
			$params['end_at'] = $period_end_at;
		}

		$where_clause              = implode( ' AND ', $conditions );
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
	            WHERE ' . $where_clause . '
	            GROUP BY code, name
	            ORDER BY count DESC, name ASC',
				$private_network_condition,
			),
			$params,
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
	            WHERE ' . $where_clause . '
	            GROUP BY name, country
	            ORDER BY count DESC, name ASC
	            LIMIT 20',
				$private_network_condition,
			),
			$params,
		);
		$total     = (int) $this->query_value(
			'SELECT COUNT(*) FROM clicks WHERE ' . $where_clause,
			$params,
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
