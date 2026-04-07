import type { Dispatch, SetStateAction } from 'react';
import type { LinkRecord } from '../types';

/**
 * Supported time ranges for filtering statistics.
 */
export type StatsTimeRange = '24h' | '7d' | '30d' | 'all';

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
}

/**
 * Main payload containing all analytics data for a link.
 */
export interface LinkStatsPayload {
	/** Total number of clicks */
	totalClicks: number;

	/** Total number of unique clicks */
	uniqueClicks: number;

	/** Conversion rate (e.g. percentage or ratio) */
	conversionRate: number;

	/** Time-series traffic data */
	traffic?: StatsTrafficSeries | null;

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
}

/**
 * Minimal traffic series used by the click chart tab.
 */
export interface ChartTrafficData {
	labels?: string[];
	clicks?: number[];
}

/**
 * Stats payload subset required by the click chart.
 */
export interface ClickChartStats {
	traffic?: ChartTrafficData | null;
}

/**
 * Props for the click history chart.
 */
export interface ClickChartProps {
	link?: LinkRecord | null;
	stats?: ClickChartStats | null;
	isLoading: boolean;
	timeRange: StatsTimeRange;
	setTimeRange: Dispatch<SetStateAction<StatsTimeRange>>;
	selectedTab: number;
	open: boolean;
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
}

/**
 * Supported share destinations from the share tab.
 */
export type SharePlatform = 'facebook' | 'twitter' | 'linkedin' | 'email';

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
	| 'Search Engine'
	| 'Social Media'
	| 'Messaging'
	| 'Video'
	| 'News & Content'
	| 'Developer'
	| 'Email'
	| 'Email Marketing'
	| 'Shopping'
	| 'AI'
	| 'Productivity'
	| 'Website'
	| 'Direct'
	| 'Unknown';

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
	isLoading: LinkStatsViewProps['isLoading'];
}
