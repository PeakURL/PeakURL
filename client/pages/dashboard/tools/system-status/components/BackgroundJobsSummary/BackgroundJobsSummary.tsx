import { useMemo } from "react";
import { formatDistanceToNow, isValid, parseISO } from "date-fns";
import { ArrowRight, CalendarClock } from "lucide-react";
import { Link } from "react-router";

import { __, sprintf } from "@/i18n";
import { calculateCronStatusSummary } from "@/pages/dashboard/tools/scheduled-jobs";
import { useGetCronStatusQuery } from "@/state/slices/api";
import { cn } from "@/shared/formatting";

function formatRelativeTimestamp(isoString: string | null | undefined): string {
	if (!isoString) {
		return __("Never");
	}

	try {
		const parsed = parseISO(isoString);
		if (!isValid(parsed)) {
			return __("Never");
		}
		return formatDistanceToNow(parsed, { addSuffix: true });
	} catch {
		return __("Never");
	}
}

export function BackgroundJobsSummary() {
	const { data, isLoading, isError } = useGetCronStatusQuery();

	const jobs = useMemo(() => data?.jobs || [], [data?.jobs]);
	const summary = useMemo(() => calculateCronStatusSummary(jobs), [jobs]);

	if (isLoading) {
		return (
			<div className="background-jobs-summary-card animate-pulse">
				<div className="background-jobs-summary-header">
					<div className="background-jobs-summary-header-left">
						<div className="h-9 w-9 rounded-lg bg-surface-alt" />
						<div className="space-y-1.5">
							<div className="h-4 w-32 rounded bg-surface-alt" />
							<div className="h-3 w-48 rounded bg-surface-alt" />
						</div>
					</div>
					<div className="h-6 w-24 rounded bg-surface-alt" />
				</div>
				<div className="background-jobs-summary-grid">
					{Array.from({ length: 6 }).map((_, idx) => (
						<div
							key={idx}
							className="h-16 rounded-lg bg-surface-alt/60"
						/>
					))}
				</div>
			</div>
		);
	}

	if (isError || !data) {
		return (
			<div className="background-jobs-summary-card">
				<div className="background-jobs-summary-header">
					<div className="background-jobs-summary-header-left">
						<div className="background-jobs-summary-icon">
							<CalendarClock size={16} />
						</div>
						<div className="background-jobs-summary-title-wrap">
							<h3 className="background-jobs-summary-title">
								{__("Background Jobs")}
							</h3>
							<p className="background-jobs-summary-subtitle">
								{__(
									"Scheduler telemetry currently unavailable"
								)}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	const isHealthy = "healthy" === summary.status;
	const isDegraded = "degraded" === summary.status;

	const statusLabel = isHealthy
		? __("Healthy")
		: isDegraded
			? __("Degraded")
			: __("Issues Detected");

	const nextDueText = summary.nextDueJob
		? sprintf(
				/* translators: 1: job title, 2: relative time */
				__("%1$s (%2$s)"),
				summary.nextDueJob.title,
				formatRelativeTimestamp(summary.nextDueJob.nextRunAt)
			)
		: __("None pending");

	return (
		<div className="background-jobs-summary-card">
			<div className="background-jobs-summary-header">
				<div className="background-jobs-summary-header-left">
					<div className="background-jobs-summary-icon">
						<CalendarClock size={16} />
					</div>
					<div className="background-jobs-summary-title-wrap">
						<h3 className="background-jobs-summary-title">
							{__("Background Jobs")}
						</h3>
						<p className="background-jobs-summary-subtitle">
							{__("Automated scheduler & recurring system tasks")}
						</p>
					</div>
				</div>

				<div className="background-jobs-summary-header-right">
					<span
						className={cn(
							"background-jobs-summary-badge",
							isHealthy &&
								"background-jobs-summary-badge-healthy",
							isDegraded &&
								"background-jobs-summary-badge-degraded",
							!isHealthy &&
								!isDegraded &&
								"background-jobs-summary-badge-issues"
						)}
					>
						<span
							className={cn(
								"background-jobs-summary-dot",
								isHealthy &&
									"background-jobs-summary-dot-healthy",
								isDegraded &&
									"background-jobs-summary-dot-degraded",
								!isHealthy &&
									!isDegraded &&
									"background-jobs-summary-dot-issues"
							)}
						/>
						<span>{statusLabel}</span>
					</span>

					<Link
						to="/dashboard/tools/scheduled-jobs"
						className="background-jobs-summary-link"
					>
						<span>{__("Manage Scheduled Jobs")}</span>
						<ArrowRight size={13} />
					</Link>
				</div>
			</div>

			<div className="background-jobs-summary-grid">
				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Scheduler")}
					</p>
					<p className="background-jobs-summary-value">
						{statusLabel}
					</p>
				</div>

				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Scheduled")}
					</p>
					<p className="background-jobs-summary-value">
						{summary.totalJobs}
					</p>
				</div>

				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Running")}
					</p>
					<p
						className={cn(
							"background-jobs-summary-value",
							summary.runningJobs > 0 &&
								"text-amber-600 dark:text-amber-400"
						)}
					>
						{summary.runningJobs}
					</p>
				</div>

				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Failed")}
					</p>
					<p
						className={cn(
							"background-jobs-summary-value",
							summary.failedJobs > 0
								? "text-rose-600 dark:text-rose-400"
								: "text-heading"
						)}
					>
						{summary.failedJobs}
					</p>
				</div>

				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Last Run")}
					</p>
					<p
						className="background-jobs-summary-value"
						title={summary.lastRunAt || ""}
					>
						{formatRelativeTimestamp(summary.lastRunAt)}
					</p>
				</div>

				<div className="background-jobs-summary-item">
					<p className="background-jobs-summary-label">
						{__("Next Due")}
					</p>
					<p
						className="background-jobs-summary-value text-xs"
						title={summary.nextDueJob?.nextRunAt || ""}
					>
						{nextDueText}
					</p>
				</div>
			</div>
		</div>
	);
}

export default BackgroundJobsSummary;
