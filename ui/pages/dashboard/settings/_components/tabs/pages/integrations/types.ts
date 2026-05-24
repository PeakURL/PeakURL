import type { NotificationContextValue } from "@/components";

export type { CreatedWebhook, WebhookSummary } from "@/api";

/**
 * Webhook event option shown in the integrations form.
 */
export interface WebhookEventOption {
	/** Stable event identifier sent to the API. */
	id: string;

	/** Human-readable label shown in the checkbox list. */
	label: string;
}

/**
 * Editable form state for the integrations webhook form.
 */
export interface WebhookFormState {
	/** Destination endpoint URL for outbound deliveries. */
	url: string;

	/** Event identifiers selected for the webhook. */
	events: string[];
}

/**
 * Props for the integrations settings tab.
 */
export interface IntegrationsTabProps {
	/** Notification helpers injected by the settings shell. */
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
}
