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

/**
 * Request payload for updating an existing webhook.
 */
export interface UpdateWebhookPayload {
	id: string;
	url?: string;
	events?: string[];
	isActive?: boolean;
}

/**
 * Request payload for sending a test webhook ping.
 */
export interface TestWebhookPayload {
	id?: string;
	event?: string;
}

/**
 * Delivery results returned after dispatching a test webhook.
 */
export interface WebhookTestResult {
	webhookId?: string;
	url?: string;
	statusCode?: number;
	success?: boolean;
	error?: string | null;
	durationMs?: number;
	response?: string;
}
