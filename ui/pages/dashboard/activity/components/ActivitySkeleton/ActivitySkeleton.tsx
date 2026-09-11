import { Skeleton } from "@/components";

export function ActivitySkeleton() {
	return (
		<div className="activity-page">
			{/* Hero Skeleton */}
			<div className="activity-page-hero">
				<div className="activity-page-hero-copy">
					<Skeleton className="mb-2 h-4 w-24 rounded-full" />
					<Skeleton className="h-8 w-48" />
					<Skeleton className="mt-2 h-4 w-96 max-w-full" />
				</div>
			</div>

			{/* Metric Cards Skeleton */}
			<div className="activity-page-overview">
				<div className="activity-page-overview-grid">
					{Array.from({ length: 4 }).map((_, i) => (
						<div key={i} className="activity-page-overview-item">
							<div className="activity-page-overview-header">
								<div className="activity-page-overview-copy">
									<Skeleton className="mb-2 h-3 w-20" />
									<Skeleton className="h-7 w-16" />
								</div>
								<Skeleton className="h-10 w-10 rounded-lg" />
							</div>
							<Skeleton className="h-3 w-32" />
						</div>
					))}
				</div>
			</div>

			{/* Toolbar Skeleton */}
			<div className="activity-page-toolbar">
				<Skeleton className="h-10 w-72 rounded-xl" />
				<Skeleton className="h-9 w-36 rounded-lg" />
			</div>

			{/* Table Panel Skeleton */}
			<div className="activity-page-panel">
				<div className="activity-page-panel-header">
					<Skeleton className="h-5 w-36" />
				</div>
				<div className="activity-page-panel-content">
					<div className="activity-page-table-wrapper">
						<div className="activity-page-events-list">
							{Array.from({ length: 6 }).map((_, i) => (
								<div key={i} className="activity-page-event">
									<Skeleton className="h-9 w-9 rounded-lg shrink-0" />
									<div className="activity-page-event-body space-y-2">
										<Skeleton className="h-4 w-3/4" />
										<Skeleton className="h-3 w-1/2" />
									</div>
									<div className="activity-page-event-time">
										<Skeleton className="h-3 w-16" />
									</div>
								</div>
							))}
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}
