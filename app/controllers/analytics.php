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
			'Analytics loaded.',
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
			'Activity loaded.',
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
			return JsonResponse::error( 'Link analytics not found.', 404 );
		}

		return JsonResponse::success( $location, 'Location analytics loaded.' );
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
			return JsonResponse::error( 'Link analytics not found.', 404 );
		}

		return JsonResponse::success( $stats, 'Link analytics loaded.' );
	}
}
