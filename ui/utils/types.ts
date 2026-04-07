import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';

/**
 * Generic object record used by utility guards before reading arbitrary keys.
 *
 * Provides a narrow object shape for helper functions that inspect unknown
 * error payloads without falling back to loose `any` access.
 */
export type ErrorRecord = Record<string, unknown>;

/**
 * API error payload shape exposed by RTK Query responses with friendly text.
 */
export interface ApiErrorData {
	/** Human-readable error text returned by the API. */
	message?: string;
}

/**
 * RTK Query error variant backed by a numeric HTTP status code.
 *
 * Narrows the broader `FetchBaseQueryError` union to the branch that
 * represents an actual HTTP response status.
 */
export type NumericStatusQueryError = Extract<
	FetchBaseQueryError,
	{ status: number }
>;

/**
 * Recovery states returned when the PHP runtime needs setup or installation.
 */
export type InstallRecoveryState = 'needs_setup' | 'needs_install';

/**
 * Redirect target returned for setup or install recovery flows.
 */
export interface InstallRecoveryResult {
	/** Recovery state that triggered the redirect target. */
	state: InstallRecoveryState;

	/** Absolute or relative URL to continue the recovery flow. */
	url: string;
}

/**
 * Recovery payload nested inside install/setup error responses.
 */
export interface InstallRecoveryPayload {
	/** Recovery mode requested by the backend. */
	recoveryState?: InstallRecoveryState | null;

	/** Setup URL returned when the install still needs `setup-config.php`. */
	setupConfigUrl?: string | null;

	/** Install URL returned when the app needs the browser installer. */
	installUrl?: string | null;
}

/**
 * Minimal domain object occasionally embedded in link payloads.
 */
export interface LinkDomainRecord {
	/** Canonical domain string when present. */
	domain?: string | null;

	/** Fallback domain label used by some payloads. */
	name?: string | null;
}

/**
 * Minimal link shape required to construct a public short URL.
 */
export interface ShortUrlLinkLike {
	/** Canonical short URL returned directly by the API. */
	shortUrl?: string | null;

	/**
	 * Embedded custom-domain payload returned in varying API shapes.
	 *
	 * Some dashboard flows still surface this field as `unknown`, so utility
	 * helpers narrow it at runtime instead of forcing every caller to cast.
	 */
	domain?: unknown;

	/** Custom alias selected for the short link. */
	alias?: string | null;

	/** Generated short code used when no alias exists. */
	shortCode?: string | null;
}

/**
 * Link payload shape accepted by the shared link-export helpers.
 *
 * Keeps the export utility decoupled from page-specific link interfaces while
 * preserving the fields needed to build CSV, JSON, and XML downloads.
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

	/** Creation timestamp for the link. */
	createdAt?: string | null;
}

/**
 * File formats supported by the shared dashboard export utility.
 */
export type LinkExportFormat = 'csv' | 'json' | 'xml';

/**
 * Normalized export row shape written into CSV, JSON, and XML downloads.
 */
export interface LinkExportItem {
	/** Original destination URL. */
	url: string;

	/** Human-friendly alias or short code. */
	alias: string;

	/** Stored link title. */
	title: string;

	/** Password column kept for import/export compatibility. */
	password: string;

	/** Link expiration timestamp. */
	expires: string;

	/** Fully-qualified public short URL. */
	short_url: string;

	/** Total click count or an empty export placeholder. */
	clicks: number | string;

	/** Unique click count or an empty export placeholder. */
	unique_clicks: number | string;

	/** Link creation timestamp. */
	created_at: string;
}

/**
 * Download metadata returned for a specific export format.
 */
export interface LinkExportFile {
	/** Default filename used by the browser download prompt. */
	filename: string;

	/** MIME type attached to the generated Blob. */
	type: string;
}

/**
 * Capability flags used by dashboard search route resolution.
 */
export interface DashboardSearchCapabilities {
	/** Whether the current user can manage other users. */
	canManageUsers?: boolean;

	/** Whether the current user can manage API keys. */
	canManageApiKeys?: boolean;

	/** Whether the current user can manage webhook integrations. */
	canManageWebhooks?: boolean;

	/** Whether the current user can manage mail delivery settings. */
	canManageMailDelivery?: boolean;

	/** Whether the current user can manage location data settings. */
	canManageLocationData?: boolean;

	/** Whether the current user can manage application updates. */
	canManageUpdates?: boolean;

	/** Whether the current user can export links. */
	canExportLinks?: boolean;
}

/**
 * Search result groups rendered in the dashboard command palette.
 */
export type DashboardSearchSection = 'pages' | 'tools';

/**
 * Internal route target definition used to build searchable dashboard routes.
 */
export interface DashboardSearchRouteTarget {
	/** Stable identifier for the route target. */
	id: string;

	/** Destination path navigated to when the item is selected. */
	href: string;

	/** Visible route label shown in the search UI. */
	label: string;

	/** Secondary description shown beneath the label. */
	description: string;

	/** Group the route should render under in the search UI. */
	section: DashboardSearchSection;

	/** Searchable terms scored against the user query. */
	terms: string[];

	/** Whether the current user is allowed to see the route. */
	isAllowed: boolean;
}

/**
 * Public route result returned to dashboard search consumers.
 */
export interface DashboardSearchRouteMatch {
	/** Stable identifier for the route result. */
	id: string;

	/** Destination path for the selected result. */
	href: string;

	/** Visible label rendered in the result list. */
	label: string;

	/** Secondary description rendered beneath the label. */
	description: string;

	/** Group the route belongs to in the results list. */
	section: DashboardSearchSection;
}

/**
 * Minimal location shape needed to read dashboard search query params.
 */
export interface DashboardSearchLocationLike {
	/** Current pathname from the router location. */
	pathname?: string | null;

	/** Raw query string from the router location. */
	search?: string | null;
}

/**
 * Minimal user shape scored by the dashboard search utilities.
 */
export interface DashboardSearchUserLike {
	/** Stable user identifier when available. */
	id?: string | null;

	/** Username displayed in the dashboard. */
	username?: string | null;

	/** Email address associated with the user. */
	email?: string | null;

	/** Site role assigned to the user. */
	role?: string | null;

	/** Given name for display and search matching. */
	firstName?: string | null;

	/** Family name for display and search matching. */
	lastName?: string | null;
}

/**
 * User result item returned to the dashboard search UI.
 */
export interface DashboardSearchUserMatch {
	/** Stable identifier for the result row. */
	id: string;

	/** Primary text shown in the result list. */
	title: string;

	/** Secondary descriptive line for the user. */
	description: string;

	/** Supplemental metadata such as the user's role. */
	meta: string;

	/** Destination path navigated to when selected. */
	href: string;
}
