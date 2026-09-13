import { History, Play, CheckCircle2, AlertCircle, Clock } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";
import type { JobsTableProps } from "../types";
import { formatInterval, formatLastRun, formatNextRun } from "../formatters";
import { JobStatusBadge } from "./JobStatusBadge";

export function JobsTable({
	jobs,
	runningJobId,
	onViewHistory,
	onRunJob,
	canManage,
}: JobsTableProps) {
	if (0 === jobs.length) {
		return (
			<div className="scheduled-jobs-empty-state">
				<Clock size={36} className="text-text-muted" />
				<h3 className="mt-3 text-sm font-semibold text-heading">
					{__("No scheduled jobs registered")}
				</h3>
				<p className="mt-1 text-xs text-text-muted">
					{__(
						"No recurring background jobs have been discovered or synced in the system registry."
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="scheduled-jobs-table-container">
			<table className="scheduled-jobs-table">
				<thead>
					<tr>
						<th>{__("Job")}</th>
						<th>{__("Status")}</th>
						<th>{__("Schedule")}</th>
						<th>{__("Last Run")}</th>
						<th>{__("Next Run")}</th>
						<th>{__("Last Result / Failure")}</th>
						<th className="text-end">{__("Actions")}</th>
					</tr>
				</thead>
				<tbody>
					{jobs.map((job) => {
						const lastRun = formatLastRun(job);
						const nextRun = formatNextRun(job.nextRunAt);
						const isJobRunning = runningJobId === job.id;
						const isExclusiveRunning = "running" === job.status;

						const latestRun = job.recentRuns[0];
						const hasError = Boolean(job.lastError);

						return (
							<tr
								key={job.id}
								className="scheduled-jobs-table-row"
							>
								{/* Job Title & Identifier */}
								<td className="scheduled-jobs-cell-job">
									<div className="scheduled-jobs-job-title">
										{job.title}
									</div>
									<code className="scheduled-jobs-job-id font-mono">
										{job.id}
									</code>
								</td>

								{/* Status Badge */}
								<td className="scheduled-jobs-cell-status">
									<JobStatusBadge
										status={job.status}
										attempts={job.attempts}
										maxAttempts={job.maxAttempts}
									/>
								</td>

								{/* Schedule Recurrence */}
								<td className="scheduled-jobs-cell-schedule">
									<span className="text-xs font-medium text-heading">
										{formatInterval(job.intervalSeconds)}
									</span>
								</td>

								{/* Last Run */}
								<td className="scheduled-jobs-cell-last-run">
									<div className="text-xs text-heading">
										{lastRun.text}
									</div>
									{lastRun.sub ? (
										<div className="text-[11px] font-mono text-text-muted">
											{lastRun.sub}
										</div>
									) : null}
								</td>

								{/* Next Run */}
								<td className="scheduled-jobs-cell-next-run">
									<span
										className={cn(
											"text-xs",
											nextRun.isDue
												? "font-semibold text-amber-600 dark:text-amber-400"
												: "text-heading"
										)}
									>
										{nextRun.text}
									</span>
								</td>

								{/* Last Result / Failure */}
								<td className="scheduled-jobs-cell-result">
									{hasError ? (
										<div className="flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
											<AlertCircle
												size={13}
												className="shrink-0"
											/>
											<span
												className="text-xs font-medium truncate max-w-xs"
												title={job.lastError as string}
											>
												{job.lastError}
											</span>
										</div>
									) : "running" === latestRun?.status ? (
										<span className="text-xs text-amber-600 dark:text-amber-400">
											{__("Executing now...")}
										</span>
									) : "success" === latestRun?.status ? (
										<div className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
											<CheckCircle2
												size={13}
												className="shrink-0"
											/>
											<span
												className="text-xs font-medium truncate max-w-xs"
												title={
													latestRun.outputSummary ||
													__("Success")
												}
											>
												{latestRun.outputSummary ||
													__("Success")}
											</span>
										</div>
									) : "skipped" === latestRun?.status ? (
										<span className="text-xs text-text-muted">
											{__("Skipped")}
										</span>
									) : (
										<span className="text-xs text-text-muted">
											—
										</span>
									)}
								</td>

								{/* Actions */}
								<td className="scheduled-jobs-cell-actions">
									<div className="flex items-center justify-end gap-2">
										<button
											type="button"
											onClick={() => onViewHistory(job)}
											className="scheduled-jobs-action-history"
											title={__("View Execution History")}
										>
											<History size={13} />
											<span>{__("History")}</span>
										</button>

										{canManage ? (
											<Button
												variant="secondary"
												size="xs"
												onClick={() => onRunJob(job)}
												loading={isJobRunning}
												disabled={
													isJobRunning ||
													isExclusiveRunning ||
													null !== runningJobId
												}
												className="scheduled-jobs-action-run"
												title={__(
													"Execute Job Immediately"
												)}
											>
												<Play size={11} />
												<span>{__("Run Now")}</span>
											</Button>
										) : null}
									</div>
								</td>
							</tr>
						);
					})}
				</tbody>
			</table>
		</div>
	);
}

export default JobsTable;
