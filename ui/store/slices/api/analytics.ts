import baseApi from './base';
import type {
	ActivityResponse,
	DashboardAnalyticsResponse,
	LinkAnalyticsArgs,
	LinkLocationPayload,
	LinkStatsResponse,
} from './types';

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
	useGetLinkLocationQuery,
	useGetLinkStatsQuery,
} = analyticsApi;
