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
use PeakURL\Includes\RuntimeConfig;
use PeakURL\Http\ApiException;
use PeakURL\Http\Request;
use PeakURL\Services\Crypto;
use PeakURL\Services\Geoip;
use PeakURL\Services\Mailer;
use PeakURL\Services\SetupConfig;
use PeakURL\Services\Update;
use PeakURL\Utils\Query;
use PeakURL\Utils\Security;
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
	 * Build the start-at timestamp for a given time window.
	 *
	 * @param int $days Number of days to look back.
	 * @return array<string, string> Map with a single `start_at` key.
	 * @since 1.0.0
	 */
	private function build_time_window( int $days ): array {
		$timezone   = new \DateTimeZone( 'UTC' );
		$start_date =
			( new \DateTimeImmutable( 'now', $timezone ) )
				->setTime( 0, 0, 0 )
				->modify( '-' . max( 0, $days - 1 ) . ' days' );

		return array(
			'start_at' => $start_date->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Build a date-bucketed traffic time series for charts.
	 *
	 * @param string|null         $url_id Optional URL ID to scope the series.
	 * @param int                 $days   Number of days to include.
	 * @param array<string, mixed>|null $user Optional user scope for site-level charts.
	 * @return array{labels: string[], clicks: int[], unique: int[]}
	 * @since 1.0.0
	 */
	private function build_traffic_series(
		?string $url_id,
		int $days,
		?array $user = null
	): array {
		$window     = $this->build_time_window( $days );
		$join_sql   = '';
		$conditions = array( 'c.clicked_at >= :start_at' );
		$params     = array( 'start_at' => $window['start_at'] );

		if ( $url_id ) {
			$conditions[]     = 'c.url_id = :url_id';
			$params['url_id'] = $url_id;
		} elseif ( null !== $user ) {
			$this->add_click_analytics_scope(
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
                DATE(c.clicked_at) AS bucket_date,
                COUNT(*) AS clicks,
                COUNT(DISTINCT COALESCE(NULLIF(c.visitor_hash, \'\'), c.id)) AS unique_clicks
            FROM clicks c' .
				$join_sql .
				' WHERE ' .
				implode( ' AND ', $conditions ) .
				'
            GROUP BY bucket_date
            ORDER BY bucket_date ASC',
			$params,
		);

		$lookup = array();

		foreach ( $rows as $row ) {
			$click_count  = (int) $row['clicks'];
			$unique_count = min(
				(int) $row['unique_clicks'],
				$click_count,
			);

			$lookup[ $row['bucket_date'] ] = array(
				'clicks' => $click_count,
				'unique' => $unique_count,
			);
		}

		$labels = array();
		$clicks = array();
		$unique = array();
		$cursor = new \DateTimeImmutable(
			$window['start_at'],
			new \DateTimeZone( 'UTC' ),
		);

		for ( $index = 0; $index < $days; $index++ ) {
			$date     = $cursor->format( 'Y-m-d' );
			$labels[] =
				$days <= 7 ? $cursor->format( 'D' ) : $cursor->format( 'M j' );
			$clicks[] = (int) ( $lookup[ $date ]['clicks'] ?? 0 );
			$unique[] = (int) ( $lookup[ $date ]['unique'] ?? 0 );
			$cursor   = $cursor->modify( '+1 day' );
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
			$this->add_click_analytics_scope(
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
			Visitor::should_skip_click_tracking(
				$request,
				$allow_non_get_hit,
			) ||
			$this->is_duplicate_click(
				(string) $url['id'],
				Visitor::build_hash( $request ),
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
		$location     = $this->geoip_service->resolve_location(
			(string) ( $ip_address ?? '' ),
		);
		$visitor_hash = Visitor::build_hash( $request );

		$this->db->insert(
			'clicks',
			array(
				'id'                => $this->generate_id( 'click' ),
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
