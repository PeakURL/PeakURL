import { useEffect, useState } from "react";
import {
	Dialog,
	DialogBackdrop,
	DialogPanel,
	DialogTitle,
} from "@headlessui/react";
import {
	CheckCircle2,
	ChevronDown,
	ChevronUp,
	Clock,
	ExternalLink,
	History,
	RefreshCw,
	ShieldAlert,
	ShieldCheck,
	X,
	XCircle,
} from "lucide-react";

import { Button } from "@/components";
import { useGetWebhookDeliveriesQuery } from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { formatLocalizedDateTime } from "@/shared/dates";
import { cn } from "@/shared/formatting";

import type {
	WebhookDeliveryItem,
	WebhookDeliveryStatus,
	WebhookSummary,
} from "./types";

interface WebhookDeliveryDrawerProps {
	webhook: WebhookSummary | null;
	isOpen: boolean;
	onClose: () => void;
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

export function WebhookDeliveryDrawer({
	webhook,
	isOpen,
	onClose,
}: WebhookDeliveryDrawerProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const [page, setPage] = useState(1);
	const [expandedErrorId, setExpandedErrorId] = useState<string | null>(null);

	// Cache the active webhook so closing animations stay smooth without jumping even if parent clears the webhook
	const [cachedWebhook, setCachedWebhook] = useState(webhook);

	useEffect(() => {
		if (webhook) {
			// eslint-disable-next-line react-hooks/set-state-in-effect -- Synchronize cached webhook on change
			setCachedWebhook(webhook);
			setPage(1);
			setExpandedErrorId(null);
		}
	}, [webhook]);

	const activeWebhook = webhook ?? cachedWebhook;
	const webhookId = activeWebhook?.id ?? "";

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

	const deliveries = data?.items ?? [];
	const meta = data?.meta ?? {
		page: 1,
		perPage: PAGE_SIZE,
		total: 0,
		totalPages: 0,
	};

	const handleClose = () => {
		setPage(1);
		setExpandedErrorId(null);
		onClose();
	};

	const toggleErrorExpand = (id: string) => {
		setExpandedErrorId((prev) => (prev === id ? null : id));
	};

	if (!activeWebhook) {
		return null;
	}

	return (
		<Dialog open={isOpen} onClose={handleClose} className="relative z-50">
			{/* Smooth backdrop fade transition */}
			<DialogBackdrop
				transition
				className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity duration-500 ease-in-out data-closed:opacity-0"
			/>

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
							className={`webhook-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							{/* Drawer Header */}
							<div className="webhook-drawer-header">
								<div className="flex items-start gap-3 min-w-0 flex-1">
									<div className="webhook-drawer-title-icon">
										<History className="w-5 h-5 text-accent" />
									</div>
									<div className="min-w-0 flex-1 text-start">
										<DialogTitle
											as="h2"
											className="webhook-drawer-title truncate"
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
										className="webhook-drawer-header-btn"
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
										className="webhook-drawer-header-btn"
										title={__("Close drawer")}
										aria-label={__("Close drawer")}
									>
										<X size={18} />
									</button>
								</div>
							</div>

							{/* Drawer Body */}
							<div className="webhook-drawer-content">
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
																					item.event
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
							<div className="webhook-drawer-footer">
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
						</DialogPanel>
					</div>
				</div>
			</div>
		</Dialog>
	);
}

export default WebhookDeliveryDrawer;
