import { Skeleton } from "@/components";

const UserRowSkeleton = () => (
	<tr className="users-page-table-row" aria-hidden="true">
		<td className="users-page-table-cell users-page-col-user">
			<div className="users-page-user">
				<Skeleton className="avatar-md users-page-avatar" />
				<div className="users-page-user-copy">
					<Skeleton className="users-skeleton-user-name" />
					<Skeleton className="users-skeleton-user-email" />
				</div>
			</div>
		</td>
		<td className="users-page-table-cell users-page-col-role">
			<Skeleton className="users-skeleton-badge" />
		</td>
		<td className="users-page-table-cell-meta users-page-col-created">
			<Skeleton className="users-skeleton-date" />
			<Skeleton className="users-skeleton-date-sub" />
		</td>
		<td className="users-page-table-cell-actions users-page-col-actions">
			<div className="users-page-actions">
				<Skeleton className="users-skeleton-action users-page-action-button" />
				<Skeleton className="users-skeleton-action users-page-action-button" />
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
