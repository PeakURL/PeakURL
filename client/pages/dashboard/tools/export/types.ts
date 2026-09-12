import type { ShortUrlLinkLike } from "@/shared/links";

/**
 * Supported file export formats for short links.
 */
export type LinkExportFormat = "csv" | "json" | "xml";

/**
 * Link payload shape accepted by the shared link-export helpers.
 */
export interface LinkExportSourceLink extends ShortUrlLinkLike {
	/** Destination URL that the short link redirects to. */
	destinationUrl?: string | null;

	/** Optional title stored for the link. */
	title?: string | null;

	/** Expiration timestamp associated with the link. */
	expiresAt?: string | null;

	/** Total click count recorded for the link. */
	clicks?: number | string | null;

	/** Unique visitor count recorded for the link. */
	uniqueClicks?: number | string | null;

	/** Creation timestamp associated with the link. */
	createdAt?: string | null;
}

/**
 * Normalized row representation emitted during link exports.
 */
export interface LinkExportItem {
	url: string;
	alias: string;
	title: string;
	password: string;
	expires: string;
	short_url: string;
	clicks: number | string;
	unique_clicks: number | string;
	created_at: string;
}

/**
 * Target file descriptor returned before triggering a browser download.
 */
export interface LinkExportFile {
	filename: string;
	type: string;
}
