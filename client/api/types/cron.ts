/**
 * Scheduled background jobs API wire and domain contracts.
 *
 * Provides typed definitions for HTTP wire responses (snake_case) and
 * internal TypeScript / React application domain models (camelCase).
 */

/**
 * Known lifecycle statuses for a background job in storage.
 */
export type CronJobKnownStatus = "idle" | "running" | "failed";

/**
 * Domain status for a background job supporting extensibility.
 */
export type CronJobStatus = CronJobKnownStatus | (string & {});

/**
 * Known execution history statuses recorded in cron_runs.
 */
export type CronRunKnownStatus = "running" | "success" | "failed" | "retrying";

/**
 * Domain status for a recorded run execution supporting extensibility.
 */
export type CronRunStatus = CronRunKnownStatus | (string & {});

/**
 * Known outcome statuses for job execution results.
 */
export type CronExecutionKnownStatus = "success" | "failed" | "skipped";

/**
 * Domain status for an execution attempt outcome supporting extensibility.
 */
export type CronExecutionStatus = CronExecutionKnownStatus | (string & {});

/**
 * Raw execution run row from the API wire contract.
 */
export interface ApiCronRun {
	id: string;
	status: string;
	attempt: number;
	started_at?: string;
	finished_at?: string | null;
	duration_ms?: number | null;
	output_summary?: string | null;
	error_message?: string | null;
}

/**
 * Raw scheduled job definition from the API wire contract.
 */
export interface ApiCronJob {
	id: string;
	title: string;
	interval_seconds?: number;
	recommended_interval_seconds?: number;
	preferred_run_time?: string | null;
	is_customized?: boolean;
	status: string;
	is_enabled?: boolean;
	next_run_at?: string | null;
	last_run_at?: string | null;
	last_finished_at?: string | null;
	attempts: number;
	max_attempts?: number;
	last_error?: string | null;
	recent_runs?: ApiCronRun[];
}

/**
 * Raw payload returned by GET /api/v1/system/cron.
 */
export interface ApiCronStatusResponse {
	jobs: ApiCronJob[];
	jobs_count: number;
	retention_days?: number;
	timezone?: string;
}

/**
 * Raw payload returned by POST /api/v1/system/cron/run/{id}.
 */
export interface ApiRunCronJobResponse {
	job_id?: string;
	status: string;
	summary?: string | null;
	error?: string | null;
	success: boolean;
}

/**
 * Raw payload returned by POST /api/v1/system/cron/run.
 */
export interface ApiRunDueJobsResponse {
	run_all?: boolean;
	results: Record<
		string,
		{
			status: string;
			summary?: string | null;
			error?: string | null;
		}
	>;
	success: boolean;
}

/**
 * Execution history run in camelCase domain representation.
 */
export interface CronRun {
	id: string;
	status: CronRunStatus;
	attempt: number;
	startedAt: string;
	finishedAt: string | null;
	durationMs: number | null;
	outputSummary: string | null;
	errorMessage: string | null;
}

/**
 * Scheduled job model in camelCase domain representation.
 */
export interface CronJob {
	id: string;
	title: string;
	intervalSeconds: number;
	recommendedIntervalSeconds: number;
	preferredRunTime: string | null;
	isCustomized: boolean;
	status: CronJobStatus;
	isEnabled: boolean;
	nextRunAt: string | null;
	lastRunAt: string | null;
	lastFinishedAt: string | null;
	attempts: number;
	maxAttempts: number;
	lastError: string | null;
	recentRuns: CronRun[];
}

/**
 * Transformed domain response for the Scheduled Jobs view.
 */
export interface CronStatusResponse {
	jobs: CronJob[];
	jobsCount: number;
	retentionDays: number;
	timezone: string;
}

/**
 * Request payload for updating a job's schedule.
 */
export interface UpdateCronJobPayload {
	id: string;
	intervalSeconds?: number;
	preferredRunTime?: string | null;
	isEnabled?: boolean;
}

/**
 * Raw request payload for updating a job's schedule.
 */
export interface ApiUpdateCronJobPayload {
	interval_seconds?: number;
	preferred_run_time?: string | null;
	is_enabled?: boolean;
}

/**
 * Raw response returned after updating or resetting a job's schedule.
 */
export interface ApiCronJobScheduleResponse {
	job: ApiCronJob;
	success: boolean;
}

/**
 * Domain outcome for job schedule update or reset.
 */
export interface CronJobScheduleResult {
	job: CronJob;
	success: boolean;
}

/**
 * Domain outcome for a single manual job execution.
 */
export interface RunCronJobResult {
	jobId: string;
	status: CronExecutionStatus;
	summary: string | null;
	error: string | null;
	success: boolean;
}

/**
 * Outcome detail for an individual background job executed during a run-due pass.
 */
export interface CronJobExecutionOutcome {
	status: CronExecutionStatus;
	summary: string | null;
	error: string | null;
}

/**
 * Domain outcome for running all due background jobs.
 */
export interface RunDueJobsResult {
	runAll: boolean;
	results: Record<string, CronJobExecutionOutcome>;
	success: boolean;
}

/**
 * Raw payload returned by POST /api/v1/system/cron/history/clear.
 */
export interface ApiClearCronHistoryResponse {
	deleted_count: number;
	job_id?: string | null;
	job_key?: string | null;
	success: boolean;
}

/**
 * Request parameters for clearing cron execution history.
 */
export interface ClearCronHistoryRequest {
	jobId?: string;
	jobKey?: string;
}

/**
 * Domain outcome for clearing cron execution history.
 */
export interface ClearCronHistoryResponse {
	deletedCount: number;
	jobId: string | null;
	success: boolean;
}
