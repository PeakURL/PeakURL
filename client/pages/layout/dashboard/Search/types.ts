import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

/**
 * Search result groups rendered in the dashboard command palette.
 */
export type DashboardSearchSection = "pages" | "tools";

/**
 * Capabilities passed to search matching to filter restricted routes.
 */
export interface DashboardSearchCapabilities {
	/** Whether the current user can manage site users. */
	canManageUsers?: boolean;

	/** Whether the current user can manage plugins. */
	canManagePlugins?: boolean;

	/** Whether the current user can manage API keys. */
	canManageApiKeys?: boolean;

	/** Whether the current user can manage webhooks. */
	canManageWebhooks?: boolean;

	/** Whether the current user can manage mail delivery settings. */
	canManageMailDelivery?: boolean;

	/** Whether the current user can manage location data settings. */
	canManageLocationData?: boolean;

	/** Whether the current user can manage application updates. */
	canManageUpdates?: boolean;

	/** Whether the current user can access bulk import tools. */
	canImportLinks?: boolean;

	/** Whether the current user can export links. */
	canExportLinks?: boolean;

	/** Whether the current user can view system status diagnostics. */
	canViewSystemStatus?: boolean;
}

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

	/** Preferred display name. */
	displayName?: string | null;
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

export interface ResultButtonProps {
	icon: LucideIcon;
	title: string;
	description?: string;
	meta?: string;
	onClick: () => void;
}

export interface ResultSectionProps {
	title: string;
	children: ReactNode;
}
