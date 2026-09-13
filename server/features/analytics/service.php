<?php
/**
 * Analytics domain service.
 *
 * Coordinates request authorization, period calculations, click aggregates,
 * and activity history for the analytics feature.
 *
 * @package PeakURL\Features\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Analytics;

use PeakURL\Api\LinksApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Database\Query;
use PeakURL\Services\Database\Sql;
use PeakURL\Utils\Date;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Service — Analytics coordination and business logic.
 *
 * @since 1.0.0
 */
class Service {

	/**
	 * Analytics repository instance.
	 *
	 * @var Repository
	 * @since 1.0.0
	 */
	private Repository $data;

	/**
	 * Shared database wrapper.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.0.0
	 */
	private AuthService $auth_service;

	/**
	 * Roles and capabilities registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Shared authorization helper.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Links data API for URL row lookups.
	 *
	 * @var LinksApi|null
	 * @since 1.0.0
	 */
	private ?LinksApi $links_api = null;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Create a new Analytics Service instance.
	 *
	 * @param Repository           $data          Analytics repository engine.
	 * @param PeakURL_DB           $db            Shared database wrapper.
	 * @param AuthService          $auth_service  Authentication domain service.
	 * @param Roles                $roles         Roles and capabilities registry.
	 * @param Authorization        $authorization Shared authorization helper.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @param LinksApi|null        $links_api     Optional links data API for URL lookups.
	 * @since 1.0.0
	 */
	public function __construct(
		Repository $data,
		PeakURL_DB $db,
		AuthService $auth_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		?LinksApi $links_api = null
	) {
		$this->data          = $data;
		$this->db            = $db;
		$this->auth_service  = $auth_service;
		$this->roles         = $roles;
		$this->authorization = $authorization;
		$this->config        = $config;
		$this->links_api     = $links_api;
	}

	/**
	 * Get the underlying data engine instance.
	 *
	 * @return Repository Analytics repository engine.
	 * @since 1.0.0
	 */
	public function get_data(): Repository {
		return $this->data;
	}

