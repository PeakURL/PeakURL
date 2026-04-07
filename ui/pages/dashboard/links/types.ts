import type { LinkRecord, LinksMeta } from './_components/types';

/**
 * API response wrapper used by the links dashboard list and lookup queries.
 *
 * Encapsulates a collection of link records along with optional metadata
 * such as pagination or total counts.
 */
export interface GetUrlsResponse {
	/** Response payload returned from the API. */
	data?: {
		/** List of link records returned by the query. */
		items?: LinkRecord[];

		/** Additional metadata (e.g. pagination, totals). */
		meta?: LinksMeta;
	};
}
