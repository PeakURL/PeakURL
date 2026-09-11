import { UserRound } from "lucide-react";

import { __ } from "@/i18n";

export function UsersEmptyState() {
	return (
		<div className="users-page-empty">
			<div className="users-page-empty-icon">
				<UserRound size={24} />
			</div>
			<p className="users-page-empty-title">{__("No users found")}</p>
			<p className="users-page-empty-summary">
				{__(
					"Add a user account to manage site links and administration."
				)}
			</p>
		</div>
	);
}
