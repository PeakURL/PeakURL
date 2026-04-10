import type { NotificationContextValue } from '@/components';

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
 * Summary webhook payload returned by the integrations API.
 */
export interface WebhookSummary {
	/** Stable webhook identifier. */
	id: string;

	/** Destination URL configured for the webhook. */
	url: string;

	/** Events subscribed by the webhook. */
	events?: string[] | null;

	/** Whether the webhook is currently active. */
	isActive?: boolean;

	/** Partial secret hint shown after creation. */
	secretHint?: string | null;

	/** Creation timestamp, when available. */
	createdAt?: string | null;
}

/**
 * Newly created webhook payload that includes the one-time signing secret.
 */
export interface CreatedWebhook extends WebhookSummary {
	/** Full signing secret shown immediately after creation. */
	secret?: string | null;
}

/**
 * Props for the integrations settings tab.
 */
export interface IntegrationsTabProps {
	/** Notification helpers injected by the settings shell. */
	notification?: Pick<NotificationContextValue, 'error' | 'success'> | null;
}
