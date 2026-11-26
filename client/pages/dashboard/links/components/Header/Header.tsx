import { PageHeader, RefreshButton } from "@/components";
import { __ } from "@/i18n";

import { PluginActions } from "../PluginActions";
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
	return (
		<PageHeader
			title={__("All Links")}
			description={__("Manage and track your shortened URLs")}
			actions={
				<div className="links-header-actions">
					<PluginActions
						location="links_overview_actions"
						className="contents"
					/>
					{canViewLinks && (
						<RefreshButton
							onRefresh={onRefresh}
							isRefreshing={isRefreshing}
							label={__("Refresh links")}
						/>
					)}
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
