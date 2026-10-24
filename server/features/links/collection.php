<?php
/**
 * Link collection domain service.
 *
 * Implements link collection query coordination, pagination, filtering,
 * click totals and statistics, count badges, and export.
 *
 * @package PeakURL\Features\Links
 * @since 1.7.2
 */

declare(strict_types=1);

namespace PeakURL\Features\Links;

use PeakURL\Core\Auth\Roles;
use PeakURL\Core\Errors\ApiException;
use PeakURL\Features\Analytics\Service as AnalyticsService;
use PeakURL\Features\Auth\Service as AuthService;
use PeakURL\Http\Request;
use PeakURL\Services\Database\Query;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * LinkCollection — Handles link collection queries, pagination, statistics, and export.
 *
 * @since 1.7.2
 */
class LinkCollection {

	/**
	 * Link repository handler.
	 *
	 * @var Repository
	 * @since 1.7.2
	 */
	private Repository $repository;

	/**
	 * Authentication domain service.
	 *
	 * @var AuthService
	 * @since 1.7.2
	 */
	private AuthService $auth_service;

	/**
	 * Analytics domain service.
	 *
	 * @var AnalyticsService
	 * @since 1.7.2
	 */
	private AnalyticsService $analytics_service;

	/**
	 * Link formatter callback for API payloads.
	 *
	 * @var callable
	 * @since 1.7.2
	 */
	private $link_formatter;

	/**
	 * Optional health payload formatter callback.
	 *
	 * @var callable|null
	 * @since 1.7.2
	 */
	private $health_formatter;

	/**
	 * Roles capability authority service.
	 *
	 * @var Roles
	 * @since 1.7.2
	 */
	private Roles $roles;

	/**
	 * Canonical link analytics filter callback.
	 *
	 * @var callable
	 * @since 1.7.2
	 */
	private $analytics_filter;

	/**
	 * Create a new LinkCollection instance.
	 *
	 * @param Repository       $repository        Repository handler.
	 * @param AuthService      $auth_service      Authentication domain service.
	 * @param AnalyticsService $analytics_service Analytics domain service.
	 * @param callable         $link_formatter    Link formatter callback.
	 * @param Roles            $roles             Roles authority.
	 * @param callable         $analytics_filter  Required canonical link analytics filter callback.
	 * @param callable|null    $health_formatter  Optional health payload formatter callback.
	 * @since 1.7.2
	 */
	public function __construct(
		Repository $repository,
		AuthService $auth_service,
		AnalyticsService $analytics_service,
		callable $link_formatter,
		Roles $roles,
		callable $analytics_filter,
		?callable $health_formatter = null
	) {
		$this->repository        = $repository;
		$this->auth_service      = $auth_service;
		$this->analytics_service = $analytics_service;
		$this->link_formatter    = $link_formatter;
		$this->roles             = $roles;
		$this->analytics_filter  = $analytics_filter;
		$this->health_formatter  = $health_formatter;
	}

