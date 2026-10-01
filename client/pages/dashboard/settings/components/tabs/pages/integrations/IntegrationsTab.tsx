import { useMemo, useState } from "react";
import {
	CheckCircle2,
	Clock,
	Copy,
	ExternalLink,
	FlaskConical,
	History,
	KeyRound,
	Pencil,
	Plus,
	RefreshCw,
	ShieldAlert,
	ShieldCheck,
	Trash2,
	Webhook as WebhookIcon,
	XCircle,
} from "lucide-react";

import { Button, ConfirmDialog, Modal, ReadOnlyValueBlock } from "@/components";
import {
	useDeleteWebhookMutation,
	useGetWebhookEventsQuery,
	useGetWebhooksQuery,
	useRotateWebhookSecretMutation,
	useTestWebhookMutation,
} from "@/state/slices/api";
import { __, _n, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { copyToClipboard as writeToClipboard } from "@/shared/browser";
import { formatLocalizedDateTime } from "@/shared/dates";
import { getErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";

import CaptchaSettings from "./CaptchaSettings";
import WebhookDeliveryDrawer from "./WebhookDeliveryDrawer";
import WebhookFormDrawer from "./WebhookFormDrawer";
import type {
	CreatedWebhook,
	IntegrationsTabProps,
	WebhookEventCatalogItem,
	WebhookEventOption,
	WebhookHealthSummary,
	WebhookSummary,
	WebhookTestResult,
} from "./types";

function HealthBadge({ health }: { health?: WebhookHealthSummary | null }) {
	if (!health) {
		return null;
	}

	const { total24h, failed24h, lastStatus, lastResponseCode, lastError } =
		health;

	if (lastStatus === "processing") {
		return (
			<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-info">
				<RefreshCw size={11} className="animate-spin" />
				{__("Processing")}
			</span>
		);
	}

	if (lastStatus === "pending") {
		return (
			<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-warning">
				<Clock size={11} />
				{__("Pending")}
			</span>
		);
	}

	if (total24h === 0) {
		return (
			<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-neutral">
				<Clock size={11} />
				{__("No completed deliveries in 24h")}
			</span>
		);
	}

	if (failed24h === 0) {
		return (
			<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-success">
				<CheckCircle2 size={11} />
				{sprintf(__("Healthy (%d deliveries in 24h)"), total24h)}
			</span>
		);
	}

	if (lastStatus === "failed") {
		return (
			<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-danger">
				<XCircle size={11} />
				{lastResponseCode
					? sprintf(__("Failed (HTTP %d)"), lastResponseCode)
					: lastError
						? sprintf(__("Failed: %s"), lastError.slice(0, 24))
						: sprintf(__("%d failed in 24h"), failed24h)}
			</span>
		);
	}

	return (
		<span className="integrations-tab-item-health-pill integrations-tab-item-health-pill-warning">
			<ShieldAlert size={11} />
			{sprintf(__("%d failed in 24h"), failed24h)}
		</span>
	);
}

function IntegrationsTab({ notification }: IntegrationsTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";

	const { data: webhookEventsData } = useGetWebhookEventsQuery();
	const {
		data: webhookData,
		isLoading,
		error,
	} = useGetWebhooksQuery(undefined);

	const [deleteWebhook, { isLoading: isDeleting }] =
		useDeleteWebhookMutation();
	const [rotateSecret, { isLoading: isRotating }] =
		useRotateWebhookSecretMutation();
	const [testWebhook] = useTestWebhookMutation();

	const webhooks = webhookData || [];

	const eventOptions: WebhookEventOption[] = useMemo(() => {
		if (!webhookEventsData || !Array.isArray(webhookEventsData)) {
			return [];
		}
		return webhookEventsData.map((item: WebhookEventCatalogItem) => ({
			id: item.id,
			label: item.label,
			description: item.description,
			group: item.group,
		}));
	}, [webhookEventsData]);

	const eventLabelMap = useMemo(() => {
		const map = new Map<string, string>();
		eventOptions.forEach((opt) => {
			map.set(opt.id, opt.label);
		});
		return map;
	}, [eventOptions]);

	// Section toggle state (matches CAPTCHA card behavior)
	const [isWebhooksToggled, setIsWebhooksToggled] = useState<boolean | null>(
		null
	);
	const isWebhooksEnabled =
		isWebhooksToggled !== null ? isWebhooksToggled : webhooks.length > 0;

	// Drawer states
	const [isFormDrawerOpen, setIsFormDrawerOpen] = useState(false);
	const [drawerWebhook, setDrawerWebhook] = useState<WebhookSummary | null>(
		null
	);

	const [isDeliveryDrawerOpen, setIsDeliveryDrawerOpen] = useState(false);
	const [deliveryWebhook, setDeliveryWebhook] =
		useState<WebhookSummary | null>(null);

	// Action dialog states
	const [webhookPendingDelete, setWebhookPendingDelete] =
		useState<WebhookSummary | null>(null);
	const [webhookPendingRotate, setWebhookPendingRotate] =
		useState<WebhookSummary | null>(null);

	// Test state
	const [testingWebhookId, setTestingWebhookId] = useState<string | null>(
		null
	);
	const [testResult, setTestResult] = useState<WebhookTestResult | null>(
		null
	);

	// Secret display modal state
	const [createdWebhook, setCreatedWebhook] = useState<CreatedWebhook | null>(
		null
	);

	const handleSendTest = async (targetWebhook: WebhookSummary) => {
		setTestingWebhookId(targetWebhook.id);
		try {
			const result = await testWebhook({
				id: targetWebhook.id,
				event: "webhook.test",
			}).unwrap();

			const testData = result?.data || null;
			setTestResult(testData);

			if (testData?.success) {
				notification?.success?.(
					__("Test Delivered"),
					sprintf(
						__("Endpoint responded with HTTP %d in %dms."),
						testData.statusCode ?? 200,
						testData.durationMs ?? 0
					)
				);
			} else {
				notification?.error?.(
					__("Test Failed"),
					testData?.error ||
						sprintf(
							__("Endpoint returned HTTP %d."),
							testData?.statusCode ?? 0
						)
				);
			}
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to dispatch test webhook."))
			);
		} finally {
			setTestingWebhookId(null);
		}
	};

	const handleDeleteWebhook = async () => {
		if (!webhookPendingDelete?.id) return;

		try {
			await deleteWebhook(webhookPendingDelete.id).unwrap();
			notification?.success?.(__("Success"), __("Webhook deleted."));
			setWebhookPendingDelete(null);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to delete webhook."))
			);
		}
	};

	const handleRotateSecret = async () => {
		if (!webhookPendingRotate?.id) return;

		try {
			const result = await rotateSecret(webhookPendingRotate.id).unwrap();
			notification?.success?.(
				__("Success"),
				__("Signing secret rotated successfully.")
			);
			setWebhookPendingRotate(null);
			if (result?.data) {
				setCreatedWebhook(result.data);
			}
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to rotate signing secret."))
			);
		}
	};

	const copyToClipboard = async (
		text?: string | null,
		label: string = __("Copied")
	) => {
		if (!text) return;
		try {
			await writeToClipboard(text);
			notification?.success?.(label, __("Copied to clipboard."));
		} catch (_err) {
			notification?.error?.(__("Error"), __("Failed to copy."));
		}
	};

	return (
		<div className="integrations-tab">
			{/* Integrations Intro Header Card */}
			<section className="settings-fieldset">
				<div dir={direction} className="integrations-tab-intro-row">
					<div className="integrations-tab-intro-icon">
						<WebhookIcon className="integrations-tab-intro-icon-glyph" />
					</div>
					<div className="integrations-tab-intro-copy">
						<h2 className="settings-legend mb-0!">
							{__("Integrations")}
						</h2>
						<p className="settings-group-description mb-0! mt-0!">
							{__(
								"Connect PeakURL to your automations with outbound webhooks for link activity."
							)}
						</p>
					</div>
				</div>
			</section>

			{/* Webhooks Section */}
			<section dir={direction} className="settings-fieldset">
				<div className="integrations-tab-panel-header">
					<div className="integrations-tab-panel-copy">
						<h3 className="integrations-tab-panel-title">
							{__("Webhooks")}
						</h3>
						<p className="integrations-tab-panel-description">
							{__(
								"PeakURL sends HMAC-SHA256 signed POST requests to your endpoint when selected link events occur."
							)}
						</p>
						<div className="mt-2">
							<a
								href="https://go.peakurl.org/5f866a"
								target="_blank"
								rel="noopener noreferrer"
								dir={direction}
								className="integrations-tab-docs-link"
							>
								{__("Read documentation")}
								<ExternalLink size={13} className="shrink-0" />
							</a>
						</div>
					</div>

					<div className="flex items-center gap-3">
						{isWebhooksEnabled && webhooks.length > 0 && (
							<Button
								size="sm"
								icon={Plus}
								onClick={() => {
									setDrawerWebhook(null);
									setIsFormDrawerOpen(true);
								}}
							>
								{__("Add Webhook")}
							</Button>
						)}
						<span className="integrations-tab-status-pill">
							{!isWebhooksEnabled
								? __("Disabled")
								: webhooks.length > 0
									? sprintf(
											_n(
												"%d configured",
												"%d configured",
												webhooks.length
											),
											webhooks.length
										)
									: __("Not configured")}
						</span>
						<div className="integrations-tab-switch">
							<span
								id="webhooks-toggle-label"
								className="sr-only"
							>
								{__("Enable webhooks")}
							</span>
							<button
								type="button"
								role="switch"
								aria-checked={isWebhooksEnabled}
								aria-labelledby="webhooks-toggle-label"
								disabled={isLoading}
								onClick={() =>
									setIsWebhooksToggled(!isWebhooksEnabled)
								}
								className={cn(
									"integrations-tab-switch-track",
									isWebhooksEnabled
										? "integrations-tab-switch-track-active"
										: "integrations-tab-switch-track-inactive"
								)}
							>
								<span
									className={cn(
										"integrations-tab-switch-thumb",
										isWebhooksEnabled
											? "integrations-tab-switch-thumb-active"
											: "integrations-tab-switch-thumb-inactive"
									)}
								/>
							</button>
						</div>
					</div>
				</div>

				{isLoading ? (
					<p className="integrations-tab-status-copy">
						{__("Loading webhook configuration…")}
					</p>
				) : error ? (
					<div className="py-4 text-sm text-red-600 dark:text-red-400">
						{getErrorMessage(
							error,
							__("Failed to load webhook configuration.")
						)}
					</div>
				) : isWebhooksEnabled ? (
					webhooks.length > 0 ? (
						<div className="integrations-tab-list" dir={direction}>
							{webhooks.map((webhook) => (
								<div
									key={webhook.id}
									className="integrations-tab-webhook-card"
								>
									{/* Webhook Card Primary Row */}
									<div className="integrations-tab-webhook-header">
										<div className="integrations-tab-webhook-main">
											{/* 1. Label as Primary Heading */}
											<div className="integrations-tab-webhook-title-row">
												<span
													className={cn(
														"integrations-tab-webhook-dot",
														webhook.isActive
															? "bg-emerald-500 shadow-xs shadow-emerald-500/50"
															: "bg-neutral-400"
													)}
												/>
												<h3
													className="integrations-tab-webhook-title"
													title={webhook.label}
												>
													{webhook.label}
												</h3>
											</div>

											{/* 2. Secondary Endpoint URL */}
											<div className="integrations-tab-webhook-url-row">
												<code
													className="integrations-tab-webhook-url"
													dir="ltr"
													title={webhook.url}
												>
													{webhook.url}
												</code>
												<button
													type="button"
													onClick={() =>
														copyToClipboard(
															webhook.url,
															__("URL copied")
														)
													}
													className="integrations-tab-icon-button"
													title={__("Copy URL")}
													aria-label={__("Copy URL")}
												>
													<Copy size={12} />
												</button>
											</div>

											<div className="integrations-tab-webhook-badges">
												<span
													className={cn(
														"integrations-tab-status-pill",
														webhook.isActive
															? "text-emerald-700 bg-emerald-500/10 border-emerald-500/20 dark:text-emerald-300"
															: "text-text-muted"
													)}
												>
													{webhook.isActive
														? __("Active")
														: __("Disabled")}
												</span>

												<span
													className={cn(
														"integrations-tab-status-pill",
														webhook.verifySsl !==
															false
															? "text-blue-700 bg-blue-500/10 border-blue-500/20 dark:text-blue-300"
															: "text-amber-700 bg-amber-500/10 border-amber-500/20 dark:text-amber-300"
													)}
												>
													{webhook.verifySsl !==
													false ? (
														<ShieldCheck
															size={11}
															className="shrink-0"
														/>
													) : (
														<ShieldAlert
															size={11}
															className="shrink-0"
														/>
													)}
													{webhook.verifySsl !== false
														? __("SSL")
														: __("Insecure")}
												</span>

												<HealthBadge
													health={webhook.health}
												/>
											</div>
										</div>

										{/* Compact Sleek Action Toolbar */}
										<div className="integrations-tab-webhook-actions">
											<Button
												variant="secondary"
												size="xs"
												icon={FlaskConical}
												loading={
													testingWebhookId ===
													webhook.id
												}
												disabled={
													!webhook.isActive ||
													Boolean(testingWebhookId)
												}
												onClick={() =>
													handleSendTest(webhook)
												}
												className="integrations-tab-action-btn"
												title={__(
													"Send a test payload to this endpoint"
												)}
											>
												{__("Test")}
											</Button>

											<Button
												variant="secondary"
												size="xs"
												icon={History}
												onClick={() => {
													setDeliveryWebhook(webhook);
													setIsDeliveryDrawerOpen(
														true
													);
												}}
												className="integrations-tab-action-btn"
												title={__(
													"View delivery history & attempts"
												)}
											>
												{__("Deliveries")}
											</Button>

											<Button
												variant="secondary"
												size="xs"
												icon={Pencil}
												onClick={() => {
													setDrawerWebhook(webhook);
													setIsFormDrawerOpen(true);
												}}
												className="integrations-tab-action-btn"
												title={__(
													"Edit webhook settings"
												)}
											>
												{__("Edit")}
											</Button>

											<button
												type="button"
												onClick={() =>
													setWebhookPendingDelete(
														webhook
													)
												}
												className="integrations-tab-delete-btn"
												title={__(
													"Delete this webhook"
												)}
												aria-label={__(
													"Delete this webhook"
												)}
											>
												<Trash2 size={13} />
											</button>
										</div>
									</div>

									{/* Compact Single-Line Bottom Metadata Strip */}
									<div className="integrations-tab-webhook-footer">
										<div className="integrations-tab-webhook-events">
											<span className="text-[11px] font-medium text-text-muted">
												{__("Events:")}
											</span>
											{webhook.events &&
											webhook.events.length > 0 ? (
												<span className="text-[11px] text-text-secondary">
													{(() => {
														const labels =
															webhook.events
																.map((id) =>
																	eventLabelMap.get(
																		id
																	)
																)
																.filter(
																	(
																		label
																	): label is string =>
																		Boolean(
																			label
																		)
																);
														if (
															labels.length === 0
														) {
															return (
																<span className="text-text-muted italic">
																	{__(
																		"No events subscribed"
																	)}
																</span>
															);
														}
														if (
															labels.length <= 2
														) {
															return labels.join(
																" · "
															);
														}
														const visible = labels
															.slice(0, 2)
															.join(" · ");
														const remaining =
															labels.length - 2;
														return `${visible} · ${sprintf(_n("+%d more", "+%d more", remaining), remaining)}`;
													})()}
												</span>
											) : (
												<span className="text-[11px] text-text-muted italic">
													{__("No events subscribed")}
												</span>
											)}
										</div>

										<div className="integrations-tab-webhook-meta">
											{webhook.secretHint && (
												<div className="flex items-center gap-1.5">
													<KeyRound
														size={12}
														className="shrink-0 text-text-muted"
													/>
													<span>{__("Secret:")}</span>
													<code className="integrations-tab-secret-code text-[10px] py-0 px-1.5">
														{webhook.secretHint}
													</code>
													<button
														type="button"
														onClick={() =>
															setWebhookPendingRotate(
																webhook
															)
														}
														className="font-medium text-accent hover:underline text-xs ml-0.5"
													>
														{__("Rotate")}
													</button>
												</div>
											)}

											{webhook.createdAt && (
												<>
													<span className="text-stroke-strong">
														•
													</span>
													<div className="text-text-muted text-[11px]">
														{__("Created:")}{" "}
														<span className="preserve-ltr-value font-medium text-heading">
															{formatLocalizedDateTime(
																webhook.createdAt,
																{
																	dateStyle:
																		"medium",
																}
															)}
														</span>
													</div>
												</>
											)}
										</div>
									</div>
								</div>
							))}
						</div>
					) : (
						<div className="integrations-tab-empty-card mt-4">
							<div className="integrations-tab-empty-icon">
								<WebhookIcon size={24} />
							</div>
							<h4 className="integrations-tab-empty-title">
								{__("No webhooks configured")}
							</h4>
							<p className="integrations-tab-empty-description">
								{__(
									"Add an HTTPS endpoint to begin receiving signed event deliveries for link activity, user updates, and API key events."
								)}
							</p>
							<div className="mt-5">
								<Button
									size="sm"
									icon={Plus}
									onClick={() => {
										setDrawerWebhook(null);
										setIsFormDrawerOpen(true);
									}}
								>
									{__("Add Webhook")}
								</Button>
							</div>
						</div>
					)
				) : null}
			</section>

			{/* CAPTCHA Protection Section */}
			<CaptchaSettings notification={notification} />

			{/* Webhook Form Drawer (Create / Edit) */}
			<WebhookFormDrawer
				isOpen={isFormDrawerOpen}
				webhook={drawerWebhook}
				onClose={() => {
					setIsFormDrawerOpen(false);
					setDrawerWebhook(null);
				}}
				eventOptions={eventOptions}
				notification={notification}
				onCreated={(created) => {
					setCreatedWebhook(created);
				}}
			/>

			{/* Webhook Delivery Slide-Over Drawer */}
			<WebhookDeliveryDrawer
				isOpen={isDeliveryDrawerOpen}
				webhook={deliveryWebhook}
				onClose={() => {
					setIsDeliveryDrawerOpen(false);
					setDeliveryWebhook(null);
				}}
				notification={notification}
				eventLabelMap={eventLabelMap}
			/>

			{/* Secret Display Modal (Shown once upon creation or secret rotation) */}
			<Modal
				isOpen={Boolean(createdWebhook?.secret)}
				onClose={() => setCreatedWebhook(null)}
				title={__("Your Webhook Signing Secret")}
				size="md"
			>
				<div className="integrations-tab-secret-modal">
					<div className="integrations-tab-secret-notice">
						<p className="integrations-tab-secret-notice-title font-semibold">
							{__("This signing secret will not be shown again.")}
						</p>
						<p className="integrations-tab-secret-notice-copy">
							{__(
								"Store this secret securely in your receiving application. Outbound requests are signed using HMAC-SHA256 with this secret."
							)}
						</p>
					</div>

					<div className="integrations-tab-secret-endpoint">
						<p className="integrations-tab-secret-endpoint-label">
							{__("Endpoint URL")}
						</p>
						<ReadOnlyValueBlock
							value={createdWebhook?.url}
							className="integrations-tab-secret-endpoint-value"
							monospace={false}
							valueClassName="integrations-tab-secret-endpoint-text"
						/>
					</div>

					<ReadOnlyValueBlock
						value={createdWebhook?.secret}
						onCopy={() =>
							copyToClipboard(
								createdWebhook?.secret,
								__("Secret copied")
							)
						}
						copyButtonLabel={__("Copy to clipboard")}
					/>

					<p className="integrations-tab-secret-copy">
						{__(
							"To verify signatures, compute HMAC-SHA256 over '{timestamp}.{raw_payload}' and compare against the X-PeakURL-Signature header."
						)}
					</p>

					<div
						className={cn(
							"integrations-tab-secret-actions",
							isRtl && "integrations-tab-secret-actions-rtl"
						)}
					>
						<Button
							variant="secondary"
							icon={Copy}
							onClick={() =>
								copyToClipboard(
									createdWebhook?.secret,
									__("Secret copied")
								)
							}
						>
							{__("Copy Secret")}
						</Button>
						<Button onClick={() => setCreatedWebhook(null)}>
							{__("I've Stored It")}
						</Button>
					</div>
				</div>
			</Modal>

			{/* Rotate Secret Confirmation Dialog */}
			<ConfirmDialog
				open={Boolean(webhookPendingRotate)}
				onClose={() => {
					if (!isRotating) {
						setWebhookPendingRotate(null);
					}
				}}
				title={__("Rotate Webhook Signing Secret")}
				description={
					webhookPendingRotate
						? sprintf(
								__(
									"Rotate the signing secret for %s? The current secret will be immediately invalidated and outbound event signatures will fail until you update your receiver application."
								),
								webhookPendingRotate.label
							)
						: ""
				}
				confirmText={__("Rotate Secret")}
				cancelText={__("Cancel")}
				confirmVariant="danger"
				onConfirm={handleRotateSecret}
				loading={isRotating}
			/>

			{/* Delete Confirmation Dialog */}
			<ConfirmDialog
				open={Boolean(webhookPendingDelete)}
				onClose={() => {
					if (!isDeleting) {
						setWebhookPendingDelete(null);
					}
				}}
				title={__("Delete Webhook")}
				description={
					webhookPendingDelete
						? sprintf(
								__(
									"Delete the webhook for %s? PeakURL will stop sending signed event requests to this endpoint immediately."
								),
								webhookPendingDelete.label
							)
						: ""
				}
				confirmText={__("Delete Webhook")}
				cancelText={__("Keep Webhook")}
				confirmVariant="danger"
				onConfirm={handleDeleteWebhook}
				loading={isDeleting}
			/>

			{/* Test Result Modal */}
			<Modal
				isOpen={Boolean(testResult)}
				onClose={() => setTestResult(null)}
				title={__("Webhook Test Ping Result")}
				size="md"
			>
				{testResult && (
					<div
						className={cn(
							"integrations-test-result-box",
							testResult.success
								? "integrations-test-result-success"
								: "integrations-test-result-failure"
						)}
					>
						<div className="integrations-test-result-header">
							<div className="integrations-test-result-title">
								{testResult.success ? (
									<>
										<CheckCircle2
											size={18}
											className="text-green-600"
										/>
										<span className="text-green-800 dark:text-green-200">
											{__("Delivery Succeeded")}
										</span>
									</>
								) : (
									<>
										<XCircle
											size={18}
											className="text-red-600"
										/>
										<span className="text-red-800 dark:text-red-200">
											{__("Delivery Failed")}
										</span>
									</>
								)}
							</div>
							<span className="font-mono text-xs font-semibold">
								{testResult.statusCode
									? `HTTP ${testResult.statusCode}`
									: "—"}
							</span>
						</div>

						<div className="integrations-test-result-meta">
							<div>
								<span className="integrations-test-result-label">
									{__("Duration")}
								</span>
								<span className="integrations-test-result-value">
									{testResult.durationMs !== null &&
									testResult.durationMs !== undefined
										? `${testResult.durationMs}ms`
										: "—"}
								</span>
							</div>
							<div>
								<span className="integrations-test-result-label">
									{__("Event")}
								</span>
								<span className="integrations-test-result-value">
									{testResult.event || "webhook.test"}
								</span>
							</div>
						</div>

						{testResult.error && (
							<div>
								<span className="integrations-test-result-label">
									{__("Error details")}
								</span>
								<p className="mt-1 rounded bg-red-100 p-2 font-mono text-xs text-red-900 dark:bg-red-950 dark:text-red-200">
									{testResult.error}
								</p>
							</div>
						)}

						<div className="mt-4 flex justify-end">
							<Button
								variant="secondary"
								size="sm"
								onClick={() => setTestResult(null)}
							>
								{__("Close")}
							</Button>
						</div>
					</div>
				)}
			</Modal>
		</div>
	);
}

export default IntegrationsTab;
