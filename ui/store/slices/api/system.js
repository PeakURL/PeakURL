import baseApi from './base';

export const systemApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAdminNotices: build.query({
			query: () => 'system/notices',
			providesTags: ['AdminNotices'],
		}),
		getGeneralSettings: build.query({
			query: () => 'system/general',
			providesTags: ['GeneralSettings'],
		}),
		getSystemStatus: build.query({
			query: () => 'system/status',
			providesTags: ['SystemStatus'],
		}),
		saveGeneralSettings: build.mutation({
			query: (body) => ({
				url: 'system/general',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['GeneralSettings'],
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
		upgradeDatabaseSchema: build.mutation({
			query: () => ({
				url: 'system/update/database',
				method: 'POST',
			}),
			invalidatesTags: ['Updates', 'AdminNotices', 'SystemStatus'],
		}),
	}),
});

export const {
	useGetAdminNoticesQuery,
	useGetGeneralSettingsQuery,
	useGetSystemStatusQuery,
	useSaveGeneralSettingsMutation,
	useGetGeoipStatusQuery,
	useGetMailStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
	useUpgradeDatabaseSchemaMutation,
} = systemApi;
