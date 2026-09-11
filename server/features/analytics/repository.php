<?php
/**
 * Analytics data access and calculation engine.
 *
 * Implements click recording, metric aggregations, period calculations,
 * and database queries for dashboard and per-link analytics.
 *
 * @package PeakURL\Features\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Analytics;

use PeakURL\Api\SettingsApi;
use PeakURL\Core\Auth\Authorization;
use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Utils\Date;
use PeakURL\Utils\Str;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Repository — Database queries and analytical calculations.
 *
 * @since 1.0.0
 */
class Repository {

	/**
	 * Database wrapper instance.
	 *
	 * @var PeakURL_DB
	 * @since 1.0.0
	 */
	private PeakURL_DB $db;

	/**
	 * Settings options API.
	 *
	 * @var SettingsApi
	 * @since 1.0.0
	 */
	private SettingsApi $settings_api;

	/**
	 * Request geolocation service.
	 *
	 * @var Geoip
	 * @since 1.0.0
	 */
	private Geoip $geoip;

	/**
	 * Roles registry.
	 *
	 * @var Roles
	 * @since 1.0.0
	 */
	private Roles $roles;

	/**
	 * Authorization service.
	 *
	 * @var Authorization
	 * @since 1.0.0
	 */
	private Authorization $authorization;

	/**
	 * Webhooks service for event dispatching.
	 *
	 * @var WebhooksService|null
	 * @since 1.0.0
	 */
	private ?WebhooksService $webhooks = null;

	/**
	 * Optional link formatter callable.
	 *
	 * @var callable|null
	 * @since 1.0.0
	 */
	private $link_formatter = null;

	/**
	 * Runtime configuration map.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $config;

	/**
	 * Create a new Analytics Data instance.
	 *
	 * @param PeakURL_DB            $db             Database wrapper.
	 * @param SettingsApi           $settings_api   Settings API.
	 * @param Geoip                 $geoip          Geolocation service.
	 * @param Roles                 $roles          Roles registry.
	 * @param Authorization         $authorization  Authorization service.
	 * @param WebhooksService|null  $webhooks       Optional webhooks service.
	 * @param callable|null         $link_formatter Optional link formatter callable.
	 * @param array<string, mixed>  $config         Runtime config map.
	 * @since 1.0.0
	 */
	public function __construct(
		PeakURL_DB $db,
		SettingsApi $settings_api,
		Geoip $geoip,
		Roles $roles,
		Authorization $authorization,
		?WebhooksService $webhooks = null,
		?callable $link_formatter = null,
		array $config = array()
	) {
		$this->db             = $db;
		$this->settings_api   = $settings_api;
		$this->geoip          = $geoip;
		$this->roles          = $roles;
		$this->authorization  = $authorization;
		$this->webhooks       = $webhooks;
		$this->link_formatter = $link_formatter;
		$this->config         = $config;
	}

	/**
	 * Set the link formatter callback.
	 *
	 * @param callable $link_formatter Callback accepting raw URL row and returning formatted array.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_link_formatter( callable $link_formatter ): void {
		$this->link_formatter = $link_formatter;
	}

	/**
	 * Set the webhooks service for link event dispatching.
	 *
	 * @param WebhooksService $webhooks Webhooks domain service.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_webhooks( WebhooksService $webhooks ): void {
		$this->webhooks = $webhooks;
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
		$this->db->insert(
			'audit_logs',
			array(
				'id'         => Str::random_id(),
				'user_id'    => $user_id,
				'type'       => $type,
				'message'    => $message,
				'link_id'    => $link_id,
				'metadata'   => wp_json_encode( $metadata ),
				'created_at' => Date::now(),
			),
		);
	}

	/**
	 * Get analytics period metadata for a rolling day count.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string, string> Period metadata using UTC start time.
	 * @since 1.0.0
	 */
	public function get_analytics_period( int $days ): array {
		$timezone   = $this->get_analytics_timezone();
		$start_date =
			( new \DateTimeImmutable( 'now', $timezone ) )
				->setTime( 0, 0, 0 )
				->modify( '-' . max( 0, $days - 1 ) . ' days' );
		$utc_start  = $start_date->setTimezone( new \DateTimeZone( 'UTC' ) );

		return array(
			'start_at'   => $utc_start->format( 'Y-m-d H:i:s' ),
			'start_date' => $start_date->format( 'Y-m-d' ),
			'timezone'   => $timezone->getName(),
		);
	}

	/**
	 * Return the configured analytics timezone.
	 *
	 * @return \DateTimeZone
	 * @since 1.0.0
	 */
	public function get_analytics_timezone(): \DateTimeZone {
		$timezone = trim( (string) $this->settings_api->get_option( 'site_timezone' ) );

		if ( '' === $timezone ) {
			$timezone = Constants::DEFAULT_TIMEZONE;
		}

		try {
			return new \DateTimeZone( $timezone );
		} catch ( \Exception $exception ) {
			return new \DateTimeZone( Constants::DEFAULT_TIMEZONE );
		}
	}

