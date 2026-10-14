import { __ } from "@/i18n";
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
		isLoading,
	} = useDashboardAnalytics();

	if (isLoading) {
		return (
			<div className="dashboard-page">
				<Header
					timeRange={timeRange}
					onTimeRangeChange={setTimeRange}
					onRefresh={handleRefresh}
					isRefreshing={true}
					canViewAnalytics={canViewAnalytics}
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
				canViewAnalytics={canViewAnalytics}
			/>

			{canViewAnalytics ? (
				<>
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
							{canManageUsers ? (
								<ActivityFeed
									recentActivities={recentActivities}
								/>
							) : null}
						</div>
						<div className="dashboard-page-summary-countries">
							<CountryStats countryData={countryData} />
						</div>
					</div>
				</>
			) : (
				<div className="space-y-6">
					<div className="rounded-xl border border-stroke bg-surface p-6 text-center max-w-lg mx-auto my-6">
						<h2 className="text-base font-semibold text-heading mb-2">
							{__("Analytics Overview Hidden")}
						</h2>
						<p className="text-sm text-text-muted">
							{__(
								"Traffic statistics and click analytics are disabled for your role."
							)}
						</p>
					</div>
					{canManageUsers ? (
						<div className="max-w-2xl mx-auto">
							<ActivityFeed recentActivities={recentActivities} />
						</div>
					) : null}
				</div>
			)}
		</div>
	);
}

export default DashboardPage;
