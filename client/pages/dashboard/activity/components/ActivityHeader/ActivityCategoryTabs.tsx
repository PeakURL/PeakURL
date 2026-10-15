import { History, Link2, Users } from "lucide-react";

import { PageSizeControl } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";

import type { ActivityCategory } from "../../types";

interface ActivityCategoryTabsProps {
	category: ActivityCategory;
	onCategoryChange: (category: ActivityCategory) => void;
	limit: number;
	onLimitChange: (limit: number) => void;
}

export function ActivityCategoryTabs({
	category,
	onCategoryChange,
	limit,
	onLimitChange,
}: ActivityCategoryTabsProps) {
	const categoryOptions = [
		{
			value: "all" as const,
			label: __("All"),
			icon: History,
		},
		{
			value: "links" as const,
			label: __("Links"),
			icon: Link2,
		},
		{
			value: "users" as const,
			label: __("Users"),
			icon: Users,
		},
	];

	return (
		<div className="activity-page-toolbar">
			<div className="activity-page-filters" role="tablist">
				{categoryOptions.map((option) => {
					const Icon = option.icon;

					return (
						<button
							key={option.value}
							type="button"
							role="tab"
							aria-selected={option.value === category}
							aria-label={
								option.value === "all"
									? __("All Events")
									: option.label
							}
							onClick={() => onCategoryChange(option.value)}
							className={cn(
								"activity-page-filter",
								option.value === category &&
									"activity-page-filter-active"
							)}
						>
							<Icon size={14} className="shrink-0" />
							<span>{option.label}</span>
						</button>
					);
				})}
			</div>

			<PageSizeControl
				value={limit}
				onChange={onLimitChange}
				className="activity-page-page-size"
				ariaLabel={__("Rows per page")}
			/>
		</div>
	);
}
