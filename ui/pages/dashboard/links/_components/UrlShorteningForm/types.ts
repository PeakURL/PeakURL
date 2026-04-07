import type { LinkRecord } from '../types';

/**
 * Request payload used to create a new short link.
 */
export interface CreateUrlPayload {
	destinationUrl: string;
	alias?: string;
	title?: string;
	password?: string;
	expiresAt?: string | null;
}

/**
 * Response wrapper returned after creating a short link.
 */
export interface CreateUrlResponse {
	data?: LinkRecord;
}
