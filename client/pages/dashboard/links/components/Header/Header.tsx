import { RefreshCw } from "lucide-react";

import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";
import { PageHeader } from "@/components";
import { cn } from "@/shared/formatting";

import type { LinksHeaderProps } from "../types";
import DateRangeFilter from "./DateRangeFilter";

const Header = ({
	onRefresh,
	isRefreshing = false,
	clickRange,
	customClickRange,
	onClickRangeChange,
	onCustomClickRangeChange,
	canViewLinks = true,
	canViewAnalytics = true,
}: LinksHeaderProps) => {
	const refreshButton = (
		<button
			type="button"
			onClick={onRefresh}
			disabled={isRefreshing}
			className="page-header-icon-button"
			aria-label={__("Refresh links")}
			title={__("Refresh links")}
		>
			<RefreshCw className={cn(isRefreshing && "animate-spin")} />
		</button>
	);

	return (
		<PageHeader
			icon={dashboardPageIcons["links"]}
			title={__("All Links")}
			description={__("Manage and track your shortened URLs")}
			actions={
				<div className="links-header-actions">
					{canViewLinks && refreshButton}
					{canViewLinks && canViewAnalytics && (
						<DateRangeFilter
							clickRange={clickRange}
							customClickRange={customClickRange}
							onClickRangeChange={onClickRangeChange}
							onCustomClickRangeChange={onCustomClickRangeChange}
						/>
					)}
				</div>
			}
		/>
	);
};

export default Header;
