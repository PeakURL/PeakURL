import type { Dispatch, SetStateAction } from "react";
import type {
	CityLocation,
	CountryLocation,
	LinkBestDay,
	LinkClickDay,
	LinkClickHistory,
	LinkClickHistoryDay,
	LinkLocationPayload,
	LinkPeriodSummary,
	LinkRecord,
	LinkStatsPayload,
	LinkStatsRange,
	LinkStatsResponse,
	ReferrerCategoryItem,
	ReferrerItem,
	StatsMetricItem,
	StatsTrafficSeries,
	UtmCampaignItem,
} from "@/api";
import type { LinksCustomDateRange, LinksDateRange } from "../types";

export type {
	CityLocation,
	CountryLocation,
	LinkBestDay,
	LinkClickDay,
	LinkClickHistory,
	LinkClickHistoryDay,
	LinkLocationPayload,
	LinkPeriodSummary,
	LinkRecord,
	LinkStatsPayload,
	LinkStatsRange,
	LinkStatsResponse,
	ReferrerCategoryItem,
	ReferrerItem,
	StatsMetricItem,
	StatsTrafficSeries,
	UtmCampaignItem,
};

/**
 * Supported time ranges for filtering statistics in the dashboard UI.
 */
export type StatsFilterRange = "all" | "24h" | "7d" | "30d" | "custom";

/**
 * Date-only range used by the custom traffic-history filter.
 */
export interface StatsCustomDateRange {
	/** Inclusive start date in YYYY-MM-DD format. */
	from: string;

	/** Inclusive end date in YYYY-MM-DD format. */
	to: string;
}

/**
 * Props for the Link Stats view component.
 */
export interface LinkStatsViewProps {
	/** The link being analyzed */
	link: LinkRecord;

	/** Stats data for the link */
	stats?: LinkStatsPayload | null;

	/** Indicates whether stats are currently loading */
	isLoading: boolean;
}

/**
 * Props for the stats drawer panel component.
 */
export interface StatsDrawerProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: LinkRecord | null;
	pageClickRange?: LinksDateRange;
	pageCustomClickRange?: LinksCustomDateRange;
}

/**
 * Stats payload subset required by the traffic history chart.
 */
export type TrafficHistoryStats = Pick<
	LinkStatsPayload,
	"totalClicks" | "uniqueClicks" | "uniqueClickRate" | "range"
> & {
	traffic?: StatsTrafficSeries | null;
};

/**
 * Props for the traffic history chart.
 */
export interface TrafficHistoryProps {
	link?: LinkRecord | null;
	stats?: TrafficHistoryStats | null;
	isLoading: boolean;
	timeRange: StatsFilterRange;
	setTimeRange: Dispatch<SetStateAction<StatsFilterRange>>;
	customDateRange: StatsCustomDateRange;
	setCustomDateRange: Dispatch<SetStateAction<StatsCustomDateRange>>;
}

/**
 * Hover state passed into the traffic location map tooltip.
 */
export interface HoveredCountry {
	countryCode: string;
	countryName: string;
	clicks: number;
}

/**
 * Props for the traffic location tab.
 */
export interface TrafficLocationTabProps {
	link: LinkRecord | null;
	selectedTab: number;
	open: boolean;
	timeRange?: StatsFilterRange;
	customDateRange?: StatsCustomDateRange;
}

/**
 * Supported share destinations from the share tab.
 */
export type SharePlatform = "facebook" | "twitter" | "linkedin" | "email";

/**
 * Props for the share tab.
 */
export interface ShareTabProps {
	link: LinkRecord;
	shortUrl: string;
}

/**
 * Supported referrer category labels rendered in the sources tab.
 */
export type TrafficCategory =
	| "Search Engine"
	| "Social Media"
	| "Messaging"
	| "Video"
	| "News & Content"
	| "Developer"
	| "Email"
	| "Email Marketing"
	| "Shopping"
	| "AI"
	| "Productivity"
	| "Website"
	| "Direct"
	| "Unknown";

/**
 * Props for the browser icon helper in the device stats card.
 */
export interface BrowserIconProps {
	browser: string;
	className?: string;
}

/**
 * Props for the device statistics panel.
 */
export interface DeviceStatsProps {
	devices?: StatsMetricItem[];
	browsers?: StatsMetricItem[];
	os?: StatsMetricItem[];
	isLoading: LinkStatsViewProps["isLoading"];
}
