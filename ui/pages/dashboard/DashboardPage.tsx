// @ts-nocheck
import { useState } from 'react';
import {
	useGetAnalyticsQuery,
	useGetActivityQuery,
} from '@/store/slices/api/analytics';
import {
	Header,
	StatsCards,
	TrafficOverview,
	ActivityFeed,
	DeviceBreakdown,
	CountryStats,
} from './_components';

function normalizeTrafficSeries(traffic) {
	const labels = Array.isArray(traffic?.labels) ? traffic.labels : [];
	const clicks = Array.isArray(traffic?.clicks) ? traffic.clicks : [];
	const unique = Array.isArray(traffic?.unique) ? traffic.unique : [];
	const length = labels.length || Math.max(clicks.length, unique.length, 0);

	return {
		labels:
			labels.length === length
				? labels
				: Array.from({ length }, (_, index) => labels[index] || ''),
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

	const { data: analyticsRes } = useGetAnalyticsQuery(timeRange);
	const { data: activityRes } = useGetActivityQuery();

	const stats = analyticsRes?.data ?? {
		totalClicks: 0,
		totalLinks: 0,
		uniqueClicks: 0,
		conversionRate: 0,
	};
	const activities = activityRes?.data ?? [];

	const recentActivities = activities.slice(0, 6);

	const deviceData = {
		devices: analyticsRes?.data?.devices ?? [],
		browsers: analyticsRes?.data?.browsers ?? [],
		operatingSystems: analyticsRes?.data?.operatingSystems ?? [],
	};
	const countryData = analyticsRes?.data?.countries ?? [];
	const trafficData = normalizeTrafficSeries(analyticsRes?.data?.traffic);

	return (
		<div className="space-y-6 pb-8">
			<Header timeRange={timeRange} onTimeRangeChange={setTimeRange} />

			<StatsCards stats={stats} />

			<div className="grid grid-cols-1 xl:grid-cols-3 gap-5">
				<div className="xl:col-span-2">
					<TrafficOverview trafficData={trafficData} />
				</div>
				<ActivityFeed recentActivities={recentActivities} />
			</div>

			<div className="grid grid-cols-1 md:grid-cols-2 gap-5">
				<DeviceBreakdown deviceData={deviceData} />
				<CountryStats countryData={countryData} />
			</div>
		</div>
	);
}

export default DashboardPage;
