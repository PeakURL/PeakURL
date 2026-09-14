/**
 * Operational health and scheduling summary calculations for background jobs.
 */

import type { CronJob, RunDueJobsResult } from "@/api";
import { __, _n, sprintf } from "@/i18n";

/**
 * Operational health classification for the scheduler.
 */
export type CronHealthStatus = "healthy" | "degraded" | "issues";

/**
 * Notification severity for the run-due aggregate outcome.
 */
export type RunDueNotificationType = "success" | "info" | "warning" | "error";

/**
 * Aggregated presentation summary for running due background tasks.
 */
export interface RunDueJobsSummaryOutcome {
	type: RunDueNotificationType;
	message: string;
	total: number;
	succeeded: number;
	skipped: number;
	failed: number;
	retrying: number;
	other: number;
}

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
 * Aggregate outcomes for due background jobs into user-facing presentation semantics.
 *
 * Distinguishes all succeeded, all skipped, all failed, retry scheduled, and mixed
 * results to prevent reporting partial failures or skips as generic success.
 *
 * @param result - Domain outcome from POST /api/v1/system/cron/run.
 * @return Aggregated presentation detail with message and notification severity.
 */
export function aggregateRunDueJobsResult(
	result?: RunDueJobsResult | null
): RunDueJobsSummaryOutcome {
	const resultsMap = result?.results ?? {};
	const entries = Object.values(resultsMap);
	const total = entries.length;

	if (0 === total) {
		return {
			type: "info",
			message: __("No background jobs were currently due."),
			total: 0,
			succeeded: 0,
			skipped: 0,
			failed: 0,
			retrying: 0,
			other: 0,
		};
	}

	let succeeded = 0;
	let skipped = 0;
	let failed = 0;
	let retrying = 0;
	let other = 0;

	for (const outcome of entries) {
		const status = (outcome?.status ?? "").toLowerCase();
		if ("success" === status) {
			succeeded++;
		} else if ("skipped" === status) {
			skipped++;
		} else if ("failed" === status) {
			failed++;
		} else if ("retrying" === status || "retry" === status) {
			retrying++;
		} else {
			other++;
		}
	}

	// 1. All succeeded
	if (succeeded === total) {
		return {
			type: "success",
			message: sprintf(
				/* translators: %d is count of executed jobs */
				_n(
					"%d due job executed successfully.",
					"%d due jobs executed successfully.",
					total
				),
				total
			),
			total,
			succeeded,
			skipped,
			failed,
			retrying,
			other,
		};
	}

	// 2. All skipped
	if (skipped === total) {
		return {
			type: "info",
			message: sprintf(
				/* translators: %d is count of skipped jobs */
				_n(
					"%d due job was skipped.",
					"%d due jobs were skipped.",
					total
				),
				total
			),
			total,
			succeeded,
			skipped,
			failed,
			retrying,
			other,
		};
	}

	// 3. All failed
	if (failed === total) {
		return {
			type: "error",
			message: sprintf(
				/* translators: %d is count of failed jobs */
				_n("%d due job failed.", "%d due jobs failed.", total),
				total
			),
			total,
			succeeded,
			skipped,
			failed,
			retrying,
			other,
		};
	}

	// 4. All scheduled for retry
	if (retrying === total) {
		return {
			type: "warning",
			message: sprintf(
				/* translators: %d is count of jobs scheduled for retry */
				_n(
					"%d due job scheduled for retry.",
					"%d due jobs scheduled for retry.",
					total
				),
				total
			),
			total,
			succeeded,
			skipped,
			failed,
			retrying,
			other,
		};
	}

	// 5. Mixed outcomes: compile human-readable breakdown in consistent order
	const parts: string[] = [];
	if (succeeded > 0) {
		parts.push(
			sprintf(
				/* translators: %d is count of succeeded jobs */
				__("%d succeeded"),
				succeeded
			)
		);
	}
	if (skipped > 0) {
		parts.push(
			sprintf(
				/* translators: %d is count of skipped jobs */
				__("%d skipped"),
				skipped
			)
		);
	}
	if (failed > 0) {
		parts.push(
			sprintf(
				/* translators: %d is count of failed jobs */
				__("%d failed"),
				failed
			)
		);
	}
	if (retrying > 0) {
		parts.push(
			sprintf(
				/* translators: %d is count of retrying jobs */
				__("%d retrying"),
				retrying
			)
		);
	}
	if (other > 0) {
		parts.push(
			sprintf(
				/* translators: %d is count of other jobs */
				__("%d other"),
				other
			)
		);
	}

	const breakdown = parts.join(", ");
	const message = sprintf(
		/* translators: 1: total job count, 2: breakdown summary */
		_n(
			"%1$d due job processed: %2$s.",
			"%1$d due jobs processed: %2$s.",
			total
		),
		total,
		breakdown
	);

	// Partial failures or retries warrant warning so they are not mistaken for full success.
	let type: RunDueNotificationType = "warning";
	if (0 === failed && 0 === retrying && 0 === other) {
		type = "info";
	}

	return {
		type,
		message,
		total,
		succeeded,
		skipped,
		failed,
		retrying,
		other,
	};
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
