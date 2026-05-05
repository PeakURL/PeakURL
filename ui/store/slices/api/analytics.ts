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
} from "./types";

const ANALYTICS_TAGS = ["Analytics"] as const;

const linkAnalyticsTags = (type: "location" | "stats", id: string) => [
	{ type: "Analytics" as const, id: `${type}-${id}` },
];

function serializeLinkStatsQuery({ id, ...args }: LinkAnalyticsArgs): string {
	const params = new URLSearchParams();

	params.set("range", args.range);

	if ("custom" === args.range) {
		params.set("from", args.from);
		params.set("to", args.to);
	}

	return `analytics/url/${id}/stats?${params.toString()}`;
}

function serializeActivityHistoryQuery({
	page = 1,
	limit = 25,
	category = "all",
}: GetActivityHistoryQueryArgs = {}): string {
	const params = new URLSearchParams();
	params.set("page", String(page));
	params.set("limit", String(limit));

	if (category && "all" !== category) {
		params.set("category", category);
	}

	return `analytics/activity/history?${params.toString()}`;
}

/**
 * RTK Query analytics endpoints used by the dashboard overview and stats UI.
 */
export const analyticsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAnalytics: build.query<DashboardAnalyticsResponse, number | void>({
			query: (days = 7) => `analytics?days=${days}`,
			providesTags: ANALYTICS_TAGS,
		}),
		getActivity: build.query<ActivityResponse, void>({
			query: () => "analytics/activity",
			providesTags: ANALYTICS_TAGS,
		}),
		getActivityHistory: build.query<
			ActivityHistoryResponse,
			GetActivityHistoryQueryArgs | void
		>({
			query: (args) => serializeActivityHistoryQuery(args || {}),
			providesTags: ANALYTICS_TAGS,
		}),
		deleteActivityLog: build.mutation<
			ApiDataResponse<{ deleted: boolean }>,
			string
		>({
			query: (id) => ({
				url: `analytics/activity/${id}`,
				method: "DELETE",
			}),
			invalidatesTags: ANALYTICS_TAGS,
		}),
		bulkDeleteActivityLogs: build.mutation<
			ApiDataResponse<{ deletedCount: number }>,
			string[]
		>({
			query: (ids) => ({
				url: "analytics/activity/bulk",
				method: "DELETE",
				body: { ids },
			}),
			invalidatesTags: ANALYTICS_TAGS,
		}),
		getLinkLocation: build.query<{ data?: LinkLocationPayload }, string>({
			query: (id) => `analytics/url/${id}/location`,
			providesTags: (_result, _error, id) =>
				linkAnalyticsTags("location", id),
		}),
		getLinkStats: build.query<LinkStatsResponse, LinkAnalyticsArgs>({
			query: serializeLinkStatsQuery,
			providesTags: (_result, _error, { id }) =>
				linkAnalyticsTags("stats", id),
		}),
	}),
});

export const {
	useGetAnalyticsQuery,
	useGetActivityQuery,
	useGetActivityHistoryQuery,
	useDeleteActivityLogMutation,
	useBulkDeleteActivityLogsMutation,
	useGetLinkLocationQuery,
	useGetLinkStatsQuery,
} = analyticsApi;
