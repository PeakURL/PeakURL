import {
	API_ROUTES,
	buildApiRouteWithQuery,
	createApiQueryParams,
} from "@/api";

import baseApi from "./base";
import type {
	ApiDataResponse,
	ActivityHistoryResponse,
	ActivityResponse,
	DashboardAnalyticsResponse,
	GetActivityHistoryQueryArgs,
	LinkAnalyticsArgs,
	LinkLocationPayload,
	LinkStatsResponse,
	RecentClicksResponse,
} from "./types";

const ANALYTICS_TAGS = ["Analytics"] as const;

const getLinkAnalyticsTags = (type: "location" | "stats", id: string) => [
	{ type: "Analytics" as const, id: `${type}-${id}` },
];

/**
 * Return the link-stats route with range query parameters.
 */
function getLinkStatsRoute({ id, ...args }: LinkAnalyticsArgs): string {
	const params = createApiQueryParams({
		range: args.range,
		from: "custom" === args.range ? args.from : undefined,
		to: "custom" === args.range ? args.to : undefined,
	});

	return buildApiRouteWithQuery(API_ROUTES.analytics.linkStats(id), params);
}

/**
 * Return the activity-history route with pagination query parameters.
 */
function getActivityHistoryRoute({
	page = 1,
	limit = 25,
	category = "all",
}: GetActivityHistoryQueryArgs = {}): string {
	const params = createApiQueryParams({
		page,
		limit,
		category: "all" !== category ? category : undefined,
	});

	return buildApiRouteWithQuery(API_ROUTES.analytics.activityHistory, params);
}

/**
 * RTK Query analytics endpoints used by the dashboard overview and stats UI.
 */
export const analyticsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAnalytics: build.query<DashboardAnalyticsResponse, number | void>({
			query: (days) => {
				const rangeDays = days ?? 7;

				return buildApiRouteWithQuery(
					API_ROUTES.analytics.index,
					createApiQueryParams({ days: rangeDays })
				);
			},
			providesTags: ANALYTICS_TAGS,
		}),
		getActivity: build.query<ActivityResponse, void>({
			query: () => API_ROUTES.analytics.activity,
			providesTags: ANALYTICS_TAGS,
		}),
		getRecentClicks: build.query<RecentClicksResponse, number | void>({
			query: (limit) => {
				const pageLimit = limit ?? 8;

				return buildApiRouteWithQuery(
					API_ROUTES.analytics.recentClicks,
					createApiQueryParams({ limit: pageLimit })
				);
			},
			providesTags: ANALYTICS_TAGS,
		}),
		getActivityHistory: build.query<
			ActivityHistoryResponse,
			GetActivityHistoryQueryArgs | void
		>({
			query: (args) => getActivityHistoryRoute(args || {}),
			providesTags: ANALYTICS_TAGS,
		}),
		deleteActivityLog: build.mutation<
			ApiDataResponse<{ deleted: boolean }>,
			string
		>({
			query: (id) => ({
				url: API_ROUTES.analytics.activityById(id),
				method: "DELETE",
			}),
			invalidatesTags: ANALYTICS_TAGS,
		}),
		bulkDeleteActivityLogs: build.mutation<
			ApiDataResponse<{ deletedCount: number }>,
			string[]
		>({
			query: (ids) => ({
				url: API_ROUTES.analytics.activityBulk,
				method: "DELETE",
				body: { ids },
			}),
			invalidatesTags: ANALYTICS_TAGS,
		}),
		clearActivityLogs: build.mutation<
			ApiDataResponse<{ deletedCount: number }>,
			void
		>({
			query: () => ({
				url: API_ROUTES.analytics.activity,
				method: "DELETE",
			}),
			invalidatesTags: ANALYTICS_TAGS,
		}),
		getLinkLocation: build.query<{ data?: LinkLocationPayload }, string>({
			query: (id) => API_ROUTES.analytics.linkLocation(id),
			providesTags: (_result, _error, id) =>
				getLinkAnalyticsTags("location", id),
		}),
		getLinkStats: build.query<LinkStatsResponse, LinkAnalyticsArgs>({
			query: getLinkStatsRoute,
			providesTags: (_result, _error, { id }) =>
				getLinkAnalyticsTags("stats", id),
		}),
	}),
});

export const {
	useGetAnalyticsQuery,
	useGetActivityQuery,
	useGetRecentClicksQuery,
	useGetActivityHistoryQuery,
	useDeleteActivityLogMutation,
	useBulkDeleteActivityLogsMutation,
	useClearActivityLogsMutation,
	useGetLinkLocationQuery,
	useGetLinkStatsQuery,
} = analyticsApi;
