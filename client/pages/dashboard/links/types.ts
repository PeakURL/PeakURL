import type { LucideIcon } from "lucide-react";
import type {
	LinkRecord,
	LinkStatus,
	LinksMeta,
	LinksSortBy,
	LinksSortOrder,
	UpdateUrlPayload,
	UrlsListResponse,
} from "@/api";

export type {
	LinkRecord,
	LinkStatus,
	LinksMeta,
	LinksSortBy,
	LinksSortOrder,
	UpdateUrlPayload,
	UrlsListResponse,
};

export type LinksDateRange = "all" | "24h" | "7d" | "30d" | "custom";
export type LinksStatusFilter =
	| "all"
	| "active"
	| "inactive"
	| "trashed"
	| "expired"
	| "paused"
	| "archived";

/**
 * Date-only custom range used by links analytics controls.
 */
export interface LinksCustomDateRange {
	/** Inclusive start date in YYYY-MM-DD format. */
	from: string;

	/** Inclusive end date in YYYY-MM-DD format. */
	to: string;
}

export type LinkStatChangeType = "positive" | "negative";

export interface LinkStatChange {
	text: string;
	type: LinkStatChangeType;
}

export type LinkStatTone = "clicks" | "visitors" | "links" | "active";

export interface LinkStatCardData {
	title: string;
	value: string;
	change: LinkStatChange | null;
	note?: string;
	icon: LucideIcon;
	tone: LinkStatTone;
}
