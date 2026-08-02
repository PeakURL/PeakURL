<?php
/**
 * Data store analytics support trait.
 *
 * @package PeakURL\Data
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Traits;

use PeakURL\Includes\Constants;
use PeakURL\Http\Request;
use PeakURL\Utils\Visitor;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AnalyticsSupportTrait — analytics recording helpers for Store.
 *
 * @since 1.0.0
 */
trait AnalyticsSupportTrait {

	/**
	 * Record an activity entry in the audit log.
	 *
	 * @param string              $type     Activity type identifier.
	 * @param string|null         $message  Human-readable message.
	 * @param string|null         $user_id  Associated user ID.
	 * @param string|null         $link_id  Associated link ID.
	 * @param array<string, mixed> $metadata Arbitrary metadata to store as JSON.
	 * @since 1.0.0
	 */
	private function record_activity(
		string $type,
		?string $message = null,
		?string $user_id = null,
		?string $link_id = null,
		array $metadata = array()
	): void {
		$this->db->insert(
			'audit_logs',
			array(
				'id'         => $this->generate_random_id(),
				'user_id'    => $user_id,
				'type'       => $type,
				'message'    => $message,
				'link_id'    => $link_id,
				'metadata'   => $this->encode_json( $metadata ),
				'created_at' => $this->now(),
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
	private function get_analytics_period( int $days ): array {
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
	 * @since 1.1.0
	 */
	private function get_analytics_timezone(): \DateTimeZone {
		$timezone = trim( (string) $this->get_option( 'site_timezone' ) );

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
	 * Dashboard summary cards compare the selected number of local day buckets
	 * with this same-length window shifted one calendar month back.
	 *
	 * @param array<string, string> $period Current dashboard period metadata.
	 * @param int                   $days   Number of selected day buckets.
	 * @return array{type: string, days: int, start_at: string, end_at: string, start_date: string, end_date: string}
	 * @since 1.2.2
	 */
	private function get_last_month_period( array $period, int $days ): array {
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

		// Clamp month-end dates so March 31 compares with February 28/29.
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
	 * When `$created_before` is supplied, the count represents the visible
	 * links that existed before that cutoff.
	 *
	 * @param array<string, mixed> $user           Current user.
	 * @param string|null          $created_before Optional exclusive creation cutoff.
	 * @return int Visible link count.
	 * @since 1.2.2
	 */
	private function get_dashboard_link_count(
		array $user,
		?string $created_before = null
	): int {
		$conditions = array();
		$params     = array();

		if ( null !== $created_before ) {
			$conditions[]             = 'u.created_at < :created_before';
			$params['created_before'] = $created_before;
		}

		$this->scope_link_visibility(
			$user,
			$conditions,
			$params,
			'u',
		);

		return (int) $this->query_value(
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
	 * The optional `$end_at` keeps last-month periods exact while the current
	 * dashboard period can stay open-ended and naturally include clicks up to
	 * the current request time.
	 *
	 * @param array<string, mixed> $user     Current user.
	 * @param string               $start_at Inclusive UTC period start.
	 * @param string|null          $end_at   Optional exclusive UTC period end.
	 * @return array{totalClicks: int, uniqueClicks: int, uniqueClickRate: float}
	 * @since 1.2.2
	 */
	private function get_dashboard_click_totals(
		array $user,
		string $start_at,
		?string $end_at = null
	): array {
		$join_sql   = '';
		$conditions = array( 'c.clicked_at >= :start_at' );
		$params     = array( 'start_at' => $start_at );

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		$this->scope_click_analytics(
			$user,
			$join_sql,
			$conditions,
			$params,
			'c',
			'u',
		);

		$stats = $this->query_one(
			'SELECT
	                COUNT(*) AS total_clicks,
	                COUNT(DISTINCT COALESCE(NULLIF(c.visitor_hash, \'\'), c.id)) AS unique_clicks
	            FROM clicks c' .
			$join_sql .
			' WHERE ' .
			implode( ' AND ', $conditions ),
			$params,
		) ?? array();

		$total_clicks  = (int) ( $stats['total_clicks'] ?? 0 );
		$unique_clicks = (int) ( $stats['unique_clicks'] ?? 0 );

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
	 * Resolve the stats drawer period into chart and query metadata.
	 *
	 * @param string|null $range            Requested dashboard period token.
	 * @param string|null $custom_date_from Custom start date in YYYY-MM-DD format.
	 * @param string|null $custom_date_to   Custom end date in YYYY-MM-DD format.
	 * @return array{key: string, days: int, start_at: string|null, end_at: string|null, start_date: string|null, end_date: string|null}
	 * @since 1.1.0
	 */
	private function get_link_stats_period(
		?string $range,
		?string $custom_date_from = null,
		?string $custom_date_to = null
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

		if ( '24h' === $range ) {
			$resolved_days = 1;
			$period_key    = '24h';
		} elseif ( '30d' === $range ) {
			$resolved_days = 30;
			$period_key    = '30d';
		} elseif ( '7d' === $range ) {
			$resolved_days = 7;
			$period_key    = '7d';
		} else {
			$resolved_days = 7;
			$period_key    = '7d';
		}
		$period = $this->get_analytics_period( $resolved_days );

		return array(
			'key'        => $period_key,
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
	 * @since 1.1.4
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
			$previous_start = $start;
			$start          = $end;
			$end            = $previous_start;
		}

		$exclusive_end = $end->modify( '+1 day' );

		return array(
			'key'        => 'custom',
			'days'       => max( 1, (int) $start->diff( $end )->format( '%a' ) + 1 ),
			'start_at'   => $start
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' ),
			'end_at'     => $exclusive_end
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' ),
			'start_date' => $start->format( 'Y-m-d' ),
			'end_date'   => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Normalize a date-only stats input.
	 *
	 * @param string|null $date_value Date value to normalize.
	 * @return string|null Valid YYYY-MM-DD value, or null when invalid.
	 * @since 1.1.4
	 */
	private function normalize_link_stats_date( ?string $date_value ): ?string {
		$date_value = trim( (string) $date_value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date_value, $matches ) ) {
			return null;
		}

		if (
			! checkdate(
				(int) $matches[2],
				(int) $matches[3],
				(int) $matches[1],
			)
		) {
			return null;
		}

		return $date_value;
	}

	/**
	 * Choose the chart bucket size for a custom traffic period.
	 *
	 * @param int $days Number of selected days.
	 * @return string Traffic series granularity.
	 * @since 1.1.4
	 */
	private function get_traffic_series_granularity( int $days ): string {
		$monthly_threshold_days = 120;

		return $days > $monthly_threshold_days ? 'month' : 'day';
	}

	/**
	 * Calculate how many date buckets are needed for all-time link charts.
	 *
	 * @param string $created_at Link creation timestamp.
	 * @return int Number of days between creation and today, inclusive.
	 * @since 1.1.0
	 */
	private function get_link_lifetime_days( string $created_at ): int {
		$timezone = $this->get_analytics_timezone();
		$today    = ( new \DateTimeImmutable( 'now', $timezone ) )
			->setTime( 0, 0, 0 );

		try {
			$created = new \DateTimeImmutable(
				$created_at,
				new \DateTimeZone( 'UTC' ),
			);
			$start   = $created->setTimezone( $timezone )->setTime( 0, 0, 0 );
		} catch ( \Exception $exception ) {
			$start = $today;
		}

		if ( $start > $today ) {
			return 1;
		}

		return max( 1, (int) $start->diff( $today )->format( '%a' ) + 1 );
	}

	/**
	 * Build exact period totals for the link stats drawer.
	 *
	 * @param string $url_id     URL ID to scope summaries to.
	 * @param string $created_at Link creation timestamp.
	 * @return array<string, array<string, mixed>> Period totals keyed by range.
	 * @since 1.1.0
	 */
	private function get_link_period_summaries(
		string $url_id,
		string $created_at
	): array {
		$timezone       = $this->get_analytics_timezone();
		$last_24_start  =
			( new \DateTimeImmutable( 'now', $timezone ) )
				->modify( '-24 hours' )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		$last_7_period  = $this->get_analytics_period( 7 );
		$last_30_period = $this->get_analytics_period( 30 );
		$lifetime_days  = $this->get_link_lifetime_days( $created_at );
		$unique_visitor = 'COALESCE(NULLIF(visitor_hash, \'\'), id)';
		$period_totals  =
			$this->query_one(
				'SELECT
	                COUNT(*) AS all_total,
	                COUNT(DISTINCT ' . $unique_visitor . ') AS all_unique,
	                SUM(CASE WHEN clicked_at >= :last_24_start_total THEN 1 ELSE 0 END) AS last_24_total,
	                COUNT(DISTINCT CASE WHEN clicked_at >= :last_24_start_unique THEN ' . $unique_visitor . ' ELSE NULL END) AS last_24_unique,
	                SUM(CASE WHEN clicked_at >= :last_7_start_total THEN 1 ELSE 0 END) AS last_7_total,
	                COUNT(DISTINCT CASE WHEN clicked_at >= :last_7_start_unique THEN ' . $unique_visitor . ' ELSE NULL END) AS last_7_unique,
	                SUM(CASE WHEN clicked_at >= :last_30_start_total THEN 1 ELSE 0 END) AS last_30_total,
	                COUNT(DISTINCT CASE WHEN clicked_at >= :last_30_start_unique THEN ' . $unique_visitor . ' ELSE NULL END) AS last_30_unique
	            FROM clicks
	            WHERE url_id = :url_id',
				array(
					'url_id'               => $url_id,
					'last_24_start_total'  => $last_24_start,
					'last_24_start_unique' => $last_24_start,
					'last_7_start_total'   => $last_7_period['start_at'],
					'last_7_start_unique'  => $last_7_period['start_at'],
					'last_30_start_total'  => $last_30_period['start_at'],
					'last_30_start_unique' => $last_30_period['start_at'],
				),
			) ?? array();

		return array(
			'last24Hours' => $this->format_link_period_summary(
				'last24Hours',
				(int) ( $period_totals['last_24_total'] ?? 0 ),
				(int) ( $period_totals['last_24_unique'] ?? 0 ),
				24,
				'hour',
			),
			'last7Days'   => $this->format_link_period_summary(
				'last7Days',
				(int) ( $period_totals['last_7_total'] ?? 0 ),
				(int) ( $period_totals['last_7_unique'] ?? 0 ),
				7,
				'day',
			),
			'last30Days'  => $this->format_link_period_summary(
				'last30Days',
				(int) ( $period_totals['last_30_total'] ?? 0 ),
				(int) ( $period_totals['last_30_unique'] ?? 0 ),
				30,
				'day',
			),
			'allTime'     => $this->format_link_period_summary(
				'allTime',
				(int) ( $period_totals['all_total'] ?? 0 ),
				(int) ( $period_totals['all_unique'] ?? 0 ),
				$lifetime_days,
				'day',
			),
		);
	}

	/**
	 * Normalize one period summary row.
	 *
	 * @param string $key            Stable period key.
	 * @param int    $total_clicks   Total clicks in the period.
	 * @param int    $unique_clicks  Unique visitors in the period.
	 * @param int    $average_days   Average divisor for the period.
	 * @param string $average_unit   Average unit label key.
	 * @return array<string, mixed> Formatted period summary.
	 * @since 1.1.0
	 */
	private function format_link_period_summary(
		string $key,
		int $total_clicks,
		int $unique_clicks,
		int $average_days,
		string $average_unit
	): array {
		$unique_clicks = min( $unique_clicks, $total_clicks );

		return array(
			'key'             => $key,
			'totalClicks'     => $total_clicks,
			'uniqueClicks'    => $unique_clicks,
			'uniqueClickRate' => $this->get_unique_click_rate(
				$total_clicks,
				$unique_clicks,
			),
			'averageClicks'   => round(
				$total_clicks / max( 1, $average_days ),
				2,
			),
			'averageUnit'     => $average_unit,
		);
	}

	/**
	 * Build the full click history for a link, grouped by active day.
	 *
	 * @param string $url_id URL ID to scope the lookup to.
	 * @return array<int, array<string, mixed>> Active day payloads sorted oldest first.
	 * @since 1.1.4
	 */
	private function get_link_click_history_days( string $url_id ): array {
		$timezone = $this->get_analytics_timezone();
		$rows     = $this->query_all(
			'SELECT
                clicked_at,
                COALESCE(NULLIF(visitor_hash, \'\'), id) AS visitor_key
            FROM clicks
            WHERE url_id = :url_id
            ORDER BY clicked_at ASC',
			array( 'url_id' => $url_id ),
		);
		$buckets  = array();

		foreach ( $rows as $row ) {
			try {
				$clicked_at = new \DateTimeImmutable(
					(string) $row['clicked_at'],
					new \DateTimeZone( 'UTC' ),
				);
			} catch ( \Exception $exception ) {
				continue;
			}

			$date = $clicked_at->setTimezone( $timezone )->format( 'Y-m-d' );

			if ( ! isset( $buckets[ $date ] ) ) {
				$buckets[ $date ] = array(
					'clicks' => 0,
					'unique' => array(),
				);
			}

			++$buckets[ $date ]['clicks'];

			$visitor_key = (string) ( $row['visitor_key'] ?? '' );

			if ( '' !== $visitor_key ) {
				$buckets[ $date ]['unique'][ $visitor_key ] = true;
			}
		}

		ksort( $buckets );

		$days = array();
		foreach ( $buckets as $date => $bucket ) {
			$total_clicks = (int) ( $bucket['clicks'] ?? 0 );

			if ( $total_clicks <= 0 ) {
				continue;
			}

			$unique_clicks = min(
				count( (array) ( $bucket['unique'] ?? array() ) ),
				$total_clicks,
			);

			$days[] = array(
				'date'            => $date,
				'totalClicks'     => $total_clicks,
				'uniqueClicks'    => $unique_clicks,
				'uniqueClickRate' => $this->get_unique_click_rate(
					$total_clicks,
					$unique_clicks,
				),
			);
		}

		return $days;
	}

	/**
	 * Find the best traffic day for a link, including unique visitors.
	 *
	 * @param array<int, array<string, mixed>> $click_days Active day payloads.
	 * @return array<string, mixed>|null Best day payload or null when empty.
	 * @since 1.1.0
	 */
	private function get_link_best_day( array $click_days ): ?array {
		$best_day    = null;
		$best_clicks = 0;

		foreach ( $click_days as $day ) {
			$date         = (string) ( $day['date'] ?? '' );
			$total_clicks = (int) ( $day['totalClicks'] ?? 0 );

			if ( '' === $date || $total_clicks < $best_clicks ) {
				continue;
			}

			if (
				$total_clicks === $best_clicks
				&& null !== $best_day
				&& $date < (string) $best_day['date']
			) {
				continue;
			}

			$best_day    = $day;
			$best_clicks = $total_clicks;
		}

		if ( null === $best_day || $best_clicks <= 0 ) {
			return null;
		}

		return $best_day;
	}

	/**
	 * Get a date-bucketed traffic time series for charts.
	 *
	 * @param string|null         $url_id Optional URL ID to scope the series.
	 * @param int                 $days   Number of days to include.
	 * @param array<string, mixed>|null $user Optional user scope for site-level charts.
	 * @return array{labels: string[], clicks: int[], unique: int[]}
	 * @since 1.0.0
	 */
	private function query_traffic_series(
		?string $url_id,
		int $days,
		?array $user = null
	): array {
		$period = $this->get_analytics_period( $days );

		return $this->query_traffic_series_range(
			$url_id,
			$period['start_date'],
			$days,
			$period['start_at'],
			null,
			$period['timezone'],
			$user,
			'day',
		);
	}

	/**
	 * Get a date-bucketed traffic time series for a specific date range.
	 *
	 * @param string|null              $url_id     Optional URL ID to scope the series.
	 * @param string                   $start_date First local date bucket in YYYY-MM-DD format.
	 * @param int                      $days       Number of day buckets to include.
	 * @param string                   $start_at   Inclusive UTC start timestamp.
	 * @param string|null              $end_at     Optional exclusive UTC end timestamp.
	 * @param string                   $timezone   Timezone used for local buckets.
	 * @param array<string, mixed>|null $user      Optional user scope for site-level charts.
	 * @param string                   $granularity Bucket size, either day or month.
	 * @return array{labels: string[], clicks: int[], unique: int[], granularity: string}
	 * @since 1.1.4
	 */
	private function query_traffic_series_range(
		?string $url_id,
		string $start_date,
		int $days,
		string $start_at,
		?string $end_at,
		string $timezone,
		?array $user = null,
		string $granularity = 'day'
	): array {
		$timezone    = new \DateTimeZone( $timezone );
		$granularity = 'month' === $granularity ? 'month' : 'day';
		$join_sql    = '';
		$conditions  = array( 'c.clicked_at >= :start_at' );
		$params      = array( 'start_at' => $start_at );

		if ( null !== $end_at ) {
			$conditions[]     = 'c.clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->scope_click_analytics(
				$user,
				$join_sql,
				$conditions,
				$params,
				'c',
				'u',
			);
		}

		$rows = $this->query_all(
			'SELECT
                c.clicked_at,
                COALESCE(NULLIF(c.visitor_hash, \'\'), c.id) AS visitor_key
            FROM clicks c' .
				$join_sql .
				' WHERE ' .
				implode( ' AND ', $conditions ) .
				' ORDER BY c.clicked_at ASC',
			$params,
		);

		$lookup      = array();
		$range_start = ( new \DateTimeImmutable(
			$start_date,
			$timezone
		) )->setTime( 0, 0, 0 );
		$range_end   = $range_start->modify( '+' . max( 0, $days - 1 ) . ' days' );
		$cursor      = $range_start;

		if ( 'month' === $granularity ) {
			$cursor    = $range_start->modify( 'first day of this month' );
			$range_end = $range_end->modify( 'first day of this month' );
		}

		while ( $cursor <= $range_end ) {
			$date            = 'month' === $granularity
				? $cursor->format( 'Y-m-01' )
				: $cursor->format( 'Y-m-d' );
			$lookup[ $date ] = array(
				'clicks' => 0,
				'unique' => array(),
			);
			$cursor          = $cursor->modify( 'month' === $granularity ? '+1 month' : '+1 day' );
		}

		foreach ( $rows as $row ) {
			try {
				$clicked_at = new \DateTimeImmutable(
					(string) $row['clicked_at'],
					new \DateTimeZone( 'UTC' ),
				);
			} catch ( \Exception $exception ) {
				continue;
			}

			$bucket_date = $clicked_at->setTimezone( $timezone )->format(
				'month' === $granularity ? 'Y-m-01' : 'Y-m-d'
			);

			if ( ! isset( $lookup[ $bucket_date ] ) ) {
				continue;
			}

			$visitor_key = (string) ( $row['visitor_key'] ?? '' );

			++$lookup[ $bucket_date ]['clicks'];

			if ( '' !== $visitor_key ) {
				$lookup[ $bucket_date ]['unique'][ $visitor_key ] = true;
			}
		}

		$labels = array();
		$clicks = array();
		$unique = array();

		foreach ( $lookup as $date => $bucket ) {
			$click_count = (int) ( $bucket['clicks'] ?? 0 );

			$labels[] = $date;
			$clicks[] = $click_count;
			$unique[] = min(
				count( (array) ( $bucket['unique'] ?? array() ) ),
				$click_count,
			);
		}

			return array(
				'labels'      => $labels,
				'clicks'      => $clicks,
				'unique'      => $unique,
				'granularity' => $granularity,
			);
	}

	/**
	 * Group click metrics by a given column.
	 *
	 * @param string               $column      Column to aggregate on.
	 * @param string               $name_key    Output key name for the grouped label.
	 * @param string               $start_at    Start timestamp for the period.
	 * @param string|null          $end_at      Optional exclusive end timestamp for the date range.
	 * @param string|null          $code_column Optional secondary column for codes.
	 * @param string|null          $url_id      Optional URL ID filter.
	 * @param array<string, mixed>|null $user   Optional user scope for site-level charts.
	 * @param int|null             $limit       Maximum metric rows, or null for all rows.
	 * @return array<int, array<string, mixed>> Sorted metric rows.
	 * @since 1.0.0
	 */
	private function group_click_metrics(
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
			$this->scope_click_analytics(
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
			' ORDER BY item_count DESC, item_name ASC' .
			$limit_sql;

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
					$item['code'] = (string) ( $row['item_code'] ?? '??' );
				}

				return $item;
			},
			$this->query_all( $sql, $params )
		);
	}

	/**
	 * Group referrers for a specific URL within a date range.
	 *
	 * @param string      $url_id   URL ID to scope referrers to.
	 * @param string      $start_at Start timestamp for the date range.
	 * @param string|null $end_at   Optional exclusive end timestamp for the date range.
	 * @return array<int, array{name: string, domain: string, category: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_referrers(
		string $url_id,
		string $start_at,
		?string $end_at = null
	): array {
		$conditions = array(
			'url_id = :url_id',
			'clicked_at >= :start_at',
		);
		$params     = array(
			'url_id'   => $url_id,
			'start_at' => $start_at,
		);

		if ( null !== $end_at ) {
			$conditions[]     = 'clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		return array_map(
			static fn( array $row ): array => array(
				'name'     =>
					(string) ( $row['referrer_name'] ?? 'Direct / Unknown' ),
				'domain'   => (string) ( $row['referrer_domain'] ?? '' ),
				'category' => (string) ( $row['referrer_category'] ?? 'Unknown' ),
				'count'    => (int) $row['referrer_count'],
			),
			$this->query_all(
				'SELECT
                    COALESCE(NULLIF(referrer_name, \'\'), \'Direct / Unknown\') AS referrer_name,
                    COALESCE(NULLIF(referrer_domain, \'\'), \'\') AS referrer_domain,
                    COALESCE(NULLIF(referrer_category, \'\'), \'Unknown\') AS referrer_category,
                    COUNT(*) AS referrer_count
                FROM clicks
                WHERE ' . implode( ' AND ', $conditions ) . '
                GROUP BY referrer_name, referrer_domain, referrer_category
                ORDER BY referrer_count DESC, referrer_name ASC
                LIMIT 20',
				$params,
			),
		);
	}

	/**
	 * Group referrer categories for a specific URL within a date range.
	 *
	 * @param string      $url_id   URL ID to scope referrers to.
	 * @param string      $start_at Start timestamp for the date range.
	 * @param string|null $end_at   Optional exclusive end timestamp for the date range.
	 * @return array<int, array{category: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_referrer_categories(
		string $url_id,
		string $start_at,
		?string $end_at = null
	): array {
		$conditions = array(
			'url_id = :url_id',
			'clicked_at >= :start_at',
		);
		$params     = array(
			'url_id'   => $url_id,
			'start_at' => $start_at,
		);

		if ( null !== $end_at ) {
			$conditions[]     = 'clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		return array_map(
			static fn( array $row ): array => array(
				'category' => (string) ( $row['referrer_category'] ?? 'Unknown' ),
				'count'    => (int) $row['referrer_count'],
			),
			$this->query_all(
				'SELECT
                    COALESCE(NULLIF(referrer_category, \'\'), \'Unknown\') AS referrer_category,
                    COUNT(*) AS referrer_count
                FROM clicks
                WHERE ' . implode( ' AND ', $conditions ) . '
                GROUP BY referrer_category
                ORDER BY referrer_count DESC, referrer_category ASC
                LIMIT 12',
				$params,
			),
		);
	}

	/**
	 * Group UTM campaign data for a specific URL within a date range.
	 *
	 * @param string      $url_id   URL ID to scope campaigns to.
	 * @param string      $start_at Start timestamp for the date range.
	 * @param string|null $end_at   Optional exclusive end timestamp for the date range.
	 * @return array<int, array{campaign: string, source: string, medium: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_utm_campaigns(
		string $url_id,
		string $start_at,
		?string $end_at = null
	): array {
		$conditions = array(
			'url_id = :url_id',
			'clicked_at >= :start_at',
		);
		$params     = array(
			'url_id'   => $url_id,
			'start_at' => $start_at,
		);

		if ( null !== $end_at ) {
			$conditions[]     = 'clicked_at < :end_at';
			$params['end_at'] = $end_at;
		}

		return array_map(
			static fn( array $row ): array => array(
				'campaign' => (string) ( $row['utm_campaign'] ?? '' ),
				'source'   => (string) ( $row['utm_source'] ?? '' ),
				'medium'   => (string) ( $row['utm_medium'] ?? '' ),
				'count'    => (int) $row['utm_count'],
			),
			$this->query_all(
				'SELECT
                    COALESCE(NULLIF(utm_campaign, \'\'), \'Unattributed\') AS utm_campaign,
                    COALESCE(NULLIF(utm_source, \'\'), \'\') AS utm_source,
                    COALESCE(NULLIF(utm_medium, \'\'), \'\') AS utm_medium,
                    COUNT(*) AS utm_count
                FROM clicks
                WHERE ' . implode( ' AND ', $conditions ) . '
                GROUP BY utm_campaign, utm_source, utm_medium
                ORDER BY utm_count DESC, utm_campaign ASC
                LIMIT 12',
				$params,
			),
		);
	}

	/**
	 * Record a click event against a short URL.
	 *
	 * @param array<string, mixed> $url                URL row from the database.
	 * @param Request              $request            Incoming HTTP request.
	 * @param bool                 $allow_non_get_hit  Whether a non-GET request should count.
	 * @since 1.0.0
	 */
	private function record_click(
		array $url,
		Request $request,
		bool $allow_non_get_hit = false
	): void {
		$user_agent = $this->nullable_string( $request->get_user_agent() );
		$ip_address = $this->nullable_string( $request->get_ip_address() );
		$now        = $this->now();

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

		$referrer     = Visitor::parse_referrer(
			$request->get_header( 'Referer', '' ),
		);
		$metadata     = Visitor::parse_user_agent(
			(string) ( $user_agent ?? '' ),
		);
		$location     = $this->geoip_service->lookup_location(
			(string) ( $ip_address ?? '' ),
		);
		$visitor_hash = Visitor::hash_request( $request );

		$this->db->insert(
			'clicks',
			array(
				'id'                => $this->generate_random_id(),
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
				'utm_source'        => $this->nullable_string(
					$url['utm_source'] ?? null,
				),
				'utm_medium'        => $this->nullable_string(
					$url['utm_medium'] ?? null,
				),
				'utm_campaign'      => $this->nullable_string(
					$url['utm_campaign'] ?? null,
				),
				'utm_term'          => $this->nullable_string(
					$url['utm_term'] ?? null,
				),
				'utm_content'       => $this->nullable_string(
					$url['utm_content'] ?? null,
				),
				'user_agent'        => $user_agent,
			),
		);
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
			return false !== $this->query_value(
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

		return false !== $this->query_value(
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
}
