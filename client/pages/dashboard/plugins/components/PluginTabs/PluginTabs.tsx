import { cn } from "@/shared/formatting";

import type { PluginTabsProps } from "../types";

function PluginTabs({ activeTab, onTabChange, tabs }: PluginTabsProps) {
	const getButtonClassName = (isActive: boolean) =>
		cn(
			"plugins-tabs-button",
			isActive
				? "plugins-tabs-button-active"
				: "plugins-tabs-button-inactive"
		);

	const getCountClassName = (isActive: boolean) =>
		cn(
			"plugins-tabs-count",
			isActive
				? "plugins-tabs-count-active"
				: "plugins-tabs-count-inactive"
		);

	return (
		<div className="plugins-tabs" role="tablist">
			{tabs.map((tab) => {
				const isActive = activeTab === tab.id;
				return (
					<button
						key={tab.id}
						type="button"
						role="tab"
						aria-selected={isActive}
						onClick={() => onTabChange(tab.id)}
						className={getButtonClassName(isActive)}
					>
						<span className="plugins-tabs-button-content">
							{tab.label}
							{typeof tab.count === "number" && (
								<span className={getCountClassName(isActive)}>
									{tab.count}
								</span>
							)}
						</span>
						{/* Active indicator bar */}
						{isActive && (
							<span className="plugins-tabs-indicator" />
						)}
					</button>
				);
			})}
		</div>
	);
}

export default PluginTabs;
