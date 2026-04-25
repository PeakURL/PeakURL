import baseApi from "./base";
import type {
	AdminNoticesResponse,
	ApiDataResponse,
	EmailStatus,
	GeoipConfigurationPayload,
	LocationDataStatus,
	MailConfigurationPayload,
	MailTestResult,
	SaveGeneralSettingsPayload,
	SiteSettings,
	SystemStatusResponse,
	UpdateStatusPayload,
	UpgradeDatabaseResponse,
} from "./types";

/**
 * RTK Query endpoints for system configuration, diagnostics, and updates.
 */
export const systemApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAdminNotices: build.query<AdminNoticesResponse, void>({
			query: () => "system/notices",
			providesTags: ["AdminNotices"],
		}),
		getGeneralSettings: build.query<ApiDataResponse<SiteSettings>, void>({
			query: () => "system/general",
			providesTags: ["GeneralSettings"],
		}),
		getSystemStatus: build.query<SystemStatusResponse, void>({
			query: () => "system/status",
			providesTags: ["SystemStatus"],
		}),
		saveGeneralSettings: build.mutation<
			ApiDataResponse<SiteSettings>,
			SaveGeneralSettingsPayload
		>({
			query: ({ siteName, siteLanguage, faviconFile, removeFavicon }) => {
				if (faviconFile || removeFavicon) {
					const formData = new FormData();
					formData.append("siteName", siteName || "");
					formData.append("siteLanguage", siteLanguage);

					if (faviconFile) {
						formData.append("favicon", faviconFile);
					}

					if (removeFavicon) {
						formData.append("removeFavicon", "1");
					}

					return {
						url: "system/general",
						method: "POST",
						body: formData,
					};
				}

				return {
					url: "system/general",
					method: "POST",
					body: {
						siteName,
						siteLanguage,
					},
				};
			},
			invalidatesTags: ["GeneralSettings"],
		}),
		getGeoipStatus: build.query<ApiDataResponse<LocationDataStatus>, void>({
			query: () => "system/geoip",
			providesTags: ["Geoip"],
		}),
		getMailStatus: build.query<ApiDataResponse<EmailStatus>, void>({
			query: () => "system/mail",
			providesTags: ["Mail"],
		}),
		saveGeoipConfiguration: build.mutation<
			ApiDataResponse<LocationDataStatus>,
			GeoipConfigurationPayload
		>({
			query: (body) => ({
				url: "system/geoip",
				method: "POST",
				body,
			}),
			invalidatesTags: ["Geoip", "AdminNotices"],
		}),
		saveMailConfiguration: build.mutation<
			ApiDataResponse<EmailStatus>,
			MailConfigurationPayload
		>({
			query: (body) => ({
				url: "system/mail",
				method: "POST",
				body,
			}),
			invalidatesTags: ["Mail"],
		}),
		sendTestEmail: build.mutation<ApiDataResponse<MailTestResult>, void>({
			query: () => ({
				url: "system/mail/test",
				method: "POST",
			}),
			invalidatesTags: ["Mail"],
		}),
		downloadGeoipDatabase: build.mutation<void, void>({
			query: () => ({
				url: "system/geoip/download",
				method: "POST",
			}),
			invalidatesTags: ["Geoip", "AdminNotices"],
		}),
		getUpdateStatus: build.query<
			ApiDataResponse<UpdateStatusPayload>,
			void
		>({
			query: () => "system/update",
			providesTags: ["Updates"],
		}),
		checkForUpdates: build.mutation<
			ApiDataResponse<UpdateStatusPayload>,
			void
		>({
			query: () => ({
				url: "system/update/check",
				method: "POST",
			}),
			invalidatesTags: ["Updates", "AdminNotices"],
		}),
		applyUpdate: build.mutation<ApiDataResponse<UpdateStatusPayload>, void>(
			{
				query: () => ({
					url: "system/update/apply",
					method: "POST",
				}),
				invalidatesTags: ["Updates", "AdminNotices"],
			}
		),
		reinstallUpdate: build.mutation<
			ApiDataResponse<UpdateStatusPayload>,
			void
		>({
			query: () => ({
				url: "system/update/reinstall",
				method: "POST",
			}),
			invalidatesTags: ["Updates", "AdminNotices"],
		}),
		upgradeDatabaseSchema: build.mutation<UpgradeDatabaseResponse, void>({
			query: () => ({
				url: "system/update/database",
				method: "POST",
			}),
			invalidatesTags: ["Updates", "AdminNotices", "SystemStatus"],
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
	useSendTestEmailMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
	useReinstallUpdateMutation,
	useUpgradeDatabaseSchemaMutation,
} = systemApi;
