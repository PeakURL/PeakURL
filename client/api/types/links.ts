/**
 * Supported short-link status values.
 */
export type LinkStatus =
	"active" | "inactive" | "expired" | "trashed" | "paused" | "archived";

/**
 * Link-list sort fields accepted by the API.
 */
export type LinksSortBy =
	| "createdAt"
	| "updatedAt"
	| "clicks"
	| "uniqueClicks"
	| "alias"
	| "title"
	| "health";

/**
 * Link-list sort directions accepted by the API.
 */
export type LinksSortOrder = "asc" | "desc";

/**
 * Explicit health check status values.
 */
export type LinkHealthStatus =
	| "healthy"
	| "slow"
	| "unreachable"
	| "dns_error"
	| "tls_error"
	| "timeout"
	| "http_error"
	| "redirect_loop"
	| "ssrf_blocked";

/**
 * Single destination health inspection snapshot.
 */
export interface LinkHealth {
	status: LinkHealthStatus;
	checkedAt: string | null;
	responseCode: number | null;
	responseTimeMs: number | null;
	errorMessage: string | null;
	redirectCount: number;
}

/**
 * Canonical short-link record returned by URL endpoints.
 */
export interface LinkRecord {
	id: string;
	userId?: string | null;
	destinationUrl: string;
	alias?: string | null;
	shortCode?: string | null;
	shortUrl?: string | null;
	title?: string | null;
	domain?: string | { domain?: string; name?: string } | null;
	socialPreview?: {
		title?: string | null;
		description?: string | null;
		imageUrl?: string | null;
		externalImageUrl?: string | null;
	} | null;
	status?: LinkStatus | null;
	clicks?: number | null;
	uniqueClicks?: number | null;
	utmSource?: string | null;
	utmMedium?: string | null;
	utmCampaign?: string | null;
	utmTerm?: string | null;
	utmContent?: string | null;
	createdAt?: string | null;
	updatedAt?: string | null;
	expiresAt?: string | null;
	hasPassword?: boolean;
	health?: LinkHealth | null;
}

/**
 * Pagination metadata returned by list endpoints.
 */
export interface LinksMeta {
	page: number;
	limit: number;
	totalItems: number;
	totalPages: number;
	totalClicks: number;
	uniqueClicks: number;
	activeLinks: number;
	trashedLinks?: number;
	expiredLinks?: number;
	lastPeriodTotalClicks?: number;
	lastPeriodUniqueClicks?: number;
}

/**
 * Endpoint response returned by the links list route.
 */
export interface GetUrlsResponse {
	data?: {
		items?: LinkRecord[];
		meta?: LinksMeta;
	};
}

export type UrlsListResponse = GetUrlsResponse;

/**
 * Endpoint response returned by the links export route.
 */
export interface UrlExportResponse {
	data?: {
		items?: LinkRecord[];
	};
}

/**
 * Request body used to update an existing short link.
 */
export interface UpdateUrlPayload {
	id: string;
	title?: string;
	status: LinkStatus;
	destinationUrl?: string;
	expiresAt: string | null;
	socialTitle?: string;
	socialDescription?: string;
	socialImageFile?: File | null;
	socialImageUrl?: string | null;
	removeSocialImage?: boolean;
	clearPassword?: boolean;
	password?: string;
}

/**
 * Request body used to create a short link.
 */
export interface CreateUrlPayload {
	destinationUrl: string;
	alias?: string;
	title?: string;
	status?: LinkStatus;
	socialTitle?: string;
	socialDescription?: string;
	socialImageFile?: File | null;
	socialImageUrl?: string | null;
	password?: string;
	expiresAt?: string | null;
	utmSource?: string;
	utmMedium?: string;
	utmCampaign?: string;
	utmTerm?: string;
	utmContent?: string;
}

/**
 * Endpoint response returned after creating a short link.
 */
export interface CreateUrlResponse {
	data?: LinkRecord;
}

/**
 * Endpoint response returned after manual link health check.
 */
export interface CheckLinkHealthResponse {
	data?: LinkHealth;
	message?: string;
}
