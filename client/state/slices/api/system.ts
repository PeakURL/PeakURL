import {
	API_ROUTES,
	mapApiClearCronHistory,
	mapApiCronJobScheduleResult,
	mapApiCronStatus,
	mapApiRunCronJobResult,
	mapApiRunDueJobsResult,
} from "@/api";
import type {
	ApiClearCronHistoryResponse,
	ApiCronJobScheduleResponse,
	ApiCronStatusResponse,
	ApiRunCronJobResponse,
	ApiRunDueJobsResponse,
	ClearCronHistoryRequest,
	ClearCronHistoryResponse,
	CronJobScheduleResult,
	CronStatusResponse,
	RunCronJobResult,
	RunDueJobsResult,
	UpdateCronJobPayload,
	InstalledPlugin,
	InstalledPluginsPayload,
	PluginExtensionsPayload,
	PluginPageDescriptor,
	PublishedPluginsQueryParams,
	PublishedPluginsResponse,
} from "@/api";

import baseApi from "./base";
import { createFormData } from "./formData";
import type {
	AdminNoticesResponse,
	ApiDataResponse,
	CacheConfigurationPayload,
	CacheStatusResponse,
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
	ReleaseNotesResponse,
	McpConnectionsResponse,
	McpSettings,
	McpToolsResponse,
	RevokeAllMcpConnectionsResponse,
	RevokeMcpConnectionResponse,
	UpdateMcpSettingsPayload,
} from "./types";

const ADMIN_NOTICE_TAGS = ["AdminNotices"] as const;
const GENERAL_SETTINGS_TAGS = ["GeneralSettings"] as const;
const GEOIP_TAGS = ["Geoip"] as const;
const GEOIP_CHANGE_TAGS = ["Geoip", "AdminNotices"] as const;
const MAIL_TAGS = ["Mail"] as const;
const CAPTCHA_TAGS = ["Captcha"] as const;
const CACHE_TAGS = ["CacheStatus"] as const;
const CACHE_CHANGE_TAGS = ["CacheStatus", "SystemStatus"] as const;
const UPDATE_TAGS = ["Updates"] as const;
const UPDATE_CHANGE_TAGS = ["Updates", "AdminNotices"] as const;
const SYSTEM_STATUS_TAGS = ["SystemStatus"] as const;
const CRON_TAGS = ["CronStatus", "CronHistory"] as const;
const DATABASE_UPDATE_TAGS = [
	"Updates",
	"AdminNotices",
	"SystemStatus",
] as const;
const MCP_SETTINGS_TAGS = ["McpSettings"] as const;
const MCP_CONNECTIONS_TAGS = ["McpConnections"] as const;
const PLUGINS_TAGS = ["Plugins"] as const;
const MCP_TOOLS_TAGS = ["McpTools"] as const;
const MCP_SETTINGS_CHANGE_TAGS = ["McpSettings", "McpTools"] as const;

export type PluginActionInvalidationTarget = "Plugins" | "Analytics" | "Urls";

export interface DispatchPluginActionArgs {
	slug: string;
	actionId: string;
	payload?: Record<string, unknown>;
	invalidates?: readonly PluginActionInvalidationTarget[];
}

/**
 * Resolves the appropriate cache tags to invalidate when a plugin action completes.
 * Uses explicit invalidations declared by the action, avoiding heuristic string matching.
 */
export function resolvePluginActionInvalidatedTags(
	invalidates?: readonly PluginActionInvalidationTarget[],
	payload?: Record<string, unknown>
): Array<
	| "Plugins"
	| "Analytics"
	| { type: "Urls"; id: string }
	| { type: "Plugins"; id: string }
> {
	const tags: Array<
		| "Plugins"
		| "Analytics"
		| { type: "Urls"; id: string }
		| { type: "Plugins"; id: string }
	> = ["Plugins"];

	if (!invalidates || invalidates.length === 0) {
		return tags;
	}

	if (invalidates.includes("Analytics")) {
		tags.push("Analytics");
	}

	if (invalidates.includes("Urls")) {
		tags.push({ type: "Urls", id: "LIST" });
		const targetUrlId = payload?.url_id ?? payload?.urlId;
		if (targetUrlId) {
			tags.push({ type: "Urls", id: String(targetUrlId) });
		}
	}

	return tags;
}

