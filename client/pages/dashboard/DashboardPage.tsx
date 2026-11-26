import { __ } from "@/i18n";

import {
	ActivityFeed,
	CountryStats,
	DashboardPanelSkeleton,
	DeviceBreakdown,
	Header,
	RecentClicks,
	StatsCards,
	TrafficOverview,
} from "./components";
import { useDashboardAnalytics } from "./hooks";

function DashboardPage() {
	const {
		canViewAnalytics,
		canManageUsers,
		timeRange,
		setTimeRange,
		isRefreshing,
		handleRefresh,
		stats,
		recentActivities,
		recentClicks,
		deviceData,
		countryData,
		trafficData,
		isAnalyticsLoading,
		isChartLoading,
		isAnalyticsError,
		isRecentClicksLoading,
		isActivityLoading,
	} = useDashboardAnalytics();
	const activity =
		canManageUsers &&
		(isActivityLoading ? (
			<DashboardPanelSkeleton panel="activity" />
		) : (
			<ActivityFeed recentActivities={recentActivities} />
		));

	return (
		<div className="dashboard-page">
			<Header
				timeRange={timeRange}
				onTimeRangeChange={setTimeRange}
				onRefresh={handleRefresh}
				isRefreshing={isRefreshing}
				canViewAnalytics={canViewAnalytics}
			/>

			{canViewAnalytics ? (
				<>
					{isAnalyticsError && (
						<p className="dashboard-analytics-notice" role="alert">
							{__(
								"Unable to load analytics for this date range. Refresh to try again."
							)}
						</p>
					)}
					<StatsCards stats={stats} isLoading={isAnalyticsLoading} />

					<div
						className="dashboard-page-traffic-grid"
						aria-busy={isAnalyticsLoading}
					>
						{!isAnalyticsError &&
							(isChartLoading ? (
								<DashboardPanelSkeleton panel="traffic" />
							) : (
								<TrafficOverview trafficData={trafficData} />
							))}
						{isRecentClicksLoading ? (
							<DashboardPanelSkeleton panel="recent-clicks" />
						) : (
							<RecentClicks recentClicks={recentClicks} />
						)}
					</div>

					{!isAnalyticsError && (
						<div
							className="dashboard-page-summary-grid"
							aria-busy={isAnalyticsLoading}
						>
							{isChartLoading ? (
								<>
									<DashboardPanelSkeleton panel="countries" />
									<DashboardPanelSkeleton panel="devices" />
								</>
							) : (
								<>
									<CountryStats countryData={countryData} />
									<DeviceBreakdown deviceData={deviceData} />
								</>
							)}
						</div>
					)}
				</>
			) : (
				<>
					<div className="dashboard-analytics-notice">
						<h2 className="mb-2 text-base font-semibold text-heading">
							{__("Analytics Overview Hidden")}
						</h2>
						<p>
							{__(
								"Traffic statistics and click analytics are disabled for your role."
							)}
						</p>
					</div>
				</>
			)}
			{activity && (
				<div className="dashboard-page-activity">{activity}</div>
			)}
		</div>
	);
}

export default DashboardPage;
