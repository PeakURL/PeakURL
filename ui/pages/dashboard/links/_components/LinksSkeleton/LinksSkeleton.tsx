import { Skeleton } from '@/components';

const LinkStatSkeleton = () => (
	<div className="skeleton-card-sm">
		<div className="skeleton-card-header">
			<Skeleton className="links-skeleton-stat-icon" />
			<Skeleton className="links-skeleton-stat-trend" />
		</div>
		<Skeleton className="links-skeleton-stat-value" />
		<Skeleton className="links-skeleton-stat-label" />
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
