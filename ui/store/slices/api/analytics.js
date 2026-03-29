import baseApi from './base';

export const analyticsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAnalytics: build.query({
			query: (days = 7) => `analytics?days=${days}`,
			providesTags: ['Analytics'],
		}),
		getActivity: build.query({
			query: () => 'analytics/activity',
			providesTags: ['Analytics'],
		}),
		getLinkLocation: build.query({
			query: (id) => `analytics/url/${id}/location`,
			providesTags: (_res, _err, id) => [
				{ type: 'Analytics', id: `location-${id}` },
			],
		}),
		getLinkStats: build.query({
			query: ({ id, days = 7 }) =>
				`analytics/url/${id}/stats?days=${days}`,
			providesTags: (_res, _err, { id }) => [
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