/**
 * Check whether general settings must be saved as multipart form data.
 */
function hasGeneralSettingsUpload({
	faviconFile,
	removeFavicon,
	socialPreviewFile,
	removeSocialPreviewImage,
}: SaveGeneralSettingsPayload): boolean {
	return Boolean(
		faviconFile ||
		removeFavicon ||
		socialPreviewFile ||
		removeSocialPreviewImage
	);
}

/**
 * Create a request body for saving general site settings.
 *
 * File uploads and remove flags use multipart form data, while plain settings
 * can stay JSON so PHP receives the same values without unnecessary encoding.
 */
function createGeneralSettingsBody({
	siteName,
	siteTagline,
	siteLanguage,
	siteTimezone,
	siteTimeFormat,
	landingPageMode,
	landingPageUrl,
	trashRetentionDays,
	analyticsRetentionDays,
	faviconFile,
	removeFavicon,
	socialPreviewFile,
	removeSocialPreviewImage,
}: SaveGeneralSettingsPayload):
	| FormData
	| Pick<
			SaveGeneralSettingsPayload,
			| "siteName"
			| "siteTagline"
			| "siteLanguage"
			| "siteTimezone"
			| "siteTimeFormat"
			| "landingPageMode"
			| "landingPageUrl"
			| "trashRetentionDays"
			| "analyticsRetentionDays"
	  > {
	if (
		hasGeneralSettingsUpload({
			siteName,
			siteTagline,
			siteLanguage,
			siteTimezone,
			siteTimeFormat,
			landingPageMode,
			landingPageUrl,
			trashRetentionDays,
			analyticsRetentionDays,
			faviconFile,
			removeFavicon,
			socialPreviewFile,
			removeSocialPreviewImage,
		})
	) {
		return createFormData({
			siteName: siteName || "",
			siteTagline: siteTagline || "",
			siteLanguage,
			siteTimezone: siteTimezone || "",
			siteTimeFormat: siteTimeFormat || "",
			landingPageMode: landingPageMode || "",
			landingPageUrl: landingPageUrl || "",
			trashRetentionDays:
				trashRetentionDays !== undefined
					? String(trashRetentionDays)
					: undefined,
			analyticsRetentionDays:
				analyticsRetentionDays !== undefined
					? String(analyticsRetentionDays)
					: undefined,
			favicon: faviconFile || undefined,
			removeFavicon: removeFavicon ? "1" : "0",
			socialPreviewImage: socialPreviewFile || undefined,
			removeSocialPreviewImage: removeSocialPreviewImage ? "1" : "0",
		});
	}

	return {
		siteName,
		siteTagline,
		siteLanguage,
		siteTimezone,
		siteTimeFormat,
		landingPageMode,
		landingPageUrl,
		trashRetentionDays,
		analyticsRetentionDays,
	};
}

/**
 * RTK Query endpoints for system configuration, diagnostics, and updates.
 */
