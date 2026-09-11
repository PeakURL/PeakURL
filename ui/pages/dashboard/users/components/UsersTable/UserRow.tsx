import { Pencil, Trash2 } from "lucide-react";

import { Avatar } from "@/components";
import { __ } from "@/i18n";
import { formatDate, formatLocalizedDateTime } from "@/utils";

import { getRoleMeta, getUserDisplayName } from "../../lib";
import type { UserRowProps } from "../../types";

export function UserRow({
	user,
	currentUser,
	onEdit,
	onDelete,
	isDeleting,
}: UserRowProps) {
	const roleMeta = getRoleMeta();
	const displayName = getUserDisplayName(user);
	const isSelf = user.id === currentUser?.id;
	const userRole = user.role || "editor";
	const roleInfo = roleMeta[userRole];

	return (
		<tr key={user.id} className="users-page-table-row">
			<td className="users-page-table-cell">
				<div className="users-page-user-cell">
					<Avatar
						fallbackName={displayName}
						email={user.email}
						firstName={user.firstName}
						lastName={user.lastName}
						className="h-9 w-9 shrink-0"
					/>
					<div className="users-page-user-info">
						<span className="users-page-user-name">
							{displayName}
						</span>
						<span className="users-page-user-username">
							@{user.username || "—"}
						</span>
					</div>
				</div>
			</td>
			<td className="users-page-table-cell users-page-table-cell-email">
				<span className="users-page-user-email">
					{user.email || "—"}
				</span>
			</td>
			<td className="users-page-table-cell users-page-table-cell-role">
				<span className={`users-page-role-badge ${roleInfo.badge}`}>
					{roleInfo.label}
				</span>
			</td>
			<td className="users-page-table-cell users-page-table-cell-date">
				{user.createdAt ? (
					<span
						className="users-page-table-date"
						title={formatLocalizedDateTime(user.createdAt, {
							dateStyle: "full",
							timeStyle: "short",
						})}
					>
						{formatDate(user.createdAt)}
					</span>
				) : (
					<span className="text-text-muted">—</span>
				)}
			</td>
			<td className="users-page-table-cell users-page-table-cell-actions">
				<div className="users-page-actions-group">
					<button
						type="button"
						onClick={() => onEdit(user)}
						className="users-page-action-btn users-page-action-btn-edit"
						title={__("Edit user")}
						aria-label={__("Edit user")}
					>
						<Pencil size={15} />
					</button>
					<button
						type="button"
						onClick={() => onDelete(user)}
						disabled={isSelf || isDeleting}
						className="users-page-action-btn users-page-action-btn-delete"
						title={
							isSelf
								? __("You cannot delete your own account")
								: __("Delete user")
						}
						aria-label={
							isSelf
								? __("You cannot delete your own account")
								: __("Delete user")
						}
					>
						<Trash2 size={15} />
					</button>
				</div>
			</td>
		</tr>
	);
}