	/**
	 * Get the underlying repository instance.
	 *
	 * @return Repository Analytics repository engine.
	 * @since 1.6.3
	 */
	public function get_repository(): Repository {
		return $this->data;
	}

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
		$user              = $this->auth_service->get_current_user( $request );
		$days              = max( 1, $days );
		$period            = $this->data->get_analytics_period( $days );
		$last_month_period = $this->data->get_last_month_period( $period, $days );
		$stats             = $this->data->get_dashboard_click_totals(
			$user,
			$period['start_at'],
		);
		$last_month_stats  = $this->data->get_dashboard_click_totals(
			$user,
			$last_month_period['start_at'],
			$last_month_period['end_at'],
		);
		$total_links       = $this->data->get_dashboard_link_count( $user );
		$last_month_links  = $this->data->get_dashboard_link_count(
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
			'devices'                  => $this->data->group_click_metrics(
				'device',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'browsers'                 => $this->data->group_click_metrics(
				'browser',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'operatingSystems'         => $this->data->group_click_metrics(
				'operating_system',
				'name',
				$period['start_at'],
				null,
				null,
				null,
				$user,
			),
			'countries'                => $this->data->group_click_metrics(
				'country_name',
				'name',
				$period['start_at'],
				null,
				'country_code',
				null,
				$user,
				null,
			),
			'traffic'                  => $this->data->query_traffic_series(
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
		$user  = $this->auth_service->get_current_user( $request );
		$query = $this->data->prepare_activity_query( $user );
		$sql   =
			$this->data->activity_select_sql() . ' ' .
			$query['from'] .
			$query['where'] .
			' ORDER BY a.created_at DESC LIMIT 12';

		return array_map(
			fn( array $row ): array => $this->format_activity( $row ),
			$this->db->get_results( $sql, $query['params'] ),
		);
	}

	/**
	 * Recent click feed for the dashboard overview.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @param int     $limit   Maximum click rows to return.
	 * @return array<int, array<string, mixed>> Recent click rows.
	 * @since 1.0.0
	 */
	public function recent_clicks( Request $request, int $limit = 8 ): array {
		$user          = $this->auth_service->get_current_user( $request );
		$default_limit = 8;
		$max_limit     = 20;
		$limit         = max(
			1,
			min( $max_limit, $limit > 0 ? $limit : $default_limit ),
		);
		$conditions    = array();
		$params        = array();

		$this->data->filter_joined_clicks_by_user(
			$user,
			$conditions,
			$params,
			'u',
		);

		$where = ! empty( $conditions )
			? ' WHERE ' . implode( ' AND ', $conditions )
			: '';

		$rows = $this->db->get_results(
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
			fn( array $row ): array => $this->data->format_recent_click( $row ),
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
		$user       = $this->auth_service->get_current_user( $request );
		$pagination = Query::pagination( $query, 25 );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];
		$listing    = $this->data->prepare_activity_query( $user, $query );
		$total      = (int) $this->db->get_var(
			'SELECT COUNT(*) ' . $listing['from'] . $listing['where'],
			$listing['params'],
		);
		$rows       = $this->db->get_results(
			$this->data->activity_select_sql() . ' ' .
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
	 * @since 1.0.0
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

		$this->auth_service->get_admin_user( $request );

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
	 * @since 1.0.0
	 */
	public function bulk_delete_activity_logs(
		Request $request,
		array $ids
	): int {
		$this->auth_service->get_admin_user( $request );
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
	 * @since 1.0.0
	 */
	public function clear_activity_logs( Request $request ): int {
		$this->auth_service->get_admin_user( $request );

		return $this->db->truncate( 'audit_logs' );
	}

	/**
	 * Per-link time-series click statistics.
	 *
	 * Includes daily click counts, traffic series, and metric breakdowns.
	 *
	 * @param Request     $request          Incoming HTTP request.
	 * @param string      $id               Short-URL row ID.
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
		$user = $this->auth_service->get_current_user( $request );
		$url  = $this->links_api ? $this->links_api->get_link_by_identifier( $id ) : null;

		if ( ! $url ) {
			return null;
		}

		$this->authorization->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$stats_period     = $this->data->get_link_stats_period(
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
		$click_days       = $this->data->get_link_click_history_days( $url_id );

		if ( null !== $period_start_at ) {
			$total_conditions[]       = 'clicked_at >= :start_at';
			$total_params['start_at'] = $period_start_at;
		}

		if ( null !== $period_end_at ) {
			$total_conditions[]     = 'clicked_at < :end_at';
			$total_params['end_at'] = $period_end_at;
		}

		$totals =
			$this->db->get_row(
				'SELECT
					COUNT(*) AS total_clicks,
					COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
				FROM clicks
				WHERE ' . implode( ' AND ', $total_conditions ),
				$total_params,
			) ?? array();

		$unique_click_rate = $this->data->get_unique_click_rate(
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
				? $this->data->query_traffic_series_range(
					$url_id,
					(string) $stats_period['start_date'],
					(int) $stats_period['days'],
					(string) $period_start_at,
					(string) $period_end_at,
					$this->data->get_analytics_timezone()->getName(),
					null,
					$this->data->get_traffic_series_granularity(
						(int) $stats_period['days'],
					),
				)
				: ( 'all' === $stats_period['key']
					? $this->data->query_traffic_series_range(
						$url_id,
						(string) $stats_period['start_date'],
						(int) $stats_period['days'],
						(string) ( $stats_period['series_start_at'] ?? '1000-01-01 00:00:00' ),
						null,
						$this->data->get_analytics_timezone()->getName(),
						null,
						$this->data->get_traffic_series_granularity(
							(int) $stats_period['days'],
						),
					)
					: $this->data->query_traffic_series(
						$url_id,
						$stats_period['days'],
					) ),
			'periodSummaries'    => $this->data->get_link_period_summaries(
				$url_id,
				$stats_period,
				(int) ( $stats_period['days'] ?? 7 ),
				(string) ( $url['created_at'] ?? '' ),
			),
			'bestDay'            => $this->data->get_link_best_day(
				$click_days,
			),
			'clickHistory'       => array(
				'activeDayCount' => count( $click_days ),
				'days'           => $click_days,
			),
			'devices'            => $this->data->group_click_metrics(
				'device',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'browsers'           => $this->data->group_click_metrics(
				'browser',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'operatingSystems'   => $this->data->group_click_metrics(
				'operating_system',
				'name',
				$metric_start_at,
				$period_end_at,
				null,
				$url_id,
			),
			'referrers'          => $this->data->group_referrers(
				$url_id,
				$metric_start_at,
				$period_end_at,
			),
			'referrerCategories' => $this->data->group_referrer_categories(
				$url_id,
				$metric_start_at,
				$period_end_at,
			),
			'utmCampaigns'       => $this->data->group_utm_campaigns(
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
		$user = $this->auth_service->get_current_user( $request );
		$url  = $this->db->get_row(
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

		$this->authorization->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_own_analytics',
			'view_site_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
		);

		$stats_period    = $this->data->get_link_stats_period(
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
		$private_network_condition = Sql::private_network_ip_sql(
			'ip_address',
		);

		$countries = $this->db->get_results(
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
		$cities    = $this->db->get_results(
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
				ORDER BY count DESC, name ASC',
				$private_network_condition,
			),
			$params,
		);
		$total     = (int) $this->db->get_var(
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

	/**
	 * Restore a link from its activity log snapshot.
	 *
	 * If the link still exists in trash, restores its status to 'active'.
	 * Permanently deleted links cannot be restored.
	 *
	 * @param Request $request     Incoming HTTP request.
	 * @param string  $activity_id Activity log row ID.
	 * @return array<string, mixed> Restored or recreated link item.
	 *
	 * @throws ApiException When activity row is not found (404), invalid (422), or permanent (400).
	 * @since 1.0.0
	 */
	public function restore_activity_link( Request $request, string $activity_id ): array {
		$this->auth_service->get_current_user( $request );
		$row = $this->db->get_row_by(
			'audit_logs',
			array( 'id' => $activity_id ),
		);

		if ( ! $row ) {
			throw new ApiException(
				__( 'Activity record not found.', 'peakurl' ),
				404,
			);
		}

		$metadata  = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$metadata  = is_array( $metadata ) ? $metadata : array();
		$link_meta = is_array( $metadata['link'] ?? null ) ? $metadata['link'] : null;

		if ( ! $link_meta || empty( $link_meta['destinationUrl'] ) ) {
			throw new ApiException(
				__( 'No restorable link metadata found in this activity record.', 'peakurl' ),
				422,
			);
		}

		$link_id = trim( (string) ( $link_meta['id'] ?? '' ) );

		if ( '' !== $link_id ) {
			$existing_link = $this->db->get_row_by( 'urls', array( 'id' => $link_id ) );
			if ( $existing_link && $this->links_service ) {
				return $this->links_service->restore( $request, $link_id );
			}
		}

		throw new ApiException(
			__( 'This link was permanently deleted and cannot be restored.', 'peakurl' ),
			400,
		);
	}

	/**
	 * Format a raw audit-log row into an API-ready activity item.
	 *
	 * @param array<string, mixed> $row Raw audit log row.
	 * @return array<string, mixed> Formatted activity item.
	 * @since 1.0.0
	 */
	public function format_activity( array $row ): array {
		$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$actor    = $this->format_activity_person(
			array(
				'id'          => (string) ( $row['user_id'] ?? '' ),
				'firstName'   => (string) ( $row['actor_first_name'] ?? '' ),
				'lastName'    => (string) ( $row['actor_last_name'] ?? '' ),
				'displayName' => (string) ( $row['actor_display_name'] ?? '' ),
				'username'    => (string) ( $row['actor_username'] ?? '' ),
				'email'       => (string) ( $row['actor_email'] ?? '' ),
				'role'        => (string) ( $row['actor_role'] ?? '' ),
			),
		);

		$link_meta = is_array( $metadata['link'] ?? null ) ? $metadata['link'] : null;
		if ( is_array( $link_meta ) && isset( $link_meta['title'] ) && is_string( $link_meta['title'] ) ) {
			$link_meta['title'] = html_entity_decode( $link_meta['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$type          = (string) $row['type'];
		$link_status   = null;
		$is_restorable = false;

		if ( $link_meta && in_array( $type, array( 'link_trashed', 'link_deleted' ), true ) ) {
			$current_status = isset( $row['current_link_status'] ) ? (string) $row['current_link_status'] : null;
			if ( 'active' === $current_status ) {
				$link_status   = 'active';
				$is_restorable = false;
			} elseif ( 'trashed' === $current_status ) {
				$link_status   = 'trashed';
				$is_restorable = true;
			} else {
				$link_status   = 'deleted';
				$is_restorable = false;
			}
		} elseif ( 'link_restored' === $type ) {
			$link_status   = 'active';
			$is_restorable = false;
		}

		return array(
			'id'           => (string) $row['id'],
			'type'         => $type,
			'message'      => html_entity_decode( (string) ( $row['message'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'actor'        => $actor,
			'user'         => $this->format_activity_person(
				is_array( $metadata['user'] ?? null )
					? $metadata['user']
					: null,
			),
			'link'         => $link_meta,
			'location'     => is_array( $metadata['location'] ?? null )
				? $metadata['location']
				: null,
			'timestamp'    => Date::to_iso( (string) $row['created_at'] ),
			'isRestorable' => $is_restorable,
			'linkStatus'   => $link_status,
			'count'        => isset( $metadata['count'] ) ? (int) $metadata['count'] : null,
		);
	}

	/**
	 * Format lightweight activity person metadata for the UI.
	 *
	 * @param array<string, mixed>|null $person Raw person metadata.
	 * @return array<string, string|null>|null
	 * @since 1.0.4
	 */
	private function format_activity_person( ?array $person ): ?array {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$id           = trim( (string) ( $person['id'] ?? '' ) );
		$first_name   = trim( (string) ( $person['firstName'] ?? '' ) );
		$last_name    = trim( (string) ( $person['lastName'] ?? '' ) );
		$display_name = trim( (string) ( $person['displayName'] ?? '' ) );
		$username     = trim( (string) ( $person['username'] ?? '' ) );
		$email        = trim( (string) ( $person['email'] ?? '' ) );
		$role         = trim( (string) ( $person['role'] ?? '' ) );

		if (
			'' === $id &&
			'' === $first_name &&
			'' === $last_name &&
			'' === $display_name &&
			'' === $username &&
			'' === $email &&
			'' === $role
		) {
			return null;
		}

		return array(
			'id'          => '' !== $id ? $id : null,
			'firstName'   => '' !== $first_name ? $first_name : null,
			'lastName'    => '' !== $last_name ? $last_name : null,
			'displayName' => '' !== $display_name ? $display_name : null,
			'username'    => '' !== $username ? $username : null,
			'email'       => '' !== $email ? $email : null,
			'role'        => '' !== $role ? $role : null,
		);
	}

	/**
	 * Record a click event in the clicks table.
	 *
	 * @param array<string, mixed> $url               Short-URL row.
	 * @param Request              $request           Incoming HTTP request.
	 * @param bool                 $allow_non_get_hit Whether to track non-GET hits.
	 * @return void
	 * @since 1.0.0
	 */
	public function record_click(
		array $url,
		Request $request,
		bool $allow_non_get_hit = false
	): void {
		$this->data->record_click( $url, $request, $allow_non_get_hit );
	}

	/**
	 * Record an activity entry in the audit log.
	 *
	 * @param string               $type     Activity type identifier.
	 * @param string|null          $message  Human-readable message.
	 * @param string|null          $user_id  Associated user ID.
	 * @param string|null          $link_id  Associated link ID.
	 * @param array<string, mixed> $metadata Arbitrary metadata to store as JSON.
	 * @return void
	 * @since 1.0.0
	 */
	public function record_activity(
		string $type,
		?string $message = null,
		?string $user_id = null,
		?string $link_id = null,
		array $metadata = array()
	): void {
		$this->data->record_activity( $type, $message, $user_id, $link_id, $metadata );
	}

	/**
	 * Get analytics period metadata for a rolling day count.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string, string> Period metadata using UTC start time.
	 * @since 1.0.0
	 */
	public function get_analytics_period( int $days ): array {
		return $this->data->get_analytics_period( $days );
	}

	/**
	 * Get the prior comparison period for a given rolling window.
	 *
	 * @param array<string, string> $period Current period array.
	 * @param int                   $days   Number of days in the period.
	 * @return array<string, mixed> Comparison period metadata.
	 * @since 1.0.0
	 */
	public function get_last_month_period( array $period, int $days ): array {
		return $this->data->get_last_month_period( $period, $days );
	}

	/**
	 * Retrieve link stats for a specific period filter.
	 *
	 * @param string      $raw_period     Raw period string.
	 * @param string      $filter_status  Link status filter.
	 * @param string|null $target_user_id Target user ID.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_link_stats_period(
		string $raw_period,
		string $filter_status,
		?string $target_user_id = null
	): array {
		return $this->data->get_link_stats_period( $raw_period, $filter_status, $target_user_id );
	}
}
