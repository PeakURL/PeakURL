import { useMemo, useState } from "react";
import { DialogBackdrop, DialogPanel, DialogTitle } from "@headlessui/react";
import {
	ExternalLink,
	Link2,
	Plus,
	Save,
	ShieldAlert,
	ShieldCheck,
	Webhook as WebhookIcon,
	X,
} from "lucide-react";

import {
	DrawerDialog,
	Button,
	Input,
	Select,
	type SelectOption,
} from "@/components";
import {
	useCreateWebhookMutation,
	useUpdateWebhookMutation,
} from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { getErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";

import type {
	CreatedWebhook,
	NotificationContextValue,
	WebhookEventOption,
	WebhookSummary,
} from "./types";

interface WebhookFormDrawerProps {
	webhook: WebhookSummary | null;
	isOpen: boolean;
	onClose: () => void;
	eventOptions: WebhookEventOption[];
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
	onCreated: (webhook: CreatedWebhook) => void;
}

interface FormInnerProps {
	webhook: WebhookSummary | null;
	onClose: () => void;
	eventOptions: WebhookEventOption[];
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
	onCreated: (webhook: CreatedWebhook) => void;
}

function FormInner({
	webhook,
	onClose,
	eventOptions,
	notification,
	onCreated,
}: FormInnerProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const isEditing = Boolean(webhook?.id);

	const [label, setLabel] = useState(webhook?.label ?? "");
	const [url, setUrl] = useState(webhook?.url ?? "");
	const [events, setEvents] = useState<string[]>(
		webhook?.events || ["link.created", "link.clicked"]
	);
	const [verifySsl, setVerifySsl] = useState(webhook?.verifySsl !== false);
	const [isActive, setIsActive] = useState(webhook?.isActive !== false);
	const [deliveryRetentionDays, setDeliveryRetentionDays] = useState<number>(
		webhook?.deliveryRetentionDays ?? 30
	);

	const [createWebhook, { isLoading: isCreating }] =
		useCreateWebhookMutation();
	const [updateWebhook, { isLoading: isUpdating }] =
		useUpdateWebhookMutation();
	const isSaving = isCreating || isUpdating;

	const canSubmit = useMemo(() => {
		const trimmedLabel = label.trim();
		const trimmedUrl = url.trim();
		const hasValidProtocol = trimmedUrl.startsWith("https://");
		return trimmedLabel.length > 0 && hasValidProtocol && events.length > 0;
	}, [label, url, events]);

	const isDirty = useMemo(() => {
		if (!isEditing || !webhook) return true;
		if (label.trim() !== webhook.label.trim()) return true;
		if (url.trim() !== webhook.url.trim()) return true;
		if (verifySsl !== (webhook.verifySsl !== false)) return true;
		if (isActive !== (webhook.isActive !== false)) return true;
		if (deliveryRetentionDays !== (webhook.deliveryRetentionDays ?? 30))
			return true;
		const originalEvents = webhook.events || [];
		if (events.length !== originalEvents.length) return true;
		return events.some((e) => !originalEvents.includes(e));
	}, [
		isEditing,
		webhook,
		label,
		url,
		verifySsl,
		isActive,
		deliveryRetentionDays,
		events,
	]);

	const retentionOptions: SelectOption<number>[] = useMemo(
		() => [
			{ value: 30, label: __("30 days (Default)") },
			{ value: 7, label: __("7 days") },
			{ value: 90, label: __("90 days") },
			{ value: 0, label: __("Keep indefinitely (Disabled)") },
		],
		[]
	);

	const eventGroups = useMemo(() => {
		const groups: {
			key: string;
			title: string;
			events: WebhookEventOption[];
		}[] = [
			{ key: "link", title: __("Link Events"), events: [] },
			{ key: "api_key", title: __("API Key Events"), events: [] },
			{ key: "user", title: __("User Events"), events: [] },
		];

		const groupMap = new Map<string, WebhookEventOption[]>();
		groups.forEach((g) => groupMap.set(g.key, g.events));

		const otherEvents: WebhookEventOption[] = [];

		eventOptions.forEach((opt) => {
			const groupKey = opt.group;
			const list = groupMap.get(groupKey);
			if (list) {
				list.push(opt);
			} else {
				otherEvents.push(opt);
			}
		});

		if (otherEvents.length > 0) {
			groups.push({
				key: "other",
				title: __("Other Events"),
				events: otherEvents,
			});
		}

		return groups.filter((g) => g.events.length > 0);
	}, [eventOptions]);

	const isAllSelected =
		eventOptions.length > 0 && events.length === eventOptions.length;
	const isNoneSelected = events.length === 0;

	const toggleEvent = (eventId: string) => {
		setEvents((prev) =>
			prev.includes(eventId)
				? prev.filter((e) => e !== eventId)
				: [...prev, eventId]
		);
	};

	const selectAllEvents = () => {
		setEvents(eventOptions.map((e) => e.id));
	};

	const clearEvents = () => {
		setEvents([]);
	};

	const handleSubmit = async (e: React.FormEvent) => {
		e.preventDefault();
		if (!canSubmit) return;

		try {
			if (isEditing && webhook?.id) {
				await updateWebhook({
					id: webhook.id,
					label: label.trim(),
					url: url.trim(),
					events,
					verifySsl,
					isActive,
					deliveryRetentionDays,
				}).unwrap();

				notification?.success?.(
					__("Success"),
					__("Webhook updated successfully.")
				);
				onClose();
			} else {
				const result = await createWebhook({
					label: label.trim(),
					url: url.trim(),
					events,
					verifySsl,
					deliveryRetentionDays,
				}).unwrap();

				notification?.success?.(
					__("Success"),
					__("Webhook created successfully.")
				);
				onClose();

				if (result?.data) {
					onCreated(result.data);
				}
			}
		} catch (err) {
			notification?.error?.(
				__("Error"),
				getErrorMessage(
					err,
					isEditing
						? __("Failed to update webhook.")
						: __("Failed to create webhook.")
				)
			);
		}
	};

	return (
		<form onSubmit={handleSubmit} className="flex h-full min-h-0 flex-col">
			{/* Drawer Header */}
			<div className="drawer-header webhook-drawer-header">
				<div className="drawer-heading-group">
					<div className="drawer-title-icon webhook-drawer-title-icon">
						<WebhookIcon className="w-5 h-5 text-accent" />
					</div>
					<div className="min-w-0 flex-1 text-start">
						<DialogTitle
							as="h2"
							className="drawer-title webhook-drawer-title"
						>
							{isEditing
								? __("Edit Webhook")
								: __("Add New Webhook")}
						</DialogTitle>
						<p className="drawer-subtitle">
							{isEditing
								? webhook?.label
								: __(
										"Configure destination endpoint and subscribed events."
									)}
						</p>
					</div>
				</div>
				<button
					type="button"
					onClick={onClose}
					className="drawer-icon-button webhook-drawer-header-btn"
					title={__("Close drawer")}
					aria-label={__("Close drawer")}
				>
					<X size={18} />
				</button>
			</div>

			{/* Drawer Body */}
			<div
				className="drawer-content webhook-drawer-content"
				dir={direction}
			>
				{/* 1. Webhook name */}
				<div className="drawer-field-section integrations-tab-field-group">
					<label className="integrations-tab-field-label">
						{__("Webhook name")}
					</label>
					<Input
						type="text"
						placeholder={__(
							"e.g. Slack notifications, Zapier, or n8n"
						)}
						value={label}
						autoCapitalize="words"
						spellCheck={false}
						onChange={(e) => setLabel(e.target.value)}
					/>
					<p className="integrations-tab-field-desc">
						{__(
							"A descriptive name to identify this webhook endpoint."
						)}
					</p>
				</div>

				{/* 2. Endpoint URL */}
				<div className="drawer-field-section integrations-tab-field-group">
					<label className="integrations-tab-field-label">
						{__("Endpoint URL")}
					</label>
					<Input
						type="url"
						valueDirection="ltr"
						icon={Link2}
						placeholder="https://api.yourdomain.com/webhooks/peakurl"
						value={url}
						autoCapitalize="off"
						spellCheck={false}
						onChange={(e) => setUrl(e.target.value)}
					/>
					<p className="integrations-tab-field-desc">
						{__(
							"Where PeakURL will send webhook requests. Must be a publicly accessible HTTPS endpoint."
						)}
					</p>
					{url.trim().startsWith("http://") && (
						<p className="text-xs text-rose-500 font-medium">
							{__(
								"Webhook endpoints must use the HTTPS protocol."
							)}
						</p>
					)}
				</div>

				{/* 2. Subscribed Events */}
				<div className="drawer-field-section integrations-tab-field-group">
					<div className="mb-2.5 flex flex-wrap items-center justify-between gap-2">
						<div className="flex items-center gap-2">
							<label className="integrations-tab-field-label mb-0!">
								{__("Subscribed Events")}
							</label>
							<span className="integrations-tab-badge">
								{sprintf(
									__("%d of %d selected"),
									events.length,
									eventOptions.length
								)}
							</span>
						</div>
						<div className="flex items-center gap-1.5">
							<button
								type="button"
								onClick={selectAllEvents}
								className={cn(
									"integrations-tab-quick-button",
									isAllSelected &&
										"integrations-tab-quick-button-active"
								)}
							>
								{__("All")}
							</button>
							<button
								type="button"
								onClick={clearEvents}
								className={cn(
									"integrations-tab-quick-button",
									isNoneSelected &&
										"integrations-tab-quick-button-active"
								)}
							>
								{__("Clear")}
							</button>
						</div>
					</div>

					{/* Event Groups */}
					<div className="webhook-events-groups-container">
						{eventGroups.map((group) => {
							const groupSelectedCount = group.events.filter(
								(e) => events.includes(e.id)
							).length;
							const isGroupAllSelected =
								group.events.length > 0 &&
								groupSelectedCount === group.events.length;

							const toggleGroup = () => {
								const groupIds = group.events.map((e) => e.id);
								if (isGroupAllSelected) {
									setEvents((prev) =>
										prev.filter(
											(id) => !groupIds.includes(id)
										)
									);
								} else {
									setEvents((prev) =>
										Array.from(
											new Set([...prev, ...groupIds])
										)
									);
								}
							};

							return (
								<div
									key={group.key}
									className="webhook-events-group"
								>
									<div className="webhook-events-group-header">
										<div className="flex items-center gap-2">
											<span className="webhook-events-group-title">
												{group.title}
											</span>
											<span className="integrations-tab-badge">
												{sprintf(
													__("%d of %d"),
													groupSelectedCount,
													group.events.length
												)}
											</span>
										</div>
										<button
											type="button"
											onClick={toggleGroup}
											className="webhook-events-group-action"
										>
											{isGroupAllSelected
												? __("Deselect all")
												: __("Select all")}
										</button>
									</div>

									{/* Compact Grouped Event List */}
									<div className="webhook-events-list">
										{group.events.map((opt) => {
											const isChecked = events.includes(
												opt.id
											);
											return (
												<label
													key={opt.id}
													htmlFor={`drawer-event-${opt.id}`}
													className="webhook-event-row"
												>
													<input
														type="checkbox"
														id={`drawer-event-${opt.id}`}
														className="integrations-tab-event-checkbox"
														checked={isChecked}
														onChange={() =>
															toggleEvent(opt.id)
														}
													/>
													<div className="min-w-0 flex-1">
														<span className="webhook-event-name">
															{opt.label}
														</span>
														{opt.description && (
															<p className="webhook-event-desc">
																{
																	opt.description
																}
															</p>
														)}
													</div>
												</label>
											);
										})}
									</div>
								</div>
							);
						})}
					</div>
				</div>

				{/* 3. SSL Certificate Verification */}
				<div className="integrations-tab-option-card">
					<div className="flex items-start gap-3">
						<div
							className={cn(
								"integrations-tab-option-icon",
								verifySsl
									? "integrations-tab-option-icon-active"
									: "integrations-tab-option-icon-warning"
							)}
						>
							{verifySsl ? (
								<ShieldCheck size={16} />
							) : (
								<ShieldAlert size={16} />
							)}
						</div>
						<div>
							<label
								htmlFor="drawer-webhook-verify-ssl"
								className="cursor-pointer text-xs font-semibold text-heading block"
							>
								{__("Enable SSL verification")}
							</label>
							<p className="mt-0.5 text-xs text-text-muted leading-relaxed">
								{__(
									"Verify the endpoint certificate when delivering requests. Disable only for self-signed or otherwise untrusted certificates."
								)}
							</p>
						</div>
					</div>
					<input
						id="drawer-webhook-verify-ssl"
						type="checkbox"
						className="integrations-tab-event-checkbox cursor-pointer"
						checked={verifySsl}
						onChange={(e) => setVerifySsl(e.target.checked)}
					/>
				</div>

				{/* History Retention */}
				<div className="drawer-field-section integrations-tab-field-group">
					<label className="integrations-tab-field-label">
						{__("History Retention")}
					</label>
					<Select<number>
						value={deliveryRetentionDays}
						onChange={(val) =>
							setDeliveryRetentionDays(Number(val))
						}
						options={retentionOptions}
						ariaLabel={__("History Retention")}
					/>
					<p className="integrations-tab-field-desc">
						{__(
							"Completed deliveries older than this retention threshold are permanently purged during scheduled maintenance."
						)}
					</p>
				</div>

				{/* 4. Active Status (Shown when editing) */}
				{isEditing && (
					<div className="integrations-tab-option-card">
						<div className="flex items-start gap-3">
							<div
								className={cn(
									"integrations-tab-option-icon",
									isActive
										? "integrations-tab-option-icon-active"
										: "integrations-tab-option-icon-warning"
								)}
							>
								<WebhookIcon size={16} />
							</div>
							<div>
								<label
									htmlFor="drawer-webhook-is-active"
									className="cursor-pointer text-xs font-semibold text-heading block"
								>
									{__("Active Webhook Delivery")}
								</label>
								<p className="mt-0.5 text-xs text-text-muted leading-relaxed">
									{isActive
										? __(
												"Webhook is enabled and will receive event deliveries."
											)
										: __(
												"Webhook is paused. Outbound deliveries will be skipped."
											)}
								</p>
							</div>
						</div>
						<input
							id="drawer-webhook-is-active"
							type="checkbox"
							className="integrations-tab-event-checkbox cursor-pointer"
							checked={isActive}
							onChange={(e) => setIsActive(e.target.checked)}
						/>
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
					<ExternalLink size={12} className="shrink-0" />
					{__("Payload format & signature docs")}
				</a>
				<div className="flex items-center gap-2">
					<Button
						variant="secondary"
						size="sm"
						onClick={onClose}
						disabled={isSaving}
					>
						{__("Cancel")}
					</Button>
					<Button
						type="submit"
						size="sm"
						icon={isEditing ? Save : Plus}
						loading={isSaving}
						disabled={!canSubmit || (isEditing && !isDirty)}
					>
						{isSaving
							? isEditing
								? __("Saving...")
								: __("Creating...")
							: isEditing
								? __("Save Changes")
								: __("Create Webhook")}
					</Button>
				</div>
			</div>
		</form>
	);
}

export function WebhookFormDrawer({
	webhook,
	isOpen,
	onClose,
	eventOptions,
	notification,
	onCreated,
}: WebhookFormDrawerProps) {
	const isRtl = isDocumentRtl();

	// Include a null selection so a new webhook stays a new form throughout its exit.
	const [cachedWebhook, setCachedWebhook] = useState(webhook);
	if (isOpen && webhook !== cachedWebhook) setCachedWebhook(webhook);
	const activeWebhook = isOpen ? webhook : cachedWebhook;

	return (
		<DrawerDialog open={isOpen} onClose={onClose}>
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
							transition
							className={`drawer-panel drawer-transition webhook-form-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							<FormInner
								key={activeWebhook?.id || "new"}
								webhook={activeWebhook}
								onClose={onClose}
								eventOptions={eventOptions}
								notification={notification}
								onCreated={onCreated}
							/>
						</DialogPanel>
					</div>
				</div>
			</div>
		</DrawerDialog>
	);
}

export default WebhookFormDrawer;
