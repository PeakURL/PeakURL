import { useState } from "react";
import { useGetAnalyticsQuery, useGetActivityQuery } from "@/store/slices/api";
import {
	Header,
	StatsCards,
	TrafficOverview,
	ActivityFeed,
	DeviceBreakdown,
	CountryStats,
	DashboardSkeleton,
} from "./_components";

import type {
	CountryMetric,
	DashboardDeviceData,
	DashboardStats,
	RecentActivity,
	TrafficSeries,
} from "./_components/types";

function normalizeTrafficSeries(
	traffic: Partial<TrafficSeries> | null | undefined
): TrafficSeries {
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
			const clickCount = Number(clicks[index] || 0);
			return Number.isFinite(clickCount) && clickCount > 0
				? clickCount
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

function DashboardPage() {
	const [timeRange, setTimeRange] = useState(7);
	const [isRefreshing, setIsRefreshing] = useState(false);

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

	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await Promise.allSettled([refetchAnalytics(), refetchActivity()]);
		} finally {
			const remaining = 700 - (Date.now() - startedAt);

			if (remaining > 0) {
				window.setTimeout(() => setIsRefreshing(false), remaining);
			} else {
				setIsRefreshing(false);
			}
		}
	};

	const stats: DashboardStats = analyticsRes?.data ?? {
		totalClicks: 0,
		totalLinks: 0,
		uniqueClicks: 0,
		uniqueClickRate: 0,
		conversionRate: 0,
	};
	const activities: RecentActivity[] = activityRes?.data ?? [];

	const recentActivities = activities.slice(0, 6);

	const deviceData: DashboardDeviceData = {
		devices: analyticsRes?.data?.devices ?? [],
		browsers: analyticsRes?.data?.browsers ?? [],
		operatingSystems: analyticsRes?.data?.operatingSystems ?? [],
	};
	const countryData: CountryMetric[] = analyticsRes?.data?.countries ?? [];
	const trafficData = normalizeTrafficSeries(analyticsRes?.data?.traffic);

	const isLoading = isAnalyticsLoading || isActivityLoading;

	if (isLoading) {
		return (
			<div className="dashboard-page">
				<Header
					timeRange={timeRange}
					onTimeRangeChange={setTimeRange}
					onRefresh={handleRefresh}
					isRefreshing={true}
				/>
				<DashboardSkeleton />
			</div>
		);
	}

	return (
		<div className="dashboard-page">
			<Header
				timeRange={timeRange}
				onTimeRangeChange={setTimeRange}
				onRefresh={handleRefresh}
				isRefreshing={
					isRefreshing || isAnalyticsFetching || isActivityFetching
				}
			/>

			<StatsCards stats={stats} />

			<div className="dashboard-page-traffic-grid">
				<div className="dashboard-page-traffic-main">
					<TrafficOverview trafficData={trafficData} />
				</div>
				<ActivityFeed recentActivities={recentActivities} />
			</div>

			<div className="dashboard-page-summary-grid">
				<DeviceBreakdown deviceData={deviceData} />
				<CountryStats countryData={countryData} />
			</div>
		</div>
	);
}

export default DashboardPage;
