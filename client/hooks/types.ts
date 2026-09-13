/**
 * Minimal link shape returned by the dashboard search API query.
 */
export interface DashboardLinkMatchItem {
	/** Stable link identifier. */
	id: string;

	/** Optional custom alias for the link. */
	alias?: string | null;

	/** Optional generated short code for the link. */
	shortCode?: string | null;

	/** Destination URL associated with the link. */
	destinationUrl?: string | null;

	/** Optional stored title for the link. */
	title?: string | null;
}

/**
 * Options used when clearing dashboard search state.
 */
export interface ClearSearchOptions {
	/** Whether clearing the search should also reset the links page query param. */
	resetLinksSearch?: boolean;
}
