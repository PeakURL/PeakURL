import { useEffect, useMemo, useRef, useState } from "react";
import { DialogBackdrop, DialogPanel, DialogTitle } from "@headlessui/react";
import {
	CheckCircle2,
	ChevronDown,
	ChevronUp,
	Clock,
	ExternalLink,
	History,
	RefreshCw,
	RotateCcw,
	Save,
	ShieldAlert,
	ShieldCheck,
	Trash2,
	X,
	XCircle,
} from "lucide-react";

import {
	DrawerDialog,
	Button,
	ConfirmDialog,
	Select,
	type SelectOption,
} from "@/components";
import {
	useClearWebhookDeliveriesMutation,
	useDeleteWebhookDeliveryMutation,
	useGetWebhookDeliveriesQuery,
	useGetWebhookEventsQuery,
	useRetryWebhookDeliveryMutation,
	useUpdateWebhookMutation,
} from "@/state/slices/api";
import { __, _n, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { formatLocalizedDateTime } from "@/shared/dates";
import { getErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";

import type {
	NotificationContextValue,
	WebhookDeliveryItem,
	WebhookDeliveryStatus,
	WebhookSummary,
} from "./types";

interface WebhookDeliveryDrawerProps {
	webhook: WebhookSummary | null;
	isOpen: boolean;
	onClose: () => void;
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
	eventLabelMap?: Map<string, string>;
}

const PAGE_SIZE = 10;

function DeliveryStatusBadge({
	status,
	attempts = 0,
}: {
	status: WebhookDeliveryStatus;
	attempts?: number;
}) {
	switch (status) {
		case "delivered":
			return (
				<span className="integrations-delivery-badge integrations-delivery-badge-success">
					<CheckCircle2 size={12} className="shrink-0" />
					{__("Delivered")}
				</span>
			);
		case "pending":
			return attempts > 0 ? (
				<span className="integrations-delivery-badge integrations-delivery-badge-warning">
					<Clock size={12} className="shrink-0" />
					{__("Retrying")}
				</span>
			) : (
				<span className="integrations-delivery-badge integrations-delivery-badge-neutral">
					<Clock size={12} className="shrink-0" />
					{__("Pending")}
				</span>
			);
		case "processing":
			return (
				<span className="integrations-delivery-badge integrations-delivery-badge-info">
					<RefreshCw size={12} className="shrink-0 animate-spin" />
					{__("Processing")}
				</span>
			);
		case "failed":
			return (
				<span className="integrations-delivery-badge integrations-delivery-badge-danger">
					<XCircle size={12} className="shrink-0" />
					{__("Failed")}
				</span>
			);
		default:
			return (
				<span className="integrations-delivery-badge integrations-delivery-badge-neutral">
					{status}
				</span>
			);
	}
}

function getEventLabel(event: string, labelMap?: Map<string, string>): string {
	if (event === "webhook.test") {
		return __("Webhook Test Ping");
	}

	const label = labelMap?.get(event);
	if (label) {
		return label;
	}

	return __("Event");
}

export function WebhookDeliveryDrawer({
	webhook,
	isOpen,
	onClose,
	notification,
	eventLabelMap,
}: WebhookDeliveryDrawerProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const [page, setPage] = useState(1);
	const [expandedErrorId, setExpandedErrorId] = useState<string | null>(null);
	const [retryingDeliveryId, setRetryingDeliveryId] = useState<string | null>(
		null
	);

	const { data: webhookEventsCatalog } = useGetWebhookEventsQuery(undefined, {
		skip: !isOpen,
	});

	const labelMap = useMemo(() => {
		const map = new Map<string, string>();
		if (eventLabelMap && eventLabelMap.size > 0) {
			eventLabelMap.forEach((label, id) => {
				map.set(id, label);
			});
		} else if (
			webhookEventsCatalog &&
			Array.isArray(webhookEventsCatalog)
		) {
			webhookEventsCatalog.forEach((item) => {
				map.set(item.id, item.label);
			});
		}
		return map;
	}, [eventLabelMap, webhookEventsCatalog]);

	// Cache the active webhook so closing animations stay smooth without jumping even if parent clears the webhook
	const [cachedWebhook, setCachedWebhook] = useState(webhook);
	const activeWebhook = webhook ?? cachedWebhook;
	const webhookId = activeWebhook?.id ?? "";

	const currentWebhookIdRef = useRef<string | null>(webhook?.id ?? null);
	const previousWebhookIdRef = useRef<string | null>(null);
	const wasOpenRef = useRef<boolean>(isOpen);

	// Track the confirmed saved retention baseline separately from the editable user draft
	const [savedRetentionDays, setSavedRetentionDays] = useState<number>(
		activeWebhook?.deliveryRetentionDays ?? 30
	);
	const [draftRetentionDays, setDraftRetentionDays] = useState<number>(
		activeWebhook?.deliveryRetentionDays ?? 30
	);
	const [isDraftEdited, setIsDraftEdited] = useState<boolean>(false);

	const isRetentionDirty =
		isDraftEdited && draftRetentionDays !== savedRetentionDays;

	const [deliveryPendingDelete, setDeliveryPendingDelete] =
		useState<WebhookDeliveryItem | null>(null);
	const [isClearConfirmOpen, setIsClearConfirmOpen] = useState(false);

	const [retryDelivery] = useRetryWebhookDeliveryMutation();
	const [deleteDelivery, { isLoading: isDeletingDelivery }] =
		useDeleteWebhookDeliveryMutation();
	const [clearDeliveries, { isLoading: isClearing }] =
		useClearWebhookDeliveriesMutation();
	const [updateWebhook, { isLoading: isUpdatingRetention }] =
		useUpdateWebhookMutation();

	useEffect(() => {
		currentWebhookIdRef.current = webhook?.id ?? null;
		const isJustOpened = !wasOpenRef.current && isOpen;
		wasOpenRef.current = isOpen;

		if (webhook) {
			// eslint-disable-next-line react-hooks/set-state-in-effect -- Synchronize cached webhook on change
			setCachedWebhook(webhook);

			if (isJustOpened || webhook.id !== previousWebhookIdRef.current) {
				previousWebhookIdRef.current = webhook.id;
				setPage(1);
				setExpandedErrorId(null);
				setDeliveryPendingDelete(null);
				setIsClearConfirmOpen(false);
				setRetryingDeliveryId(null);

				const initialRetention = webhook.deliveryRetentionDays ?? 30;
				setSavedRetentionDays(initialRetention);
				setDraftRetentionDays(initialRetention);
				setIsDraftEdited(false);
			} else {
				const incomingSavedRetention =
					webhook.deliveryRetentionDays ?? 30;

				if (!isDraftEdited) {
					// Untouched draft adopts incoming saved policy (including 0)
					setSavedRetentionDays(incomingSavedRetention);
					setDraftRetentionDays(incomingSavedRetention);
				} else {
					// Genuinely edited draft: update saved baseline
					setSavedRetentionDays(incomingSavedRetention);
					// If refreshed saved policy matches the current draft, draft becomes clean
					if (incomingSavedRetention === draftRetentionDays) {
						setIsDraftEdited(false);
					}
				}
			}
		} else {
			previousWebhookIdRef.current = null;
		}
	}, [webhook, isOpen, isDraftEdited, draftRetentionDays]);

	const { data, isLoading, isFetching, refetch } =
		useGetWebhookDeliveriesQuery(
			{
				id: webhookId,
				page,
				perPage: PAGE_SIZE,
			},
			{
				skip: !isOpen || !webhookId,
			}
		);

	const retentionOptions: SelectOption<number>[] = useMemo(
		() => [
			{ value: 30, label: __("30 days (Default)") },
			{ value: 7, label: __("7 days") },
			{ value: 90, label: __("90 days") },
			{ value: 0, label: __("Keep indefinitely (Disabled)") },
		],
		[]
	);

	const handleSaveRetention = async () => {
		const targetWebhookId = webhookId;
		const targetRetention = draftRetentionDays;

		if (!targetWebhookId || isUpdatingRetention) {
			return;
		}

		try {
			const res = await updateWebhook({
				id: targetWebhookId,
				deliveryRetentionDays: targetRetention,
			}).unwrap();

			// Guard state updates by captured webhook context
			if (currentWebhookIdRef.current === targetWebhookId) {
				const confirmedValue =
					res?.data?.deliveryRetentionDays ?? targetRetention;
				setSavedRetentionDays(confirmedValue);
				setDraftRetentionDays(confirmedValue);
				setIsDraftEdited(false);
			}

			notification?.success?.(
				__("Success"),
				__("Delivery retention period updated.")
			);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to update delivery retention."))
			);
		}
	};

	const handleRetryDelivery = async (deliveryId: string) => {
		if (
			!webhookId ||
			retryingDeliveryId ||
			isDeletingDelivery ||
			isClearing
		) {
			return;
		}

		setRetryingDeliveryId(deliveryId);
		try {
			await retryDelivery({ id: webhookId, deliveryId }).unwrap();
			notification?.success?.(
				__("Success"),
				__("Delivery queued for retry.")
			);
			refetch();
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(
					err,
					__("Failed to queue webhook delivery for retry.")
				)
			);
		} finally {
			setRetryingDeliveryId(null);
		}
	};

	const handleDeleteDelivery = async () => {
		if (
			!webhookId ||
			!deliveryPendingDelete ||
			deliveryPendingDelete.webhookId !== webhookId ||
			isDeletingDelivery
		) {
			return;
		}

		const target = deliveryPendingDelete;
		try {
			await deleteDelivery({
				id: webhookId,
				deliveryId: target.id,
			}).unwrap();

			notification?.success?.(
				__("Success"),
				__("Delivery record deleted.")
			);

			if (expandedErrorId === target.id) {
				setExpandedErrorId(null);
			}

			if (deliveries.length === 1 && page > 1) {
				setPage((p) => Math.max(1, p - 1));
			}

			setDeliveryPendingDelete(null);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to delete delivery record."))
			);
		}
	};

	const handleClearHistory = async () => {
		if (!webhookId || isClearing) {
			return;
		}

		try {
			const res = await clearDeliveries({ id: webhookId }).unwrap();
			const count = res?.data?.deletedCount ?? 0;
			notification?.success?.(
				__("History Cleared"),
				count > 0
					? sprintf(
							_n(
								"Cleared %d completed delivery record.",
								"Cleared %d completed delivery records.",
								count
							),
							count
						)
					: __("No completed delivery records to clear.")
			);
			setPage(1);
			setExpandedErrorId(null);
			setIsClearConfirmOpen(false);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to clear delivery history."))
			);
		}
	};

	const deliveries = data?.items ?? [];
	const meta = data?.meta ?? {
		page: 1,
		perPage: PAGE_SIZE,
		total: 0,
		totalPages: 0,
	};

	const handleClose = () => {
		if (
			isClearing ||
			isDeletingDelivery ||
			Boolean(deliveryPendingDelete) ||
			isClearConfirmOpen
		) {
			return;
		}

		setPage(1);
		setExpandedErrorId(null);
		setDeliveryPendingDelete(null);
		setIsClearConfirmOpen(false);
		onClose();
	};

	const toggleErrorExpand = (id: string) => {
		setExpandedErrorId((prev) => (prev === id ? null : id));
	};

	if (!activeWebhook) {
		return null;
	}

	return (
		<DrawerDialog open={isOpen} onClose={handleClose}>
			{/* Smooth backdrop fade transition */}
			<DialogBackdrop transition className="dialog-backdrop" />

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`webhook-drawer-layout ${
							isRtl
								? "webhook-drawer-layout-rtl"
								: "webhook-drawer-layout-ltr"
						}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`drawer-panel drawer-transition webhook-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							{/* Drawer Header */}
							<div className="drawer-header webhook-drawer-header">
								<div className="drawer-heading-group">
									<div className="drawer-title-icon webhook-drawer-title-icon">
										<History className="w-5 h-5 text-accent" />
									</div>
									<div className="min-w-0 flex-1 text-start">
										<DialogTitle
											as="h2"
											className="drawer-title webhook-drawer-title truncate"
											title={activeWebhook.label}
										>
											{activeWebhook.label}
										</DialogTitle>
										<p
											className="webhook-drawer-subtitle truncate font-mono text-xs"
											dir="ltr"
											title={activeWebhook.url}
										>
											{activeWebhook.url}
										</p>
									</div>
								</div>
								<div className="flex items-center gap-1.5 shrink-0">
									<button
										type="button"
										onClick={() => refetch()}
										disabled={isFetching}
										className="drawer-icon-button webhook-drawer-header-btn"
										title={__("Refresh deliveries")}
										aria-label={__("Refresh deliveries")}
									>
										<RefreshCw
											size={16}
											className={cn(
												isFetching && "animate-spin"
											)}
										/>
									</button>
									<button
										type="button"
										onClick={handleClose}
										className="drawer-icon-button webhook-drawer-header-btn"
										title={__("Close drawer")}
										aria-label={__("Close drawer")}
									>
										<X size={18} />
									</button>
								</div>
							</div>

							{/* Drawer Body */}
							<div className="drawer-content webhook-drawer-content">
								{/* Metadata Cards Bar */}
								<div className="webhook-drawer-meta-grid">
									<div className="webhook-drawer-meta-card">
										<span className="webhook-drawer-meta-label">
											{__("Status")}
										</span>
										<span className="webhook-drawer-meta-value flex items-center gap-1.5 mt-0.5">
											<span
												className={cn(
													"h-2 w-2 rounded-full shrink-0",
													activeWebhook.isActive
														? "bg-green-500"
														: "bg-slate-400"
												)}
											/>
											{activeWebhook.isActive
												? __("Active")
												: __("Inactive")}
										</span>
									</div>
									<div className="webhook-drawer-meta-card">
										<span className="webhook-drawer-meta-label">
											{__("SSL Verification")}
										</span>
										<span className="webhook-drawer-meta-value flex items-center gap-1 mt-0.5">
											{activeWebhook.verifySsl !==
											false ? (
												<>
													<ShieldCheck
														size={13}
														className="text-green-600"
													/>
													<span>{__("Enabled")}</span>
												</>
											) : (
												<>
													<ShieldAlert
														size={13}
														className="text-amber-600"
													/>
													<span>
														{__("Disabled")}
													</span>
												</>
											)}
										</span>
									</div>
									<div className="webhook-drawer-meta-card">
										<span className="webhook-drawer-meta-label">
											{__("Health (24h)")}
										</span>
										<span className="webhook-drawer-meta-value">
											{!activeWebhook.health ||
											activeWebhook.health.total24h === 0
												? __(
														"No completed deliveries in 24h"
													)
												: activeWebhook.health
															.failed24h === 0
													? sprintf(
															__(
																"%d deliveries (100%%)"
															),
															activeWebhook.health
																.total24h
														)
													: sprintf(
															__(
																"%d failed / %d total"
															),
															activeWebhook.health
																.failed24h,
															activeWebhook.health
																.total24h
														)}
										</span>
									</div>
									<div className="webhook-drawer-meta-card">
										<span className="webhook-drawer-meta-label">
											{__("Total Logged")}
										</span>
										<span className="webhook-drawer-meta-value font-mono">
											{meta.total}
										</span>
									</div>
								</div>

								{/* History Retention and Bulk Clear Bar */}
								<div className="rounded-xl border border-stroke bg-surface-alt/40 p-3 sm:p-3.5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
									<div className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-2.5 min-w-0">
										<span className="text-xs font-semibold text-heading shrink-0">
											{__("History Retention:")}
										</span>
										<div className="flex items-center gap-2">
											<div className="w-52">
												<Select<number>
													value={draftRetentionDays}
													onChange={(val) => {
														const nextValue =
															Number(val);
														setDraftRetentionDays(
															nextValue
														);
														setIsDraftEdited(
															nextValue !==
																savedRetentionDays
														);
													}}
													options={retentionOptions}
													disabled={
														isUpdatingRetention ||
														isClearing
													}
													ariaLabel={__(
														"History Retention"
													)}
												/>
											</div>
											{isRetentionDirty && (
												<Button
													variant="primary"
													size="xs"
													icon={Save}
													loading={
														isUpdatingRetention
													}
													onClick={
														handleSaveRetention
													}
													title={__(
														"Save retention policy"
													)}
												>
													{__("Save")}
												</Button>
											)}
										</div>
									</div>
									<div className="flex items-center gap-2 shrink-0">
										<Button
											variant="secondary"
											size="xs"
											icon={Trash2}
											disabled={
												meta.total === 0 ||
												isClearing ||
												isDeletingDelivery
											}
											onClick={() =>
												setIsClearConfirmOpen(true)
											}
											className="hover:border-red-300 hover:text-red-600 dark:hover:border-red-900/40 dark:hover:text-red-400"
											title={__(
												"Clear completed delivery history"
											)}
										>
											{__("Clear history")}
										</Button>
									</div>
								</div>

								{/* Deliveries Table / States */}
								{isLoading ? (
									<div className="webhook-drawer-loading">
										<RefreshCw
											size={20}
											className="animate-spin text-accent"
										/>
										<span>
											{__("Loading delivery history…")}
										</span>
									</div>
								) : deliveries.length === 0 ? (
									<div className="webhook-drawer-empty">
										<History className="webhook-drawer-empty-icon" />
										<h4 className="webhook-drawer-empty-title">
											{__("No Delivery Attempts Yet")}
										</h4>
										<p className="webhook-drawer-empty-copy">
											{__(
												"When PeakURL delivers outbound events to this endpoint, each attempt and response will appear here."
											)}
										</p>
									</div>
								) : (
									<div className="space-y-4">
										<div className="webhook-drawer-table-wrap">
											<table className="webhook-drawer-table">
												<thead>
													<tr>
														<th>{__("Status")}</th>
														<th>{__("Event")}</th>
														<th>
															{__("HTTP Code")}
														</th>
														<th>
															{__("Duration")}
														</th>
														<th>
															{__("Attempts")}
														</th>
														<th>{__("Date")}</th>
														<th className="text-end">
															{__("Actions")}
														</th>
													</tr>
												</thead>
												<tbody>
													{deliveries.map(
														(
															item: WebhookDeliveryItem
														) => {
															const isFailed =
																item.status ===
																"failed";
															const isExpanded =
																expandedErrorId ===
																item.id;
															const eventLabel =
																getEventLabel(
																	item.event,
																	labelMap
																);

															return (
																<tr
																	key={
																		item.id
																	}
																	className={cn(
																		isFailed &&
																			"webhook-drawer-row-failed"
																	)}
																>
																	<td>
																		<DeliveryStatusBadge
																			status={
																				item.status
																			}
																			attempts={
																				item.attempts
																			}
																		/>
																	</td>
																	<td>
																		<div className="flex flex-col gap-0.5">
																			<span className="font-semibold text-xs text-heading">
																				{
																					eventLabel
																				}
																			</span>
																			<span className="font-mono text-[10px] text-text-muted">
																				{
																					item.id
																				}
																			</span>
																		</div>
																	</td>
																	<td>
																		{item.responseCode ? (
																			<span
																				className={cn(
																					"font-mono text-xs font-semibold",
																					item.responseCode >=
																						200 &&
																						item.responseCode <
																							300
																						? "text-green-600 dark:text-green-400"
																						: "text-red-600 dark:text-red-400"
																				)}
																			>
																				{
																					item.responseCode
																				}
																			</span>
																		) : (
																			<span className="text-text-muted text-xs">
																				—
																			</span>
																		)}
																	</td>
																	<td>
																		<span className="font-mono text-xs text-text-muted">
																			{item.durationMs !==
																				null &&
																			item.durationMs !==
																				undefined
																				? `${item.durationMs}ms`
																				: "—"}
																		</span>
																	</td>
																	<td>
																		<span className="text-xs text-heading font-medium">
																			{
																				item.attempts
																			}
																			{item.maxAttempts
																				? ` / ${item.maxAttempts}`
																				: ""}
																		</span>
																		{item.nextAttemptAt &&
																			item.status ===
																				"pending" && (
																				<span className="block text-[10px] text-amber-600 dark:text-amber-400 mt-0.5">
																					{__(
																						"Retrying soon"
																					)}
																				</span>
																			)}
																	</td>
																	<td>
																		<div className="text-xs text-text-muted">
																			<bdi className="preserve-ltr-value inline-block">
																				{formatLocalizedDateTime(
																					item.completedAt ||
																						item.createdAt,
																					{
																						dateStyle:
																							"short",
																						timeStyle:
																							"short",
																					}
																				)}
																			</bdi>
																		</div>
																		{item.lastError && (
																			<div className="mt-1">
																				<button
																					type="button"
																					onClick={() =>
																						toggleErrorExpand(
																							item.id
																						)
																					}
																					className="inline-flex items-center gap-1 text-[11px] font-medium text-red-600 hover:underline dark:text-red-400"
																				>
																					{isExpanded ? (
																						<>
																							<ChevronUp
																								size={
																									11
																								}
																							/>
																							{__(
																								"Hide error"
																							)}
																						</>
																					) : (
																						<>
																							<ChevronDown
																								size={
																									11
																								}
																							/>
																							{__(
																								"View error"
																							)}
																						</>
																					)}
																				</button>
																				{isExpanded && (
																					<div className="webhook-drawer-error-box">
																						<p className="font-mono text-[11px] text-red-800 dark:text-red-300 wrap-break-word whitespace-pre-wrap">
																							{
																								item.lastError
																							}
																						</p>
																					</div>
																				)}
																			</div>
																		)}
																	</td>
																	<td className="text-end">
																		<div className="flex items-center justify-end gap-1.5">
																			{isFailed && (
																				<Button
																					variant="secondary"
																					size="xs"
																					icon={
																						RotateCcw
																					}
																					loading={
																						retryingDeliveryId ===
																						item.id
																					}
																					disabled={
																						Boolean(
																							retryingDeliveryId
																						) ||
																						isDeletingDelivery ||
																						isClearing
																					}
																					onClick={() =>
																						handleRetryDelivery(
																							item.id
																						)
																					}
																					title={__(
																						"Retry Delivery"
																					)}
																				>
																					{__(
																						"Retry"
																					)}
																				</Button>
																			)}
																			{item.status ===
																				"delivered" ||
																			item.status ===
																				"failed" ? (
																				<button
																					type="button"
																					disabled={
																						isDeletingDelivery ||
																						isClearing ||
																						Boolean(
																							retryingDeliveryId
																						)
																					}
																					onClick={() =>
																						setDeliveryPendingDelete(
																							item
																						)
																					}
																					className="integrations-tab-delete-btn"
																					title={__(
																						"Delete delivery record"
																					)}
																					aria-label={__(
																						"Delete delivery record"
																					)}
																				>
																					<Trash2
																						size={
																							13
																						}
																					/>
																				</button>
																			) : (
																				<button
																					type="button"
																					disabled
																					className="integrations-tab-delete-btn opacity-40 cursor-not-allowed hover:border-stroke! hover:bg-transparent! hover:text-text-muted!"
																					title={__(
																						"Active deliveries cannot be deleted until completed."
																					)}
																					aria-label={__(
																						"Active deliveries cannot be deleted until completed."
																					)}
																				>
																					<Trash2
																						size={
																							13
																						}
																					/>
																				</button>
																			)}
																		</div>
																	</td>
																</tr>
															);
														}
													)}
												</tbody>
											</table>
										</div>

										{/* Pagination */}
										{meta.totalPages > 1 && (
											<div className="webhook-drawer-pagination">
												<span className="webhook-drawer-pagination-info">
													{sprintf(
														__(
															"Showing page %d of %d (%d total)"
														),
														meta.page,
														meta.totalPages,
														meta.total
													)}
												</span>
												<div className="flex items-center gap-2">
													<Button
														variant="secondary"
														size="sm"
														disabled={
															page <= 1 ||
															isFetching
														}
														onClick={() =>
															setPage((p) =>
																Math.max(
																	1,
																	p - 1
																)
															)
														}
													>
														{__("Previous")}
													</Button>
													<Button
														variant="secondary"
														size="sm"
														disabled={
															page >=
																meta.totalPages ||
															isFetching
														}
														onClick={() =>
															setPage((p) =>
																Math.min(
																	meta.totalPages,
																	p + 1
																)
															)
														}
													>
														{__("Next")}
													</Button>
												</div>
											</div>
										)}
									</div>
								)}
							</div>

							{/* Drawer Footer */}
							<div className="drawer-footer webhook-drawer-footer">
								<a
									href="https://go.peakurl.org/5f866a"
									target="_blank"
									rel="noopener noreferrer"
									dir={direction}
									className="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline"
								>
									<ExternalLink
										size={12}
										className="shrink-0"
									/>
									{__("Webhook delivery & retry guide")}
								</a>
								<Button
									variant="secondary"
									size="sm"
									onClick={handleClose}
								>
									{__("Close")}
								</Button>
							</div>

							{/* Delete Delivery Confirmation Dialog */}
							<ConfirmDialog
								open={Boolean(deliveryPendingDelete)}
								onClose={() => {
									if (!isDeletingDelivery) {
										setDeliveryPendingDelete(null);
									}
								}}
								title={__("Delete Delivery Record")}
								description={
									deliveryPendingDelete
										? deliveryPendingDelete.status ===
											"failed"
											? sprintf(
													__(
														"Permanently delete the delivery record for event %1$s (ID: %2$s)? Retained replay data will also be deleted, so this delivery can no longer be retried."
													),
													getEventLabel(
														deliveryPendingDelete.event,
														labelMap
													),
													deliveryPendingDelete.id
												)
											: sprintf(
													__(
														"Permanently delete the delivery record for event %1$s (ID: %2$s)? This action cannot be undone."
													),
													getEventLabel(
														deliveryPendingDelete.event,
														labelMap
													),
													deliveryPendingDelete.id
												)
										: ""
								}
								confirmText={__("Delete Delivery")}
								cancelText={__("Cancel")}
								confirmVariant="danger"
								onConfirm={handleDeleteDelivery}
								loading={isDeletingDelivery}
							/>

							{/* Clear Completed History Confirmation Dialog */}
							<ConfirmDialog
								open={isClearConfirmOpen}
								onClose={() => {
									if (!isClearing) {
										setIsClearConfirmOpen(false);
									}
								}}
								title={__("Clear Completed History")}
								description={__(
									"Permanently delete all delivered and failed records for this webhook across all pages? Active pending and processing deliveries will be preserved. This action cannot be undone."
								)}
								confirmText={__("Clear Completed History")}
								cancelText={__("Cancel")}
								confirmVariant="danger"
								onConfirm={handleClearHistory}
								loading={isClearing}
							/>
						</DialogPanel>
					</div>
				</div>
			</div>
		</DrawerDialog>
	);
}

export default WebhookDeliveryDrawer;
