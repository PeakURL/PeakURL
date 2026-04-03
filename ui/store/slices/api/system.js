import baseApi from './base';

export const systemApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAdminNotices: build.query({
			query: () => 'system/notices',
			providesTags: ['AdminNotices'],
		}),
		getGeoipStatus: build.query({
			query: () => 'system/geoip',
			providesTags: ['Geoip'],
		}),
		getMailStatus: build.query({
			query: () => 'system/mail',
			providesTags: ['Mail'],
		}),
		saveGeoipConfiguration: build.mutation({
			query: (body) => ({
				url: 'system/geoip',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Geoip', 'AdminNotices'],
		}),
		saveMailConfiguration: build.mutation({
			query: (body) => ({
				url: 'system/mail',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Mail'],
		}),
		downloadGeoipDatabase: build.mutation({
			query: () => ({
				url: 'system/geoip/download',
				method: 'POST',
			}),
			invalidatesTags: ['Geoip', 'AdminNotices'],
		}),
		getUpdateStatus: build.query({
			query: () => 'system/update',
			providesTags: ['Updates'],
		}),
		checkForUpdates: build.mutation({
			query: () => ({
				url: 'system/update/check',
				method: 'POST',
			}),
			invalidatesTags: ['Updates', 'AdminNotices'],
		}),
		applyUpdate: build.mutation({
			query: () => ({
				url: 'system/update/apply',
				method: 'POST',
			}),
			invalidatesTags: ['Updates', 'AdminNotices'],
		}),
	}),
});

export const {
	useGetAdminNoticesQuery,
	useGetGeoipStatusQuery,
	useGetMailStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
} = systemApi;
