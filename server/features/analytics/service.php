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
use PeakURL\Features\Links\Service as LinksService;
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
	 * @var LinksApi
	 * @since 1.0.0
	 */
	private LinksApi $links_api;

	/**
	 * Links domain service for activity link restoration.
	 *
	 * @var LinksService|null
	 * @since 1.7.2
	 */
	private ?LinksService $links_service = null;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Set the links domain service.
	 *
	 * @param LinksService $links_service Links domain service.
	 * @return void
	 * @since 1.7.2
	 */
	public function set_links_service( LinksService $links_service ): void {
		$this->links_service = $links_service;
	}

	/**
	 * Create a new Analytics Service instance.
	 *
	 * @param Repository           $data          Analytics repository engine.
	 * @param PeakURL_DB           $db            Shared database wrapper.
	 * @param AuthService          $auth_service  Authentication domain service.
	 * @param Roles                $roles         Roles and capabilities registry.
	 * @param Authorization        $authorization Shared authorization helper.
	 * @param array<string, mixed> $config        Runtime config map.
	 * @param LinksApi             $links_api     Links data API for URL lookups.
	 * @since 1.0.0
	 */
	public function __construct(
		Repository $data,
		PeakURL_DB $db,
		AuthService $auth_service,
		Roles $roles,
		Authorization $authorization,
		array $config,
		LinksApi $links_api
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
	 * @since 1.7.0
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'view_analytics',
			__( 'You do not have permission to view analytics.', 'peakurl' ),
		);

		$days              = max( 1, $days );
		$period            = $this->get_analytics_period( $days );
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

		$metrics = array(
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
			'traffic'                  => $this->data->query_traffic_series_range(
				null,
				$period['start_date'],
				$days,
				$period['start_at'],
				null,
				$period['timezone'],
				$user,
				'day',
			),
		);

		/**
		 * Filter the aggregated analytics summary metrics after permission and scope checks.
		 *
		 * @since 1.8.0
		 *
		 * @param array<string, mixed>      $metrics Aggregated summary metrics.
		 * @param int                       $days    Number of rolling days queried.
		 * @param array<string, mixed>|null $user    Authenticated user context.
		 */
		$filtered_metrics = \apply_filters( 'analytics_metrics', $metrics, $days, $user );

		if ( ! is_array( $filtered_metrics ) ) {
			return $metrics;
		}

		// Ensure required top-level numeric and structure fields are preserved with valid types.
		$numeric_fields = array(
			'totalClicks',
			'lastMonthTotalClicks',
			'totalLinks',
			'lastMonthTotalLinks',
			'uniqueClicks',
			'lastMonthUniqueClicks',
		);
		foreach ( $numeric_fields as $field ) {
			if ( ! isset( $filtered_metrics[ $field ] ) || ! is_numeric( $filtered_metrics[ $field ] ) || $filtered_metrics[ $field ] < 0 ) {
				$filtered_metrics[ $field ] = $metrics[ $field ];
			} else {
				$filtered_metrics[ $field ] = (int) $filtered_metrics[ $field ];
			}
		}

		$rate_fields = array( 'uniqueClickRate', 'lastMonthUniqueClickRate' );
		foreach ( $rate_fields as $field ) {
			if ( ! isset( $filtered_metrics[ $field ] ) || ! is_numeric( $filtered_metrics[ $field ] ) || ! is_finite( (float) $filtered_metrics[ $field ] ) || $filtered_metrics[ $field ] < 0 ) {
				$filtered_metrics[ $field ] = $metrics[ $field ];
			} else {
				$filtered_metrics[ $field ] = (float) $filtered_metrics[ $field ];
			}
		}

		$array_fields = array( 'devices', 'browsers', 'operatingSystems', 'countries', 'traffic', 'lastMonth' );
		foreach ( $array_fields as $field ) {
			if ( ! isset( $filtered_metrics[ $field ] ) || ! is_array( $filtered_metrics[ $field ] ) ) {
				$filtered_metrics[ $field ] = $metrics[ $field ];
			}
		}

		// Validate nested lastMonth comparison period structure.
		if (
			! isset( $filtered_metrics['lastMonth']['type'], $filtered_metrics['lastMonth']['days'], $filtered_metrics['lastMonth']['startDate'], $filtered_metrics['lastMonth']['endDate'] ) ||
			! is_string( $filtered_metrics['lastMonth']['type'] ) ||
			! is_numeric( $filtered_metrics['lastMonth']['days'] ) ||
			$filtered_metrics['lastMonth']['days'] < 0 ||
			! is_string( $filtered_metrics['lastMonth']['startDate'] ) ||
			! is_string( $filtered_metrics['lastMonth']['endDate'] )
		) {
			$filtered_metrics['lastMonth'] = $metrics['lastMonth'];
		} else {
			$filtered_metrics['lastMonth'] = array(
				'type'      => $filtered_metrics['lastMonth']['type'],
				'days'      => (int) $filtered_metrics['lastMonth']['days'],
				'startDate' => $filtered_metrics['lastMonth']['startDate'],
				'endDate'   => $filtered_metrics['lastMonth']['endDate'],
			);
		}

		// Validate nested traffic series structure and compatible array lengths.
		if (
			! isset( $filtered_metrics['traffic']['granularity'], $filtered_metrics['traffic']['labels'], $filtered_metrics['traffic']['clicks'], $filtered_metrics['traffic']['unique'] ) ||
			! is_string( $filtered_metrics['traffic']['granularity'] ) ||
			! is_array( $filtered_metrics['traffic']['labels'] ) ||
			! is_array( $filtered_metrics['traffic']['clicks'] ) ||
			! is_array( $filtered_metrics['traffic']['unique'] )
		) {
			$filtered_metrics['traffic'] = $metrics['traffic'];
		} else {
			$labels = $filtered_metrics['traffic']['labels'];
			$clicks = $filtered_metrics['traffic']['clicks'];
			$unique = $filtered_metrics['traffic']['unique'];

			if ( count( $labels ) !== count( $clicks ) || count( $labels ) !== count( $unique ) ) {
				$filtered_metrics['traffic'] = $metrics['traffic'];
			} else {
				$clean_labels = array();
				$clean_clicks = array();
				$clean_unique = array();
				$valid_series = true;

				foreach ( $labels as $idx => $label ) {
					if ( ! is_string( $label ) || ! is_numeric( $clicks[ $idx ] ) || $clicks[ $idx ] < 0 || ! is_numeric( $unique[ $idx ] ) || $unique[ $idx ] < 0 ) {
						$valid_series = false;
						break;
					}
					$clean_labels[] = $label;
					$clean_clicks[] = (int) $clicks[ $idx ];
					$clean_unique[] = (int) $unique[ $idx ];
				}

				if ( $valid_series ) {
					$filtered_metrics['traffic'] = array(
						'granularity' => $filtered_metrics['traffic']['granularity'],
						'labels'      => $clean_labels,
						'clicks'      => $clean_clicks,
						'unique'      => $clean_unique,
					);
				} else {
					$filtered_metrics['traffic'] = $metrics['traffic'];
				}
			}
		}

		// Validate breakdown items (name, count, optional code).
		$breakdown_fields = array( 'devices', 'browsers', 'operatingSystems', 'countries' );
		foreach ( $breakdown_fields as $bf ) {
			if ( ! isset( $filtered_metrics[ $bf ] ) || ! is_array( $filtered_metrics[ $bf ] ) ) {
				$filtered_metrics[ $bf ] = $metrics[ $bf ];
				continue;
			}
			$filtered_items = array();
			$valid          = true;
			foreach ( $filtered_metrics[ $bf ] as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['name'], $item['count'] ) || ! is_string( $item['name'] ) || ! is_numeric( $item['count'] ) || $item['count'] < 0 ) {
					$valid = false;
					break;
				}
				$clean_item = array(
					'name'  => $item['name'],
					'count' => (int) $item['count'],
				);
				if ( isset( $item['code'] ) && is_string( $item['code'] ) ) {
					$clean_item['code'] = $item['code'];
				}
				$filtered_items[] = $clean_item;
			}
			$filtered_metrics[ $bf ] = $valid ? $filtered_items : $metrics[ $bf ];
		}

		return $filtered_metrics;
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
		$user = $this->auth_service->get_current_user( $request );
		$this->authorization->validate_capability(
			$user,
			'view_analytics',
			__( 'You do not have permission to view analytics.', 'peakurl' ),
		);

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
				u.*
			FROM clicks c
			INNER JOIN urls u ON u.id = c.url_id' .
			$where .
			' ORDER BY c.clicked_at DESC' .
			Query::limit_offset_clause( $limit, 0 ),
			$params,
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		// Batch-load click stats for only the distinct URLs in the recent clicks feed.
		$url_ids   = array_values( array_unique( array_column( $rows, 'id' ) ) );
		$stats_map = array();

		if ( ! empty( $url_ids ) ) {
			$in_clause  = $this->db->in_placeholders( $url_ids, 'url_id' );
			$stats_rows = $this->db->get_results(
				'SELECT
					url_id,
					COUNT(*) AS clicks,
					COUNT(DISTINCT COALESCE(NULLIF(visitor_hash, \'\'), id)) AS unique_clicks
				FROM clicks
				WHERE url_id IN (' . $in_clause['sql'] . ')
				GROUP BY url_id',
				$in_clause['params'],
			);

			if ( is_array( $stats_rows ) ) {
				foreach ( $stats_rows as $sr ) {
					$stats_map[ (string) $sr['url_id'] ] = array(
						'clicks'        => (int) $sr['clicks'],
						'unique_clicks' => (int) $sr['unique_clicks'],
					);
				}
			}
		}

		foreach ( $rows as &$row ) {
			$uid                       = (string) ( $row['id'] ?? '' );
			$row['click_count']        = $stats_map[ $uid ]['clicks'] ?? 0;
			$row['unique_click_count'] = $stats_map[ $uid ]['unique_clicks'] ?? 0;
		}
		unset( $row );

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
		$url  = $this->links_api->get_link_by_identifier( $id );

		if ( ! $url ) {
			return null;
		}

		$this->authorization->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
			true
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
		$url  = $this->links_api->get_link_by_identifier( $id );

		if ( ! $url ) {
			return null;
		}

		$this->authorization->validate_record_access(
			$user,
			(string) ( $url['user_id'] ?? '' ),
			'view_analytics',
			__( 'You do not have permission to view analytics for this link.', 'peakurl' ),
			true
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
				return $this->links_service->restore_url( $request, $link_id );
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

		$item = array(
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

		$safe_metadata = array();
		if ( isset( $metadata['count'] ) ) {
			$safe_metadata['count'] = (int) $metadata['count'];
		}
		if ( isset( $metadata['location'] ) && is_array( $metadata['location'] ) ) {
			$safe_metadata['location'] = array(
				'country'     => isset( $metadata['location']['country'] ) ? (string) $metadata['location']['country'] : null,
				'countryCode' => isset( $metadata['location']['countryCode'] ) ? (string) $metadata['location']['countryCode'] : null,
				'city'        => isset( $metadata['location']['city'] ) ? (string) $metadata['location']['city'] : null,
			);
		}
		if ( isset( $metadata['link'] ) && is_array( $metadata['link'] ) ) {
			$safe_link = array(
				'id'        => (string) ( $metadata['link']['id'] ?? '' ),
				'shortCode' => (string) ( $metadata['link']['shortCode'] ?? $metadata['link']['short_code'] ?? '' ),
				'title'     => (string) ( $metadata['link']['title'] ?? '' ),
			);

			$raw_dest = (string) ( $metadata['link']['destinationUrl'] ?? $metadata['link']['destination_url'] ?? '' );
			if ( '' !== $raw_dest ) {
				$parsed = parse_url( $raw_dest );
				if ( is_array( $parsed ) && ! empty( $parsed['host'] ) ) {
					$scheme = strtolower( (string) ( $parsed['scheme'] ?? 'https' ) );
					if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
						$port                        = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
						$safe_link['destinationUrl'] = $scheme . '://' . $parsed['host'] . $port;
					}
				}
			}

			$safe_metadata['link'] = $safe_link;
		}
		if ( isset( $metadata['user'] ) && is_array( $metadata['user'] ) ) {
			$safe_metadata['user'] = array(
				'id'          => (string) ( $metadata['user']['id'] ?? '' ),
				'username'    => (string) ( $metadata['user']['username'] ?? '' ),
				'displayName' => (string) ( $metadata['user']['displayName'] ?? $metadata['user']['display_name'] ?? '' ),
			);
		}

		$safe_row = array(
			'id'        => (string) ( $row['id'] ?? '' ),
			'type'      => $type,
			'userId'    => (string) ( $row['user_id'] ?? '' ),
			'linkId'    => (string) ( $row['link_id'] ?? '' ),
			'createdAt' => (string) ( $row['created_at'] ?? '' ),
			'metadata'  => $safe_metadata,
		);

		/**
		 * Filter a formatted presentation activity row before output.
		 *
		 * Fired after user-scope and capability checks verify caller access.
		 * The contextual audit row exposes bounded operational metadata: user display info,
		 * link IDs/titles, validated HTTP/HTTPS origin, geographic names, and counts.
		 * Operational metadata is strictly confidential, even after minimization.
		 * Observers must treat even sanitized operational metadata as confidential and avoid logging or leaking it.
		 *
		 * @since 1.8.0
		 *
		 * @param array<string, mixed> $item Formatted activity item.
		 * @param array<string, mixed> $row  Sanitized database audit log row with safe metadata.
		 */
		$filtered_item = \apply_filters( 'activity_item', $item, $safe_row );

		return is_array( $filtered_item ) ? $filtered_item : $item;
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
	 * @return bool True if persisted, false otherwise.
	 * @since 1.0.0
	 */
	public function record_activity(
		string $type,
		?string $message = null,
		?string $user_id = null,
		?string $link_id = null,
		array $metadata = array()
	) {
		$persisted = (bool) $this->data->record_activity( $type, $message, $user_id, $link_id, $metadata );
		if ( ! $persisted ) {
			return false;
		}

		/**
		 * Fires after an activity or audit event has been successfully persisted.
		 *
		 * @since 1.8.0
		 *
		 * @param string               $type     Activity event type.
		 * @param string|null          $message  Activity message description.
		 * @param string|null          $user_id  Acting user ID if available.
		 * @param string|null          $link_id  Target link ID if applicable.
		 * @param array<string, mixed> $metadata Additional event metadata.
		 */
		try {
			\do_action( 'activity_recorded', $type, $message, $user_id, $link_id, $metadata );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( sprintf( 'PeakURL activity_recorded observer error: %s', $e->getMessage() ) );
		}

		return true;
	}

	/**
	 * Get analytics period metadata for a rolling day count.
	 *
	 * Allows customizing the reporting timezone via the 'analytics_period' filter.
	 * Enforces the rolling-period contract where $days represents the window ending on the current
	 * local date in the validated timezone. The expected start_date and UTC start_at are consistently
	 * derived to reject or normalize window shifts that would produce future dates or reporting drift.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string, string> Period metadata using UTC start time.
	 * @since 1.0.0
	 */
	public function get_analytics_period( int $days ): array {
		$days     = max( 1, $days );
		$period   = $this->data->get_analytics_period( $days );
		$filtered = \apply_filters( 'analytics_period', $period, $days );

		if ( ! is_array( $filtered ) ) {
			return $period;
		}

		$tz_name = trim( (string) ( $filtered['timezone'] ?? '' ) );
		if ( '' === $tz_name || ! in_array( $tz_name, \DateTimeZone::listIdentifiers(), true ) ) {
			return $period;
		}

		if ( ( $period['timezone'] ?? '' ) === $tz_name ) {
			$expected_start_date = (string) ( $period['start_date'] ?? '' );
			$expected_start_at   = (string) ( $period['start_at'] ?? '' );
		} else {
			try {
				$tz                  = new \DateTimeZone( $tz_name );
				$canonical_start     = ( new \DateTimeImmutable( 'now', $tz ) )
					->setTime( 0, 0, 0 )
					->modify( '-' . max( 0, $days - 1 ) . ' days' );
				$expected_start_date = $canonical_start->format( 'Y-m-d' );
				$expected_start_at   = $canonical_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( \Throwable $e ) {
				return $period;
			}
		}

		$start_date = (string) ( $filtered['start_date'] ?? '' );
		$start_at   = (string) ( $filtered['start_at'] ?? '' );

		// Reject or normalize callback results attempting to shift the reporting window away
		// from the canonical rolling window ending on the current local date.
		if ( $start_date !== $expected_start_date || $start_at !== $expected_start_at ) {
			$start_date = $expected_start_date;
			$start_at   = $expected_start_at;
		}

		$filtered['timezone']   = $tz_name;
		$filtered['start_date'] = $start_date;
		$filtered['start_at']   = $start_at;

		return $filtered;
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

	/**
	 * Purge click records older than the given cutoff date according to retention policy.
	 *
	 * @param string $cutoff Cutoff timestamp string (Y-m-d H:i:s).
	 * @param int    $limit  Maximum records to delete in one batch.
	 * @return int Number of deleted click records.
	 * @since 1.7.0
	 */
	public function purge_old_clicks( string $cutoff, int $limit = 1000 ): int {
		$deleted      = $this->data->purge_old_clicks( $cutoff, $limit );
		$actual_limit = max( 1, min( 5000, $limit ) );

		if ( \has_action( 'analytics_retention_completed' ) ) {
			try {
				/**
				 * Fires after click analytics retention purge completes.
				 *
				 * @since 1.8.0
				 *
				 * @param array{purgedClicks: int, cutoff: string, limit: int} $summary Retention summary.
				 */
				\do_action(
					'analytics_retention_completed',
					array(
						'purgedClicks' => $deleted,
						'cutoff'       => $cutoff,
						'limit'        => $actual_limit,
					)
				);
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( sprintf( 'PeakURL analytics_retention_completed observer error: %s', get_class( $e ) ) );
			}
		}

		return $deleted;
	}
}
