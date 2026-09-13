/**
 * Explicit API boundary transformation adapters.
 *
 * Provides bidirectional mappings between the backend PHP HTTP JSON API
 * contract (snake_case) and internal TypeScript / React application models (camelCase).
 */

import type {
	ApiProfileUser,
	ApiUserCapabilities,
	ProfileUser,
	UserCapabilities,
} from "./types/users";
import type {
	ApiCronJob,
	ApiCronRun,
	ApiCronStatusResponse,
	ApiRunCronJobResponse,
	ApiRunDueJobsResponse,
	CronJob,
	CronRun,
	CronStatusResponse,
	RunCronJobResult,
	RunDueJobsResult,
} from "./types/cron";

/**
 * Default internal user capability flags with all permissions disabled.
 */
export const DEFAULT_USER_CAPABILITIES: Readonly<UserCapabilities> =
	Object.freeze({
		manageUsers: false,
		manageSiteSettings: false,
		manageMailDelivery: false,
		manageLocationData: false,
		managePerformance: false,
		manageUpdates: false,
		manageProfile: false,
		manageApiKeys: false,
		manageWebhooks: false,
		viewLinks: false,
		editLinks: false,
		trashLinks: false,
		deleteLinks: false,
		emptyTrash: false,
		viewAnalytics: false,
		createLinks: false,
	});

/**
 * Map raw wire capability flags from the API response into the camelCase domain model.
 *
 * @param apiCapabilities - Raw snake_case capability flags returned by the backend.
 * @return Normalized UserCapabilities object.
 */
export function mapApiCapabilities(
	apiCapabilities?: ApiUserCapabilities | null
): UserCapabilities {
	if (!apiCapabilities) {
		return { ...DEFAULT_USER_CAPABILITIES };
	}

	return {
		manageUsers: Boolean(apiCapabilities.manage_users),
		manageSiteSettings: Boolean(apiCapabilities.manage_site_settings),
		manageMailDelivery: Boolean(apiCapabilities.manage_mail_delivery),
		manageLocationData: Boolean(apiCapabilities.manage_location_data),
		managePerformance: Boolean(apiCapabilities.manage_performance),
		manageUpdates: Boolean(apiCapabilities.manage_updates),
		manageProfile: Boolean(apiCapabilities.manage_profile),
		manageApiKeys: Boolean(apiCapabilities.manage_api_keys),
		manageWebhooks: Boolean(apiCapabilities.manage_webhooks),
		viewLinks: Boolean(apiCapabilities.view_links),
		editLinks: Boolean(apiCapabilities.edit_links),
		trashLinks: Boolean(apiCapabilities.trash_links),
		deleteLinks: Boolean(apiCapabilities.delete_links),
		emptyTrash: Boolean(apiCapabilities.empty_trash),
		viewAnalytics: Boolean(apiCapabilities.view_analytics),
		createLinks: Boolean(apiCapabilities.create_links),
	};
}

/**
 * Map internal camelCase capability flags to the snake_case API wire payload.
 *
 * @param capabilities - Internal capability flags.
 * @return Raw ApiUserCapabilities payload for the wire contract.
 */
export function mapUserCapabilitiesToApi(
	capabilities: Partial<UserCapabilities>
): ApiUserCapabilities {
	const apiCapabilitiesPayload: ApiUserCapabilities = {};

	if (undefined !== capabilities.manageUsers) {
		apiCapabilitiesPayload.manage_users = Boolean(capabilities.manageUsers);
	}
	if (undefined !== capabilities.manageSiteSettings) {
		apiCapabilitiesPayload.manage_site_settings = Boolean(
			capabilities.manageSiteSettings
		);
	}
	if (undefined !== capabilities.manageMailDelivery) {
		apiCapabilitiesPayload.manage_mail_delivery = Boolean(
			capabilities.manageMailDelivery
		);
	}
	if (undefined !== capabilities.manageLocationData) {
		apiCapabilitiesPayload.manage_location_data = Boolean(
			capabilities.manageLocationData
		);
	}
	if (undefined !== capabilities.managePerformance) {
		apiCapabilitiesPayload.manage_performance = Boolean(
			capabilities.managePerformance
		);
	}
	if (undefined !== capabilities.manageUpdates) {
		apiCapabilitiesPayload.manage_updates = Boolean(
			capabilities.manageUpdates
		);
	}
	if (undefined !== capabilities.manageProfile) {
		apiCapabilitiesPayload.manage_profile = Boolean(
			capabilities.manageProfile
		);
	}
	if (undefined !== capabilities.manageApiKeys) {
		apiCapabilitiesPayload.manage_api_keys = Boolean(
			capabilities.manageApiKeys
		);
	}
	if (undefined !== capabilities.manageWebhooks) {
		apiCapabilitiesPayload.manage_webhooks = Boolean(
			capabilities.manageWebhooks
		);
	}
	if (undefined !== capabilities.viewLinks) {
		apiCapabilitiesPayload.view_links = Boolean(capabilities.viewLinks);
	}
	if (undefined !== capabilities.editLinks) {
		apiCapabilitiesPayload.edit_links = Boolean(capabilities.editLinks);
	}
	if (undefined !== capabilities.trashLinks) {
		apiCapabilitiesPayload.trash_links = Boolean(capabilities.trashLinks);
	}
	if (undefined !== capabilities.deleteLinks) {
		apiCapabilitiesPayload.delete_links = Boolean(capabilities.deleteLinks);
	}
	if (undefined !== capabilities.emptyTrash) {
		apiCapabilitiesPayload.empty_trash = Boolean(capabilities.emptyTrash);
	}
	if (undefined !== capabilities.viewAnalytics) {
		apiCapabilitiesPayload.view_analytics = Boolean(
			capabilities.viewAnalytics
		);
	}
	if (undefined !== capabilities.createLinks) {
		apiCapabilitiesPayload.create_links = Boolean(capabilities.createLinks);
	}

	return apiCapabilitiesPayload;
}

