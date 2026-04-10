import { Skeleton } from '@/components';

const UserRowSkeleton = () => (
	<tr>
		<td className="users-page-table-cell">
			<div className="users-skeleton-user">
				<Skeleton className="users-skeleton-user-avatar" />
				<div className="users-skeleton-user-copy">
					<Skeleton className="users-skeleton-user-name" />
					<Skeleton className="users-skeleton-user-email" />
					<Skeleton className="users-skeleton-user-role" />
				</div>
			</div>
		</td>
		<td className="users-page-table-cell">
			<Skeleton className="users-skeleton-badge" />
		</td>
		<td className="users-page-table-cell-meta">
			<Skeleton className="users-skeleton-date" />
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
