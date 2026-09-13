import { test, expect } from "@playwright/test";
import {
	API_ROUTES,
	mapApiCronRun,
	mapApiCronJob,
	mapApiCronStatus,
	mapApiRunCronJobResult,
	mapApiRunDueJobsResult,
	type ApiCronJob,
	type ApiCronRun,
	type ApiCronStatusResponse,
	type CronJob,
} from "../../client/api";
import { calculateCronStatusSummary } from "../../client/pages/dashboard/tools/scheduled-jobs/summary";
import { formatInterval } from "../../client/pages/dashboard/tools/scheduled-jobs/formatters";

test.describe("Scheduled Jobs API Boundary Adapters & Presentation", () => {
	test.describe("API Route Definitions", () => {
		test("constructs accurate system cron route paths", () => {
			expect(API_ROUTES.system.cron).toBe("system/cron");
			expect(API_ROUTES.system.cronRunDue).toBe("system/cron/run");
			expect(
				API_ROUTES.system.cronRunJob("peakurl_session_cleanup")
			).toBe("system/cron/run/peakurl_session_cleanup");
			expect(API_ROUTES.system.cronRunJob("job with spaces/slash")).toBe(
				"system/cron/run/job%20with%20spaces%2Fslash"
			);
		});
	});

	test.describe("CronRun Adapter (mapApiCronRun)", () => {
		test("maps snake_case wire fields to camelCase domain model", () => {
			const wireRun: ApiCronRun = {
				id: "run_abc123",
				status: "success",
				attempt: 1,
				started_at: "2026-09-13T10:00:00Z",
				finished_at: "2026-09-13T10:00:01Z",
				duration_ms: 124,
				output_summary: "Purged 15 expired sessions",
				error_message: null,
			};

			const domainRun = mapApiCronRun(wireRun);

			expect(domainRun.id).toBe("run_abc123");
			expect(domainRun.status).toBe("success");
			expect(domainRun.attempt).toBe(1);
			expect(domainRun.startedAt).toBe("2026-09-13T10:00:00Z");
			expect(domainRun.finishedAt).toBe("2026-09-13T10:00:01Z");
			expect(domainRun.durationMs).toBe(124);
			expect(domainRun.outputSummary).toBe("Purged 15 expired sessions");
			expect(domainRun.errorMessage).toBeNull();
		});

		test("safely defaults optional wire fields when omitted", () => {
			const minimalRun: ApiCronRun = {
				id: "run_xyz456",
				status: "running",
				attempt: 1,
			};

			const domainRun = mapApiCronRun(minimalRun);

			expect(domainRun.id).toBe("run_xyz456");
			expect(domainRun.status).toBe("running");
			expect(domainRun.attempt).toBe(1);
			expect(domainRun.startedAt).toBe("");
			expect(domainRun.finishedAt).toBeNull();
			expect(domainRun.durationMs).toBeNull();
			expect(domainRun.outputSummary).toBeNull();
			expect(domainRun.errorMessage).toBeNull();
		});

		test("safely handles null and undefined runs", () => {
			const defaultRun = mapApiCronRun(null);
			expect(defaultRun.id).toBe("");
			expect(defaultRun.status).toBe("unknown");
			expect(defaultRun.attempt).toBe(1);
			expect(defaultRun.startedAt).toBe("");
			expect(defaultRun.finishedAt).toBeNull();
			expect(defaultRun.durationMs).toBeNull();
			expect(defaultRun.outputSummary).toBeNull();
			expect(defaultRun.errorMessage).toBeNull();
		});
	});

	test.describe("CronJob Adapter (mapApiCronJob)", () => {
		test("maps snake_case wire fields and nested runs accurately", () => {
			const wireJob: ApiCronJob = {
				id: "peakurl_session_cleanup",
				title: "Session Cleanup",
				interval_seconds: 86400,
				status: "idle",
				is_enabled: true,
				next_run_at: "2026-09-14T00:00:00Z",
				last_run_at: "2026-09-13T00:00:00Z",
				last_finished_at: "2026-09-13T00:00:01Z",
				attempts: 0,
				max_attempts: 3,
				last_error: null,
				recent_runs: [
					{
						id: "run_1",
						status: "success",
						attempt: 1,
						started_at: "2026-09-13T00:00:00Z",
						duration_ms: 45,
					},
				],
			};

			const domainJob = mapApiCronJob(wireJob);

			expect(domainJob.id).toBe("peakurl_session_cleanup");
			expect(domainJob.title).toBe("Session Cleanup");
			expect(domainJob.intervalSeconds).toBe(86400);
			expect(domainJob.status).toBe("idle");
			expect(domainJob.isEnabled).toBe(true);
			expect(domainJob.nextRunAt).toBe("2026-09-14T00:00:00Z");
			expect(domainJob.lastRunAt).toBe("2026-09-13T00:00:00Z");
			expect(domainJob.lastFinishedAt).toBe("2026-09-13T00:00:01Z");
			expect(domainJob.attempts).toBe(0);
			expect(domainJob.maxAttempts).toBe(3);
			expect(domainJob.lastError).toBeNull();
			expect(domainJob.recentRuns).toHaveLength(1);
			expect(domainJob.recentRuns[0].durationMs).toBe(45);
		});

		test("safely handles empty or undefined job payload", () => {
			const fallbackJob = mapApiCronJob(undefined);
			expect(fallbackJob.id).toBe("");
			expect(fallbackJob.title).toBe("");
			expect(fallbackJob.intervalSeconds).toBe(0);
			expect(fallbackJob.status).toBe("idle");
			expect(fallbackJob.isEnabled).toBe(false);
			expect(fallbackJob.nextRunAt).toBeNull();
			expect(fallbackJob.recentRuns).toEqual([]);
		});
	});

	test.describe("Summary Calculation (calculateCronStatusSummary)", () => {
		const sampleJobs: CronJob[] = [
			{
				id: "job_1",
				title: "Job One",
				intervalSeconds: 3600,
				status: "idle",
				isEnabled: true,
				nextRunAt: "2026-09-13T12:00:00Z",
				lastRunAt: "2026-09-13T11:00:00Z",
				lastFinishedAt: "2026-09-13T11:00:05Z",
				attempts: 0,
				maxAttempts: 3,
				lastError: null,
				recentRuns: [
					{
						id: "r1",
						status: "success",
						attempt: 1,
						startedAt: "2026-09-13T11:00:00Z",
						finishedAt: null,
						durationMs: 50,
						outputSummary: null,
						errorMessage: null,
					},
				],
			},
			{
				id: "job_2",
				title: "Job Two",
				intervalSeconds: 86400,
				status: "idle",
				isEnabled: true,
				nextRunAt: "2026-09-13T15:00:00Z",
				lastRunAt: "2026-09-12T15:00:00Z",
				lastFinishedAt: "2026-09-12T15:00:02Z",
				attempts: 0,
				maxAttempts: 3,
				lastError: null,
				recentRuns: [],
			},
		];

		test("identifies healthy status and earliest next due job", () => {
			const summary = calculateCronStatusSummary(sampleJobs);

			expect(summary.status).toBe("healthy");
			expect(summary.totalJobs).toBe(2);
			expect(summary.scheduledJobs).toBe(2);
			expect(summary.runningJobs).toBe(0);
			expect(summary.failedJobs).toBe(0);
			expect(summary.lastRunAt).toBe("2026-09-13T11:00:00Z");
			expect(summary.nextDueJob?.id).toBe("job_1");
			expect(summary.nextDueJob?.nextRunAt).toBe("2026-09-13T12:00:00Z");
		});

		test("identifies running status counts", () => {
			const jobsWithRunning: CronJob[] = [
				...sampleJobs,
				{
					id: "job_running",
					title: "Running Job",
					intervalSeconds: 60,
					status: "running",
					isEnabled: true,
					nextRunAt: null,
					lastRunAt: "2026-09-13T11:59:00Z",
					lastFinishedAt: null,
					attempts: 1,
					maxAttempts: 3,
					lastError: null,
					recentRuns: [],
				},
			];

			const summary = calculateCronStatusSummary(jobsWithRunning);
			expect(summary.totalJobs).toBe(3);
			expect(summary.runningJobs).toBe(1);
			expect(summary.scheduledJobs).toBe(2); // running is not scheduled
		});

		test("identifies issues status when a job has failed", () => {
			const jobsWithFailure: CronJob[] = [
				...sampleJobs,
				{
					id: "job_failed",
					title: "Failing Job",
					intervalSeconds: 300,
					status: "failed",
					isEnabled: true,
					nextRunAt: "2026-09-13T12:05:00Z",
					lastRunAt: "2026-09-13T11:55:00Z",
					lastFinishedAt: null,
					attempts: 3,
					maxAttempts: 3,
					lastError: "Connection refused",
					recentRuns: [
						{
							id: "r_fail",
							status: "failed",
							attempt: 3,
							startedAt: "2026-09-13T11:55:00Z",
							finishedAt: null,
							durationMs: 15,
							outputSummary: null,
							errorMessage: "Connection refused",
						},
					],
				},
			];

			const summary = calculateCronStatusSummary(jobsWithFailure);
			expect(summary.status).toBe("issues");
			expect(summary.failedJobs).toBe(1);
		});

		test("identifies degraded status when previous run failed but job is not currently failed", () => {
			const jobsWithRecentFailure: CronJob[] = [
				{
					...sampleJobs[0],
					recentRuns: [
						{
							id: "r_degraded",
							status: "failed",
							attempt: 1,
							startedAt: "2026-09-13T11:00:00Z",
							finishedAt: null,
							durationMs: 10,
							outputSummary: null,
							errorMessage: "Temporary glitch",
						},
					],
				},
			];

			const summary = calculateCronStatusSummary(jobsWithRecentFailure);
			expect(summary.status).toBe("degraded");
		});

		test("handles empty jobs list safely", () => {
			const summary = calculateCronStatusSummary([]);
			expect(summary.status).toBe("healthy");
			expect(summary.totalJobs).toBe(0);
			expect(summary.scheduledJobs).toBe(0);
			expect(summary.runningJobs).toBe(0);
			expect(summary.failedJobs).toBe(0);
			expect(summary.lastRunAt).toBeNull();
			expect(summary.nextDueJob).toBeNull();
		});
	});

	test.describe("Full Status Payload Adapter (mapApiCronStatus)", () => {
		test("transforms full status payload with jobs and jobsCount", () => {
			const rawPayload: ApiCronStatusResponse = {
				jobs: [
					{
						id: "test_job_1",
						title: "Test Job 1",
						interval_seconds: 3600,
						status: "idle",
						is_enabled: true,
						next_run_at: "2026-09-13T12:00:00Z",
						attempts: 0,
					},
				],
				jobs_count: 1,
			};

			const domain = mapApiCronStatus(rawPayload);
			expect(domain.jobs).toHaveLength(1);
			expect(domain.jobsCount).toBe(1);
			expect(domain.jobs[0].id).toBe("test_job_1");
		});

		test("handles null API payload safely", () => {
			const domain = mapApiCronStatus(null);
			expect(domain.jobs).toEqual([]);
			expect(domain.jobsCount).toBe(0);
		});
	});

	test.describe("Run Action Outcome Adapters", () => {
		test("mapApiRunCronJobResult converts single run outcome", () => {
			const successResult = mapApiRunCronJobResult({
				job_id: "job_xyz",
				status: "success",
				summary: "Executed task successfully",
				error: null,
				success: true,
			});

			expect(successResult.jobId).toBe("job_xyz");
			expect(successResult.status).toBe("success");
			expect(successResult.summary).toBe("Executed task successfully");
			expect(successResult.success).toBe(true);

			const failResult = mapApiRunCronJobResult({
				job_id: "job_err",
				status: "failed",
				summary: null,
				error: "Failed to connect",
				success: false,
			});

			expect(failResult.jobId).toBe("job_err");
			expect(failResult.status).toBe("failed");
			expect(failResult.error).toBe("Failed to connect");
			expect(failResult.success).toBe(false);
		});

		test("mapApiRunDueJobsResult converts due-jobs outcome", () => {
			const dueResult = mapApiRunDueJobsResult({
				run_all: true,
				results: {
					job_1: { status: "success", summary: "Completed" },
				},
				success: true,
			});

			expect(dueResult.runAll).toBe(true);
			expect(dueResult.results.job_1.status).toBe("success");
			expect(dueResult.success).toBe(true);
		});
	});

	test.describe("Recurrence Interval Formatting", () => {
		test("formats standard intervals correctly into human readable strings", () => {
			expect(formatInterval(0)).toBe("Manual");
			expect(formatInterval(60)).toBe("Every minute");
			expect(formatInterval(300)).toBe("Every 5 minutes");
			expect(formatInterval(900)).toBe("Every 15 minutes");
			expect(formatInterval(3600)).toBe("Hourly");
			expect(formatInterval(7200)).toBe("Every 2 hours");
			expect(formatInterval(43200)).toBe("Every 12 hours");
			expect(formatInterval(86400)).toBe("Daily");
			expect(formatInterval(604800)).toBe("Weekly");
		});
	});
});
