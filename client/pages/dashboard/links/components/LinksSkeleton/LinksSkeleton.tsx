import { Skeleton } from "@/components";

const LinkStatSkeleton = () => (
	<div className="skeleton-card links-skeleton-stat-card">
		<div className="skeleton-card-header links-skeleton-stat-header">
			<div className="flex-1 space-y-1">
				<Skeleton className="links-skeleton-stat-title" />
				<Skeleton className="links-skeleton-stat-value" />
			</div>
			<Skeleton className="links-skeleton-stat-icon" />
		</div>
		<div className="links-skeleton-stat-footer">
			<Skeleton className="links-skeleton-stat-trend" />
		</div>
	</div>
);

const LinkRowSkeleton = () => (
	<div className="skeleton-table-row">
		<Skeleton className="links-skeleton-row-avatar" />
		<div className="links-skeleton-row-copy">
			<Skeleton className="links-skeleton-row-title" />
			<Skeleton className="links-skeleton-row-meta" />
		</div>
		<div className="links-skeleton-row-metrics">
			<Skeleton className="links-skeleton-row-metric" />
			<Skeleton className="links-skeleton-row-metric" />
		</div>
		<Skeleton className="links-skeleton-row-action" />
	</div>
);

export const LinksSkeleton = () => {
	return (
		<div className="links-page">
			{/* Stats Grid */}
			<div className="links-page-stats">
				{[1, 2, 3, 4].map((i) => (
					<LinkStatSkeleton key={i} />
				))}
			</div>

			{/* Shortening Form Placeholder */}
			<div className="skeleton-form">
				<Skeleton className="links-skeleton-form-title" />
				<Skeleton className="links-skeleton-form-field" />
			</div>

			{/* Table Placeholder */}
			<div className="skeleton-table-container">
				<Skeleton className="links-skeleton-table-header" />
				<div>
					{[1, 2, 3, 4, 5].map((i) => (
						<LinkRowSkeleton key={i} />
					))}
				</div>
			</div>
		</div>
	);
};

export default LinksSkeleton;
