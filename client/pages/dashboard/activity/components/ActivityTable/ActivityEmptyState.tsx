import { History, Link2, Users } from "lucide-react";

import { __ } from "@/i18n";

import type { ActivityCategory } from "../../types";

interface ActivityEmptyStateProps {
	category: ActivityCategory;
}

export function ActivityEmptyState({ category }: ActivityEmptyStateProps) {
	const Icon =
		category === "users" ? Users : category === "links" ? Link2 : History;
	return (
		<div className="activity-page-empty">
			<div className="activity-page-empty-icon">
				<Icon size={24} />
			</div>
			<h3 className="activity-page-empty-title">
				{"users" === category
					? __("No user activity yet")
					: "links" === category
						? __("No link activity yet")
						: __("No activity recorded yet")}
			</h3>
			<p className="activity-page-empty-summary">
				{__(
					"Once users manage accounts or links change, the audit log will appear here."
				)}
			</p>
		</div>
	);
}