export const systemApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getAdminNotices: build.query<AdminNoticesResponse, void>({
			query: () => API_ROUTES.system.notices,
			providesTags: ADMIN_NOTICE_TAGS,
		}),
		getGeneralSettings: build.query<ApiDataResponse<SiteSettings>, void>({
			query: () => API_ROUTES.system.general,
			providesTags: GENERAL_SETTINGS_TAGS,
		}),
		getSystemStatus: build.query<SystemStatusResponse, void>({
			query: () => API_ROUTES.system.status,
			providesTags: SYSTEM_STATUS_TAGS,
		}),
		getInstalledPlugins: build.query<
			ApiDataResponse<InstalledPluginsPayload>,
			void
		>({
			query: () => API_ROUTES.system.plugins,
			providesTags: PLUGINS_TAGS,
		}),
		getPublishedPlugins: build.query<
			PublishedPluginsResponse,
			PublishedPluginsQueryParams | void
		>({
			query: (params) => {
				const queryParams = new URLSearchParams();
				if (params?.q?.trim()) {
					queryParams.set("q", params.q.trim());
				}
				if (params?.sort) {
					queryParams.set("sort", params.sort);
				}
				if (params?.page) {
					queryParams.set("page", String(params.page));
				}
				if (params?.limit) {
					queryParams.set("limit", String(params.limit));
				}
				if (params?.tag) {
					queryParams.set("tag", params.tag);
				}
				const qs = queryParams.toString();
				return {
					url: `https://api.peakurl.org/v1/plugins${qs ? `?${qs}` : ""}`,
					credentials: "omit",
				};
			},
		}),
		activatePlugin: build.mutation<
			ApiDataResponse<{
				id: string;
				active: boolean;
				activePlugins: string[];
			}>,
			{ id: string }
		>({
			query: ({ id }) => ({
				url: API_ROUTES.system.pluginsActivate,
				method: "POST",
				body: { id },
			}),
			invalidatesTags: PLUGINS_TAGS,
		}),
		deactivatePlugin: build.mutation<
			ApiDataResponse<{
				id: string;
				active: boolean;
				activePlugins: string[];
			}>,
			{ id: string }
		>({
			query: ({ id }) => ({
				url: API_ROUTES.system.pluginsDeactivate,
				method: "POST",
				body: { id },
			}),
			invalidatesTags: PLUGINS_TAGS,
		}),
		deletePlugin: build.mutation<
			ApiDataResponse<{ id: string; deleted: boolean }>,
			{ id: string }
		>({
			query: ({ id }) => ({
				url: API_ROUTES.system.pluginsDelete,
				method: "POST",
				body: { id },
			}),
			invalidatesTags: PLUGINS_TAGS,
		}),
		uploadPlugin: build.mutation<
			ApiDataResponse<InstalledPlugin>,
			FormData
		>({
			query: (formData) => ({
				url: API_ROUTES.system.pluginsUpload,
				method: "POST",
				body: formData,
			}),
			invalidatesTags: PLUGINS_TAGS,
		}),
		getPluginExtensions: build.query<
			ApiDataResponse<PluginExtensionsPayload>,
			void
		>({
			query: () => API_ROUTES.system.pluginExtensions,
			providesTags: PLUGINS_TAGS,
		}),
		getPluginPage: build.query<
			ApiDataResponse<PluginPageDescriptor>,
			{ slug: string; pageId: string }
		>({
			query: ({ slug, pageId }) =>
				API_ROUTES.system.pluginPage(slug, pageId),
			providesTags: PLUGINS_TAGS,
		}),
		getPluginSettings: build.query<
			ApiDataResponse<{
				pluginSlug: string;
				settings: Record<string, unknown>;
			}>,
			{ slug: string }
		>({
			query: ({ slug }) => API_ROUTES.system.pluginSettings(slug),
			providesTags: PLUGINS_TAGS,
		}),
		updatePluginSettings: build.mutation<
			ApiDataResponse<{
				pluginSlug: string;
				settings: Record<string, unknown>;
			}>,
			{ slug: string; settings: Record<string, unknown> }
		>({
			query: ({ slug, settings }) => ({
				url: API_ROUTES.system.pluginSettings(slug),
				method: "POST",
				body: { settings },
			}),
			invalidatesTags: PLUGINS_TAGS,
		}),
		dispatchPluginAction: build.mutation<
			ApiDataResponse<Record<string, unknown>>,
			DispatchPluginActionArgs
		>({
			query: ({ slug, actionId, payload }) => ({
				url: API_ROUTES.system.pluginAction(slug, actionId),
				method: "POST",
				body: payload ?? {},
			}),
			invalidatesTags: (_result, error, { invalidates, payload }) => {
				if (error) {
					return [];
				}
				return resolvePluginActionInvalidatedTags(invalidates, payload);
			},
		}),
		saveGeneralSettings: build.mutation<
			ApiDataResponse<SiteSettings>,
			SaveGeneralSettingsPayload
		>({
			query: (body) => ({
				url: API_ROUTES.system.general,
				method: "POST",
				body: createGeneralSettingsBody(body),
			}),
			invalidatesTags: GENERAL_SETTINGS_TAGS,
		}),
		getGeoipStatus: build.query<ApiDataResponse<LocationDataStatus>, void>({
			query: () => API_ROUTES.system.geoip,
			providesTags: GEOIP_TAGS,
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
			providesTags: UPDATE_TAGS,
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
		getCacheStatus: build.query<CacheStatusResponse, void>({
			query: () => API_ROUTES.system.cache,
			providesTags: CACHE_TAGS,
		}),
		saveCacheConfiguration: build.mutation<
			CacheStatusResponse,
			CacheConfigurationPayload
		>({
			query: (body) => ({
				url: API_ROUTES.system.cache,
				method: "POST",
				body,
			}),
			invalidatesTags: CACHE_CHANGE_TAGS,
		}),
		clearCache: build.mutation<CacheStatusResponse, void>({
			query: () => ({
				url: API_ROUTES.system.cacheClear,
				method: "POST",
			}),
			invalidatesTags: CACHE_CHANGE_TAGS,
		}),
		upgradeDatabaseSchema: build.mutation<UpgradeDatabaseResponse, void>({
			query: () => ({
				url: API_ROUTES.system.updateDatabase,
				method: "POST",
			}),
			invalidatesTags: DATABASE_UPDATE_TAGS,
		}),
		getReleaseNotes: build.query<ReleaseNotesResponse, void>({
			queryFn: async (_arg, api) => {
				// <-- grab `api` from the arguments
				try {
					const response = await fetch(
						"https://api.peakurl.org/v1/release-notes",
						{
							signal: api.signal, // <-- pass the signal to native fetch
						}
					);
					if (!response.ok) {
						return {
							error: {
								status: response.status,
								data: "Failed to fetch release notes",
							},
						};
					}
					const data = await response.json();
					return { data };
				} catch (err: unknown) {
					// Check if the error is just an AbortError (from component unmounting)
					if (err instanceof Error && err.name === "AbortError") {
						return {
							error: {
								status: "FETCH_ERROR",
								error: "Request aborted",
							},
						};
					}
					return {
						error: {
							status: "FETCH_ERROR",
							error:
								err instanceof Error
									? err.message
									: "An unknown error occurred",
						},
					};
				}
			},
		}),
		getCronStatus: build.query<CronStatusResponse, void>({
			query: () => API_ROUTES.system.cron,
			transformResponse: (
				response: ApiDataResponse<ApiCronStatusResponse>
			) => mapApiCronStatus(response?.data),
			providesTags: CRON_TAGS,
		}),
		runDueJobs: build.mutation<RunDueJobsResult, void>({
			query: () => ({
				url: API_ROUTES.system.cronRunDue,
				method: "POST",
			}),
			transformResponse: (
				response: ApiDataResponse<ApiRunDueJobsResponse>
			) => mapApiRunDueJobsResult(response?.data),
			invalidatesTags: CRON_TAGS,
		}),
		runCronJob: build.mutation<RunCronJobResult, string>({
			query: (id: string) => ({
				url: API_ROUTES.system.cronRunJob(id),
				method: "POST",
			}),
			transformResponse: (
				response: ApiDataResponse<ApiRunCronJobResponse>
			) => mapApiRunCronJobResult(response?.data),
			invalidatesTags: CRON_TAGS,
		}),
		clearCronHistory: build.mutation<
			ClearCronHistoryResponse,
			ClearCronHistoryRequest | void
		>({
			query: (params) => {
				const jobKey = params?.jobId ?? params?.jobKey;
				return {
					url: API_ROUTES.system.cronClearHistory,
					method: "POST",
					body: jobKey ? { job_id: jobKey, job_key: jobKey } : {},
				};
			},
			transformResponse: (
				response: ApiDataResponse<ApiClearCronHistoryResponse>
			) => mapApiClearCronHistory(response?.data),
			invalidatesTags: CRON_TAGS,
		}),
		updateCronJobSchedule: build.mutation<
			CronJobScheduleResult,
			UpdateCronJobPayload
		>({
			query: ({
				id,
				intervalSeconds,
				preferredRunTime,
				isEnabled,
				retentionDays,
			}) => ({
				url: API_ROUTES.system.cronUpdateJob(id),
				method: "PATCH",
				body: {
					...(intervalSeconds !== undefined
						? { interval_seconds: intervalSeconds }
						: {}),
					...(preferredRunTime !== undefined
						? { preferred_run_time: preferredRunTime }
						: {}),
					...(isEnabled !== undefined
						? { is_enabled: isEnabled }
						: {}),
					...(retentionDays !== undefined
						? { retention_days: retentionDays }
						: {}),
				},
			}),
			transformResponse: (
				response: ApiDataResponse<ApiCronJobScheduleResponse>
			) => mapApiCronJobScheduleResult(response?.data),
			invalidatesTags: CRON_TAGS,
		}),
		resetCronJobSchedule: build.mutation<CronJobScheduleResult, string>({
			query: (id: string) => ({
				url: API_ROUTES.system.cronResetJob(id),
				method: "POST",
			}),
			transformResponse: (
				response: ApiDataResponse<ApiCronJobScheduleResponse>
			) => mapApiCronJobScheduleResult(response?.data),
			invalidatesTags: CRON_TAGS,
		}),
		updateCronSettings: build.mutation<
			{ retentionDays: number; success: boolean },
			{ retentionDays: number }
		>({
			query: ({ retentionDays }) => ({
				url: API_ROUTES.system.cronSettings,
				method: "POST",
				body: { retention_days: retentionDays },
			}),
			transformResponse: (
				response: ApiDataResponse<{
					retention_days: number;
					success: boolean;
				}>
			) => ({
				retentionDays: Number(response.data?.retention_days),
				success: Boolean(response.data?.success),
			}),
			invalidatesTags: CRON_TAGS,
		}),
		getMcpSettings: build.query<ApiDataResponse<McpSettings>, void>({
			query: () => API_ROUTES.system.mcpSettings,
			providesTags: MCP_SETTINGS_TAGS,
		}),
		updateMcpSettings: build.mutation<
			ApiDataResponse<McpSettings>,
			UpdateMcpSettingsPayload
		>({
			query: (body) => ({
				url: API_ROUTES.system.mcpSettings,
				method: "PUT",
				body,
			}),
			invalidatesTags: MCP_SETTINGS_CHANGE_TAGS,
		}),
		getMcpConnections: build.query<McpConnectionsResponse, void>({
			query: () => API_ROUTES.system.mcpConnections,
			providesTags: MCP_CONNECTIONS_TAGS,
		}),
		revokeMcpConnection: build.mutation<
			RevokeMcpConnectionResponse,
			string
		>({
			query: (id) => ({
				url: API_ROUTES.system.mcpConnectionById(id),
				method: "DELETE",
			}),
			invalidatesTags: MCP_CONNECTIONS_TAGS,
		}),
		revokeAllMcpConnections: build.mutation<
			RevokeAllMcpConnectionsResponse,
			void
		>({
			query: () => ({
				url: API_ROUTES.system.mcpRevokeAllConnections,
				method: "POST",
			}),
			invalidatesTags: MCP_CONNECTIONS_TAGS,
		}),
		getMcpTools: build.query<McpToolsResponse, void>({
			query: () => API_ROUTES.system.mcpTools,
			providesTags: MCP_TOOLS_TAGS,
		}),
	}),
});

