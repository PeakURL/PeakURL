import { useMemo, useState } from "react";
import {
	Button,
	ConfirmDialog,
	Input,
	ReadOnlyValueBlock,
	Modal,
} from "@/components";
import {
	Copy,
	ExternalLink,
	Plus,
	Trash2,
	Webhook as WebhookIcon,
	Link2,
} from "lucide-react";
import {
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
	useGetWebhooksQuery,
} from "@/store/slices/api";
import { isDocumentRtl } from "@/i18n/direction";
import { __, sprintf } from "@/i18n";
import {
	cn,
	copyToClipboard as writeToClipboard,
	getErrorMessage,
} from "@/utils";
import type {
	CreatedWebhook,
	IntegrationsTabProps,
	WebhookEventOption,
	WebhookFormState,
	WebhookSummary,
} from "./types";

const getEventOptions = (): WebhookEventOption[] => [
	{ id: "link.created", label: __("Link Created") },
	{ id: "link.clicked", label: __("Link Clicked") },
	{ id: "link.updated", label: __("Link Updated") },
	{ id: "link.deleted", label: __("Link Deleted") },
];

function IntegrationsTab({ notification }: IntegrationsTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const eventOptions = getEventOptions();
	const {
		data: webhookData,
		isLoading,
		error,
	} = useGetWebhooksQuery(undefined);
	const [createWebhook, { isLoading: isCreating }] =
		useCreateWebhookMutation();
	const [deleteWebhook, { isLoading: isDeleting }] =
		useDeleteWebhookMutation();
	const webhooks = webhookData || [];

	const [form, setForm] = useState<WebhookFormState>({
		url: "",
		events: ["link.clicked"],
	});
	const [createdWebhook, setCreatedWebhook] = useState<CreatedWebhook | null>(
		null
	);
	const [webhookPendingDelete, setWebhookPendingDelete] =
		useState<WebhookSummary | null>(null);

	const canCreate = useMemo(() => {
		return form.url.trim().length > 0 && form.events.length > 0;
	}, [form.url, form.events]);

	const toggleEvent = (eventId: string) => {
		setForm((prev) => {
			const has = prev.events.includes(eventId);
			const nextEvents = has
				? prev.events.filter((e) => e !== eventId)
				: [...prev.events, eventId];
			return { ...prev, events: nextEvents };
		});
	};

	const handleCreate = async () => {
		if (!canCreate) {
			notification?.error?.(
				__("Error"),
				__("Enter a URL and select at least one event.")
			);
			return;
		}

		try {
			const result = await createWebhook({
				url: form.url.trim(),
				events: form.events,
			}).unwrap();
			notification?.success?.(
				__("Success"),
				__("Webhook created successfully")
			);
			setForm({ url: "", events: ["link.clicked"] });
			setCreatedWebhook(result?.data || null);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to create webhook"))
			);
		}
	};

	const handleDelete = async (id?: string) => {
		if (!id) return;

		try {
			await deleteWebhook(id).unwrap();
			notification?.success?.(__("Success"), __("Webhook deleted"));
			setWebhookPendingDelete(null);
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(err, __("Failed to delete webhook"))
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
			notification?.success?.(label, __("Copied to clipboard"));
		} catch (err) {
			notification?.error?.(__("Error"), __("Failed to copy"));
		}
	};

	return (
		<div className="integrations-tab">
			<div className="integrations-tab-intro">
				<div className="integrations-tab-intro-row">
					<div className="integrations-tab-intro-icon">
						<WebhookIcon className="integrations-tab-intro-icon-glyph" />
					</div>
					<div className="integrations-tab-intro-copy">
						<h2 className="integrations-tab-intro-title">
							{__("Integrations")}
						</h2>
						<p className="integrations-tab-intro-description">
							{__(
								"Connect PeakURL to your automations with outbound webhooks for link activity."
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="integrations-tab-panel">
				<div className="integrations-tab-panel-header">
					<div className="integrations-tab-panel-copy">
						<h3 className="integrations-tab-panel-title">
							{__("Webhooks")}
						</h3>
						<p className="integrations-tab-panel-description">
							{__(
								"PeakURL sends signed POST requests to your endpoint when selected link events happen."
							)}
						</p>
					</div>
					<a
						href="https://peakurl.org/docs/integrations"
						target="_blank"
						rel="noreferrer"
						dir={direction}
						className="integrations-tab-docs-link"
					>
						{__("Webhook docs")}
						<ExternalLink size={14} />
					</a>
				</div>

				<div className="integrations-tab-form">
					<h4 className="integrations-tab-form-title">
						{__("Add New Webhook")}
					</h4>
					<p className="integrations-tab-form-description">
						{__(
							"Choose which events should trigger a delivery, then save the signing secret somewhere secure when it is shown."
						)}
					</p>

					<div className="integrations-tab-form-grid">
						<div>
							<Input
								label={__("Endpoint URL")}
								type="url"
								valueDirection="ltr"
								icon={Link2}
								placeholder="https://hooks.zapier.com/hooks/catch/123456/peakurl"
								value={form.url}
								autoCapitalize="off"
								spellCheck={false}
								onChange={(event) =>
									setForm((prev) => ({
										...prev,
										url: event.target.value,
									}))
								}
							/>
							<div className="integrations-tab-endpoint-help">
								<p>
									{__(
										"Use a public HTTPS endpoint that can accept POST requests, such as a Zapier catch hook, an n8n webhook URL, or your own API route like"
									)}
								</p>
								<code className="integrations-tab-endpoint-code">
									https://example.com/api/webhooks/peakurl
								</code>
							</div>
						</div>

						<div>
							<label className="integrations-tab-field-label">
								{__("Events")}
							</label>
							<div className="integrations-tab-events-grid">
								{eventOptions.map((event) => (
									<label
										key={event.id}
										dir={direction}
										className="integrations-tab-event-option"
									>
										<input
											type="checkbox"
											checked={form.events.includes(
												event.id
											)}
											onChange={() =>
												toggleEvent(event.id)
											}
										/>
										<span>{event.label}</span>
									</label>
								))}
							</div>
						</div>
					</div>

					<div
						className={cn(
							"integrations-tab-form-actions",
							isRtl && "integrations-tab-form-actions-rtl"
						)}
					>
						<Button
							size="sm"
							icon={Plus}
							loading={isCreating}
							onClick={handleCreate}
							disabled={!canCreate}
						>
							{isCreating
								? __("Creating...")
								: __("Create Webhook")}
						</Button>
					</div>
				</div>

				{isLoading ? (
					<div className="integrations-tab-status-copy">
						{__("Loading webhooks…")}
					</div>
				) : error ? (
					<div className="integrations-tab-status-copy integrations-tab-status-copy-error">
						{getErrorMessage(error, __("Failed to load webhooks"))}
					</div>
				) : webhooks.length === 0 ? (
					<div className="integrations-tab-empty">
						<WebhookIcon className="integrations-tab-empty-icon" />
						<h4 className="integrations-tab-empty-title">
							{__("No Webhooks Configured")}
						</h4>
						<p className="integrations-tab-empty-copy">
							{__(
								"Add a webhook to receive link events in real time."
							)}
						</p>
					</div>
				) : (
					<div className="integrations-tab-list">
						{webhooks.map((wh) => (
							<div key={wh.id} className="integrations-tab-item">
								<div
									dir={direction}
									className="integrations-tab-item-row"
								>
									<div className="integrations-tab-item-content">
										<p className="integrations-tab-item-url">
											{wh.url}
										</p>
										<div className="integrations-tab-item-events">
											{(wh.events || []).map((evt) => (
												<span
													key={evt}
													className="integrations-tab-item-event-pill"
												>
													{evt}
												</span>
											))}
											{!wh.isActive && (
												<span className="integrations-tab-item-state-pill">
													{__("Inactive")}
												</span>
											)}
										</div>

										<div className="integrations-tab-item-secret">
											{wh.secretHint ? (
												<span className="integrations-tab-item-secret-value">
													{wh.secretHint}
												</span>
											) : (
												<span className="integrations-tab-item-secret-copy">
													{__(
														"Signing secret stored"
													)}
												</span>
											)}
										</div>

										{wh.createdAt && (
											<p className="integrations-tab-item-created">
												{__("Created:")}{" "}
												<bdi className="integrations-tab-item-created-value">
													{new Date(
														wh.createdAt
													).toLocaleDateString()}
												</bdi>
											</p>
										)}
									</div>

									<button
										className="integrations-tab-item-delete"
										aria-label={__("Delete webhook")}
										onClick={() =>
											setWebhookPendingDelete(wh)
										}
										disabled={isDeleting}
										title={__("Delete webhook")}
									>
										<Trash2 size={18} />
									</button>
								</div>
							</div>
						))}
					</div>
				)}
			</div>

			<Modal
				isOpen={Boolean(createdWebhook?.secret)}
				onClose={() => setCreatedWebhook(null)}
				title={__("Copy Your Webhook Secret")}
				size="md"
			>
				<div className="integrations-tab-secret-modal">
					<div className="integrations-tab-secret-notice">
						<p className="integrations-tab-secret-notice-title">
							{__("This signing secret will not be shown again.")}
						</p>
						<p className="integrations-tab-secret-notice-copy">
							{__(
								"Store it in your automation tool or secret manager before closing this window."
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
							"If this secret is ever exposed, delete the webhook and create a new one."
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

			<ConfirmDialog
				open={Boolean(webhookPendingDelete)}
				onClose={() => {
					if (!isDeleting) {
						setWebhookPendingDelete(null);
					}
				}}
				title={__("Delete webhook")}
				description={
					webhookPendingDelete
						? sprintf(
								__(
									"Delete the webhook for %s? PeakURL will stop sending signed event requests to this endpoint immediately."
								),
								webhookPendingDelete.url
							)
						: ""
				}
				confirmText={__("Delete webhook")}
				cancelText={__("Keep webhook")}
				confirmVariant="danger"
				onConfirm={() => handleDelete(webhookPendingDelete?.id)}
				loading={isDeleting}
			/>
		</div>
	);
}

export default IntegrationsTab;
