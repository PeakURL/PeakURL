import { Link2, Search, Trash2 } from "lucide-react";

import { __, sprintf } from "@/i18n";

interface EmptyStateProps {
	isSearchActive?: boolean;
	searchQuery?: string;
	isTrashTab?: boolean;
}

function EmptyState({
	isSearchActive = false,
	searchQuery = "",
	isTrashTab = false,
}: EmptyStateProps) {
	if (isSearchActive) {
		return (
			<div className="links-empty-state">
				<div className="links-empty-state-icon-wrap">
					<Search className="h-7 w-7 text-accent" />
				</div>
				<h3 className="links-empty-state-title">
					{__("No links found")}
				</h3>
				<p className="links-empty-state-description">
					{sprintf(__('No links matched "%s".'), searchQuery)}
				</p>
			</div>
		);
	}

	if (isTrashTab) {
		return (
			<div className="links-empty-state">
				<div className="links-empty-state-icon-wrap">
					<Trash2 className="h-7 w-7 text-accent" />
				</div>
				<h3 className="links-empty-state-title">
					{__("Trash is empty")}
				</h3>
				<p className="links-empty-state-description">
					{__("No deleted links found in trash.")}
				</p>
			</div>
		);
	}

	return (
		<div className="links-empty-state">
			<div className="links-empty-state-icon-wrap">
				<Link2 className="h-7 w-7 text-accent" />
			</div>
			<h3 className="links-empty-state-title">{__("No links yet")}</h3>
			<p className="links-empty-state-description">
				{__("Create your first shortened link to get started")}
			</p>
		</div>
	);
}

export default EmptyState;
