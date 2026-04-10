import { Skeleton } from '@/components';

const StatCardSkeleton = () => (
	<div className="skeleton-card">
		<div className="skeleton-card-header">
			<div className="dashboard-skeleton-stat-copy">
				<Skeleton className="dashboard-skeleton-stat-label" />
				<Skeleton className="dashboard-skeleton-stat-value" />
			</div>
			<Skeleton className="dashboard-skeleton-stat-icon" />
		</div>
		<div className="dashboard-skeleton-stat-meta">
			<Skeleton className="dashboard-skeleton-stat-trend" />
			<Skeleton className="dashboard-skeleton-stat-note" />
		</div>
	</div>
);

const TrafficSkeleton = () => {
	const heights = ['60%', '45%', '70%', '50%', '80%', '40%', '65%'];

	return (
		<div className="skeleton-form">
			<div className="skeleton-card-header">
				<Skeleton className="dashboard-skeleton-section-title-wide" />
				<div className="dashboard-skeleton-toolbar">
					<Skeleton className="dashboard-skeleton-toolbar-button" />
					<Skeleton className="dashboard-skeleton-toolbar-button" />
				</div>
			</div>
			<div className="dashboard-skeleton-chart">
				{heights.map((height, i) => (
					<Skeleton
						key={i}
						className="dashboard-skeleton-chart-bar"
						style={{ height }}
					/>
				))}
			</div>
		</div>
	);
};

const ActivitySkeleton = () => (
	<div className="skeleton-form">
		<Skeleton className="dashboard-skeleton-section-title" />
		<div className="dashboard-skeleton-activity-list">
			{[1, 2, 3, 4].map((i) => (
				<div key={i} className="dashboard-skeleton-activity-item">
					<Skeleton className="dashboard-skeleton-activity-avatar" />
					<div className="dashboard-skeleton-activity-copy">
						<Skeleton className="dashboard-skeleton-activity-title" />
						<Skeleton className="dashboard-skeleton-activity-time" />
					</div>
				</div>
			))}
		</div>
	</div>
);

const SummarySkeleton = () => (
	<div className="skeleton-form">
		<Skeleton className="dashboard-skeleton-section-title-wide" />
		<div className="dashboard-skeleton-summary-list">
			{[1, 2, 3].map((i) => (
				<div key={i} className="dashboard-skeleton-summary-item">
					<div className="dashboard-skeleton-summary-row">
						<Skeleton className="dashboard-skeleton-summary-label" />
						<Skeleton className="dashboard-skeleton-summary-value" />
					</div>
					<Skeleton className="dashboard-skeleton-summary-bar" />
				</div>
			))}
		</div>
	</div>
);

export const DashboardSkeleton = () => {
	return (
		<div className="dashboard-page">
			{/* Stats Grid */}
			<div className="dashboard-stats-grid">
				<StatCardSkeleton />
				<StatCardSkeleton />
				<StatCardSkeleton />
				<StatCardSkeleton />
			</div>

			{/* Traffic & Activity Grid */}
			<div className="dashboard-page-traffic-grid">
				<div className="dashboard-page-traffic-main">
					<TrafficSkeleton />
				</div>
				<ActivitySkeleton />
			</div>

			{/* Summary Grid */}
			<div className="dashboard-page-summary-grid">
				<SummarySkeleton />
				<SummarySkeleton />
			</div>
		</div>
	);
};

export default DashboardSkeleton;
