/**
 * Single file-import row before it is submitted to the API.
 */
export interface ImportRecord {
	destinationUrl: string;
	alias?: string;
	title?: string;
	password?: string;
	expiresAt?: string;
	status?: string;
	socialTitle?: string;
	socialDescription?: string;
	socialImageUrl?: string;
	utmSource?: string;
	utmMedium?: string;
	utmCampaign?: string;
	utmTerm?: string;
	utmContent?: string;
}

/**
 * Single paste-import row before it is submitted to the API.
 */
export interface PasteImportRequestItem {
	destinationUrl: string;
	alias?: string;
	title?: string;
}

/**
 * Successful row returned by the bulk-create endpoint.
 */
export interface BulkCreateSuccessItem {
	destinationUrl: string;
	alias?: string;
	shortCode?: string;
	shortUrl?: string;
}

/**
 * Failed row returned by the bulk-create endpoint.
 */
export interface BulkCreateErrorItem {
	destinationUrl: string;
	alias?: string;
	error?: string;
}

/**
 * Endpoint response returned by the bulk-create route.
 */
export interface BulkCreateResponse {
	data?: {
		results?: BulkCreateSuccessItem[];
		errors?: BulkCreateErrorItem[];
	};
}
