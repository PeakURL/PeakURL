import { useEffect, useMemo, useRef, useState } from "react";
import {
	AlertCircle,
	AlertTriangle,
	CalendarClock,
	Clock,
	Play,
	RefreshCw,
	Search,
	ShieldCheck,
	SlidersHorizontal,
	Trash2,
} from "lucide-react";

import type { CronJob } from "@/api";
import { Button, ConfirmDialog, useNotification } from "@/components";
import { useAdminAccess } from "@/hooks";
import { __, _n, sprintf } from "@/i18n";
import { extractErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";
import {
	useClearCronHistoryMutation,
	useGetCronStatusQuery,
	useRunCronJobMutation,
	useRunDueJobsMutation,
} from "@/state/slices/api";

import {
	JobHistoryDrawer,
	JobsTable,
	ManageSchedulesDrawer,
	RunDueJobsModal,
} from "./components";
import {
	aggregateRunDueJobsResult,
	calculateCronStatusSummary,
} from "./summary";

const MIN_REFRESH_DURATION_MS = 700;

export function ScheduledJobsPage() {
	const { canManageUpdates, isLoading: isAccessLoading } = useAdminAccess();
	const notification = useNotification();

	const {
		data,
		isLoading: isCronLoading,
		isError,
		refetch,
	} = useGetCronStatusQuery();

	const [runDueJobs, { isLoading: isRunningDue }] = useRunDueJobsMutation();
	const [runCronJob] = useRunCronJobMutation();
	const [clearCronHistory, { isLoading: isClearingHistory }] =
		useClearCronHistoryMutation();

	const [isRefreshing, setIsRefreshing] = useState(false);
	const refreshTimeoutRef = useRef<number | null>(null);

	useEffect(() => {
		return () => {
			if (refreshTimeoutRef.current !== null) {
				window.clearTimeout(refreshTimeoutRef.current);
			}
		};
	}, []);

	const [runningJobId, setRunningJobId] = useState<string | null>(null);
	const [selectedJobForHistory, setSelectedJobForHistory] =
		useState<CronJob | null>(null);
	const [isHistoryDrawerOpen, setIsHistoryDrawerOpen] = useState(false);
	const [isRunDueModalOpen, setIsRunDueModalOpen] = useState(false);
	const [isClearAllModalOpen, setIsClearAllModalOpen] = useState(false);
	const [isManageSchedulesOpen, setIsManageSchedulesOpen] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");

	const jobs = useMemo(() => data?.jobs || [], [data?.jobs]);
	const summary = useMemo(() => calculateCronStatusSummary(jobs), [jobs]);

	const filteredJobs = useMemo(() => {
		if (!searchQuery.trim()) {
			return jobs;
		}
		const query = searchQuery.toLowerCase().trim();
		return jobs.filter(
			(job) =>
				job.id.toLowerCase().includes(query) ||
				job.title.toLowerCase().includes(query)
		);
	}, [jobs, searchQuery]);

	// Keep selectedJobForHistory synced with fresh data after refetch
	const activeHistoryJob = useMemo(() => {
		if (!selectedJobForHistory) return null;
		return (
			jobs.find((j) => j.id === selectedJobForHistory.id) ||
			selectedJobForHistory
		);
	}, [jobs, selectedJobForHistory]);

	const handleRunSingleJob = async (job: CronJob) => {
		if (runningJobId || isRunningDue) {
			return;
		}

		setRunningJobId(job.id);
		try {
			const result = await runCronJob(job.id).unwrap();
			if (result.status === "skipped") {
				notification.info(
					result.summary ||
						sprintf(
							/* translators: %s is the job title */
							__("Job [%s] was skipped."),
							job.title
						)
				);
			} else if (result.success) {
				notification.success(
					result.summary ||
						sprintf(
							/* translators: %s is the job title */
							__("Job [%s] executed successfully."),
							job.title
						)
				);
			} else {
				notification.error(
					result.error ||
						sprintf(
							/* translators: %s is the job title */
							__("Job [%s] execution failed."),
							job.title
						)
				);
			}
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) ||
					sprintf(
						/* translators: %s is the job title */
						__("Failed to execute [%s]."),
						job.title
					)
			);
		} finally {
			setRunningJobId(null);
			void refetch();
		}
	};

	const handleRunDueJobs = async () => {
		try {
			const result = await runDueJobs().unwrap();
			const outcome = aggregateRunDueJobsResult(result);

			notification[outcome.type](outcome.message);
			setIsRunDueModalOpen(false);
			void refetch();
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) ||
					__("Failed to execute due background jobs.")
			);
		}
	};

	const handleClearAllHistory = async () => {
		try {
			const result = await clearCronHistory().unwrap();
			notification.success(
				sprintf(
					/* translators: %s is the number of cleared history records */
					_n(
						"Cleared %s execution history record.",
						"Cleared %s execution history records.",
						result.deletedCount
					),
					String(result.deletedCount)
				)
			);
			setIsClearAllModalOpen(false);
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) ||
					__("Failed to clear execution history.")
			);
		}
	};

	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		if (refreshTimeoutRef.current !== null) {
			window.clearTimeout(refreshTimeoutRef.current);
			refreshTimeoutRef.current = null;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await refetch();
		} finally {
			const remaining =
				MIN_REFRESH_DURATION_MS - (Date.now() - startedAt);

			if (remaining > 0) {
				refreshTimeoutRef.current = window.setTimeout(() => {
					setIsRefreshing(false);
					refreshTimeoutRef.current = null;
				}, remaining);
			} else {
				setIsRefreshing(false);
			}
		}
	};

	/* ─── Non-admin gate ─── */
	if (!isAccessLoading && !canManageUpdates) {
		return (
			<div className="scheduled-jobs-page-gate">
				<div className="scheduled-jobs-page-gate-icon">
					<ShieldCheck size={28} />
				</div>
				<h2 className="scheduled-jobs-page-gate-title">
					{__("Admin access required")}
				</h2>
				<p className="scheduled-jobs-page-gate-summary">
					{__(
						"Only administrator accounts with update management capabilities can view and manage scheduled background jobs."
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="scheduled-jobs-page">
			{/* ════════════════════════════════════
			    PAGE HERO (Matching Activity Page)
			   ════════════════════════════════════ */}
			<div className="scheduled-jobs-page-hero">
				<div className="scheduled-jobs-page-hero-copy">
					<p className="scheduled-jobs-page-hero-badge">
						<CalendarClock size={14} />
						<span>{__("System Automation")}</span>
					</p>
					<h1
						className="scheduled-jobs-page-title"
						aria-label={__("Scheduled Jobs")}
					>
						{__("Scheduled Jobs")}
					</h1>
					<p className="scheduled-jobs-page-summary">
						{__(
							"Inspect recurring background tasks, monitor execution health, review recent run logs, and manually trigger jobs where authorized."
						)}
					</p>
				</div>

				<div className="scheduled-jobs-page-hero-actions">
					<button
						type="button"
						onClick={handleRefresh}
						disabled={isRefreshing}
						className="dashboard-page-refresh"
						aria-label={__("Refresh")}
						title={__("Refresh scheduled jobs status")}
					>
						<RefreshCw
							className={cn(
								"dashboard-page-refresh-icon",
								isRefreshing && "animate-spin"
							)}
						/>
					</button>
					{canManageUpdates ? (
						<>
							<Button
								variant="outline"
								size="sm"
								onClick={() => setIsManageSchedulesOpen(true)}
								disabled={
									isRunningDue ||
									null !== runningJobId ||
									isClearingHistory
								}
								className="text-heading hover:bg-surface-alt"
								title={__(
									"Configure job recurrence schedules, preferred times, and history retention"
								)}
							>
								<SlidersHorizontal size={13} />
								<span>{__("Manage Schedules")}</span>
							</Button>
							<Button
								variant="outline"
								size="sm"
								onClick={() => setIsClearAllModalOpen(true)}
								disabled={
									isRunningDue ||
									null !== runningJobId ||
									isClearingHistory
								}
								className="text-rose-600 hover:text-rose-700 hover:bg-rose-50 border-rose-200 dark:border-rose-900/40 dark:hover:bg-rose-950/20"
								title={__(
									"Clear execution run history for all jobs"
								)}
							>
								<Trash2 size={13} />
								<span>{__("Clear All History")}</span>
							</Button>
							<Button
								variant="primary"
								size="sm"
								onClick={() => setIsRunDueModalOpen(true)}
								disabled={
									isRunningDue ||
									null !== runningJobId ||
									isClearingHistory
								}
								title={__(
									"Execute all jobs that are currently due"
								)}
							>
								<Play size={13} />
								<span>{__("Run Due Jobs")}</span>
							</Button>
						</>
					) : null}
				</div>
			</div>

			{/* ════════════════════════════════════
			    OVERVIEW METRIC CARDS (Matching Activity Page 4-column Grid)
			   ════════════════════════════════════ */}
			<div className="scheduled-jobs-overview">
				<div className="scheduled-jobs-overview-grid">
					{/* Total Registered Jobs */}
					<div className="scheduled-jobs-overview-item">
						<div className="scheduled-jobs-overview-header">
							<div className="scheduled-jobs-overview-copy">
								<p className="scheduled-jobs-overview-title">
									{__("Total Jobs")}
								</p>
								<p className="scheduled-jobs-overview-value">
									{isCronLoading
										? "—"
										: (summary?.totalJobs ?? 0)}
								</p>
							</div>
							<div className="scheduled-jobs-overview-icon scheduled-jobs-overview-icon-total">
								<CalendarClock className="scheduled-jobs-overview-icon-glyph" />
							</div>
						</div>
						<p className="scheduled-jobs-overview-note">
							{__("Registered in system")}
						</p>
					</div>

					{/* Active & Scheduled */}
					<div className="scheduled-jobs-overview-item">
						<div className="scheduled-jobs-overview-header">
							<div className="scheduled-jobs-overview-copy">
								<p className="scheduled-jobs-overview-title">
									{__("Scheduled")}
								</p>
								<p className="scheduled-jobs-overview-value">
									{isCronLoading
										? "—"
										: (summary?.scheduledJobs ?? 0)}
								</p>
							</div>
							<div className="scheduled-jobs-overview-icon scheduled-jobs-overview-icon-scheduled">
								<Clock className="scheduled-jobs-overview-icon-glyph" />
							</div>
						</div>
						<p className="scheduled-jobs-overview-note">
							{__("Active recurring tasks")}
						</p>
					</div>

					{/* Currently Running */}
					<div className="scheduled-jobs-overview-item">
						<div className="scheduled-jobs-overview-header">
							<div className="scheduled-jobs-overview-copy">
								<p className="scheduled-jobs-overview-title">
									{__("Running")}
								</p>
								<p
									className={cn(
										"scheduled-jobs-overview-value",
										(summary?.runningJobs ?? 0) > 0 &&
											"text-emerald-600 dark:text-emerald-400"
									)}
								>
									{isCronLoading
										? "—"
										: (summary?.runningJobs ?? 0)}
								</p>
							</div>
							<div className="scheduled-jobs-overview-icon scheduled-jobs-overview-icon-running">
								<RefreshCw
									className={cn(
										"scheduled-jobs-overview-icon-glyph",
										(summary?.runningJobs ?? 0) > 0 &&
											"animate-spin"
									)}
								/>
							</div>
						</div>
						<p className="scheduled-jobs-overview-note">
							{__("Currently in progress")}
						</p>
					</div>

					{/* Issues / Failing */}
					<div className="scheduled-jobs-overview-item">
						<div className="scheduled-jobs-overview-header">
							<div className="scheduled-jobs-overview-copy">
								<p className="scheduled-jobs-overview-title">
									{__("Failed / Retrying")}
								</p>
								<p
									className={cn(
										"scheduled-jobs-overview-value",
										(summary?.failedJobs ?? 0) > 0 &&
											"text-rose-600 dark:text-rose-400"
									)}
								>
									{isCronLoading
										? "—"
										: (summary?.failedJobs ?? 0)}
								</p>
							</div>
							<div
								className={cn(
									"scheduled-jobs-overview-icon",
									(summary?.failedJobs ?? 0) > 0
										? "scheduled-jobs-overview-icon-failed"
										: "scheduled-jobs-overview-icon-neutral"
								)}
							>
								<AlertTriangle className="scheduled-jobs-overview-icon-glyph" />
							</div>
						</div>
						<p className="scheduled-jobs-overview-note">
							{__("Requiring attention")}
						</p>
					</div>
				</div>
			</div>

			{/* ════════════════════════════════════
			    MAIN PANEL & JOBS TABLE (Matching Activity Page Panel)
			   ════════════════════════════════════ */}
			<div className="scheduled-jobs-panel">
				<div className="scheduled-jobs-panel-header">
					<div className="flex items-center gap-2">
						<h2 className="scheduled-jobs-panel-title">
							{__("Registered Background Tasks")}
						</h2>
						<span className="scheduled-jobs-panel-badge">
							{filteredJobs.length}
						</span>
					</div>

					<div className="w-full sm:w-64">
						<div className="relative">
							<Search
								size={14}
								className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-text-muted"
							/>
							<input
								type="text"
								value={searchQuery}
								onChange={(e) => setSearchQuery(e.target.value)}
								placeholder={__("Search jobs...")}
								className="w-full rounded-lg border border-stroke bg-surface ps-9 pe-3 py-1.5 text-xs text-heading placeholder:text-text-muted/60 transition-colors focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
							/>
						</div>
					</div>
				</div>

				{isError ? (
					<div className="p-8 text-center">
						<AlertCircle
							size={32}
							className="mx-auto text-rose-600 dark:text-rose-400"
						/>
						<h3 className="mt-2 text-sm font-semibold text-rose-800 dark:text-rose-300">
							{__("Failed to load scheduled jobs")}
						</h3>
						<p className="mt-1 text-xs text-rose-700 dark:text-rose-400">
							{__(
								"Could not retrieve registered jobs from the scheduler API."
							)}
						</p>
						<div className="mt-4">
							<Button
								variant="outline"
								size="sm"
								onClick={() => refetch()}
							>
								{__("Retry")}
							</Button>
						</div>
					</div>
				) : isCronLoading ? (
					<div className="p-12 text-center">
						<div className="mx-auto h-8 w-8 animate-spin rounded-full border-2 border-stroke border-t-accent" />
						<p className="mt-3 text-xs text-text-muted">
							{__("Loading scheduled jobs...")}
						</p>
					</div>
				) : (
					<JobsTable
						jobs={filteredJobs}
						runningJobId={runningJobId}
						onViewHistory={(job) => {
							setSelectedJobForHistory(job);
							setIsHistoryDrawerOpen(true);
						}}
						onRunJob={handleRunSingleJob}
						canManage={canManageUpdates}
					/>
				)}
			</div>

			{/* ════════════════════════════════════
			    SLIDE-OVER HISTORY DRAWER & CONFIRM MODAL
			   ════════════════════════════════════ */}
			<JobHistoryDrawer
				job={activeHistoryJob}
				isOpen={isHistoryDrawerOpen}
				onClose={() => setIsHistoryDrawerOpen(false)}
				onRefresh={() => void refetch()}
				onRunJob={handleRunSingleJob}
				isJobRunning={
					null !== runningJobId &&
					activeHistoryJob?.id === runningJobId
				}
				canManage={canManageUpdates}
			/>

			<RunDueJobsModal
				isOpen={isRunDueModalOpen}
				onClose={() => setIsRunDueModalOpen(false)}
				onConfirm={handleRunDueJobs}
				isExecuting={isRunningDue}
			/>

			<ManageSchedulesDrawer
				isOpen={isManageSchedulesOpen}
				onClose={() => setIsManageSchedulesOpen(false)}
				jobs={jobs}
				timezone={data?.timezone}
				retentionDays={data?.retentionDays}
				onRefresh={() => void refetch()}
			/>

			<ConfirmDialog
				open={isClearAllModalOpen}
				onClose={() => setIsClearAllModalOpen(false)}
				title={__("Clear All Execution History")}
				description={__(
					"Are you sure you want to clear execution history across all background jobs? Finished run records and output logs will be permanently deleted. Active and retrying runs will remain protected."
				)}
				confirmText={__("Clear All History")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				loading={isClearingHistory}
				onConfirm={handleClearAllHistory}
			/>
		</div>
	);
}

export default ScheduledJobsPage;
