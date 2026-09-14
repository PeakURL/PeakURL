import { useId, useState } from "react";
import {
	Dialog,
	DialogBackdrop,
	DialogPanel,
	DialogTitle,
} from "@headlessui/react";
import {
	Clock,
	RotateCcw,
	Save,
	SlidersHorizontal,
	Calendar,
	CheckCircle2,
	ChevronDown,
	ChevronUp,
	X,
} from "lucide-react";

import type { CronJob } from "@/api";
import {
	Button,
	ConfirmDialog,
	Input,
	Select,
	type SelectOption,
	useNotification,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { extractErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";
import {
	useResetCronJobScheduleMutation,
	useUpdateCronJobScheduleMutation,
	useUpdateCronSettingsMutation,
} from "@/state/slices/api";

import { formatInterval, formatNextRun, formatSchedule } from "../formatters";
import type { ManageSchedulesModalProps } from "../types";
import { JobStatusBadge } from "./JobStatusBadge";

const RETENTION_OPTIONS: SelectOption<number>[] = [
	{ value: 7, label: __("7 Days") },
	{ value: 14, label: __("14 Days") },
	{ value: 30, label: __("30 Days (Recommended)") },
	{ value: 60, label: __("60 Days") },
	{ value: 90, label: __("90 Days") },
	{ value: 0, label: __("Keep Forever") },
];

const STANDARD_INTERVAL_OPTIONS: SelectOption<number>[] = [
	{ value: 300, label: __("Every 5 minutes") },
	{ value: 900, label: __("Every 15 minutes") },
	{ value: 1800, label: __("Every 30 minutes") },
	{ value: 3600, label: __("Hourly") },
	{ value: 7200, label: __("Every 2 hours") },
	{ value: 21600, label: __("Every 6 hours") },
	{ value: 43200, label: __("Every 12 hours") },
	{ value: 86400, label: __("Daily") },
	{ value: 604800, label: __("Weekly") },
];

interface JobEditState {
	intervalSeconds: number;
	preferredTime: string;
	isEnabled: boolean;
}

export function ManageSchedulesDrawer({
	isOpen,
	onClose,
	jobs,
	timezone = "UTC",
	retentionDays = 30,
	onRefresh,
}: ManageSchedulesModalProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const notification = useNotification();
	const retentionSelectId = useId();

	// Mutations
	const [updateCronSettings, { isLoading: isSavingRetention }] =
		useUpdateCronSettingsMutation();
	const [updateCronJobSchedule] = useUpdateCronJobScheduleMutation();
	const [resetCronJobSchedule] = useResetCronJobScheduleMutation();

	// Local retention state
	const [selectedRetention, setSelectedRetention] =
		useState<number>(retentionDays);

	// Expanded editing state per job ID
	const [editingJobId, setEditingJobId] = useState<string | null>(null);
	const [editForm, setEditForm] = useState<Record<string, JobEditState>>({});
	const [savingJobId, setSavingJobId] = useState<string | null>(null);
	const [jobToReset, setJobToReset] = useState<CronJob | null>(null);
	const [isResetting, setIsResetting] = useState(false);

	const handleOpenEdit = (job: CronJob) => {
		setEditingJobId(job.id);
		setEditForm((prev) => ({
			...prev,
			[job.id]: {
				intervalSeconds: job.intervalSeconds,
				preferredTime: job.preferredTime || "02:00",
				isEnabled: job.isEnabled,
			},
		}));
	};

	const handleCancelEdit = () => {
		setEditingJobId(null);
	};

	const handleSaveRetention = async () => {
		try {
			await updateCronSettings({
				retentionDays: selectedRetention,
			}).unwrap();
			notification.success(
				__("Execution history retention setting saved successfully.")
			);
			onRefresh?.();
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) ||
					__("Failed to update history retention.")
			);
		}
	};

	const handleSaveJob = async (job: CronJob) => {
		const form = editForm[job.id];
		if (!form) return;

		setSavingJobId(job.id);
		try {
			const preferredTimePayload =
				form.intervalSeconds >= 86400
					? form.preferredTime || null
					: null;

			await updateCronJobSchedule({
				id: job.id,
				intervalSeconds: form.intervalSeconds,
				preferredTime: preferredTimePayload,
				isEnabled: form.isEnabled,
			}).unwrap();

			notification.success(
				sprintf(
					/* translators: %s is the background job title */
					__("Schedule for [%s] updated successfully."),
					job.title
				)
			);
			setEditingJobId(null);
			onRefresh?.();
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) ||
					sprintf(
						/* translators: %s is the background job title */
						__("Failed to update schedule for [%s]."),
						job.title
					)
			);
		} finally {
			setSavingJobId(null);
		}
	};

	const handleConfirmReset = async () => {
		if (!jobToReset) return;

		setIsResetting(true);
		try {
			await resetCronJobSchedule(jobToReset.id).unwrap();
			notification.success(
				sprintf(
					/* translators: %s is the background job title */
					__("Reset [%s] to recommended default schedule."),
					jobToReset.title
				)
			);
			if (editingJobId === jobToReset.id) {
				setEditingJobId(null);
			}
			setJobToReset(null);
			onRefresh?.();
		} catch (err: unknown) {
			notification.error(
				extractErrorMessage(err) || __("Failed to reset job schedule.")
			);
		} finally {
			setIsResetting(false);
		}
	};

	return (
		<Dialog open={isOpen} onClose={onClose} className="relative z-50">
			{/* Synchronized smooth backdrop fade transition */}
			<DialogBackdrop
				transition
				className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity duration-500 ease-in-out data-closed:opacity-0"
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
										<SlidersHorizontal className="w-5 h-5 text-accent" />
									</div>
									<div className="min-w-0 flex-1">
										<DialogTitle
											as="h2"
											className="scheduled-jobs-drawer-title"
										>
											{__("Manage Schedules & Retention")}
										</DialogTitle>
										<div className="flex flex-wrap items-center gap-2 mt-1">
											<span
												className="inline-flex items-center gap-1.5 rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent border border-accent/20"
												title={sprintf(
													/* translators: %s is the site timezone */
													__("Site Timezone: %s"),
													timezone
												)}
											>
												<Clock size={11} />
												<span>
													{sprintf(
														/* translators: %s is the site timezone */
														__("Timezone: %s"),
														timezone
													)}
												</span>
											</span>
										</div>
									</div>
								</div>

								<button
									type="button"
									onClick={onClose}
									className="scheduled-jobs-drawer-close"
									aria-label={__("Close schedules drawer")}
									title={__("Close schedules drawer")}
								>
									<X size={18} />
								</button>
							</div>

							{/* ─── Drawer Body ─── */}
							<div className="scheduled-jobs-drawer-content space-y-6">
								{/* Execution History Retention Section */}
								<div className="rounded-xl border border-stroke bg-surface-alt/40 p-4 sm:p-5 space-y-3">
									<div className="flex items-center gap-2">
										<Calendar
											size={16}
											className="text-accent"
										/>
										<h3 className="text-sm font-semibold text-heading">
											{__("Execution History Retention")}
										</h3>
									</div>
									<p className="text-xs text-text-muted leading-relaxed">
										{__(
											"Automatically cleans up finished execution logs and output older than the selected retention period. Active or retrying runs are never deleted."
										)}
									</p>

									<div className="flex flex-col sm:flex-row sm:items-center gap-3 pt-1">
										<div className="w-full sm:w-64">
											<Select
												id={retentionSelectId}
												value={selectedRetention}
												options={RETENTION_OPTIONS}
												onChange={(val) =>
													setSelectedRetention(
														val as number
													)
												}
												ariaLabel={__(
													"History Retention Period"
												)}
											/>
										</div>
										<Button
											variant="secondary"
											size="sm"
											icon={Save}
											onClick={handleSaveRetention}
											loading={isSavingRetention}
											disabled={
												isSavingRetention ||
												selectedRetention ===
													retentionDays
											}
											className="min-w-28"
										>
											<span>{__("Save Retention")}</span>
										</Button>
									</div>
								</div>

								{/* Registered Tasks Schedules Section */}
								<div className="space-y-3">
									<div className="flex items-center justify-between">
										<div className="flex items-center gap-2">
											<SlidersHorizontal
												size={15}
												className="text-text-muted"
											/>
											<h3 className="text-sm font-semibold text-heading">
												{__(
													"Registered Background Tasks"
												)}
											</h3>
											<span className="inline-flex items-center rounded-full bg-surface-alt px-2 py-0.5 text-xs font-medium text-text-muted border border-stroke/50">
												{jobs.length}
											</span>
										</div>
									</div>

									<div className="space-y-3">
										{jobs.map((job) => {
											const isEditing =
												editingJobId === job.id;
											const isSavingThisJob =
												savingJobId === job.id;
											const form = editForm[job.id] || {
												intervalSeconds:
													job.intervalSeconds,
												preferredTime:
													job.preferredTime ||
													"02:00",
												isEnabled: job.isEnabled,
											};

											// Build select options ensuring current interval is present
											const jobIntervalOptions: SelectOption<number>[] =
												STANDARD_INTERVAL_OPTIONS.some(
													(opt) =>
														opt.value ===
														form.intervalSeconds
												)
													? STANDARD_INTERVAL_OPTIONS
													: [
															{
																value: form.intervalSeconds,
																label: formatInterval(
																	form.intervalSeconds
																),
															},
															...STANDARD_INTERVAL_OPTIONS,
														].sort(
															(a, b) =>
																a.value -
																b.value
														);

											const nextRun = formatNextRun(
												job.nextRunAt
											);

											return (
												<div
													key={job.id}
													className={cn(
														"rounded-xl border transition-colors p-4",
														isEditing
															? "border-accent/60 bg-accent/5 shadow-xs"
															: "border-stroke bg-surface hover:border-stroke-strong"
													)}
												>
													{/* Top Card Row */}
													<div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5">
														<div className="space-y-1 min-w-0 flex-1">
															<div className="flex flex-wrap items-center gap-2">
																<h4 className="text-xs font-semibold text-heading truncate">
																	{job.title}
																</h4>
																<JobStatusBadge
																	status={
																		job.status
																	}
																	attempts={
																		job.attempts
																	}
																	maxAttempts={
																		job.maxAttempts
																	}
																	isEnabled={
																		job.isEnabled
																	}
																/>
																{job.isCustomized ? (
																	<span className="inline-flex items-center gap-1 rounded bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-400 border border-amber-500/20">
																		{__(
																			"Customized"
																		)}
																	</span>
																) : (
																	<span className="inline-flex items-center gap-1 rounded bg-surface-alt px-2 py-0.5 text-[10px] font-semibold text-text-muted border border-stroke/50">
																		{__(
																			"Recommended Default"
																		)}
																	</span>
																)}
															</div>

															{/* Schedule Summary (when not editing) */}
															{!isEditing ? (
																<div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-muted pt-0.5">
																	<span>
																		<strong className="font-medium text-heading">
																			{__(
																				"Current:"
																			)}
																		</strong>{" "}
																		{formatSchedule(
																			job.intervalSeconds,
																			job.preferredTime
																		)}
																	</span>
																	<span>
																		<strong className="font-medium text-text-muted">
																			{__(
																				"Default:"
																			)}
																		</strong>{" "}
																		{formatInterval(
																			job.recommendedIntervalSeconds
																		)}
																	</span>
																	<span>
																		<strong className="font-medium text-text-muted">
																			{__(
																				"Next run:"
																			)}
																		</strong>{" "}
																		<span
																			className={cn(
																				nextRun.isDue &&
																					"text-amber-600 dark:text-amber-400 font-semibold"
																			)}
																		>
																			{
																				nextRun.text
																			}
																		</span>
																	</span>
																</div>
															) : null}
														</div>

														{/* Action buttons (when not editing) */}
														{!isEditing ? (
															<div className="flex items-center gap-2 shrink-0 self-end sm:self-center pt-1 sm:pt-0">
																{job.isCustomized ? (
																	<Button
																		variant="ghost"
																		size="xs"
																		icon={
																			RotateCcw
																		}
																		onClick={() =>
																			setJobToReset(
																				job
																			)
																		}
																		title={__(
																			"Reset schedule to built-in recommendation"
																		)}
																		className="text-text-muted hover:text-heading"
																	>
																		<span>
																			{__(
																				"Reset"
																			)}
																		</span>
																	</Button>
																) : null}
																<Button
																	variant="outline"
																	size="xs"
																	icon={
																		ChevronDown
																	}
																	onClick={() =>
																		handleOpenEdit(
																			job
																		)
																	}
																>
																	<span>
																		{__(
																			"Configure"
																		)}
																	</span>
																</Button>
															</div>
														) : null}
													</div>

													{/* Expanded Inline Editor Form */}
													{isEditing ? (
														<div className="mt-4 pt-4 border-t border-stroke/70 space-y-4">
															<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
																{/* Recurrence Interval */}
																<div className="space-y-1.5">
																	<label
																		htmlFor={`interval-${job.id}`}
																		className="text-xs font-medium text-heading block"
																	>
																		{__(
																			"Recurrence Schedule"
																		)}
																	</label>
																	<Select
																		id={`interval-${job.id}`}
																		value={
																			form.intervalSeconds
																		}
																		options={
																			jobIntervalOptions
																		}
																		onChange={(
																			val
																		) =>
																			setEditForm(
																				(
																					prev
																				) => ({
																					...prev,
																					[job.id]:
																						{
																							...form,
																							intervalSeconds:
																								val as number,
																						},
																				})
																			)
																		}
																	/>
																</div>

																{/* Preferred Time (only if interval >= 86400) */}
																{form.intervalSeconds >=
																86400 ? (
																	<div className="space-y-1.5">
																		<label
																			htmlFor={`preferred-time-${job.id}`}
																			className="text-xs font-medium text-heading block"
																		>
																			{sprintf(
																				/* translators: %s is the site timezone */
																				__(
																					"Preferred Run Time (%s)"
																				),
																				timezone
																			)}
																		</label>
																		<Input
																			id={`preferred-time-${job.id}`}
																			type="time"
																			value={
																				form.preferredTime
																			}
																			onChange={(
																				e
																			) =>
																				setEditForm(
																					(
																						prev
																					) => ({
																						...prev,
																						[job.id]:
																							{
																								...form,
																								preferredTime:
																									e
																										.target
																										.value,
																							},
																					})
																				)
																			}
																			helperText={sprintf(
																				/* translators: %s is the site timezone */
																				__(
																					"Evaluated daily/weekly in %s."
																				),
																				timezone
																			)}
																		/>
																	</div>
																) : null}
															</div>

															{/* Automatic Execution Enable/Disable Switch */}
															<div className="flex items-center justify-between rounded-lg border border-stroke bg-surface-alt/40 p-3">
																<div className="space-y-0.5">
																	<p className="text-xs font-semibold text-heading">
																		{__(
																			"Automatic Execution"
																		)}
																	</p>
																	<p className="text-[11px] text-text-muted">
																		{form.isEnabled
																			? __(
																					"The scheduler will automatically run this job when due."
																				)
																			: __(
																					"Automatic execution is disabled. You can still trigger this job manually via Run Now."
																				)}
																	</p>
																</div>

																<button
																	type="button"
																	role="switch"
																	aria-checked={
																		form.isEnabled
																	}
																	aria-label={__(
																		"Toggle automatic execution"
																	)}
																	onClick={() =>
																		setEditForm(
																			(
																				prev
																			) => ({
																				...prev,
																				[job.id]:
																					{
																						...form,
																						isEnabled:
																							!form.isEnabled,
																					},
																			})
																		)
																	}
																	className={cn(
																		"scheduled-jobs-switch-track",
																		form.isEnabled
																			? "scheduled-jobs-switch-track-active"
																			: "scheduled-jobs-switch-track-inactive"
																	)}
																>
																	<span
																		className={cn(
																			"scheduled-jobs-switch-thumb",
																			form.isEnabled
																				? "scheduled-jobs-switch-thumb-active"
																				: "scheduled-jobs-switch-thumb-inactive"
																		)}
																	/>
																</button>
															</div>

															{/* Form Actions */}
															<div className="flex flex-wrap items-center justify-between gap-2 pt-2">
																<div>
																	{job.isCustomized ? (
																		<Button
																			variant="ghost"
																			size="xs"
																			icon={
																				RotateCcw
																			}
																			onClick={() =>
																				setJobToReset(
																					job
																				)
																			}
																			disabled={
																				isSavingThisJob
																			}
																			className="text-text-muted hover:text-heading"
																		>
																			<span>
																				{__(
																					"Reset to Default"
																				)}
																			</span>
																		</Button>
																	) : null}
																</div>

																<div className="flex items-center gap-2">
																	<Button
																		variant="outline"
																		size="xs"
																		icon={
																			ChevronUp
																		}
																		onClick={
																			handleCancelEdit
																		}
																		disabled={
																			isSavingThisJob
																		}
																	>
																		<span>
																			{__(
																				"Cancel"
																			)}
																		</span>
																	</Button>
																	<Button
																		variant="primary"
																		size="xs"
																		icon={
																			!isSavingThisJob
																				? CheckCircle2
																				: undefined
																		}
																		onClick={() =>
																			handleSaveJob(
																				job
																			)
																		}
																		loading={
																			isSavingThisJob
																		}
																		disabled={
																			isSavingThisJob
																		}
																		className="w-28 min-w-28"
																	>
																		<span>
																			{isSavingThisJob
																				? __(
																						"Saving..."
																					)
																				: __(
																						"Save Schedule"
																					)}
																		</span>
																	</Button>
																</div>
															</div>
														</div>
													) : null}
												</div>
											);
										})}
									</div>
								</div>
							</div>

							{/* ─── Drawer Footer ─── */}
							<div className="scheduled-jobs-drawer-footer">
								<div className="text-xs text-text-muted">
									{__(
										"Background job schedules and retention rules are stored permanently."
									)}
								</div>

								<Button
									variant="outline"
									size="sm"
									onClick={onClose}
								>
									{__("Close")}
								</Button>
							</div>
						</DialogPanel>
					</div>
				</div>
			</div>

			{/* Reset Confirmation Dialog */}
			<ConfirmDialog
				open={Boolean(jobToReset)}
				onClose={() => setJobToReset(null)}
				title={sprintf(
					/* translators: %s is the background job title */
					__("Reset Schedule — %s"),
					jobToReset?.title || ""
				)}
				description={sprintf(
					/* translators: 1: job title, 2: recommended cadence */
					__(
						"Are you sure you want to reset [%1$s] to its recommended default schedule (%2$s)? Any customized interval, preferred time, and enable state will be restored to the built-in recommendation. Past execution history will remain preserved."
					),
					jobToReset?.title || "",
					jobToReset
						? formatInterval(jobToReset.recommendedIntervalSeconds)
						: ""
				)}
				confirmText={__("Reset to Recommended")}
				cancelText={__("Cancel")}
				confirmVariant="primary"
				loading={isResetting}
				onConfirm={handleConfirmReset}
			/>
		</Dialog>
	);
}

export default ManageSchedulesDrawer;
