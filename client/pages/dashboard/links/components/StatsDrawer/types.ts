import type { Dispatch, SetStateAction } from "react";
import type { LinkRecord } from "@/api";
import type { LinksCustomDateRange, LinksDateRange } from "../types";

/**
 * Supported time ranges for filtering statistics.
 */
export type StatsTimeRange = "all" | "24h" | "7d" | "30d" | "custom";

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
 * Generic metric item used for grouped statistics
 * (e.g. devices, browsers, operating systems).
 */
export interface StatsMetricItem {
	/** Name of the metric (e.g. "Chrome", "Mobile") */
	name: string;

	/** Number of occurrences */
	count: number;
}

/**
 * Represents a traffic referrer source.
 */
export interface ReferrerItem {
	/** Display name of the referrer (e.g. "Google") */
	name?: string | null;

	/** Domain of the referrer (e.g. "google.com") */
	domain?: string | null;

	/** Category of the referrer (e.g. "search", "social") */
	category?: string | null;

	/** Number of clicks from this referrer */
	count: number;
}

/**
 * Aggregated referrer data grouped by category.
 */
export interface ReferrerCategoryItem {
	/** Referrer category (e.g. "social", "direct") */
	category: string;

	/** Total clicks for this category */
	count: number;
}

/**
 * Represents UTM campaign tracking data.
 */
export interface UtmCampaignItem {
	/** Campaign name */
	campaign: string;

	/** Traffic source (e.g. "facebook") */
	source?: string | null;

	/** Marketing medium (e.g. "cpc", "email") */
	medium?: string | null;

	/** Number of clicks attributed to this campaign */
	count: number;
}

/**
 * Time-series traffic data used for charts.
 */
export interface StatsTrafficSeries {
	/** Labels for the x-axis (e.g. dates or timestamps) */
	labels?: string[];

	/** Total clicks over time */
	clicks?: number[];

	/** Unique clicks over time */
	unique?: number[];

	/** Bucket size used by the traffic series. */
	granularity?: "day" | "month";
}

/**
 * Server-resolved stats range metadata.
 */
export interface LinkStatsRange {
	/** Requested range key used by the dashboard. */
	key?: StatsTimeRange | string;

	/** Number of date buckets returned for the chart. */
	days?: number;

	/** Inclusive custom start date when the range is custom. */
	from?: string;

	/** Inclusive custom end date when the range is custom. */
	to?: string;
}

/**
 * Exact totals for a named historical period.
 */
export interface LinkPeriodSummary {
	/** Stable period key returned by the API. */
	key: "last24Hours" | "last7Days" | "last30Days" | "allTime" | string;

	/** Total clicks in the period. */
	totalClicks: number;

	/** Unique visitor count in the period. */
	uniqueClicks: number;

	/** Unique visitor rate for the period. */
	uniqueClickRate: number;

	/** Average click count for the period unit. */
	averageClicks: number;

	/** Period unit used by the average. */
	averageUnit: "hour" | "day" | string;
}

/**
 * Day-level click totals shared by best-day and click-history analytics.
 */
export interface LinkClickDay {
	/** Date-only value in YYYY-MM-DD format. */
	date: string;

	/** Total clicks recorded on the date. */
	totalClicks: number;

	/** Unique visitors recorded on the date. */
	uniqueClicks: number;

	/** Unique visitor rate for the date. */
	uniqueClickRate: number;
}

/**
 * Best all-time click day for a link.
 */
export type LinkBestDay = LinkClickDay;

/**
 * One active day in a link's click history.
 */
export type LinkClickHistoryDay = LinkClickDay;

/**
 * Day-level click history for a link.
 */
export interface LinkClickHistory {
	/** Number of days with click activity. */
	activeDayCount: number;

	/** Active day rows returned oldest first by the API. */
	days: LinkClickHistoryDay[];
}

/**
 * Main payload containing all analytics data for a link.
 */
export interface LinkStatsPayload {
	/** Total number of clicks */
	totalClicks: number;

	/** Total number of unique clicks */
	uniqueClicks: number;

	/** Unique click rate percentage. */
	uniqueClickRate: number;

	/** Server-resolved range metadata for this response. */
	range?: LinkStatsRange;

	/** Time-series traffic data */
	traffic?: StatsTrafficSeries | null;

	/** Exact historical totals for common dashboard periods. */
	periodSummaries?: Partial<Record<string, LinkPeriodSummary>>;

	/** Best all-time day for this link. */
	bestDay?: LinkBestDay | null;

	/** Day-level click history for this link. */
	clickHistory?: LinkClickHistory | null;

	/** Breakdown by device type */
	devices?: StatsMetricItem[];

	/** Breakdown by browser */
	browsers?: StatsMetricItem[];

	/** Breakdown by operating system */
	operatingSystems?: StatsMetricItem[];

	/** List of referrer sources */
	referrers?: ReferrerItem[];

	/** Referrer data grouped by category */
	referrerCategories?: ReferrerCategoryItem[];

	/** UTM campaign analytics */
	utmCampaigns?: UtmCampaignItem[];
}

/**
 * API response wrapper for link statistics.
 */
export interface LinkStatsResponse {
	/** Stats payload returned from the API */
	data?: LinkStatsPayload;
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
	timeRange: StatsTimeRange;
	setTimeRange: Dispatch<SetStateAction<StatsTimeRange>>;
	customDateRange: StatsCustomDateRange;
	setCustomDateRange: Dispatch<SetStateAction<StatsCustomDateRange>>;
}

/**
 * Country-level location metric for link analytics.
 */
export interface CountryLocation {
	code: string;
	name: string;
	count: number;
}

/**
 * City-level location metric for link analytics.
 */
export interface CityLocation {
	name?: string | null;
	country?: string | null;
	count: number;
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
 * Location analytics payload returned for a short link.
 */
export interface LinkLocationPayload {
	countries?: CountryLocation[];
	cities?: CityLocation[];
	totalClicks?: number;
}

/**
 * Props for the traffic location tab.
 */
export interface TrafficLocationTabProps {
	link: LinkRecord | null;
	selectedTab: number;
	open: boolean;
	timeRange?: StatsTimeRange;
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