	/**
	 * Get the matching dashboard window from last month.
	 *
	 * @param array<string, string> $period Current dashboard period metadata.
	 * @param int                   $days   Number of selected day buckets.
	 * @return array{type: string, days: int, start_at: string, end_at: string, start_date: string, end_date: string}
	 * @since 1.0.0
	 */
	public function get_last_month_period( array $period, int $days ): array {
		$days     = max( 1, $days );
		$timezone = new \DateTimeZone(
			(string) ( $period['timezone'] ?? Constants::DEFAULT_TIMEZONE ),
		);
		$start    = ( new \DateTimeImmutable(
			(string) ( $period['start_date'] ?? 'now' ),
			$timezone,
		) )->setTime( 0, 0, 0 );

		$last_month_base = $start
			->modify( 'first day of this month' )
			->modify( '-1 month' );
		$last_month_day  = min(
			(int) $start->format( 'd' ),
			(int) $last_month_base->format( 't' ),
		);

		$last_month_start = $last_month_base->setDate(
			(int) $last_month_base->format( 'Y' ),
			(int) $last_month_base->format( 'm' ),
			$last_month_day,
		);
		$last_month_end   = $last_month_start->modify( '+' . $days . ' days' );
		$utc_timezone     = new \DateTimeZone( 'UTC' );

		return array(
			'type'       => 'lastMonth',
			'days'       => $days,
			'start_at'   => $last_month_start
				->setTimezone( $utc_timezone )
				->format( 'Y-m-d H:i:s' ),
			'end_at'     => $last_month_end
				->setTimezone( $utc_timezone )
				->format( 'Y-m-d H:i:s' ),
			'start_date' => $last_month_start->format( 'Y-m-d' ),
			'end_date'   => $last_month_end->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Count visible links for the dashboard summary cards.
	 *
	 * @param array<string, mixed> $user           Current user.
	 * @param string|null          $created_before Optional exclusive creation cutoff.
	 * @return int Visible link count.
	 * @since 1.0.0
	 */
	public function get_dashboard_link_count(
		array $user,
		?string $created_before = null
	): int {
		$conditions = array();
		$params     = array();

		if ( null !== $created_before ) {
			$conditions[]             = 'u.created_at < :created_before';
			$params['created_before'] = $created_before;
		}

		$this->authorization->scope_link_visibility(
			$user,
			$conditions,
			$params,
			'u',
		);

		return (int) $this->db->get_var(
			'SELECT COUNT(*) FROM urls u ' .
			( ! empty( $conditions )
				? 'WHERE ' . implode( ' AND ', $conditions )
				: '' ),
			$params,
		);
	}

	/**
	 * Get dashboard click totals for a bounded or rolling period.
	 *
	 * @param array<string, mixed> $user     Current user.
	 * @param string|null          $start_at Optional inclusive UTC start datetime.
	 * @param string|null          $end_at   Optional exclusive UTC end datetime.
	 * @return array{totalClicks: int, uniqueClicks: int, uniqueClickRate: float}
	 * @since 1.0.0
	 */
	public function get_dashboard_click_totals(
		array $user,
		?string $start_at = null,
		?string $end_at = null
	): array {
		$join_sql   = '';
		$conditions = array();
		$params     = array();

		if ( null !== $start_at ) {
			$conditions[]       = 'c.clicked_at >= :start_at';
			$params['start_at'] = $start_at;
		}

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		$this->authorization->scope_click_analytics(
			$user,
			$join_sql,
			$conditions,
			$params,
			'c',
			'u',
		);

		$sql =
			'SELECT
                COUNT(*) AS total_clicks,
                COUNT(DISTINCT c.visitor_hash) AS unique_clicks
            FROM clicks c' .
			$join_sql .
			( ! empty( $conditions )
				? ' WHERE ' . implode( ' AND ', $conditions )
				: '' );

		$row           = $this->db->get_row( $sql, $params );
		$total_clicks  = (int) ( $row['total_clicks'] ?? 0 );
		$unique_clicks = min(
			(int) ( $row['unique_clicks'] ?? 0 ),
			$total_clicks,
		);

		return array(
			'totalClicks'     => $total_clicks,
			'uniqueClicks'    => $unique_clicks,
			'uniqueClickRate' => $this->get_unique_click_rate(
				$total_clicks,
				$unique_clicks,
			),
		);
	}

	/**
	 * Calculate period boundaries for link stats.
	 *
	 * @param string|null $range            Timeframe key.
	 * @param string|null $custom_date_from Custom range start date.
	 * @param string|null $custom_date_to   Custom range end date.
	 * @param string|null $created_at       Link creation datetime in UTC.
	 * @return array{key: string, days: int, start_at: string|null, end_at: string|null, start_date: string|null, end_date: string|null, series_start_at?: string}
	 * @since 1.0.0
	 */
	public function get_link_stats_period(
		?string $range,
		?string $custom_date_from = null,
		?string $custom_date_to = null,
		?string $created_at = null
	): array {
		$range = sanitize_key( (string) $range );

		if ( 'custom' === $range ) {
			$custom_period = $this->get_link_stats_custom_period(
				$custom_date_from,
				$custom_date_to,
			);

			if ( null !== $custom_period ) {
				return $custom_period;
			}
		}

		if ( 'all' === $range ) {
			$lifetime_days = $this->get_link_lifetime_days(
				(string) ( $created_at ?? Date::now() ),
			);
			$period        = $this->get_analytics_period( $lifetime_days );

			return array(
				'key'             => 'all',
				'days'            => $lifetime_days,
				'start_at'        => null,
				'end_at'          => null,
				'start_date'      => null,
				'end_date'        => null,
				'series_start_at' => $period['start_at'],
			);
		}

		$resolved_days = match ( $range ) {
			'24h'   => 1,
			'30d'   => 30,
			'90d'   => 90,
			default => 7,
		};
		$period = $this->get_analytics_period( $resolved_days );

		return array(
			'key'        => '24h' === $range ? '24h' : ( '30d' === $range ? '30d' : ( '90d' === $range ? '90d' : '7d' ) ),
			'days'       => $resolved_days,
			'start_at'   => $period['start_at'],
			'end_at'     => null,
			'start_date' => $period['start_date'],
			'end_date'   => null,
		);
	}

	/**
	 * Resolve a custom stats period into inclusive day buckets and UTC bounds.
	 *
	 * @param string|null $custom_date_from Custom range start date.
	 * @param string|null $custom_date_to   Custom range end date.
	 * @return array{key: string, days: int, start_at: string, end_at: string, start_date: string, end_date: string}|null
	 * @since 1.0.0
	 */
	private function get_link_stats_custom_period(
		?string $custom_date_from,
		?string $custom_date_to
	): ?array {
		$start_date = $this->normalize_link_stats_date( $custom_date_from );
		$end_date   = $this->normalize_link_stats_date( $custom_date_to );

		if ( null === $start_date || null === $end_date ) {
			return null;
		}

		$timezone = $this->get_analytics_timezone();

		try {
			$start = ( new \DateTimeImmutable( $start_date, $timezone ) )
				->setTime( 0, 0, 0 );
			$end   = ( new \DateTimeImmutable( $end_date, $timezone ) )
				->setTime( 0, 0, 0 );
		} catch ( \Exception $exception ) {
			return null;
		}

		if ( $start > $end ) {
			$temp  = $start;
			$start = $end;
			$end   = $temp;
		}

		$days         = (int) $start->diff( $end )->format( '%a' ) + 1;
		$utc_timezone = new \DateTimeZone( 'UTC' );

		return array(
			'key'        => 'custom',
			'days'       => $days,
			'start_at'   => $start->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' ),
			'end_at'     => $end->modify( '+1 day' )->setTimezone( $utc_timezone )->format( 'Y-m-d H:i:s' ),
			'start_date' => $start->format( 'Y-m-d' ),
			'end_date'   => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Normalize a date parameter into YYYY-MM-DD format.
	 *
	 * @param string|null $date_value Input date value.
	 * @return string|null Normalized date or null.
	 * @since 1.0.0
	 */
	private function normalize_link_stats_date( ?string $date_value ): ?string {
		if ( null === $date_value ) {
			return null;
		}

		$clean = trim( $date_value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ) {
			return null;
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $clean ) );

		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Resolve bucket granularity based on total timeframe days.
	 *
	 * @param int $days Total days in the window.
	 * @return string Bucket size: 'hour', 'day', or 'month'.
	 * @since 1.0.0
	 */
	public function get_traffic_series_granularity( int $days ): string {
		if ( $days <= 1 ) {
			return 'hour';
		}

		return 'day';
	}

	/**
	 * Calculate lifetime days between link creation and now.
	 *
	 * @param string $created_at Link creation datetime in UTC.
	 * @return int Number of active days.
	 * @since 1.0.0
	 */
	private function get_link_lifetime_days( string $created_at ): int {
		$created_date = ( new \DateTimeImmutable(
			$created_at,
			new \DateTimeZone( 'UTC' ),
		) )
			->setTimezone( $this->get_analytics_timezone() )
			->setTime( 0, 0, 0 );
		$now_date     = ( new \DateTimeImmutable(
			'now',
			$this->get_analytics_timezone(),
		) )
			->setTime( 0, 0, 0 );

		return max( 1, (int) $created_date->diff( $now_date )->format( '%a' ) + 1 );
	}

	/**
	 * Calculate comparison period summaries for a link.
	 *
	 * @param string                $url_id     URL ID to summarize.
	 * @param array<string, mixed> $period     Current active period metadata.
	 * @param int                   $days       Number of days in the active period.
	 * @param string                $created_at URL created_at timestamp.
	 * @return array<string, mixed> Comparison summaries.
	 * @since 1.0.0
	 */
	public function get_link_period_summaries(
		string $url_id,
		array $period,
		int $days,
		string $created_at
	): array {
		$current_total_clicks  = (int) $this->db->get_var(
			'SELECT COUNT(*) FROM clicks WHERE url_id = :url_id',
			array( 'url_id' => $url_id ),
		);
		$current_unique_clicks = min(
			(int) $this->db->get_var(
				'SELECT COUNT(DISTINCT visitor_hash) FROM clicks WHERE url_id = :url_id',
				array( 'url_id' => $url_id ),
			),
			$current_total_clicks,
		);

		$lifetime_days = $this->get_link_lifetime_days( $created_at );

		$last_7_period  = $this->get_analytics_period( 7 );
		$last_30_period = $this->get_analytics_period( 30 );

		return array(
			'selected'   => $this->format_link_period_summary(
				$period,
				$days,
				$url_id,
				'selected',
			),
			'allTime'    => array(
				'key'             => 'allTime',
				'totalClicks'     => $current_total_clicks,
				'uniqueClicks'    => $current_unique_clicks,
				'uniqueClickRate' => $this->get_unique_click_rate(
					$current_total_clicks,
					$current_unique_clicks,
				),
				'averageClicks'   => round(
					$current_total_clicks / max( 1, $lifetime_days ),
					2,
				),
				'averageUnit'     => 'day',
			),
			'last7Days'  => $this->format_link_period_summary(
				$last_7_period,
				7,
				$url_id,
				'last7Days',
			),
			'last30Days' => $this->format_link_period_summary(
				$last_30_period,
				30,
				$url_id,
				'last30Days',
			),
		);
	}

	/**
	 * Format a link period summary bucket.
	 *
	 * @param array<string, mixed> $period  Period metadata array.
	 * @param int                   $days    Day count.
	 * @param string                $url_id  URL row ID.
	 * @param string                $type    Period type key.
	 * @return array<string, mixed> Formatted period summary.
	 * @since 1.0.0
	 */
	private function format_link_period_summary(
		array $period,
		int $days,
		string $url_id,
		string $type
	): array {
		$conditions = array( 'url_id = :url_id' );
		$params     = array( 'url_id' => $url_id );

		if ( ! empty( $period['start_at'] ) ) {
			$conditions[]       = 'clicked_at >= :start_at';
			$params['start_at'] = $period['start_at'];
		}

		if ( ! empty( $period['end_at'] ) ) {
			$conditions[]     = 'clicked_at < :end_at';
			$params['end_at'] = $period['end_at'];
		}

		$where = implode( ' AND ', $conditions );
		$row   = $this->db->get_row(
			'SELECT
                COUNT(*) AS total_clicks,
                COUNT(DISTINCT visitor_hash) AS unique_clicks
            FROM clicks
            WHERE ' . $where,
			$params,
		);

		$total_clicks  = (int) ( $row['total_clicks'] ?? 0 );
		$unique_clicks = min(
			(int) ( $row['unique_clicks'] ?? 0 ),
			$total_clicks,
		);

		return array(
			'key'             => $type,
			'totalClicks'     => $total_clicks,
			'uniqueClicks'    => $unique_clicks,
			'uniqueClickRate' => $this->get_unique_click_rate(
				$total_clicks,
				$unique_clicks,
			),
			'averageClicks'   => round(
				$total_clicks / max( 1, $days ),
				2,
			),
			'averageUnit'     => $days <= 1 ? 'hour' : 'day',
		);
	}

	/**
	 * Build the full click history for a link, grouped by active day.
	 *
	 * @param string $url_id URL ID to scope the lookup to.
	 * @return array<int, array<string, mixed>> Active day payloads sorted oldest first.
	 * @since 1.0.0
	 */
	public function get_link_click_history_days( string $url_id ): array {
		$timezone = $this->get_analytics_timezone();
		$sql      = sprintf(
			'SELECT
                DATE(CONVERT_TZ(clicked_at, \'+00:00\', \'%1$s\')) AS click_day,
                COUNT(*) AS total_clicks,
                COUNT(DISTINCT visitor_hash) AS unique_clicks
            FROM clicks
            WHERE url_id = :url_id
            GROUP BY click_day
            ORDER BY click_day ASC',
			$this->db->esc_like( $timezone->getName() ),
		);

		$rows = $this->db->get_results( $sql, array( 'url_id' => $url_id ) );

		return array_map(
			function ( array $row ): array {
				$total_clicks  = (int) $row['total_clicks'];
				$unique_clicks = min(
					(int) $row['unique_clicks'],
					$total_clicks,
				);

				return array(
					'date'            => (string) $row['click_day'],
					'totalClicks'     => $total_clicks,
					'uniqueClicks'    => $unique_clicks,
					'uniqueClickRate' => $this->get_unique_click_rate(
						$total_clicks,
						$unique_clicks,
					),
				);
			},
			$rows,
		);
	}

	/**
	 * Calculate the historical best single day for a link.
	 *
	 * @param array<int, array<string, mixed>> $click_days Pre-grouped active day click records.
	 * @return array<string, mixed>|null Best day payload or null when no clicks exist.
	 * @since 1.0.0
	 */
	public function get_link_best_day( array $click_days ): ?array {
		if ( empty( $click_days ) ) {
			return null;
		}

		$best_day = null;

		foreach ( $click_days as $day ) {
			if (
				null === $best_day ||
				(int) $day['totalClicks'] > (int) $best_day['totalClicks'] ||
				(
					(int) $day['totalClicks'] === (int) $best_day['totalClicks'] &&
					(int) $day['uniqueClicks'] > (int) $best_day['uniqueClicks']
				)
			) {
				$best_day = $day;
			}
		}

		return $best_day;
	}

	/**
	 * Calculate the unique click percentage rate.
	 *
	 * @param int $total_clicks  Total click count.
	 * @param int $unique_clicks Unique visitor count.
	 * @return float Unique rate percentage.
	 * @since 1.0.0
	 */
	public function get_unique_click_rate(
		int $total_clicks,
		int $unique_clicks
	): float {
		if ( $total_clicks <= 0 ) {
			return 0.0;
		}

		return round( ( $unique_clicks / $total_clicks ) * 100, 2 );
	}

	/**
	 * Query time-series traffic series for a standard rolling window.
	 *
	 * @param string|null          $url_id Optional URL ID filter.
	 * @param int                  $days   Number of days in the window.
	 * @param array<string, mixed> $user   Current user for capability scoping.
	 * @return array<int, array<string, mixed>> Ordered timeline datapoints.
	 * @since 1.0.0
	 */
	public function query_traffic_series(
		?string $url_id = null,
		int $days = 7,
		?array $user = null
	): array {
		$period   = $this->get_analytics_period( $days );
		$timezone = $this->get_analytics_timezone();

		return $this->query_traffic_series_range(
			$url_id,
			$period['start_at'],
			Date::now(),
			$timezone->getName(),
			$this->get_traffic_series_granularity( $days ),
			$user,
		);
	}

	/**
	 * Query time-series traffic points across an explicit UTC range.
	 *
	 * @param string|null          $url_id      Optional URL ID filter.
	 * @param string               $start_at    Inclusive UTC start datetime.
	 * @param string               $end_at      Exclusive UTC end datetime.
	 * @param string               $timezone    Local timezone name.
	 * @param string               $granularity Bucket size ('hour', 'day', or 'month').
	 * @param array<string, mixed> $user        Current user for capability scoping.
	 * @return array<int, array<string, mixed>> Time-series buckets.
	 * @since 1.0.0
	 */
	public function query_traffic_series_range(
		?string $url_id,
		string $start_at,
		string $end_at,
		string $timezone,
		string $granularity = 'day',
		?array $user = null
	): array {
		$join_sql   = '';
		$conditions = array(
			'c.clicked_at >= :start_at',
			'c.clicked_at < :end_at',
		);
		$params     = array(
			'start_at' => $start_at,
			'end_at'   => $end_at,
		);

		$format_string = $this->get_traffic_series_format_string(
			$granularity,
		);

		if ( null !== $url_id && '' !== $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->authorization->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$sql = sprintf(
			'SELECT
                DATE_FORMAT(CONVERT_TZ(c.clicked_at, \'+00:00\', \'%1$s\'), \'%2$s\') AS bucket,
                COUNT(*) AS total_clicks,
                COUNT(DISTINCT c.visitor_hash) AS unique_clicks
            FROM clicks c' .
			$join_sql .
			' WHERE ' . implode( ' AND ', $conditions ) .
			' GROUP BY bucket
            ORDER BY bucket ASC',
			$this->db->esc_like( $timezone ),
			$format_string,
		);

		$rows = $this->db->get_results( $sql, $params );

		return array_map(
			function ( array $row ): array {
				$total  = (int) $row['total_clicks'];
				$unique = min( (int) $row['unique_clicks'], $total );

				return array(
					'timestamp'    => Date::to_iso( (string) $row['bucket'] ),
					'totalClicks'  => $total,
					'clicks'       => $total,
					'uniqueClicks' => $unique,
				);
			},
			$rows,
		);
	}

	/**
	 * Group and rank click attribute frequencies.
	 *
	 * @param string               $column      Click column name.
	 * @param string               $name_key    Key name in the output array.
	 * @param string               $start_at    Inclusive UTC start datetime.
	 * @param string|null          $end_at      Exclusive UTC end datetime.
	 * @param string|null          $code_column Optional secondary column for code values.
	 * @param string|null          $url_id      Optional URL ID filter.
	 * @param array<string, mixed> $user        Current user for capability scoping.
	 * @param int|null             $limit       Maximum rows to return.
	 * @return array<int, array<string, mixed>> Grouped metric rows.
	 * @since 1.0.0
	 */
	public function group_click_metrics(
		string $column,
		string $name_key,
		string $start_at,
		?string $end_at = null,
		?string $code_column = null,
		?string $url_id = null,
		?array $user = null,
		?int $limit = 12
	): array {
		$allowed_columns = array(
			'device',
			'browser',
			'operating_system',
			'country_name',
			'country_code',
		);

		if ( ! in_array( $column, $allowed_columns, true ) ) {
			throw new \RuntimeException( 'Invalid analytics column requested.' );
		}

		if ( null !== $code_column && ! in_array( $code_column, $allowed_columns, true ) ) {
			throw new \RuntimeException(
				'Invalid analytics code column requested.',
			);
		}

		$name_expression =
			'COALESCE(NULLIF(c.' . $column . ', \'\'), \'Unknown\')';

		if ( 'country_name' === $column ) {
			$name_expression =
				'COALESCE(NULLIF(c.country_name, \'\'), NULLIF(c.country_code, \'\'), \'Unknown\')';
		}

		$selects = array(
			$name_expression . ' AS item_name',
			'COUNT(*) AS item_count',
		);

		if ( $code_column ) {
			$selects[] =
				'COALESCE(NULLIF(c.' .
				$code_column .
				', \'\'), \'??\') AS item_code';
		}

		$join_sql   = '';
		$sql        =
			'SELECT ' .
			implode( ', ', $selects ) .
			'
            FROM clicks c';
		$params     = array( 'start_at' => $start_at );
		$conditions = array( 'c.clicked_at >= :start_at' );

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->authorization->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$limit_sql = null === $limit ? '' : ' LIMIT ' . max( 1, $limit );

		$sql .=
			$join_sql .
			' WHERE ' .
			implode( ' AND ', $conditions ) .
			' GROUP BY item_name' .
			( $code_column ? ', item_code' : '' ) .
			' ORDER BY item_count DESC' .
			$limit_sql;

		$rows = $this->db->get_results( $sql, $params );

		return array_map(
			static function ( array $row ) use (
				$name_key,
				$code_column
			): array {
				$item = array(
					$name_key => (string) $row['item_name'],
					'count'   => (int) $row['item_count'],
				);

				if ( $code_column ) {
					$item['code'] = (string) $row['item_code'];
				}

				return $item;
			},
			$rows,
		);
	}

	/**
	 * Group and rank top referrers.
	 *
	 * @param string|null          $url_id   Optional URL ID filter.
	 * @param string|null          $start_at Inclusive UTC start datetime.
	 * @param string|null          $end_at   Exclusive UTC end datetime.
	 * @param array<string, mixed> $user     Current user for capability scoping.
	 * @param int                  $limit    Maximum referrers to return.
	 * @return array<int, array<string, mixed>> Ranked referrer records.
	 * @since 1.0.0
	 */
	public function group_referrers(
		?string $url_id = null,
		?string $start_at = null,
		?string $end_at = null,
		?array $user = null,
		int $limit = 10
	): array {
		$join_sql   = '';
		$conditions = array();
		$params     = array();

		if ( null !== $start_at ) {
			$conditions[]       = 'c.clicked_at >= :start_at';
			$params['start_at'] = $start_at;
		}

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->authorization->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$sql =
			'SELECT
                COALESCE(NULLIF(c.referrer_name, \'\'), NULLIF(c.referrer_domain, \'\'), \'Direct / Unknown\') AS name,
                c.referrer_domain AS domain,
                COUNT(*) AS count
            FROM clicks c' .
			$join_sql .
			( ! empty( $conditions )
				? ' WHERE ' . implode( ' AND ', $conditions )
				: '' ) .
			' GROUP BY name, domain
            ORDER BY count DESC
            LIMIT ' . max( 1, $limit );

		$rows = $this->db->get_results( $sql, $params );

		return array_map(
			static fn( array $row ): array => array(
				'name'   => (string) $row['name'],
				'domain' => (string) ( $row['domain'] ?? '' ),
				'count'  => (int) $row['count'],
			),
			$rows,
		);
	}

	/**
	 * Group and rank referrer category distribution.
	 *
	 * @param string|null          $url_id   Optional URL ID filter.
	 * @param string|null          $start_at Inclusive UTC start datetime.
	 * @param string|null          $end_at   Exclusive UTC end datetime.
	 * @param array<string, mixed> $user     Current user for capability scoping.
	 * @return array<int, array<string, mixed>> Referrer categories.
	 * @since 1.0.0
	 */
	public function group_referrer_categories(
		?string $url_id = null,
		?string $start_at = null,
		?string $end_at = null,
		?array $user = null
	): array {
		$join_sql   = '';
		$conditions = array();
		$params     = array();

		if ( null !== $start_at ) {
			$conditions[]       = 'c.clicked_at >= :start_at';
			$params['start_at'] = $start_at;
		}

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->authorization->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$sql =
			'SELECT
                COALESCE(NULLIF(c.referrer_category, \'\'), \'direct\') AS category,
                COUNT(*) AS count
            FROM clicks c' .
			$join_sql .
			( ! empty( $conditions )
				? ' WHERE ' . implode( ' AND ', $conditions )
				: '' ) .
			' GROUP BY category
            ORDER BY count DESC';

		$rows = $this->db->get_results( $sql, $params );

		return array_map(
			static fn( array $row ): array => array(
				'category' => (string) $row['category'],
				'count'    => (int) $row['count'],
			),
			$rows,
		);
	}

	/**
	 * Group and rank UTM campaign occurrences.
	 *
	 * @param string|null          $url_id   Optional URL ID filter.
	 * @param string|null          $start_at Inclusive UTC start datetime.
	 * @param string|null          $end_at   Exclusive UTC end datetime.
	 * @param array<string, mixed> $user     Current user for capability scoping.
	 * @param int                  $limit    Maximum campaign rows.
	 * @return array<int, array<string, mixed>> Ranked UTM campaigns.
	 * @since 1.0.0
	 */
	public function group_utm_campaigns(
		?string $url_id = null,
		?string $start_at = null,
		?string $end_at = null,
		?array $user = null,
		int $limit = 10
	): array {
		$join_sql   = '';
		$conditions = array( 'c.utm_campaign IS NOT NULL', 'c.utm_campaign != \'\'' );
		$params     = array();

		if ( null !== $start_at ) {
			$conditions[]       = 'c.clicked_at >= :start_at';
			$params['start_at'] = $start_at;
		}

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->authorization->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$sql =
			'SELECT
                c.utm_campaign AS name,
                COUNT(*) AS count
            FROM clicks c' .
			$join_sql .
			' WHERE ' . implode( ' AND ', $conditions ) .
			' GROUP BY c.utm_campaign
            ORDER BY count DESC
            LIMIT ' . max( 1, $limit );

		$rows = $this->db->get_results( $sql, $params );

		return array_map(
			static fn( array $row ): array => array(
				'name'  => (string) $row['name'],
				'count' => (int) $row['count'],
			),
			$rows,
		);
	}

	/**
	 * Record a click event for a short link.
	 *
	 * @param array<string, mixed> $url               URL database row.
	 * @param Request              $request           Incoming HTTP request.
	 * @param bool                 $allow_non_get_hit Whether a non-GET request should count.
	 * @return void
	 * @since 1.0.0
	 */
	public function record_click(
		array $url,
		Request $request,
		bool $allow_non_get_hit = false
	): void {
		$user_agent = Str::nullable( $request->get_user_agent() );
		$ip_address = Str::nullable( $request->get_ip_address() );
		$now        = Date::now();

		if (
			Visitor::skip_click_tracking(
				$request,
				$allow_non_get_hit,
			) ||
			$this->is_duplicate_click(
				(string) $url['id'],
				Visitor::hash_request( $request ),
				$ip_address,
				$user_agent,
				$now,
			)
		) {
			return;
		}

		$referrer      = Visitor::parse_referrer(
			$request->get_header( 'Referer', '' ),
		);
		$metadata      = Visitor::parse_user_agent(
			(string) ( $user_agent ?? '' ),
		);
		$location      = $this->geoip->lookup_location(
			(string) ( $ip_address ?? '' ),
		);
		$visitor_hash  = Visitor::hash_request( $request );
		$click_id      = Str::random_id();
		$click_payload = array(
			'id'                => $click_id,
			'url_id'            => (string) $url['id'],
			'clicked_at'        => $now,
			'visitor_hash'      => $visitor_hash,
			'ip_address'        => $ip_address,
			'country_code'      => $location['country_code'],
			'country_name'      => $location['country_name'],
			'city_name'         => $location['city_name'],
			'device'            => $metadata['device'],
			'browser'           => $metadata['browser'],
			'operating_system'  => $metadata['os'],
			'referrer_name'     => $referrer['name'],
			'referrer_domain'   => $referrer['domain'],
			'referrer_category' => $referrer['category'],
			'utm_source'        => Str::nullable(
				$url['utm_source'] ?? null,
			),
			'utm_medium'        => Str::nullable(
				$url['utm_medium'] ?? null,
			),
			'utm_campaign'      => Str::nullable(
				$url['utm_campaign'] ?? null,
			),
			'utm_term'          => Str::nullable(
				$url['utm_term'] ?? null,
			),
			'utm_content'       => Str::nullable(
				$url['utm_content'] ?? null,
			),
			'user_agent'        => $user_agent,
		);

		$this->db->insert( 'clicks', $click_payload );

		/**
		 * Fires after a short link click has been recorded.
		 *
		 * @since 1.6.1
		 *
		 * @param array<string, mixed> $url           URL database record.
		 * @param array<string, mixed> $click_payload Recorded click attributes.
		 * @param Request              $request       Incoming HTTP request.
		 */
		\do_action( 'link_clicked', $url, $click_payload, $request );

		if ( $this->webhooks ) {
			$this->webhooks->dispatch_link_event( 'link.clicked', $url, null, null, $click_payload );
		}
	}

	/**
	 * Detect a near-immediate duplicate click from the same visitor.
	 *
	 * @param string      $url_id       Short-link row ID.
	 * @param string|null $visitor_hash Visitor fingerprint hash.
	 * @param string|null $ip_address   Client IP address.
	 * @param string|null $user_agent   Raw user-agent string.
	 * @param string      $clicked_at   Current click timestamp in UTC.
	 * @return bool True when a recent matching click already exists.
	 * @since 1.0.0
	 */
	private function is_duplicate_click(
		string $url_id,
		?string $visitor_hash,
		?string $ip_address,
		?string $user_agent,
		string $clicked_at
	): bool {
		$threshold = ( new \DateTimeImmutable(
			$clicked_at,
			new \DateTimeZone( 'UTC' ),
		) )
			->modify( '-2 seconds' )
			->format( 'Y-m-d H:i:s' );

		if ( null !== $visitor_hash && '' !== $visitor_hash ) {
			return false !== $this->db->get_var(
				'SELECT id
				FROM clicks
				WHERE url_id = :url_id
				AND visitor_hash = :visitor_hash
				AND clicked_at >= :threshold
				ORDER BY clicked_at DESC
				LIMIT 1',
				array(
					'url_id'       => $url_id,
					'visitor_hash' => $visitor_hash,
					'threshold'    => $threshold,
				),
			);
		}

		if (
			( null === $ip_address || '' === $ip_address ) &&
			( null === $user_agent || '' === $user_agent )
		) {
			return false;
		}

		return false !== $this->db->get_var(
			'SELECT id
			FROM clicks
			WHERE url_id = :url_id
			AND COALESCE(ip_address, \'\') = :ip_address
			AND COALESCE(user_agent, \'\') = :user_agent
			AND clicked_at >= :threshold
			ORDER BY clicked_at DESC
			LIMIT 1',
			array(
				'url_id'     => $url_id,
				'ip_address' => (string) ( $ip_address ?? '' ),
				'user_agent' => (string) ( $user_agent ?? '' ),
				'threshold'  => $threshold,
			),
		);
	}

	/**
	 * Get the scoped FROM / WHERE clauses used by activity queries.
	 *
	 * @param array<string, mixed> $user  Current user.
	 * @param array<string, mixed> $query Optional activity filters.
	 * @return array{from: string, where: string, params: array<string, string>}
	 * @since 1.0.0
	 */
	public function prepare_activity_query(
		array $user,
		array $query = array()
	): array {
		$category   = trim( (string) ( $query['category'] ?? '' ) );
		$from       = 'FROM audit_logs a LEFT JOIN users actor ON actor.id = a.user_id LEFT JOIN urls u ON u.id = a.link_id';
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
	 * @since 1.0.0
	 */
	public function activity_select_sql(): string {
		return 'SELECT a.*,
			actor.first_name AS actor_first_name,
			actor.last_name AS actor_last_name,
			actor.display_name AS actor_display_name,
			actor.username AS actor_username,
			actor.email AS actor_email,
			actor.role AS actor_role,
			u.status AS current_link_status,
			u.id AS current_link_id';
	}

	/**
	 * Format a raw recent-click row for the dashboard.
	 *
	 * @param array<string, mixed> $row Raw click and link row.
	 * @return array<string, mixed> API-ready recent click item.
	 * @since 1.0.0
	 */
	public function format_recent_click( array $row ): array {
		$country = trim( (string) ( $row['click_country_name'] ?? '' ) );
		$city    = trim( (string) ( $row['click_city_name'] ?? '' ) );

		return array(
			'id'              => (string) $row['recent_click_id'],
			'clickedAt'       => Date::to_iso( (string) $row['recent_clicked_at'] ),
			'link'            => $this->link_formatter ? ( $this->link_formatter )( $row ) : array(),
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
}
