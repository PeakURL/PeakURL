/**
 * Summary data for one outbound webhook.
 */
export interface WebhookSummary {
	id: string;
	url: string;
	events?: string[] | null;
	isActive?: boolean;
	secretHint?: string | null;
	createdAt?: string | null;
}

/**
 * Newly created webhook data, including the one-time signing secret.
 */
export interface CreatedWebhook extends WebhookSummary {
	secret?: string | null;
}
