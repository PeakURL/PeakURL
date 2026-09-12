<?php
/**
 * Analytics endpoints controller.
 *
 * Serves aggregate click analytics, recent activity, and
 * per-link location / time-series statistics for the dashboard.
 *
 * @package PeakURL\Features\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PeakURL\Features\Analytics;

use PeakURL\Core\Controller as BaseController;
use PeakURL\Http\Request;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Controller — Thin HTTP entry adapter delegating to Analytics Service.
 *
 * Route paths are registered centrally in Application.
 *
 * @since 1.0.0
 */
class Controller extends BaseController {

	/**
	 * Analytics domain service.
	 *
	 * @var Service
	 * @since 1.0.0
	 */
	private Service $analytics_service;

	/**
	 * Create a new Analytics controller instance.
	 *
	 * @param Service $analytics_service Analytics domain service.
	 * @since 1.0.0
	 */
	public function __construct( Service $analytics_service ) {
		$this->analytics_service = $analytics_service;
	}

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
			$this->analytics_service->analytics_summary( $request, $days ),
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
			$this->analytics_service->activity( $request ),
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
	 * @since 1.0.0
	 */
	public function recent_clicks( Request $request ): array {
		return $this->success_response(
			$this->analytics_service->recent_clicks(
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
			$this->analytics_service->activity_history(
				$request,
				$this->query_params(
					$request,
					array(
						'page'     => 1,
						'limit'    => 25,
						'category' => '',
					),
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
	 * @since 1.0.0
	 */
	public function delete( Request $request ): array {
		$deleted = $this->analytics_service->delete_activity_log(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->delete_response(
			$deleted,
			__( 'Activity log not found.', 'peakurl' ),
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
	 * @since 1.0.0
	 */
	public function bulk_delete( Request $request ): array {
		$count = $this->analytics_service->bulk_delete_activity_logs(
			$request,
			$this->body_array_param( $request, 'ids' ),
		);

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'Bulk activity delete complete.', 'peakurl' ),
		);
	}

	/**
	 * Delete all activity entries.
	 *
	 * Admin-only. Clears all audit log rows.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with deleted count.
	 * @since 1.0.0
	 */
	public function clear( Request $request ): array {
		$count = $this->analytics_service->clear_activity_logs( $request );

		return $this->success_response(
			array(
				'deletedCount' => $count,
			),
			__( 'All activity logs deleted.', 'peakurl' ),
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
		$range            = trim( (string) $request->get_query_param( 'range', '' ) );
		$custom_date_from = trim( (string) $request->get_query_param( 'from', '' ) );
		$custom_date_to   = trim( (string) $request->get_query_param( 'to', '' ) );
		$location         = $this->analytics_service->link_location(
			$request,
			$this->route_param( $request, 'id' ),
			$range,
			$custom_date_from,
			$custom_date_to,
		);

		return $this->found_response(
			$location,
			__( 'Link analytics not found.', 'peakurl' ),
			__( 'Location analytics loaded.', 'peakurl' ),
		);
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
		$stats            = $this->analytics_service->link_stats(
			$request,
			$this->route_param( $request, 'id' ),
			$range,
			$custom_date_from,
			$custom_date_to,
		);

		return $this->found_response(
			$stats,
			__( 'Link analytics not found.', 'peakurl' ),
			__( 'Link analytics loaded.', 'peakurl' ),
		);
	}

	/**
	 * Restore a link from an activity log entry.
	 *
	 * @param Request $request Incoming HTTP request.
	 * @return array<string, mixed> JSON envelope with restored link.
	 * @since 1.0.0
	 */
	public function restore_link( Request $request ): array {
		$item = $this->analytics_service->restore_activity_link(
			$request,
			$this->route_param( $request, 'id' ),
		);

		return $this->success_response(
			$item,
			__( 'Link restored successfully.', 'peakurl' ),
		);
	}
}