export const {
	useGetAdminNoticesQuery,
	useGetGeneralSettingsQuery,
	useGetSystemStatusQuery,
	useGetInstalledPluginsQuery,
	useGetPublishedPluginsQuery,
	useActivatePluginMutation,
	useDeactivatePluginMutation,
	useDeletePluginMutation,
	useUploadPluginMutation,
	useGetPluginExtensionsQuery,
	useGetPluginPageQuery,
	useGetPluginSettingsQuery,
	useUpdatePluginSettingsMutation,
	useDispatchPluginActionMutation,
	useGetCacheStatusQuery,
	useSaveGeneralSettingsMutation,
	useSaveCacheConfigurationMutation,
	useClearCacheMutation,
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
	useGetReleaseNotesQuery,
	useGetCronStatusQuery,
	useRunDueJobsMutation,
	useRunCronJobMutation,
	useClearCronHistoryMutation,
	useUpdateCronJobScheduleMutation,
	useResetCronJobScheduleMutation,
	useUpdateCronSettingsMutation,
	useGetMcpSettingsQuery,
	useUpdateMcpSettingsMutation,
	useGetMcpConnectionsQuery,
	useRevokeMcpConnectionMutation,
	useRevokeAllMcpConnectionsMutation,
	useGetMcpToolsQuery,
} = systemApi;
