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
	 * Get the start-at timestamp for a given time window.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string, string> Window metadata using UTC start time.
	 * @since 1.0.0
	 */
	private function time_window( int $days ): array {
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
	 * Resolve the stats drawer range into chart and query metadata.
	 *
	 * @param string|null $range      Requested dashboard range token.
	 * @param int         $days       Number of days to look back.
	 * @param string      $created_at Link creation timestamp.
	 * @return array{key: string, days: int, start_at: string|null}
	 * @since 1.1.0
	 */
	private function get_link_stats_range_config(
		?string $range,
		int $days,
		string $created_at = ''
	): array {
		$range = sanitize_key( (string) $range );

		if ( 'all' === $range ) {
			return array(
				'key'      => 'all',
				'days'     => $this->get_link_lifetime_days( $created_at ),
				'start_at' => null,
			);
		}

		if ( '24h' === $range ) {
			$resolved_days = 1;
		} elseif ( '30d' === $range ) {
			$resolved_days = 30;
		} elseif ( '7d' === $range ) {
			$resolved_days = 7;
		} else {
			$resolved_days = max( 1, $days );
		}
		$window = $this->time_window( $resolved_days );

		return array(
			'key'      => '' !== $range ? $range : $resolved_days . 'd',
			'days'     => $resolved_days,
			'start_at' => $window['start_at'],
		);
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
		$last_7_window  = $this->time_window( 7 );
		$last_30_window = $this->time_window( 30 );
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
					'last_7_start_total'   => $last_7_window['start_at'],
					'last_7_start_unique'  => $last_7_window['start_at'],
					'last_30_start_total'  => $last_30_window['start_at'],
					'last_30_start_unique' => $last_30_window['start_at'],
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
	 * @param int    $average_window Average divisor for the period.
	 * @param string $average_unit   Average unit label key.
	 * @return array<string, mixed> Formatted period summary.
	 * @since 1.1.0
	 */
	private function format_link_period_summary(
		string $key,
		int $total_clicks,
		int $unique_clicks,
		int $average_window,
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
				$total_clicks / max( 1, $average_window ),
				2,
			),
			'averageUnit'     => $average_unit,
		);
	}

	/**
	 * Find the best traffic day for a link, including unique visitors.
	 *
	 * @param string $url_id URL ID to scope the lookup to.
	 * @return array<string, mixed>|null Best day payload or null when empty.
	 * @since 1.1.0
	 */
	private function get_link_best_day( string $url_id ): ?array {
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

		$best_date   = null;
		$best_clicks = 0;
		$best_unique = 0;

		foreach ( $buckets as $date => $bucket ) {
			$clicks = (int) ( $bucket['clicks'] ?? 0 );

			if ( $clicks < $best_clicks ) {
				continue;
			}

			if ( $clicks === $best_clicks && null !== $best_date && $date < $best_date ) {
				continue;
			}

			$best_date   = $date;
			$best_clicks = $clicks;
			$best_unique = min(
				count( (array) ( $bucket['unique'] ?? array() ) ),
				$clicks,
			);
		}

		if ( null === $best_date || $best_clicks <= 0 ) {
			return null;
		}

		return array(
			'date'            => $best_date,
			'totalClicks'     => $best_clicks,
			'uniqueClicks'    => $best_unique,
			'uniqueClickRate' => $this->get_unique_click_rate(
				$best_clicks,
				$best_unique,
			),
		);
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
		$window     = $this->time_window( $days );
		$timezone   = new \DateTimeZone( $window['timezone'] );
		$join_sql   = '';
		$conditions = array( 'c.clicked_at >= :start_at' );
		$params     = array( 'start_at' => $window['start_at'] );

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

		$lookup = array();

		$cursor = new \DateTimeImmutable(
			$window['start_date'],
			$timezone,
		);

		for ( $index = 0; $index < $days; $index++ ) {
			$date            = $cursor->format( 'Y-m-d' );
			$lookup[ $date ] = array(
				'clicks' => 0,
				'unique' => array(),
			);
			$cursor          = $cursor->modify( '+1 day' );
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

			$bucket_date = $clicked_at->setTimezone( $timezone )->format( 'Y-m-d' );

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
			'labels' => $labels,
			'clicks' => $clicks,
			'unique' => $unique,
		);
	}

	/**
	 * Group click metrics by a given column.
	 *
	 * @param string               $column      Column to aggregate on.
	 * @param string               $name_key    Output key name for the grouped label.
	 * @param string               $start_at    Start timestamp for the time window.
	 * @param string|null          $code_column Optional secondary column for codes.
	 * @param string|null          $url_id      Optional URL ID filter.
	 * @param array<string, mixed>|null $user   Optional user scope for site-level charts.
	 * @return array<int, array<string, mixed>> Sorted metric rows.
	 * @since 1.0.0
	 */
	private function group_click_metrics(
		string $column,
		string $name_key,
		string $start_at,
		?string $code_column = null,
		?string $url_id = null,
		?array $user = null
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

		$sql .=
			$join_sql .
			' WHERE ' .
			implode( ' AND ', $conditions ) .
			' GROUP BY item_name' .
			( $code_column ? ', item_code' : '' ) .
			' ORDER BY item_count DESC, item_name ASC LIMIT 12';

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
	 * Group referrers for a specific URL within a time window.
	 *
	 * @param string $url_id   URL ID to scope referrers to.
	 * @param string $start_at Start timestamp for the window.
	 * @return array<int, array{name: string, domain: string, category: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_referrers( string $url_id, string $start_at ): array {
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
                WHERE url_id = :url_id
                AND clicked_at >= :start_at
                GROUP BY referrer_name, referrer_domain, referrer_category
                ORDER BY referrer_count DESC, referrer_name ASC
                LIMIT 20',
				array(
					'url_id'   => $url_id,
					'start_at' => $start_at,
				),
			),
		);
	}

	/**
	 * Group referrer categories for a specific URL within a time window.
	 *
	 * @param string $url_id   URL ID to scope referrers to.
	 * @param string $start_at Start timestamp for the window.
	 * @return array<int, array{category: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_referrer_categories(
		string $url_id,
		string $start_at
	): array {
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
                WHERE url_id = :url_id
                AND clicked_at >= :start_at
                GROUP BY referrer_category
                ORDER BY referrer_count DESC, referrer_category ASC
                LIMIT 12',
				array(
					'url_id'   => $url_id,
					'start_at' => $start_at,
				),
			),
		);
	}

	/**
	 * Group UTM campaign data for a specific URL within a time window.
	 *
	 * @param string $url_id   URL ID to scope campaigns to.
	 * @param string $start_at Start timestamp for the window.
	 * @return array<int, array{campaign: string, source: string, medium: string, count: int}>
	 * @since 1.0.0
	 */
	private function group_utm_campaigns(
		string $url_id,
		string $start_at
	): array {
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
                WHERE url_id = :url_id
                AND clicked_at >= :start_at
                GROUP BY utm_campaign, utm_source, utm_medium
                ORDER BY utm_count DESC, utm_campaign ASC
                LIMIT 12',
				array(
					'url_id'   => $url_id,
					'start_at' => $start_at,
				),
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
