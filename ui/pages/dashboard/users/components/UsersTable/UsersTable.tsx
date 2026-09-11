import { __, sprintf } from "@/i18n";
import { formatCount } from "@/shared/formatting";

import { UsersTableSkeletonRows } from "../UsersSkeleton";
import { UserRow } from "./UserRow";
import { UsersEmptyState } from "./UsersEmptyState";
import type { UsersTableProps } from "../../types";

export function UsersTable({
	users,
	currentUser,
	onEditUser,
	onDeleteUser,
	isDeleting,
	isLoading = false,
}: UsersTableProps) {
	const hasUsers = users.length > 0;

	return (
		<div className="users-page-table-card">
			<div className="users-page-table-header">
				<h2 className="users-page-table-title">{__("All Users")}</h2>
				<span className="users-page-table-count">
					{sprintf(__("%s total"), formatCount(users.length))}
				</span>
			</div>

			<div className="users-page-table-wrapper">
				<table className="users-page-table">
					<thead className="users-page-thead">
						<tr>
							<th className="users-page-th users-page-th-user">
								{__("User")}
							</th>
							<th className="users-page-th users-page-th-email">
								{__("Email")}
							</th>
							<th className="users-page-th users-page-th-role">
								{__("Role")}
							</th>
							<th className="users-page-th users-page-th-date">
								{__("Registered")}
							</th>
							<th className="users-page-th users-page-th-actions">
								<span className="sr-only">{__("Actions")}</span>
							</th>
						</tr>
					</thead>
					<tbody className="users-page-tbody">
						{isLoading ? (
							<UsersTableSkeletonRows />
						) : hasUsers ? (
							users.map((user) => (
								<UserRow
									key={user.id}
									user={user}
									currentUser={currentUser}
									onEdit={onEditUser}
									onDelete={onDeleteUser}
									isDeleting={isDeleting}
								/>
							))
						) : (
							<tr>
								<td colSpan={5} className="p-0">
									<UsersEmptyState />
								</td>
							</tr>
						)}
					</tbody>
				</table>
			</div>
		</div>
	);
}
