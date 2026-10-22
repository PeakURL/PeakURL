import { History, Link2, Search, Users } from "lucide-react";

import { Button } from "@/components";
import { __, sprintf } from "@/i18n";

import type { ActivityCategory } from "../../types";

interface ActivityEmptyStateProps {
	category: ActivityCategory;
	searchQuery?: string;
	onClearSearch?: () => void;
}

export function ActivityEmptyState({
	category,
	searchQuery,
	onClearSearch,
}: ActivityEmptyStateProps) {
	if (searchQuery) {
		return (
			<div className="activity-page-empty">
				<div className="activity-page-empty-icon">
					<Search size={24} />
				</div>
				<h3 className="activity-page-empty-title">
					{__("No matching activity")}
				</h3>
				<p className="activity-page-empty-summary">
					{sprintf(
						__(
							'No activity events match "%s". Try a different search term.'
						),
						searchQuery
					)}
				</p>
				{onClearSearch ? (
					<div className="mt-4 flex justify-center">
						<Button
							variant="outline"
							size="sm"
							onClick={onClearSearch}
						>
							{__("Clear search")}
						</Button>
					</div>
				) : null}
			</div>
		);
	}

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
