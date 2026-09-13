import { Skeleton } from "@/components";

export const UsersOverviewSkeleton = () => (
	<div className="users-page-overview-grid">
		{Array.from({ length: 4 }).map((_, index) => (
			<div key={index} className="users-page-overview-item">
				<div className="users-page-overview-header">
					<div className="users-page-overview-copy">
						<Skeleton className="users-skeleton-stat-label" />
						<Skeleton className="users-skeleton-stat-value" />
					</div>
					<Skeleton className="users-skeleton-stat-icon" />
				</div>
				<Skeleton className="users-skeleton-stat-note" />
			</div>
		))}
	</div>
);

const UserRowSkeleton = () => (
	<tr className="users-page-table-row">
		<td className="users-page-table-cell">
			<div className="users-skeleton-user">
				<Skeleton className="users-skeleton-user-avatar" />
				<div className="users-skeleton-user-copy">
					<Skeleton className="users-skeleton-user-name" />
					<Skeleton className="users-skeleton-user-email" />
				</div>
			</div>
		</td>
		<td className="users-page-table-cell">
			<Skeleton className="users-skeleton-badge" />
		</td>
		<td className="users-page-table-cell-meta">
			<Skeleton className="users-skeleton-date" />
			<Skeleton className="users-skeleton-date-sub" />
		</td>
		<td className="users-page-table-cell-actions">
			<div className="users-skeleton-actions">
				<Skeleton className="users-skeleton-action" />
				<Skeleton className="users-skeleton-action" />
			</div>
		</td>
	</tr>
);

interface UsersTableSkeletonRowsProps {
	rowCount?: number;
}

export const UsersTableSkeletonRows = ({
	rowCount = 4,
}: UsersTableSkeletonRowsProps) => {
	return Array.from({ length: rowCount }).map((_, index) => (
		<UserRowSkeleton key={index} />
	));
};

export default UsersTableSkeletonRows;
