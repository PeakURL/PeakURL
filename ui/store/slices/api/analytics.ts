import baseApi from './base';
import type {
	ApiDataResponse,
	ActivityHistoryResponse,
	ActivityResponse,
	DashboardAnalyticsResponse,
	GetActivityHistoryQueryArgs,
	LinkAnalyticsArgs,
	LinkLocationPayload,
	LinkStatsResponse,
} from './types';

function buildActivityHistoryQueryString({
	page = 1,
	limit = 25,
	category = 'all',
}: GetActivityHistoryQueryArgs = {}): string {
	const params = new URLSearchParams();
	params.set('page', String(page));
	params.set('limit', String(limit));

	if (category && 'all' !== category) {
		params.set('category', category);
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
			providesTags: ['Analytics'],
		}),
		getActivity: build.query<ActivityResponse, void>({
			query: () => 'analytics/activity',
			providesTags: ['Analytics'],
		}),
		getActivityHistory: build.query<
			ActivityHistoryResponse,
			GetActivityHistoryQueryArgs | void
		>({
			query: (args) => buildActivityHistoryQueryString(args || {}),
			providesTags: ['Analytics'],
		}),
		deleteActivityLog: build.mutation<
			ApiDataResponse<{ deleted: boolean }>,
			string
		>({
			query: (id) => ({
				url: `analytics/activity/${id}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Analytics'],
		}),
		bulkDeleteActivityLogs: build.mutation<
			ApiDataResponse<{ deletedCount: number }>,
			string[]
		>({
			query: (ids) => ({
				url: 'analytics/activity/bulk',
				method: 'DELETE',
				body: { ids },
			}),
			invalidatesTags: ['Analytics'],
		}),
		getLinkLocation: build.query<
			{ data?: LinkLocationPayload },
			string
		>({
			query: (id) => `analytics/url/${id}/location`,
			providesTags: (_result, _error, id) => [
				{ type: 'Analytics', id: `location-${id}` },
			],
		}),
		getLinkStats: build.query<LinkStatsResponse, LinkAnalyticsArgs>({
			query: ({ id, days = 7 }) =>
				`analytics/url/${id}/stats?days=${days}`,
			providesTags: (_result, _error, { id }) => [
				{ type: 'Analytics', id: `stats-${id}` },
			],
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
