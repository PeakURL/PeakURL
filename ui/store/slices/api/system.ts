import baseApi from "./base";
import type {
	AdminNoticesResponse,
	ApiDataResponse,
	CaptchaConfigurationPayload,
	CaptchaStatus,
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

const GENERAL_SETTINGS_TAGS = ["GeneralSettings"] as const;
const GEOIP_CHANGE_TAGS = ["Geoip", "AdminNotices"] as const;
const MAIL_TAGS = ["Mail"] as const;
const CAPTCHA_TAGS = ["Captcha"] as const;
const UPDATE_CHANGE_TAGS = ["Updates", "AdminNotices"] as const;
const DATABASE_UPDATE_TAGS = [
	"Updates",
	"AdminNotices",
	"SystemStatus",
] as const;

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
			providesTags: GENERAL_SETTINGS_TAGS,
		}),
		getSystemStatus: build.query<SystemStatusResponse, void>({
			query: () => "system/status",
			providesTags: ["SystemStatus"],
		}),
		saveGeneralSettings: build.mutation<
			ApiDataResponse<SiteSettings>,
			SaveGeneralSettingsPayload
		>({
			query: ({
				siteName,
				siteLanguage,
				siteTimezone,
				siteTimeFormat,
				faviconFile,
				removeFavicon,
			}) => {
				if (faviconFile || removeFavicon) {
					const formData = new FormData();
					formData.append("siteName", siteName || "");
					formData.append("siteLanguage", siteLanguage);
					formData.append("siteTimezone", siteTimezone || "");
					formData.append("siteTimeFormat", siteTimeFormat || "");

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
						siteTimezone,
						siteTimeFormat,
					},
				};
			},
			invalidatesTags: GENERAL_SETTINGS_TAGS,
		}),
		getGeoipStatus: build.query<ApiDataResponse<LocationDataStatus>, void>({
			query: () => "system/geoip",
			providesTags: ["Geoip"],
		}),
		getMailStatus: build.query<ApiDataResponse<EmailStatus>, void>({
			query: () => "system/mail",
			providesTags: MAIL_TAGS,
		}),
		getCaptchaStatus: build.query<ApiDataResponse<CaptchaStatus>, void>({
			query: () => "system/captcha",
			providesTags: CAPTCHA_TAGS,
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
			invalidatesTags: GEOIP_CHANGE_TAGS,
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
			invalidatesTags: MAIL_TAGS,
		}),
		saveCaptchaConfiguration: build.mutation<
			ApiDataResponse<CaptchaStatus>,
			CaptchaConfigurationPayload
		>({
			query: (body) => ({
				url: "system/captcha",
				method: "POST",
				body,
			}),
			invalidatesTags: CAPTCHA_TAGS,
		}),
		sendTestEmail: build.mutation<ApiDataResponse<MailTestResult>, void>({
			query: () => ({
				url: "system/mail/test",
				method: "POST",
			}),
			invalidatesTags: MAIL_TAGS,
		}),
		downloadGeoipDatabase: build.mutation<void, void>({
			query: () => ({
				url: "system/geoip/download",
				method: "POST",
			}),
			invalidatesTags: GEOIP_CHANGE_TAGS,
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
			invalidatesTags: UPDATE_CHANGE_TAGS,
		}),
		applyUpdate: build.mutation<ApiDataResponse<UpdateStatusPayload>, void>(
			{
				query: () => ({
					url: "system/update/apply",
					method: "POST",
				}),
				invalidatesTags: UPDATE_CHANGE_TAGS,
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
			invalidatesTags: UPDATE_CHANGE_TAGS,
		}),
		upgradeDatabaseSchema: build.mutation<UpgradeDatabaseResponse, void>({
			query: () => ({
				url: "system/update/database",
				method: "POST",
			}),
			invalidatesTags: DATABASE_UPDATE_TAGS,
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
	useGetCaptchaStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useSaveCaptchaConfigurationMutation,
	useSendTestEmailMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
	useReinstallUpdateMutation,
	useUpgradeDatabaseSchemaMutation,
} = systemApi;
