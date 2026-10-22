import { Search, Users } from "lucide-react";

import { Button } from "@/components";
import { __, sprintf } from "@/i18n";

interface UsersEmptyStateProps {
	searchQuery?: string;
	onClearSearch?: () => void;
}

export function UsersEmptyState({
	searchQuery,
	onClearSearch,
}: UsersEmptyStateProps = {}) {
	if (searchQuery) {
		return (
			<div className="users-page-panel-state">
				<div className="users-page-empty-icon">
					<Search size={24} />
				</div>
				<h3 className="users-page-empty-title">
					{__("No matching users")}
				</h3>
				<p className="users-page-empty-summary">
					{sprintf(
						__(
							'No accounts match "%s". Try a different search term.'
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
