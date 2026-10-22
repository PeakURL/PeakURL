import { useMemo, useState } from "react";

import { SearchInput } from "@/components";
import { __, sprintf } from "@/i18n";
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
	const [searchQuery, setSearchQuery] = useState("");
	const hasUsers = users.length > 0;

	const filteredUsers = useMemo(() => {
		const q = searchQuery.trim().toLowerCase();
		if (!q) return users;
		return users.filter((user) => {
			if (user.username?.toLowerCase().includes(q)) return true;
			if (user.email?.toLowerCase().includes(q)) return true;
			if (user.displayName?.toLowerCase().includes(q)) return true;
			if (user.firstName?.toLowerCase().includes(q)) return true;
			if (user.lastName?.toLowerCase().includes(q)) return true;
			if (user.role?.toLowerCase().includes(q)) return true;
			if (user.roleName?.toLowerCase().includes(q)) return true;
			return false;
		});
	}, [users, searchQuery]);

	const isSearchActive = Boolean(searchQuery.trim());
	const hasFilteredUsers = filteredUsers.length > 0;

	return (
		<div className="users-page-panel">
			<div className="users-page-panel-header">
				<div className="users-page-panel-header-main">
					<h2 className="users-page-panel-title">{__("Accounts")}</h2>
					{!isLoading && hasUsers ? (
						<span className="users-page-panel-count">
							{isSearchActive
								? sprintf(
										__("%s of %s"),
										formatCount(filteredUsers.length),
										formatCount(users.length)
									)
								: formatCount(users.length)}
						</span>
					) : null}
				</div>

				{!isLoading && (hasUsers || isSearchActive) ? (
					<div className="w-full sm:w-64">
						<SearchInput
							value={searchQuery}
							onChange={setSearchQuery}
							placeholder={__("Search users...")}
							aria-label={__("Search users")}
						/>
					</div>
				) : null}
			</div>

			{usersError ? (
				<div className="users-page-panel-error">
					{getErrorMessage(usersError, __("Unable to load users."))}
				</div>
			) : !isLoading && !hasFilteredUsers ? (
				<UsersEmptyState
					searchQuery={isSearchActive ? searchQuery : undefined}
					onClearSearch={() => setSearchQuery("")}
				/>
			) : (
				<div className="users-page-table-scroll">
					<table className="users-page-table">
						<thead className="users-page-table-head">
							<tr className="users-page-table-head-row">
								<th className="users-page-table-heading users-page-col-user">
									{__("User")}
								</th>
								<th className="users-page-table-heading users-page-col-role">
									{__("Role")}
								</th>
								<th className="users-page-table-heading users-page-col-created">
									{__("Created")}
								</th>
								<th className="users-page-table-heading-end users-page-col-actions">
									{__("Actions")}
								</th>
							</tr>
						</thead>
						<tbody className="users-page-table-body">
							{isLoading ? (
								<UsersTableSkeletonRows />
							) : (
								filteredUsers.map((user) => (
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
