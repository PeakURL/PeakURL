import {
	PageHeader,
	RefreshButton,
	Select,
	type SelectOption,
} from "@/components";
import { useAdminAccess } from "@/hooks";
import { getOverviewGreeting } from "@/pages/dashboard/lib/getGreeting";
import { __ } from "@/i18n";

import type { HeaderProps } from "../types";

const Header = ({
	timeRange,
	onTimeRangeChange,
	onRefresh,
	isRefreshing = false,
	canViewAnalytics = true,
}: HeaderProps) => {
	const { user } = useAdminAccess();
	const greeting = getOverviewGreeting({
		firstName: user?.firstName,
		displayName: user?.displayName,
	});
	const timeRangeOptions: SelectOption<number>[] = [
		{ value: 7, label: __("Last 7 days") },
		{ value: 30, label: __("Last 30 days") },
		{ value: 90, label: __("Last 90 days") },
	];

	return (
		<PageHeader
			title={greeting}
			description={__("Here's an overview of your link activity.")}
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