	/**
	 * List short URLs with pagination, sorting, and optional search.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Query parameters for pagination/sorting/search.
	 * @return array<string, mixed> Paginated URL list with meta.
	 * @since 1.7.2
	 */
	public function list_urls( Request $request, array $query ): array {
		$user = $this->auth_service->get_current_user( $request );

		$requested_sort = (string) ( $query['sortBy'] ?? '' );
		if ( in_array( strtolower( $requested_sort ), array( 'clicks', 'uniqueclicks' ), true ) ) {
			if ( ! $this->roles->is_admin( $user ) ) {
				throw new ApiException(
					__( 'You do not have permission to sort by analytics metrics.', 'peakurl' ),
					403
				);
			}
		}

		$can_view_analytics = $this->roles->has_capability( $user, 'view_analytics' );

		$pagination = Query::pagination( $query, 25 );
		$page       = $pagination['page'];
		$limit      = $pagination['limit'];
		$offset     = $pagination['offset'];

		$link_query = $this->repository->get_link_collection_query(
			$user,
			$query,
			null,
			fn( string $range, string $start_at, string $end_at ): array => $this->analytics_service->get_link_stats_period( $range, $start_at, $end_at ),
		);

		$count = $this->repository->count_link_rows(
			$link_query['where'],
			$link_query['params'],
		);
		$stats = $this->get_link_collection_stats(
			$user,
			$query,
			$link_query['where'],
			$link_query['params'],
			$link_query['statsParams'],
			$can_view_analytics
		);
		$rows  = $this->repository->get_link_rows(
			$link_query['where'],
			$link_query['params'],
			$link_query['sortBy'],
			$link_query['sortOrder'],
			$limit,
			$offset,
			$can_view_analytics ? $link_query['statsParams'] : array(),
		);

		$meta = array(
			'page'         => $page,
			'limit'        => $limit,
			'totalItems'   => $count,
			'totalPages'   => max( 1, (int) ceil( $count / $limit ) ),
			'totalClicks'  => $can_view_analytics ? $stats['totalClicks'] : null,
			'uniqueClicks' => $can_view_analytics ? $stats['uniqueClicks'] : null,
			'activeLinks'  => $stats['activeLinks'],
			'trashedLinks' => $this->count_trashed_links( $request ),
			'expiredLinks' => $this->count_expired_links( $request ),
		);

		if ( isset( $stats['lastPeriodTotalClicks'] ) || ( ! $can_view_analytics && in_array( trim( (string) ( $query['range'] ?? '' ) ), array( '24h', '7d', '30d' ), true ) ) ) {
			$meta['lastPeriodTotalClicks']  = $can_view_analytics ? ( $stats['lastPeriodTotalClicks'] ?? null ) : null;
			$meta['lastPeriodUniqueClicks'] = $can_view_analytics ? ( $stats['lastPeriodUniqueClicks'] ?? null ) : null;
		}

		$row_ids    = array_map( 'strval', array_column( $rows, 'id' ) );
		$health_map = ! empty( $row_ids ) ? $this->repository->get_link_health_by_ids( $row_ids ) : array();

		$items = array_map(
			function ( array $row ) use ( $health_map, $user ): array {
				$formatted           = ( $this->link_formatter )( $row );
				$id                  = (string) ( $row['id'] ?? '' );
				$formatted['health'] = isset( $health_map[ $id ] ) && null !== $this->health_formatter
					? ( $this->health_formatter )( $health_map[ $id ] )
					: null;
				return ( $this->analytics_filter )( $formatted, $user, (string) ( $row['user_id'] ?? '' ) );
			},
			$rows
		);

		return array(
			'items' => $items,
			'meta'  => $meta,
		);
	}

	/**
	 * Export all accessible short URLs for the current user.
	 *
	 * @param Request              $request Incoming HTTP request.
	 * @param array<string, mixed> $query   Optional sort/search parameters.
	 * @return array<string, mixed> Full accessible link export payload.
	 * @since 1.7.2
	 */
	public function export_urls( Request $request, array $query = array() ): array {
		$user = $this->auth_service->get_current_user( $request );

		$requested_sort = (string) ( $query['sortBy'] ?? '' );
		if ( in_array( strtolower( $requested_sort ), array( 'clicks', 'uniqueclicks' ), true ) ) {
			if ( ! $this->roles->is_admin( $user ) ) {
				throw new ApiException(
					__( 'You do not have permission to sort by analytics metrics.', 'peakurl' ),
					403
				);
			}
		}

		$can_view_analytics = $this->roles->has_capability( $user, 'view_analytics' );

		$link_query = $this->repository->get_link_collection_query(
			$user,
			$query,
			null,
			fn( string $range, string $start_at, string $end_at ): array => $this->analytics_service->get_link_stats_period( $range, $start_at, $end_at ),
		);
		$rows       = $this->repository->get_link_rows(
			$link_query['where'],
			$link_query['params'],
			$link_query['sortBy'],
			$link_query['sortOrder'],
			null,
			null,
			$can_view_analytics ? $link_query['statsParams'] : array(),
		);

		$items = array_map(
			function ( array $row ) use ( $user ): array {
				$formatted = ( $this->link_formatter )( $row );
				return ( $this->analytics_filter )( $formatted, $user, (string) ( $row['user_id'] ?? '' ) );
			},
			$rows,
		);

		$export = array(
			'items' => $items,
			'meta'  => array(
				'totalItems' => count( $items ),
			),
		);

		if ( \has_action( 'link_export_prepared' ) ) {
			$actor = array(
				'id'       => (string) ( $user['id'] ?? '' ),
				'username' => (string) ( $user['username'] ?? '' ),
				'role'     => (string) ( $user['role'] ?? '' ),
			);

			try {
				/**
				 * Fires after an authorized link export payload has been prepared.
				 *
				 * @since 1.8.0
				 *
				 * @param array<string, mixed> $export Prepared export payload with 'items' and 'meta' ('totalItems').
				 * @param array<string, mixed> $actor  Minimal authenticated actor context ('id', 'username', 'role').
				 */
				\do_action( 'link_export_prepared', $export, $actor );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( sprintf( 'PeakURL link_export_prepared observer error: %s', get_class( $e ) ) );
			}
		}

		return $export;
	}

