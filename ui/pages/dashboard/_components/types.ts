/**
 * Summary statistics displayed on the dashboard.
 */
export interface DashboardStats {
	/** Total number of clicks across all links */
	totalClicks: number;

	/** Total number of links created */
	totalLinks: number;

	/** Total number of unique clicks */
	uniqueClicks: number;

	/** Conversion rate (e.g. percentage or ratio) */
	conversionRate: number;
}

/**
 * Time-series data used for traffic charts on the dashboard.
 */
export interface TrafficSeries {
	/** Labels for the x-axis (e.g. dates or timestamps) */
	labels: string[];

	/** Total clicks over time */
	clicks: number[];

	/** Unique clicks over time */
	unique: number[];
}

/**
 * Basic information about a link associated with an activity event.
 */
export interface ActivityLink {
	/** Title of the link */
	title?: string | null;

	/** Short code or identifier for the link */
	shortCode?: string | null;
}

/**
 * Location information associated with an activity event.
 */
export interface ActivityLocation {
	/** City where the activity occurred */
	city?: string | null;

	/** Country where the activity occurred */
	country?: string | null;
}

/**
 * Represents a single recent activity item on the dashboard.
 */
export interface RecentActivity {
	/** Unique identifier for the activity */
	id?: string | null;

	/** Type of activity (e.g. click, creation, update) */
	type?: string | null;

	/** Human-readable message describing the activity */
	message?: string | null;

	/** Timestamp of when the activity occurred */
	timestamp?: string | null;

	/** Associated link data */
	link?: ActivityLink | null;

	/** Associated location data */
	location?: ActivityLocation | null;
}

/**
 * Generic metric item used for grouped statistics.
 */
export interface MetricItem {
	/** Name of the metric (e.g. "Chrome", "Mobile") */
	name: string;

	/** Count for the metric */
	count: number;
}

/**
 * Metric item extended with a country code.
 */
export interface CountryMetric extends MetricItem {
	/** ISO country code (e.g. "US", "GB") */
	code?: string | null;
}

/**
 * Device-related analytics data for the dashboard.
 */
export interface DashboardDeviceData {
	/** Breakdown by device type */
	devices: MetricItem[];

	/** Breakdown by browser */
	browsers: MetricItem[];

	/** Breakdown by operating system */
	operatingSystems: MetricItem[];
}

/**
 * Props for the recent activity feed component.
 */
export interface ActivityFeedProps {
	/** Activity items rendered in the feed. */
	recentActivities: RecentActivity[];
}

/**
 * Props for the country stats component.
 */
export interface CountryStatsProps {
	/** Country metrics rendered in the panel. */
	countryData: CountryMetric[];
}

/**
 * Props for the dashboard date-range header.
 */
export interface HeaderProps {
	/** Currently selected time-range value. */
	timeRange: number;

	/** Updates the active time range. */
	onTimeRangeChange: (value: number) => void;

	/** Refreshes the overview data. */
	onRefresh?: () => void | Promise<unknown>;

	/** Marks the overview refresh action as busy. */
	isRefreshing?: boolean;
}

/**
 * Props for the dashboard stats-card grid.
 */
export interface StatsCardsProps {
	/** Aggregate dashboard stats rendered in the cards. */
	stats: DashboardStats;
}

/**
 * Props for the device breakdown panel.
 */
export interface DeviceBreakdownProps {
	/** Device, browser, and OS metrics rendered in the panel. */
	deviceData?: DashboardDeviceData | null;
}

/**
 * Supported device color keys used in the dashboard breakdown.
 */
export type DeviceColorKey = 'mobile' | 'desktop' | 'tablet';

/**
 * Traffic-series data shown in the dashboard overview chart.
 */
export interface TrafficOverviewData {
	/** Ordered chart labels. */
	labels?: string[];

	/** Total click counts aligned with the labels array. */
	clicks?: number[];

	/** Unique click counts aligned with the labels array. */
	unique?: number[];
}

/**
 * Props for the dashboard traffic overview card.
 */
export interface TrafficOverviewProps {
	/** Optional traffic data rendered in the chart. */
	trafficData?: TrafficOverviewData | null;
}
