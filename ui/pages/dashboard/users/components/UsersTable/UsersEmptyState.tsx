import { Users } from "lucide-react";

import { __ } from "@/i18n";

export function UsersEmptyState() {
	return (
		<div className="users-page-panel-state">
			<div className="users-page-empty-icon">
				<Users size={24} />
			</div>
			<h3 className="users-page-empty-title">{__("No users yet")}</h3>
			<p className="users-page-empty-summary">
				{__("Add an admin or editor account to start sharing access.")}
			</p>
		</div>
	);
}
