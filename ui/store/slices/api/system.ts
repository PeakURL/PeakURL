import { API_ROUTES } from "@/api";
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
			query: () => API_ROUTES.system.notices,
			providesTags: ["AdminNotices"],
		}),
		getGeneralSettings: build.query<ApiDataResponse<SiteSettings>, void>({
			query: () => API_ROUTES.system.general,
			providesTags: GENERAL_SETTINGS_TAGS,
		}),
		getSystemStatus: build.query<SystemStatusResponse, void>({
			query: () => API_ROUTES.system.status,
			providesTags: ["SystemStatus"],
		}),
		saveGeneralSettings: build.mutation<
			ApiDataResponse<SiteSettings>,
			SaveGeneralSettingsPayload
		>({
			query: ({
				siteName,
				siteTagline,
				siteLanguage,
				siteTimezone,
				siteTimeFormat,
				socialPreviewFile,
				removeSocialPreviewImage,
				faviconFile,
				removeFavicon,
			}) => {
				if (
					faviconFile ||
					removeFavicon ||
					socialPreviewFile ||
					removeSocialPreviewImage
				) {
					const formData = new FormData();
					formData.append("siteName", siteName || "");
					formData.append("siteTagline", siteTagline || "");
					formData.append("siteLanguage", siteLanguage);
					formData.append("siteTimezone", siteTimezone || "");
					formData.append("siteTimeFormat", siteTimeFormat || "");

					if (faviconFile) {
						formData.append("favicon", faviconFile);
					}

					if (removeFavicon) {
						formData.append("removeFavicon", "1");
					}

					if (socialPreviewFile) {
						formData.append(
							"socialPreviewImage",
							socialPreviewFile
						);
					}

					if (removeSocialPreviewImage) {
						formData.append("removeSocialPreviewImage", "1");
					}

					return {
						url: API_ROUTES.system.general,
						method: "POST",
						body: formData,
					};
				}

				return {
					url: API_ROUTES.system.general,
					method: "POST",
					body: {
						siteName,
						siteTagline,
						siteLanguage,
						siteTimezone,
						siteTimeFormat,
					},
				};
			},
			invalidatesTags: GENERAL_SETTINGS_TAGS,
		}),
		getGeoipStatus: build.query<ApiDataResponse<LocationDataStatus>, void>({
			query: () => API_ROUTES.system.geoip,
			providesTags: ["Geoip"],
		}),
		getMailStatus: build.query<ApiDataResponse<EmailStatus>, void>({
			query: () => API_ROUTES.system.mail,
			providesTags: MAIL_TAGS,
		}),
		getCaptchaStatus: build.query<ApiDataResponse<CaptchaStatus>, void>({
			query: () => API_ROUTES.system.captcha,
			providesTags: CAPTCHA_TAGS,
		}),
		saveGeoipConfiguration: build.mutation<
			ApiDataResponse<LocationDataStatus>,
			GeoipConfigurationPayload
		>({
			query: (body) => ({
				url: API_ROUTES.system.geoip,
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
				url: API_ROUTES.system.mail,
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
				url: API_ROUTES.system.captcha,
				method: "POST",
				body,
			}),
			invalidatesTags: CAPTCHA_TAGS,
		}),
		sendTestEmail: build.mutation<ApiDataResponse<MailTestResult>, void>({
			query: () => ({
				url: API_ROUTES.system.mailTest,
				method: "POST",
			}),
			invalidatesTags: MAIL_TAGS,
		}),
		downloadGeoipDatabase: build.mutation<void, void>({
			query: () => ({
				url: API_ROUTES.system.geoipDownload,
				method: "POST",
			}),
			invalidatesTags: GEOIP_CHANGE_TAGS,
		}),
		getUpdateStatus: build.query<
			ApiDataResponse<UpdateStatusPayload>,
			void
		>({
			query: () => API_ROUTES.system.update,
			providesTags: ["Updates"],
		}),
		checkForUpdates: build.mutation<
			ApiDataResponse<UpdateStatusPayload>,
			void
		>({
			query: () => ({
				url: API_ROUTES.system.updateCheck,
				method: "POST",
			}),
			invalidatesTags: UPDATE_CHANGE_TAGS,
		}),
		applyUpdate: build.mutation<ApiDataResponse<UpdateStatusPayload>, void>(
		{
			query: () => ({
				url: API_ROUTES.system.updateApply,
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
				url: API_ROUTES.system.updateReinstall,
				method: "POST",
			}),
			invalidatesTags: UPDATE_CHANGE_TAGS,
		}),
		upgradeDatabaseSchema: build.mutation<UpgradeDatabaseResponse, void>({
			query: () => ({
				url: API_ROUTES.system.updateDatabase,
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
