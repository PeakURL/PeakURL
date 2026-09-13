/**
 * Operational health and scheduling summary calculations for background jobs.
 */

import type { CronJob } from "@/api";

/**
 * Operational health classification for the scheduler.
 */
export type CronHealthStatus = "healthy" | "degraded" | "issues";

/**
 * Summary detail for the next upcoming scheduled job.
 */
export interface NextDueJobInfo {
	id: string;
	title: string;
	nextRunAt: string;
}

/**
 * High-level aggregated summary used by System Status and overview cards.
 */
export interface CronStatusSummary {
	status: CronHealthStatus;
	totalJobs: number;
	scheduledJobs: number;
	runningJobs: number;
	failedJobs: number;
	lastRunAt: string | null;
	nextDueJob: NextDueJobInfo | null;
}

/**
 * Calculate high-level health summary and upcoming schedule status from registered jobs.
 *
 * @param jobs - List of normalized domain cron jobs.
 * @return Aggregated operational summary.
 */
export function calculateCronStatusSummary(jobs: CronJob[]): CronStatusSummary {
	const totalJobs = jobs.length;
	const runningJobs = jobs.filter((j) => "running" === j.status).length;
	const failedJobs = jobs.filter(
		(j) => "failed" === j.status || (j.attempts > 0 && null !== j.lastError)
	).length;
	const scheduledJobs = jobs.filter(
		(j) => j.isEnabled && "running" !== j.status
	).length;

	// Overall status
	let status: CronHealthStatus = "healthy";
	if (failedJobs > 0) {
		status = "issues";
	} else if (
		jobs.some((j) => j.recentRuns.some((r) => "failed" === r.status))
	) {
		status = "degraded";
	}

	// Calculate last run timestamp
	const validLastRuns = jobs
		.map((j) => j.lastRunAt)
		.filter((t): t is string => Boolean(t))
		.sort((a, b) => new Date(b).getTime() - new Date(a).getTime());
	const lastRunAt = validLastRuns[0] ?? null;

	// Calculate next due job
	const validNextRuns = jobs
		.filter((j) => j.isEnabled && Boolean(j.nextRunAt))
		.map((j) => ({
			id: j.id,
			title: j.title,
			nextRunAt: j.nextRunAt as string,
		}))
		.sort(
			(a, b) =>
				new Date(a.nextRunAt).getTime() -
				new Date(b.nextRunAt).getTime()
		);
	const nextDueJob = validNextRuns[0] ?? null;

	return {
		status,
		totalJobs,
		scheduledJobs,
		runningJobs,
		failedJobs,
		lastRunAt,
		nextDueJob,
	};
}
