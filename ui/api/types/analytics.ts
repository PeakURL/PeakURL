import type { LinkRecord } from "./links";

/**
 * Dashboard overview totals returned by analytics.
 */
export interface DashboardStats {
	totalClicks: number;
	previousTotalClicks?: number;
	totalLinks: number;
	previousTotalLinks?: number;
	uniqueClicks: number;
	previousUniqueClicks?: number;
	uniqueClickRate: number;
	previousUniqueClickRate?: number;
}

/**
 * Dashboard traffic chart series.
 */
export interface TrafficSeries {
	labels: string[];
	clicks: number[];
	unique: number[];
}

/**
 * Short-link summary attached to an activity entry.
 */
export interface ActivityLink {
	id?: string | null;
	title?: string | null;
	shortCode?: string | null;
}

/**
 * User or actor summary attached to an activity entry.
 */
export interface ActivityPerson {
	id?: string | null;
	firstName?: string | null;
	lastName?: string | null;
	username?: string | null;
	email?: string | null;
	role?: string | null;
}

/**
 * Location summary attached to analytics or activity rows.
 */
export interface ActivityLocation {
	city?: string | null;
	country?: string | null;
}

/**
 * Activity-log item returned by the analytics endpoints.
 */
export interface RecentActivity {
	id?: string | null;
	type?: string | null;
	message?: string | null;
	timestamp?: string | null;
	link?: ActivityLink | null;
	actor?: ActivityPerson | null;
	user?: ActivityPerson | null;
	location?: ActivityLocation | null;
}

/**
 * Recent click row returned by the analytics endpoints.
 */
export interface RecentClick {
	id: string;
	clickedAt?: string | null;
	link: LinkRecord;
	location?: ActivityLocation | null;
	device?: string | null;
	browser?: string | null;
	operatingSystem?: string | null;
	referrer?: {
		name?: string | null;
		domain?: string | null;
	} | null;
}

/**
 * Name/count pair used by grouped analytics sections.
 */
export interface MetricItem {
	name: string;
	count: number;
}

/**
 * Country-level analytics metric.
 */
export interface CountryMetric extends MetricItem {
	code?: string | null;
}

/**
 * Device, browser, and operating-system analytics groups.
 */
export interface DashboardDeviceData {
	devices: MetricItem[];
	browsers: MetricItem[];
	operatingSystems: MetricItem[];
}

/**
 * Preset or custom ranges accepted by link analytics.
 */
export type StatsTimeRange = "24h" | "7d" | "30d" | "custom";

/**
 * Name/count pair used inside link-specific analytics.
 */
export interface StatsMetricItem {
	name: string;
	count: number;
}

/**
 * Referrer source row returned by link analytics.
 */
export interface ReferrerItem {
	name?: string | null;
	domain?: string | null;
	category?: string | null;
	count: number;
}

/**
 * Referrer totals grouped by category.
 */
export interface ReferrerCategoryItem {
	category: string;
	count: number;
}

/**
 * UTM campaign analytics row.
 */
export interface UtmCampaignItem {
	campaign: string;
	source?: string | null;
	medium?: string | null;
	count: number;
}

/**
 * Time-series data returned for one short link.
 */
export interface StatsTrafficSeries {
	labels?: string[];
	clicks?: number[];
	unique?: number[];
	granularity?: "day" | "month";
}

/**
 * Resolved date range metadata for link statistics.
 */
export interface LinkStatsRange {
	key?: StatsTimeRange | string;
	days?: number;
	from?: string;
	to?: string;
}

/**
 * Exact analytics totals for a named historical period.
 */
export interface LinkPeriodSummary {
	key: "last24Hours" | "last7Days" | "last30Days" | "allTime" | string;
	totalClicks: number;
	uniqueClicks: number;
	uniqueClickRate: number;
	averageClicks: number;
	averageUnit: "hour" | "day" | string;
}

/**
 * Day-level click totals shared by best-day and history views.
 */
export interface LinkClickDay {
	date: string;
	totalClicks: number;
	uniqueClicks: number;
	uniqueClickRate: number;
}

/**
 * Best click day returned by link analytics.
 */
export type LinkBestDay = LinkClickDay;

/**
 * One active day returned in click history.
 */
export type LinkClickHistoryDay = LinkClickDay;

/**
 * Day-level click history returned by link analytics.
 */
export interface LinkClickHistory {
	activeDayCount: number;
	days: LinkClickHistoryDay[];
}

/**
 * Full analytics payload returned for one short link.
 */
export interface LinkStatsPayload {
	totalClicks: number;
	uniqueClicks: number;
	uniqueClickRate: number;
	range?: LinkStatsRange;
	traffic?: StatsTrafficSeries | null;
	periodSummaries?: Partial<Record<string, LinkPeriodSummary>>;
	bestDay?: LinkBestDay | null;
	clickHistory?: LinkClickHistory | null;
	devices?: StatsMetricItem[];
	browsers?: StatsMetricItem[];
	operatingSystems?: StatsMetricItem[];
	referrers?: ReferrerItem[];
	referrerCategories?: ReferrerCategoryItem[];
	utmCampaigns?: UtmCampaignItem[];
}

/**
 * Endpoint response returned by the link stats route.
 */
export interface LinkStatsResponse {
	data?: LinkStatsPayload;
}

/**
 * Country metric returned by the link location endpoint.
 */
export interface CountryLocation {
	code: string;
	name: string;
	count: number;
}

/**
 * City metric returned by the link location endpoint.
 */
export interface CityLocation {
	name?: string | null;
	country?: string | null;
	count: number;
}

/**
 * Location analytics payload returned for one short link.
 */
export interface LinkLocationPayload {
	countries?: CountryLocation[];
	cities?: CityLocation[];
	totalClicks?: number;
}
