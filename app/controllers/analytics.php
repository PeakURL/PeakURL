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

use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * AnalyticsController — REST handlers for analytics data.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.0.0
 */
class AnalyticsController extends BaseController {

	/**
	 * Return the dashboard analytics summary.
	 *
	 * Returns total clicks, unique visitors, top links, and
	 * referrer/device/browser breakdowns for the selected period.
	 *
	 * @param Request $request Incoming HTTP request with optional `days` query param.
	 * @return array<string, mixed> JSON envelope with analytics summary.
	 * @since 1.0.0
	 */
	public function index( Request $request ): array {
		$days = (int) $request->get_query_param( 'days', 7 );
		return $this->success_response(
			$this->data_store->analytics_summary( $request, $days ),
			__( 'Analytics loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the recent activity feed.
	 *
	 * Returns the latest click / creation events across all links.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with activity list.
	 * @since 1.0.0
	 */
	public function activity( Request $request ): array {
		return $this->success_response(
			$this->data_store->activity( $request ),
			__( 'Activity loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the recent click feed.
	 *
	 * Returns recent click rows with their related short-link payloads.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with recent click list.
	 * @since 1.2.1
	 */
	public function recent_clicks( Request $request ): array {
		return $this->success_response(
			$this->data_store->recent_clicks(
				$request,
				(int) $request->get_query_param( 'limit', 8 ),
			),
			__( 'Recent clicks loaded.', 'peakurl' ),
		);
	}

	/**
	 * Return the paginated activity history.
	 *
	 * Returns the full audit-log feed with pagination metadata for the
	 * dedicated dashboard activity page.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with activity list and meta.
	 * @since 1.0.0
	 */
	public function history( Request $request ): array {
		return $this->success_response(
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
	 * Delete an activity entry.
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
			return $this->not_found_response( __( 'Activity log not found.', 'peakurl' ) );
		}

		return $this->boolean_response(
			'deleted',
			__( 'Activity log deleted.', 'peakurl' ),
		);
	}

	/**
	 * Delete multiple activity entries.
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

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk activity delete complete.', 'peakurl' ),
		);
	}

	/**
	 * Return per-link location analytics.
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
			return $this->not_found_response( __( 'Link analytics not found.', 'peakurl' ) );
		}

		return $this->success_response( $location, __( 'Location analytics loaded.', 'peakurl' ) );
	}

	/**
	 * Return per-link time-series statistics.
	 *
	 * Returns daily click counts for a specific short link over the
	 * requested range. Returns 404 if the link has no data.
	 *
	 * @param Request $request Incoming HTTP request with route param `id`.
	 * @return array<string, mixed> JSON envelope with click time-series or 404 error.
	 * @since 1.0.0
	 */
	public function stats( Request $request ): array {
		$range            = trim( (string) $request->get_query_param( 'range', '' ) );
		$custom_date_from = trim( (string) $request->get_query_param( 'from', '' ) );
		$custom_date_to   = trim( (string) $request->get_query_param( 'to', '' ) );
		$stats            = $this->data_store->link_stats(
			$request,
			(string) $request->get_route_param( 'id' ),
			$range,
			$custom_date_from,
			$custom_date_to,
		);

		if ( ! $stats ) {
			return $this->not_found_response( __( 'Link analytics not found.', 'peakurl' ) );
		}

		return $this->success_response( $stats, __( 'Link analytics loaded.', 'peakurl' ) );
	}
}
