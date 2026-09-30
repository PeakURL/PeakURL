import type { NotificationContextValue } from "@/components";

export type { NotificationContextValue };

export type {
	CreatedWebhook,
	RotateSecretResult,
	WebhookDeliveriesResponse,
	WebhookDeliveryItem,
	WebhookDeliveryStatus,
	WebhookEventCatalogItem,
	WebhookHealthSummary,
	WebhookSummary,
	WebhookTestResult,
} from "@/api";

/**
 * Webhook event option shown in the integrations form.
 */
export interface WebhookEventOption {
	/** Stable event identifier sent to the API. */
	id: string;

	/** Human-readable label shown in the checkbox list. */
	label: string;

	/** Concise description of when the event triggers. */
	description?: string;

	/** Event group classification (e.g. link, api_key, user). */
	group: string;
}

/**
 * Props for the integrations settings tab.
 */
export interface IntegrationsTabProps {
	/** Notification helpers injected by the settings shell. */
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
}
