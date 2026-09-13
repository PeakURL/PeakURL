import {
	ActivityFeed,
	CountryStats,
	DashboardSkeleton,
	DeviceBreakdown,
	Header,
	RecentClicks,
	StatsCards,
	TrafficOverview,
} from "./components";
import { useDashboardAnalytics } from "./hooks";

function DashboardPage() {
	const {
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
		isLoading,
	} = useDashboardAnalytics();

	if (isLoading) {
		return (
			<div className="dashboard-page">
				<Header
					timeRange={timeRange}
					onTimeRangeChange={setTimeRange}
					onRefresh={handleRefresh}
					isRefreshing={isRefreshing}
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
				isRefreshing={isRefreshing}
			/>

			<StatsCards stats={stats} />

			<div className="dashboard-page-traffic-grid">
				<div className="dashboard-page-traffic-main">
					<TrafficOverview trafficData={trafficData} />
				</div>
				<div className="dashboard-page-traffic-side">
					<RecentClicks recentClicks={recentClicks} />
				</div>
			</div>

			<div className="dashboard-page-summary-grid">
				<div className="dashboard-page-summary-column">
					<DeviceBreakdown deviceData={deviceData} />
					<ActivityFeed recentActivities={recentActivities} />
				</div>
				<div className="dashboard-page-summary-countries">
					<CountryStats countryData={countryData} />
				</div>
			</div>
		</div>
	);
}

export default DashboardPage;
