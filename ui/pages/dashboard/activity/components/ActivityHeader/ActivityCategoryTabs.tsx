import { History, Link2, Users } from "lucide-react";

import { PageSizeControl } from "@/components";
import { __ } from "@/i18n";
import { formatCount } from "@/utils";

import type { ActivityCategory, ActivitySummaryCounts } from "../../types";

interface ActivityCategoryTabsProps {
	category: ActivityCategory;
	onCategoryChange: (category: ActivityCategory) => void;
	limit: number;
	onLimitChange: (limit: number) => void;
	summaryCounts: ActivitySummaryCounts;
}

export function ActivityCategoryTabs({
	category,
	onCategoryChange,
	limit,
	onLimitChange,
	summaryCounts,
}: ActivityCategoryTabsProps) {
	return (
		<div className="activity-page-toolbar">
			<div className="activity-page-filters" role="tablist">
				<button
					type="button"
					role="tab"
					aria-selected={category === "all"}
					onClick={() => onCategoryChange("all")}
					className={`activity-page-filter ${category === "all" ? "activity-page-filter-active" : ""}`}
				>
					<History className="h-3.5 w-3.5 shrink-0" />
					<span>{__("All Events")}</span>
					<span className="activity-page-filter-count">
						{formatCount(summaryCounts.all)}
					</span>
				</button>

				<button
					type="button"
					role="tab"
					aria-selected={category === "links"}
					onClick={() => onCategoryChange("links")}
					className={`activity-page-filter ${category === "links" ? "activity-page-filter-active" : ""}`}
				>
					<Link2 className="h-3.5 w-3.5 shrink-0" />
					<span>{__("Links")}</span>
					<span className="activity-page-filter-count">
						{formatCount(summaryCounts.links)}
					</span>
				</button>

				<button
					type="button"
					role="tab"
					aria-selected={category === "users"}
					onClick={() => onCategoryChange("users")}
					className={`activity-page-filter ${category === "users" ? "activity-page-filter-active" : ""}`}
				>
					<Users className="h-3.5 w-3.5 shrink-0" />
					<span>{__("Users")}</span>
					<span className="activity-page-filter-count">
						{formatCount(summaryCounts.users)}
					</span>
				</button>
			</div>

			<div className="activity-page-page-size">
				<PageSizeControl
					value={limit}
					onChange={onLimitChange}
					ariaLabel={__("Show per page")}
				/>
			</div>
		</div>
	);
}
