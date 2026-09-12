import { Shield } from "lucide-react";

import { __ } from "@/i18n";

import type { ActivityCategory } from "../../types";

interface ActivityEmptyStateProps {
	category: ActivityCategory;
}

export function ActivityEmptyState({ category }: ActivityEmptyStateProps) {
	return (
		<div className="activity-page-empty">
			<div className="activity-page-empty-icon">
				<Shield size={24} />
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
