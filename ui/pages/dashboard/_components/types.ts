import type {
	CountryMetric,
	DashboardDeviceData,
	DashboardStats,
	RecentActivity,
	RecentClick,
} from "@/api";

export type {
	ActivityLink,
	ActivityLocation,
	ActivityPerson,
	CountryMetric,
	DashboardDeviceData,
	DashboardStats,
	MetricItem,
	RecentActivity,
	RecentClick,
	TrafficSeries,
} from "@/api";

/**
 * Props for the recent activity feed component.
 */
export interface ActivityFeedProps {
	/** Activity items rendered in the feed. */
	recentActivities: RecentActivity[];

	/** Optional custom heading rendered above the list. */
	title?: string;

	/** Empty-state copy shown when no activity exists. */
	emptyText?: string;

	/** Optional CTA label rendered below the list. */
	actionLabel?: string | null;

	/** Optional CTA destination rendered below the list. */
	actionTo?: string | null;

	/** Whether the list should stay vertically scrollable. */
	isScrollable?: boolean;
}

/**
 * Props for the recent clicks dashboard widget.
 */
export interface RecentClicksProps {
	/** Click events rendered in the widget. */
	recentClicks: RecentClick[];
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
