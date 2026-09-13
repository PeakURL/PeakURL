/**
 * Scheduled background jobs API wire and domain contracts.
 *
 * Provides typed definitions for HTTP wire responses (snake_case) and
 * internal TypeScript / React application domain models (camelCase).
 */

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
	jobs_count?: number;
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
	status: "running" | "success" | "failed" | "skipped" | string;
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
	status: "idle" | "running" | "failed" | "skipped" | string;
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
}

/**
 * Domain outcome for a single manual job execution.
 */
export interface RunCronJobResult {
	jobId: string;
	status: string;
	summary: string | null;
	error: string | null;
	success: boolean;
}

/**
 * Domain outcome for running all due background jobs.
 */
export interface RunDueJobsResult {
	runAll: boolean;
	results: Record<
		string,
		{
			status: string;
			summary: string | null;
			error: string | null;
		}
	>;
	success: boolean;
}
