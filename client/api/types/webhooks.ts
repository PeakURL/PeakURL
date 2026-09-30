/**
 * Webhook health summary metrics.
 */
export interface WebhookHealthSummary {
	total24h: number;
	failed24h: number;
	lastStatus?: string | null;
	lastResponseCode?: number | null;
	lastError?: string | null;
}

/**
 * Authoritative webhook event catalogue item.
 */
export interface WebhookEventCatalogItem {
	id: string;
	label: string;
	description: string;
	group: string;
}

/**
 * Summary data for one outbound webhook.
 */
export interface WebhookSummary {
	id: string;
	label: string;
	url: string;
	events?: string[] | null;
	verifySsl?: boolean;
	isActive?: boolean;
	secretHint?: string | null;
	health?: WebhookHealthSummary | null;
	createdAt?: string | null;
	updatedAt?: string | null;
}

/**
 * Newly created webhook data, including the one-time signing secret.
 */
export interface CreatedWebhook extends WebhookSummary {
	secret?: string | null;
}

/**
 * Result of rotating a webhook signing secret.
 */
export interface RotateSecretResult extends WebhookSummary {
	secret: string;
}

/**
 * Request payload for creating a new webhook.
 */
export interface CreateWebhookPayload {
	label: string;
	url: string;
	events: string[];
	verifySsl?: boolean;
}

/**
 * Request payload for updating an existing webhook.
 */
export interface UpdateWebhookPayload {
	id: string;
	label?: string;
	url?: string;
	events?: string[];
	verifySsl?: boolean;
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
	deliveryId?: string;
	eventId?: string;
	webhookId?: string;
	event?: string;
	url?: string;
	statusCode?: number;
	success?: boolean;
	error?: string | null;
	durationMs?: number;
}

/**
 * Status of a delivery attempt.
 */
export type WebhookDeliveryStatus =
	"delivered" | "pending" | "processing" | "failed";

/**
 * Single delivery log entry for a webhook.
 */
export interface WebhookDeliveryItem {
	id: string;
	webhookId: string;
	eventId: string;
	event: string;
	status: WebhookDeliveryStatus;
	attempts: number;
	maxAttempts: number;
	nextAttemptAt?: string | null;
	lastAttemptAt?: string | null;
	completedAt?: string | null;
	durationMs?: number | null;
	responseCode?: number | null;
	lastError?: string | null;
	createdAt: string;
}

/**
 * Paginated response for webhook delivery history.
 */
export interface WebhookDeliveriesResponse {
	items: WebhookDeliveryItem[];
	meta: {
		page: number;
		perPage: number;
		total: number;
		totalPages: number;
	};
}

/**
 * Query parameters for fetching webhook deliveries.
 */
export interface GetWebhookDeliveriesParams {
	id: string;
	page?: number;
	perPage?: number;
}
