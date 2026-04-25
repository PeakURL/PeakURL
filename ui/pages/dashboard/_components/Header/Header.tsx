import { RefreshCw } from "lucide-react";
import { Select, type SelectOption } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import type { HeaderProps } from "../types";

const Header = ({
	timeRange,
	onTimeRangeChange,
	onRefresh,
	isRefreshing = false,
}: HeaderProps) => {
	const timeRangeOptions: SelectOption<number>[] = [
		{ value: 7, label: __("Last 7 days") },
		{ value: 30, label: __("Last 30 days") },
		{ value: 90, label: __("Last 90 days") },
	];

	return (
		<div className="dashboard-overview-header">
			<div className="dashboard-overview-header-copy">
				<h1 className="dashboard-overview-header-title">
					{__("Dashboard")}
				</h1>
				<p className="dashboard-overview-header-summary">
					{__(
						"Welcome back! Here's what's happening with your links."
					)}
				</p>
			</div>
			<div className="dashboard-overview-header-actions">
				<button
					type="button"
					onClick={onRefresh}
					disabled={isRefreshing}
					className="dashboard-page-refresh"
					aria-label={__("Refresh dashboard data")}
					title={__("Refresh dashboard data")}
				>
					<RefreshCw
						className={cn(
							"dashboard-page-refresh-icon",
							isRefreshing && "animate-spin"
						)}
					/>
				</button>
				<Select
					value={timeRange}
					onChange={onTimeRangeChange}
					options={timeRangeOptions}
					ariaLabel={__("Dashboard time range")}
					className="dashboard-overview-header-select"
					buttonClassName="dashboard-overview-header-select-button"
				/>
			</div>
		</div>
	);
};

export default Header;
