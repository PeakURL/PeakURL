<?php
/**
 * Analytics endpoints.
 *
 * Serves aggregate click analytics, recent activity, and
 * per-link location / time-series statistics for the dashboard.
 *
 * @package PeakURL\Controllers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Controllers;

use PeakURL\Http\JsonResponse;
use PeakURL\Http\Request;
use PeakURL\Store;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AnalyticsController — REST handlers for analytics data.
 *
 * Routes registered by Application::register_routes():
 *  GET /api/v1/analytics              → index
 *  GET /api/v1/analytics/activity     → activity
 *  GET /api/v1/analytics/activity/history → history
 *  DELETE /api/v1/analytics/activity/bulk → bulk_delete
 *  DELETE /api/v1/analytics/activity/:id → delete
 *  GET /api/v1/analytics/location/:id → location
 *  GET /api/v1/analytics/stats/:id    → stats
 *
 * @since 1.0.0
 */
class AnalyticsController {

	/**
	 * Persistence layer for analytics queries.
	 *
	 * @var Store
	 * @since 1.0.0
	 */
	private Store $data_store;

	/**
	 * Create a new AnalyticsController instance.
	 *
	 * @param Store $data_store Shared data-store dependency.
	 * @since 1.0.0
	 */
	public function __construct( Store $data_store ) {
		$this->data_store = $data_store;
	}

	/**
	 * Dashboard analytics summary (GET /api/v1/analytics).
	 *
	 * Returns total clicks, unique visitors, top links, and
	 * referrer/device/browser breakdowns for the given time window.
	 *
	 * @param Request $request Incoming HTTP request with optional `days` query param.
	 * @return array<string, mixed> JSON envelope with analytics summary.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		$days = (int) $request->get_query_param( 'days', 7 );
		return JsonResponse::success(
			$this->data_store->analytics_summary( $request, $days ),
			__( 'Analytics loaded.', 'peakurl' ),
		);
	}

	/**
	 * Recent activity feed (GET /api/v1/analytics/activity).
	 *
	 * Returns the latest click / creation events across all links.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with activity list.
	 * @since 1.0.0
	 */
	public function activity( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->activity( $request ),
			__( 'Activity loaded.', 'peakurl' ),
		);
	}

	/**
	 * Paginated activity history (GET /api/v1/analytics/activity/history).
	 *
	 * Returns the full audit-log feed with pagination metadata for the
	 * dedicated dashboard activity page.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with activity list and meta.
	 * @since 1.0.0
	 */
	public function history( Request $request ): array {
		return JsonResponse::success(
			$this->data_store->activity_history(
				$request,
				array(
					'page'     => $request->get_query_param( 'page', 1 ),
					'limit'    => $request->get_query_param( 'limit', 25 ),
					'category' => $request->get_query_param( 'category', '' ),
				),
			),
			__( 'Activity history loaded.', 'peakurl' ),
		);
	}

	/**
	 * Delete an activity entry (DELETE /api/v1/analytics/activity/:id).
	 *
	 * Admin-only. Returns 404 if the activity row does not exist.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope confirming deletion or 404 error.
	 * @since 1.0.6
	 */
	public function delete( Request $request ): array {
		$deleted = $this->data_store->delete_activity_log(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $deleted ) {
			return JsonResponse::error( __( 'Activity log not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success(
			array( 'deleted' => true ),
			__( 'Activity log deleted.', 'peakurl' ),
		);
	}

	/**
	 * Delete multiple activity entries (DELETE /api/v1/analytics/activity/bulk).
	 *
	 * Admin-only. Accepts an `ids` array in the request body.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with deleted count.
	 * @since 1.0.6
	 */
	public function bulk_delete( Request $request ): array {
		$ids   = $request->get_body_param( 'ids', array() );
		$count = $this->data_store->bulk_delete_activity_logs(
			$request,
			is_array( $ids ) ? $ids : array(),
		);

		return JsonResponse::success(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk activity delete complete.', 'peakurl' ),
		);
	}

	/**
	 * Per-link location analytics (GET /api/v1/analytics/location/:id).
	 *
	 * Returns geographic breakdown (country, city) of clicks for a
	 * specific short link. Returns 404 if the link has no analytics data.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope with location data or 404 error.
	 * @since 1.0.0
	 */
	public function location( Request $request ): array {
		$location = $this->data_store->link_location(
			$request,
			(string) $request->get_route_param( 'id' ),
		);

		if ( ! $location ) {
			return JsonResponse::error( __( 'Link analytics not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( $location, __( 'Location analytics loaded.', 'peakurl' ) );
	}

	/**
	 * Per-link time-series statistics (GET /api/v1/analytics/stats/:id).
	 *
	 * Returns daily click counts for a specific short link over the
	 * requested number of days. Returns 404 if the link has no data.
	 *
	 * @param Request $request Incoming HTTP request with route param `id` and optional `days`.
	 * @return array<string, mixed> JSON envelope with click time-series or 404 error.
	 * @since 1.0.0
	 */
	public function stats( Request $request ): array {
		$days  = (int) $request->get_query_param( 'days', 7 );
		$stats = $this->data_store->link_stats(
			$request,
			(string) $request->get_route_param( 'id' ),
			$days,
		);

		if ( ! $stats ) {
			return JsonResponse::error( __( 'Link analytics not found.', 'peakurl' ), 404 );
		}

		return JsonResponse::success( $stats, __( 'Link analytics loaded.', 'peakurl' ) );
	}
}
