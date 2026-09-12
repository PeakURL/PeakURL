import { __ } from "@/i18n";
import { formatCount } from "@/shared/formatting";
import { getErrorMessage } from "@/shared/errors";

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
	usersError,
}: UsersTableProps) {
	const hasUsers = users.length > 0;

	return (
		<div className="users-page-panel">
			<div className="users-page-panel-header">
				<div className="users-page-panel-header-main">
					<h2 className="users-page-panel-title">{__("Accounts")}</h2>
					{!isLoading && hasUsers ? (
						<span className="users-page-panel-count">
							{formatCount(users.length)}
						</span>
					) : null}
				</div>
			</div>

			{usersError ? (
				<div className="users-page-panel-error">
					{getErrorMessage(usersError, __("Unable to load users."))}
				</div>
			) : !isLoading && !hasUsers ? (
				<UsersEmptyState />
			) : (
				<div className="users-page-table-scroll">
					<table className="users-page-table">
						<thead className="users-page-table-head">
							<tr className="users-page-table-head-row">
								<th className="users-page-table-heading">
									{__("User")}
								</th>
								<th className="users-page-table-heading">
									{__("Role")}
								</th>
								<th className="users-page-table-heading">
									{__("Created")}
								</th>
								<th className="users-page-table-heading-end">
									{__("Actions")}
								</th>
							</tr>
						</thead>
						<tbody className="users-page-table-body">
							{isLoading ? (
								<UsersTableSkeletonRows />
							) : (
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
							)}
						</tbody>
					</table>
				</div>
			)}
		</div>
	);
}
