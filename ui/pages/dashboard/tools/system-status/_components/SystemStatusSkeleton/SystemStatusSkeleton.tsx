import { SkeletonLoader } from "@/components";

export function SystemStatusSkeleton() {
	return (
		<div className="system-status-skeleton">
			{/* Hero Skeleton */}
			<div className="system-status-skeleton-hero">
				<div className="system-status-skeleton-hero-copy">
					<SkeletonLoader className="system-status-skeleton-badge" />
					<SkeletonLoader className="system-status-skeleton-title" />
					<SkeletonLoader className="system-status-skeleton-summary" />
				</div>
				<SkeletonLoader className="system-status-skeleton-refresh" />
			</div>

			{/* Operational Status Banner Skeleton */}
			<div className="system-status-skeleton-banner">
				<div className="system-status-skeleton-banner-main">
					<SkeletonLoader className="system-status-skeleton-banner-beacon" />
					<div className="system-status-skeleton-banner-copy">
						<SkeletonLoader className="system-status-skeleton-banner-title" />
						<SkeletonLoader className="system-status-skeleton-banner-description" />
					</div>
				</div>
				<div className="system-status-skeleton-banner-footer">
					<div className="system-status-skeleton-banner-pills">
						<SkeletonLoader className="system-status-skeleton-banner-pill" />
						<SkeletonLoader className="system-status-skeleton-banner-pill" />
					</div>
					<SkeletonLoader className="system-status-skeleton-banner-timestamp" />
				</div>
			</div>

			{/* Subsystems Component Grid Skeleton */}
			<div className="system-status-skeleton-subsystems">
				<div className="system-status-skeleton-subsystems-header">
					<SkeletonLoader className="system-status-skeleton-subsystems-title" />
					<SkeletonLoader className="system-status-skeleton-subsystems-count" />
				</div>
				<div className="system-status-skeleton-subsystems-grid">
					{Array.from({ length: 6 }).map((_, index) => (
						<div
							key={index}
							className="system-status-skeleton-subsystem-card"
						>
							<SkeletonLoader className="system-status-skeleton-subsystem-icon" />
							<div className="system-status-skeleton-subsystem-info">
								<SkeletonLoader className="system-status-skeleton-subsystem-name" />
								<SkeletonLoader className="system-status-skeleton-subsystem-meta" />
							</div>
							<SkeletonLoader className="system-status-skeleton-subsystem-badge" />
						</div>
					))}
				</div>
			</div>

			{/* Toolbar Tabs Skeleton */}
			<div className="system-status-skeleton-toolbar">
				<div className="system-status-skeleton-tabs">
					<SkeletonLoader className="system-status-skeleton-tab" />
					<SkeletonLoader className="system-status-skeleton-tab" />
				</div>
			</div>

			{/* Panel & List Skeleton */}
			<div className="system-status-skeleton-panel">
				<div className="system-status-skeleton-panel-header">
					<SkeletonLoader className="system-status-skeleton-section-title" />
					<SkeletonLoader className="system-status-skeleton-section-summary" />
				</div>
				<div className="system-status-skeleton-list">
					{Array.from({ length: 4 }).map((_, index) => (
						<div key={index} className="system-status-skeleton-row">
							<SkeletonLoader className="system-status-skeleton-row-label" />
							<SkeletonLoader className="system-status-skeleton-row-badge" />
						</div>
					))}
				</div>
			</div>
		</div>
	);
}

export default SystemStatusSkeleton;