/**
 * Normalize an API user profile into the internal application domain model.
 *
 * @param apiUser - Raw user payload from the API wire contract.
 * @return Fully normalized ProfileUser or null.
 */
export function mapApiUser(
	apiUser?: ApiProfileUser | null
): ProfileUser | null {
	if (!apiUser) {
		return null;
	}

	return {
		...apiUser,
		capabilities: mapApiCapabilities(apiUser.capabilities),
	};
}

/**
 * Map raw wire cron run history record into the internal camelCase domain model.
 *
 * @param apiRun - Raw cron run record from the API response.
 * @return Normalized CronRun model.
 */
export function mapApiCronRun(apiRun?: ApiCronRun | null): CronRun {
	if (!apiRun) {
		return {
			id: "",
			status: "unknown",
			attempt: 1,
			startedAt: "",
			finishedAt: null,
			durationMs: null,
			outputSummary: null,
			errorMessage: null,
		};
	}

	return {
		id: String(apiRun.id || ""),
		status: String(apiRun.status || "unknown"),
		attempt: Number(apiRun.attempt || 1),
		startedAt: String(apiRun.started_at || ""),
		finishedAt: apiRun.finished_at ?? null,
		durationMs:
			typeof apiRun.duration_ms === "number" ? apiRun.duration_ms : null,
		outputSummary: apiRun.output_summary ?? null,
		errorMessage: apiRun.error_message ?? null,
	};
}

/**
 * Map raw wire cron job record into the internal camelCase domain model.
 *
 * @param apiJob - Raw cron job record from the API response.
 * @return Normalized CronJob model.
 */
export function mapApiCronJob(apiJob?: ApiCronJob | null): CronJob {
	if (!apiJob) {
		return {
			id: "",
			title: "",
			intervalSeconds: 0,
			status: "idle",
			isEnabled: false,
			nextRunAt: null,
			lastRunAt: null,
			lastFinishedAt: null,
			attempts: 0,
			maxAttempts: 3,
			lastError: null,
			recentRuns: [],
		};
	}

	const apiRuns = apiJob.recent_runs ?? [];

	return {
		id: String(apiJob.id || ""),
		title: String(apiJob.title || ""),
		intervalSeconds: Number(apiJob.interval_seconds || 0),
		status: String(apiJob.status || "idle"),
		isEnabled: Boolean(apiJob.is_enabled),
		nextRunAt: apiJob.next_run_at ?? null,
		lastRunAt: apiJob.last_run_at ?? null,
		lastFinishedAt: apiJob.last_finished_at ?? null,
		attempts: Number(apiJob.attempts || 0),
		maxAttempts: Number(apiJob.max_attempts ?? 3),
		lastError: apiJob.last_error ?? null,
		recentRuns: Array.isArray(apiRuns)
			? apiRuns.map((apiRun) => mapApiCronRun(apiRun))
			: [],
	};
}

/**
 * Map raw cron status payload into the fully transformed CronStatusResponse.
 *
 * @param apiStatus - Raw payload returned by GET /api/v1/system/cron.
 * @return Normalized CronStatusResponse.
 */
export function mapApiCronStatus(
	apiStatus?: ApiCronStatusResponse | null
): CronStatusResponse {
	const apiJobs = apiStatus?.jobs ?? [];
	const jobs = Array.isArray(apiJobs)
		? apiJobs.map((apiJob) => mapApiCronJob(apiJob))
		: [];
	const jobsCount = Number(apiStatus?.jobs_count ?? jobs.length);

	return {
		jobs,
		jobsCount,
	};
}

/**
 * Map manual run outcome from wire to camelCase domain result.
 *
 * @param apiResponse - Raw manual run response from POST /api/v1/system/cron/run/:job_id.
 * @return Normalized RunCronJobResult.
 */
export function mapApiRunCronJobResult(
	apiResponse?: ApiRunCronJobResponse | null
): RunCronJobResult {
	return {
		jobId: String(apiResponse?.job_id || ""),
		status: String(apiResponse?.status || "unknown"),
		summary: apiResponse?.summary ?? null,
		error: apiResponse?.error ?? null,
		success: Boolean(apiResponse?.success),
	};
}

/**
 * Map run-due-jobs outcome from wire to camelCase domain result.
 *
 * @param apiResponse - Raw run-due response from POST /api/v1/system/cron/run.
 * @return Normalized RunDueJobsResult.
 */
export function mapApiRunDueJobsResult(
	apiResponse?: ApiRunDueJobsResponse | null
): RunDueJobsResult {
	const apiResults = apiResponse?.results ?? {};
	const normalizedResults: Record<
		string,
		{ status: string; summary: string | null; error: string | null }
	> = {};

	for (const [jobId, jobOutcome] of Object.entries(apiResults)) {
		normalizedResults[jobId] = {
			status: String(jobOutcome?.status || "unknown"),
			summary: jobOutcome?.summary ?? null,
			error: jobOutcome?.error ?? null,
		};
	}

	return {
		runAll: Boolean(apiResponse?.run_all),
		results: normalizedResults,
		success: Boolean(apiResponse?.success),
	};
}
