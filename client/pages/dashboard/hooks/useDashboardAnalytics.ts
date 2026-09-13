import { useEffect, useMemo, useRef, useState } from "react";

import type {
	CountryMetric,
	DashboardDeviceData,
	DashboardStats,
	RecentActivity,
	RecentClick,
	TrafficSeries,
} from "@/api";
import {
	useGetActivityQuery,
	useGetAnalyticsQuery,
	useGetRecentClicksQuery,
} from "@/state/slices/api";

const MIN_REFRESH_DURATION_MS = 700;
const RECENT_CLICKS_LIMIT = 8;
const RECENT_ACTIVITIES_LIMIT = 6;
const DEFAULT_TIME_RANGE_DAYS = 7;

const EMPTY_STATS: DashboardStats = {
	totalClicks: 0,
	totalLinks: 0,
	uniqueClicks: 0,
	uniqueClickRate: 0,
};

function normalizeTrafficSeries(
	traffic:
		Partial<TrafficSeries> | Record<string, unknown>[] | null | undefined
): TrafficSeries {
	if (Array.isArray(traffic)) {
		const labels: string[] = [];
		const clicks: number[] = [];
		const unique: number[] = [];

		for (const point of traffic) {
			const label = String(
				point?.date || point?.timestamp || point?.bucket || ""
			);
			const clickCount = Number(point?.clicks ?? point?.totalClicks ?? 0);
			const uniqueCount = Number(
				point?.uniqueClicks ?? point?.unique ?? 0
			);
			const safeClicks =
				Number.isFinite(clickCount) && clickCount > 0 ? clickCount : 0;
			const safeUnique =
				Number.isFinite(uniqueCount) && uniqueCount > 0
					? uniqueCount
					: 0;

			labels.push(label);
			clicks.push(safeClicks);
			unique.push(Math.min(safeUnique, safeClicks));
		}

		return { labels, clicks, unique };
	}

	const labels = Array.isArray(traffic?.labels) ? traffic.labels : [];
	const clicks = Array.isArray(traffic?.clicks) ? traffic.clicks : [];
	const unique = Array.isArray(traffic?.unique) ? traffic.unique : [];
	const length = labels.length || Math.max(clicks.length, unique.length, 0);

	return {
		labels:
			labels.length === length
				? labels
				: Array.from({ length }, (_, index) => labels[index] || ""),
		clicks: Array.from({ length }, (_, index) => {
			const clickValue = Number(clicks[index] || 0);
			return Number.isFinite(clickValue) && clickValue > 0
				? clickValue
				: 0;
		}),
		unique: Array.from({ length }, (_, index) => {
			const clickCount = Number(clicks[index] || 0);
			const uniqueCount = Number(unique[index] || 0);
			const safeClicks =
				Number.isFinite(clickCount) && clickCount > 0 ? clickCount : 0;
			const safeUnique =
				Number.isFinite(uniqueCount) && uniqueCount > 0
					? uniqueCount
					: 0;
			return Math.min(safeUnique, safeClicks);
		}),
	};
}

/**
 * Hook to manage Dashboard overview metrics, recent activity, recent clicks,
 * time-range filtering, and synchronized background refresh.
 */
export function useDashboardAnalytics() {
	const [timeRange, setTimeRange] = useState<number>(DEFAULT_TIME_RANGE_DAYS);
	const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
	const refreshTimeoutRef = useRef<number | null>(null);

	useEffect(() => {
		return () => {
			if (refreshTimeoutRef.current !== null) {
				window.clearTimeout(refreshTimeoutRef.current);
			}
		};
	}, []);

	const {
		data: analyticsRes,
		refetch: refetchAnalytics,
		isFetching: isAnalyticsFetching,
		isLoading: isAnalyticsLoading,
	} = useGetAnalyticsQuery(timeRange);

	const {
		data: activityRes,
		refetch: refetchActivity,
		isFetching: isActivityFetching,
		isLoading: isActivityLoading,
	} = useGetActivityQuery(undefined);

	const {
		data: recentClicksRes,
		refetch: refetchRecentClicks,
		isFetching: isRecentClicksFetching,
		isLoading: isRecentClicksLoading,
	} = useGetRecentClicksQuery(RECENT_CLICKS_LIMIT);

	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		if (refreshTimeoutRef.current !== null) {
			window.clearTimeout(refreshTimeoutRef.current);
			refreshTimeoutRef.current = null;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await Promise.allSettled([
				refetchAnalytics(),
				refetchActivity(),
				refetchRecentClicks(),
			]);
		} finally {
			const remaining =
				MIN_REFRESH_DURATION_MS - (Date.now() - startedAt);

			if (remaining > 0) {
				refreshTimeoutRef.current = window.setTimeout(() => {
					setIsRefreshing(false);
					refreshTimeoutRef.current = null;
				}, remaining);
			} else {
				setIsRefreshing(false);
			}
		}
	};

	const stats: DashboardStats = analyticsRes?.data ?? EMPTY_STATS;
	const activities: RecentActivity[] = activityRes?.data ?? [];
	const recentClicks: RecentClick[] = recentClicksRes?.data ?? [];
	const recentActivities = activities.slice(0, RECENT_ACTIVITIES_LIMIT);

	const deviceData: DashboardDeviceData = {
		devices: analyticsRes?.data?.devices ?? [],
		browsers: analyticsRes?.data?.browsers ?? [],
		operatingSystems: analyticsRes?.data?.operatingSystems ?? [],
	};

	const countryData: CountryMetric[] = analyticsRes?.data?.countries ?? [];

	const trafficData = useMemo(
		() => normalizeTrafficSeries(analyticsRes?.data?.traffic),
		[analyticsRes?.data?.traffic]
	);

	const isLoading =
		isAnalyticsLoading || isActivityLoading || isRecentClicksLoading;

	const isBusy =
		isRefreshing ||
		isAnalyticsFetching ||
		isActivityFetching ||
		isRecentClicksFetching;

	return {
		timeRange,
		setTimeRange,
		isRefreshing: isBusy,
		handleRefresh,
		stats,
		recentActivities,
		recentClicks,
		deviceData,
		countryData,
		trafficData,
		isLoading,
	};
}
