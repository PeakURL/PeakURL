import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { format, formatDistanceToNow, isValid, parseISO } from "date-fns";
import { AlertTriangle, Clock, History, Play, X } from "lucide-react";

import { Button } from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/shared/formatting";

import { formatInterval, formatNextRun } from "../formatters";
import type { JobHistoryDrawerProps } from "../types";
import { JobStatusBadge } from "./JobStatusBadge";

function formatRunTimestamp(dateString: string | null | undefined) {
	if (!dateString) {
		return { relative: __("Unknown"), full: "" };
	}

	try {
		const parsed = parseISO(dateString);
		if (!isValid(parsed)) {
			return { relative: String(dateString), full: "" };
		}
		return {
			relative: formatDistanceToNow(parsed, { addSuffix: true }),
			full: format(parsed, "MMM d, yyyy 'at' HH:mm:ss"),
		};
	} catch {
		return { relative: String(dateString), full: "" };
	}
}

function formatDuration(durationMs: number | null | undefined) {
	if (null === durationMs || undefined === durationMs) {
		return "—";
	}
	if (durationMs < 1000) {
		return `${durationMs}ms`;
	}
	return `${(durationMs / 1000).toFixed(2)}s`;
}

export function JobHistoryDrawer({
	job,
	isOpen,
	onClose,
	onRunJob,
	isJobRunning = false,
	canManage = false,
}: JobHistoryDrawerProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";

	if (!job) {
		return null;
	}

	const runs = job.recentRuns || [];
	const nextRun = formatNextRun(job.nextRunAt);

	return (
		<Dialog open={isOpen} onClose={onClose} className="relative z-50">
			{/* Backdrop with smooth fade transition */}
			<div
				className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity duration-500 ease-in-out data-closed:opacity-0"
				aria-hidden="true"
			/>

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`scheduled-jobs-drawer-layout ${
							isRtl
								? "scheduled-jobs-drawer-layout-rtl"
								: "scheduled-jobs-drawer-layout-ltr"
						}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`scheduled-jobs-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							{/* ─── Drawer Header ─── */}
							<div className="scheduled-jobs-drawer-header">
								<div className="flex items-start gap-3 min-w-0 flex-1">
									<div className="scheduled-jobs-drawer-title-icon">
										<History className="w-5 h-5 text-accent" />
									</div>
									<div className="min-w-0 flex-1">
										<DialogTitle
											as="h2"
											className="scheduled-jobs-drawer-title"
										>
											{sprintf(
												/* translators: %s is the background job title */
												__("Execution History — %s"),
												job.title
											)}
										</DialogTitle>
										<div className="flex flex-wrap items-center gap-2 mt-1">
											<code className="font-mono text-xs text-text-muted">
												{job.id}
											</code>
										</div>
									</div>
								</div>

								<button
									type="button"
									onClick={onClose}
									className="scheduled-jobs-drawer-close"
									aria-label={__("Close history drawer")}
									title={__("Close history drawer")}
								>
									<X size={18} />
								</button>
							</div>

							{/* ─── Drawer Body ─── */}
							<div className="scheduled-jobs-drawer-content">
								{/* Job Metadata Cards */}
								<div className="scheduled-jobs-drawer-meta-grid">
									<div className="scheduled-jobs-drawer-meta-card">
										<span className="scheduled-jobs-drawer-meta-label">
											{__("Job ID")}
										</span>
										<code className="scheduled-jobs-drawer-meta-value font-mono">
											{job.id}
										</code>
									</div>
									<div className="scheduled-jobs-drawer-meta-card">
										<span className="scheduled-jobs-drawer-meta-label">
											{__("Status")}
										</span>
										<div className="mt-1">
											<JobStatusBadge
												status={job.status}
												attempts={job.attempts}
												maxAttempts={job.maxAttempts}
											/>
										</div>
									</div>
									<div className="scheduled-jobs-drawer-meta-card">
										<span className="scheduled-jobs-drawer-meta-label">
											{__("Recurrence")}
										</span>
										<span className="scheduled-jobs-drawer-meta-value">
											{formatInterval(
												job.intervalSeconds
											)}
										</span>
									</div>
									<div className="scheduled-jobs-drawer-meta-card">
										<span className="scheduled-jobs-drawer-meta-label">
											{__("Next Due")}
										</span>
										<span
											className={cn(
												"scheduled-jobs-drawer-meta-value",
												nextRun.isDue &&
													"text-amber-600 dark:text-amber-400 font-semibold"
											)}
										>
											{nextRun.text}
										</span>
									</div>
								</div>

								{/* Error Banner */}
								{job.lastError ? (
									<div className="scheduled-jobs-drawer-error-banner">
										<AlertTriangle
											size={16}
											className="shrink-0 text-rose-500 mt-0.5"
										/>
										<div className="min-w-0 flex-1">
											<p className="text-xs font-semibold text-rose-800 dark:text-rose-300">
												{__("Most Recent Failure")}
											</p>
											<p className="mt-1 text-xs text-rose-700 dark:text-rose-400 break-words font-mono bg-rose-500/5 p-2 rounded border border-rose-500/10">
												{job.lastError}
											</p>
										</div>
									</div>
								) : null}

								{/* Recent Runs Section */}
								<div className="space-y-3">
									<div className="flex items-center justify-between">
										<h3 className="scheduled-jobs-drawer-section-title">
											<History
												size={14}
												className="text-text-muted"
											/>
											<span>
												{__("Recent Execution Logs")}
											</span>
										</h3>
										<span className="inline-flex items-center rounded-full bg-surface-alt px-2 py-0.5 text-xs font-medium text-text-muted border border-stroke/50">
											{sprintf(
												/* translators: %d is run count */
												__("%d recorded"),
												runs.length
											)}
										</span>
									</div>

									{0 === runs.length ? (
										<div className="scheduled-jobs-drawer-empty">
											<Clock
												size={32}
												className="text-text-muted"
											/>
											<p className="mt-2 text-xs font-medium text-heading">
												{__(
													"No recorded execution history"
												)}
											</p>
											<p className="mt-1 text-xs text-text-muted">
												{__(
													"This job has not been executed yet or past execution logs have expired."
												)}
											</p>
										</div>
									) : (
										<div className="scheduled-jobs-drawer-table-wrap">
											<table className="scheduled-jobs-drawer-table">
												<thead>
													<tr>
														<th>{__("Run")}</th>
														<th>{__("Status")}</th>
														<th>{__("Attempt")}</th>
														<th>{__("Started")}</th>
														<th>
															{__("Duration")}
														</th>
														<th>
															{__(
																"Output / Details"
															)}
														</th>
													</tr>
												</thead>
												<tbody>
													{runs.map((run) => {
														const started =
															formatRunTimestamp(
																run.startedAt
															);
														return (
															<tr key={run.id}>
																<td>
																	<code className="font-mono text-xs text-text-muted">
																		{run.id.substring(
																			0,
																			8
																		)}
																	</code>
																</td>
																<td>
																	<JobStatusBadge
																		status={
																			run.status
																		}
																	/>
																</td>
																<td>
																	<span className="inline-flex items-center rounded bg-surface-alt px-1.5 py-0.5 text-[10px] font-mono text-text-muted border border-stroke/50">
																		{sprintf(
																			/* translators: %d is attempt number */
																			__(
																				"#%d"
																			),
																			run.attempt ||
																				1
																		)}
																	</span>
																</td>
																<td>
																	<div
																		className="text-xs text-heading"
																		title={
																			started.full
																		}
																	>
																		{
																			started.relative
																		}
																	</div>
																	{started.full ? (
																		<div className="text-[10px] text-text-muted">
																			{
																				started.full
																			}
																		</div>
																	) : null}
																</td>
																<td>
																	<span className="font-mono text-xs text-heading">
																		{formatDuration(
																			run.durationMs
																		)}
																	</span>
																</td>
																<td>
																	{run.errorMessage ? (
																		<div className="text-xs text-rose-600 dark:text-rose-400 font-mono break-words max-w-xs">
																			{
																				run.errorMessage
																			}
																		</div>
																	) : run.outputSummary ? (
																		<div className="text-xs text-text-muted font-mono truncate max-w-xs">
																			{
																				run.outputSummary
																			}
																		</div>
																	) : (
																		<span className="text-xs text-text-muted">
																			—
																		</span>
																	)}
																</td>
															</tr>
														);
													})}
												</tbody>
											</table>
										</div>
									)}
								</div>
							</div>

							{/* ─── Drawer Footer ─── */}
							<div className="scheduled-jobs-drawer-footer">
								<div className="text-xs text-text-muted">
									{sprintf(
										/* translators: %s is the job title */
										__("Registry: %s"),
										job.title
									)}
								</div>

								<div className="flex items-center gap-2">
									<Button
										variant="outline"
										size="sm"
										onClick={onClose}
									>
										{__("Close")}
									</Button>
									{canManage && onRunJob ? (
										<Button
											variant="primary"
											size="sm"
											onClick={() => onRunJob(job)}
											loading={isJobRunning}
											disabled={
												isJobRunning ||
												"running" === job.status
											}
										>
											<Play size={12} />
											<span>{__("Run Now")}</span>
										</Button>
									) : null}
								</div>
							</div>
						</DialogPanel>
					</div>
				</div>
			</div>
		</Dialog>
	);
}

export default JobHistoryDrawer;
