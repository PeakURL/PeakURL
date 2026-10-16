import {
	PageHeader,
	RefreshButton,
	Select,
	type SelectOption,
} from "@/components";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";

import type { HeaderProps } from "../types";

const Header = ({
	timeRange,
	onTimeRangeChange,
	onRefresh,
	isRefreshing = false,
	canViewAnalytics = true,
}: HeaderProps) => {
	const timeRangeOptions: SelectOption<number>[] = [
		{ value: 7, label: __("Last 7 days") },
		{ value: 30, label: __("Last 30 days") },
		{ value: 90, label: __("Last 90 days") },
	];

	return (
		<PageHeader
			icon={dashboardPageIcons["overview"]}
			title={__("Dashboard")}
			description={__(
				"Welcome back! Here's what's happening with your links."
			)}
			actions={
				<div className="dashboard-overview-header-actions">
					<RefreshButton
						onRefresh={onRefresh}
						isRefreshing={isRefreshing}
						label={__("Refresh dashboard data")}
					/>

					{canViewAnalytics && (
						<Select
							value={timeRange}
							onChange={onTimeRangeChange}
							options={timeRangeOptions}
							ariaLabel={__("Dashboard time range")}
							className="dashboard-overview-header-select"
						/>
					)}
				</div>
			}
		/>
	);
};

export default Header;