	/**
	 * Count trashed links for the current user/scope.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Total trashed links.
	 * @since 1.7.2
	 */
	public function count_trashed_links( Request $request ): int {
		$user = $this->auth_service->get_current_user( $request );

		return $this->repository->count_trashed_links( $user );
	}

	/**
	 * Count expired links for the current user/scope.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return int Total expired links.
	 * @since 1.7.2
	 */
	public function count_expired_links( Request $request ): int {
		$user = $this->auth_service->get_current_user( $request );

		return $this->repository->count_expired_links( $user );
	}

	/**
	 * Retrieve total statistics and comparison values for a link collection query.
	 *
	 * Scopes click metrics strictly to links owned by the caller when the caller
	 * is not an administrator. General inventory active links count remains site-wide.
	 *
	 * @param array<string, mixed>  $user               Current user array.
	 * @param array<string, mixed>  $query              Raw query parameters.
	 * @param string                $where              Prepared WHERE clause.
	 * @param array<string, mixed>  $params             Query parameters.
	 * @param array<string, string> $stats_params       Optional click-stat query bounds.
	 * @param bool                  $can_view_analytics Whether analytics metrics should be computed.
	 * @return array<string, int|null> Link collection statistics.
	 * @since 1.7.2
	 */
	private function get_link_collection_stats(
		array $user,
		array $query,
		string $where,
		array $params,
		array $stats_params,
		bool $can_view_analytics = true
	): array {
		$is_admin     = $this->roles->is_admin( $user );
		$click_where  = null;
		$click_params = null;

		if ( ! $is_admin && $can_view_analytics ) {
			$click_owner_id = (string) ( $user['id'] ?? '' );
			$click_where    = '' === $where
				? 'WHERE u.user_id = :stats_owner_user_id'
				: $where . ' AND u.user_id = :stats_owner_user_id';
			$click_params   = array_merge( $params, array( 'stats_owner_user_id' => $click_owner_id ) );
		}

		$stats = $this->repository->get_link_collection_stats(
			$where,
			$params,
			$can_view_analytics ? $stats_params : array(),
			$click_where,
			$click_params,
			$can_view_analytics
		);

		if ( ! $can_view_analytics ) {
			return $stats;
		}

		$range = trim( (string) ( $query['range'] ?? '' ) );
		if ( in_array( $range, array( '24h', '7d', '30d' ), true ) ) {
			$days        = '24h' === $range ? 1 : ( '30d' === $range ? 30 : 7 );
			$period      = $this->analytics_service->get_analytics_period( $days );
			$last_period = $this->analytics_service->get_last_month_period( $period, $days );

			$last_stats_params = array(
				'stats_start_at' => $last_period['start_at'],
				'stats_end_at'   => $last_period['end_at'],
			);

			$effective_where  = $click_where ?? $where;
			$effective_params = $click_params ?? $params;

			$last_stats = $this->repository->get_link_click_totals(
				$effective_where,
				$effective_params,
				$last_stats_params,
			);

			$stats['lastPeriodTotalClicks']  = $last_stats['totalClicks'];
			$stats['lastPeriodUniqueClicks'] = $last_stats['uniqueClicks'];
		}

		return $stats;
	}
}
