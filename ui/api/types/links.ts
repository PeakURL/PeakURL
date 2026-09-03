/**
 * Supported short-link status values.
 */
export type LinkStatus = "active" | "inactive" | "expired" | "trashed";

/**
 * Link-list sort fields accepted by the API.
 */
export type LinksSortBy =
	"createdAt" | "updatedAt" | "clicks" | "uniqueClicks" | "alias" | "title";

/**
 * Link-list sort directions accepted by the API.
 */
export type LinksSortOrder = "asc" | "desc";

/**
 * Canonical short-link record returned by URL endpoints.
 */
export interface LinkRecord {
	id: string;
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
	createdAt?: string | null;
	updatedAt?: string | null;
	expiresAt?: string | null;
	hasPassword?: boolean;
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
	socialTitle?: string;
	socialDescription?: string;
	socialImageFile?: File | null;
	socialImageUrl?: string | null;
	password?: string;
	expiresAt?: string | null;
}

/**
 * Endpoint response returned after creating a short link.
 */
export interface CreateUrlResponse {
	data?: LinkRecord;
}
