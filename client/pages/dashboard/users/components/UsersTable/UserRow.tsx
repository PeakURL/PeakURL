import { Pencil, Trash2 } from "lucide-react";

import { Avatar } from "@/components";
import { __, sprintf } from "@/i18n";
import { getDocumentDirection } from "@/i18n/direction";
import { cn, formatDate } from "@/shared/formatting";
import { formatLocalizedDateTime } from "@/shared/dates";

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
	const displayName =
		user.displayName ||
		`${user.firstName || ""} ${user.lastName || ""}`.trim() ||
		user.username ||
		__("User");
	const isSelf = Boolean(currentUser?.id && user.id === currentUser.id);
	const userRole = user.role === "admin" ? "admin" : "editor";
	const roleInfo = roleMeta[userRole];
	const direction = getDocumentDirection();

	return (
		<tr key={user.id} className="users-page-table-row">
			<td className="users-page-table-cell">
				<div dir={direction} className="users-page-user">
					<Avatar
						size="md"
						email={user.email}
						firstName={user.firstName}
						lastName={user.lastName}
						fallbackName={user.username || __("User")}
						className="users-page-avatar"
					/>
					<div className="users-page-user-copy">
						<div className="users-page-user-name">
							<span
								dir="auto"
								className="font-semibold text-heading text-sm"
							>
								{displayName}
							</span>
							{isSelf ? (
								<span className="users-page-self-badge">
									{__("You")}
								</span>
							) : null}
						</div>
						<div className="users-page-user-meta">
							<span
								className="users-page-user-username"
								dir="ltr"
							>
								@{user.username}
							</span>
							<span className="users-page-user-dot">•</span>
							<span className="users-page-user-email" dir="ltr">
								{user.email}
							</span>
						</div>
					</div>
				</div>
			</td>
			<td className="users-page-table-cell">
				<span className={cn("users-page-role-badge", roleInfo.badge)}>
					{roleInfo.label}
				</span>
			</td>
			<td className="users-page-table-cell-meta">
				<span className="users-page-date-primary" dir="auto">
					{user.createdAt
						? formatDate(user.createdAt)
						: __("Unknown")}
				</span>
				{user.createdAt ? (
					<span className="users-page-date-exact" dir="auto">
						{formatLocalizedDateTime(user.createdAt, {
							dateStyle: "medium",
						})}
					</span>
				) : null}
			</td>
			<td className="users-page-table-cell-actions">
				<div className="users-page-actions">
					<button
						type="button"
						onClick={() => onEdit(user)}
						className="users-page-action-button users-page-action-btn-edit"
						aria-label={sprintf(
							__("Edit %s"),
							getUserDisplayName(user)
						)}
						title={sprintf(__("Edit %s"), getUserDisplayName(user))}
					>
						<Pencil size={14} />
					</button>
					<button
						type="button"
						onClick={() => onDelete(user)}
						disabled={!currentUser?.id || isSelf || isDeleting}
						className={cn(
							"users-page-action-button users-page-action-delete users-page-action-btn-delete",
							(!currentUser?.id || isSelf || isDeleting) &&
								"pointer-events-none cursor-not-allowed opacity-30"
						)}
						aria-label={
							isSelf
								? __("Cannot delete your own account")
								: sprintf(
										__("Delete %s"),
										getUserDisplayName(user)
									)
						}
						title={
							isSelf
								? __("Cannot delete your own account")
								: sprintf(
										__("Delete %s"),
										getUserDisplayName(user)
									)
						}
					>
						<Trash2 size={14} />
					</button>
				</div>
			</td>
		</tr>
	);
}
