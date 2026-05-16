import { Link2, RefreshCw } from "lucide-react";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import type { LinksHeaderProps } from "../types";
import DateRangeFilter from "./DateRangeFilter";

const Header = ({
	onRefresh,
	isRefreshing = false,
	clickRange,
	customClickRange,
	onClickRangeChange,
	onCustomClickRangeChange,
}: LinksHeaderProps) => {
	const refreshButton = (
		<button
			type="button"
			onClick={onRefresh}
			disabled={isRefreshing}
			className="links-header-refresh"
			aria-label={__("Refresh links")}
			title={__("Refresh links")}
		>
			<RefreshCw
				className={cn(
					"links-header-refresh-icon",
					isRefreshing && "animate-spin"
				)}
			/>
		</button>
	);

	return (
		<div className="links-header">
			<div className="links-header-brand">
				<div className="links-header-brand-icon">
					<Link2 className="h-5 w-5 text-white" />
				</div>
				<div className="links-header-brand-copy">
					<h1 className="links-header-title">{__("Links")}</h1>
					<p className="links-header-description">
						{__("Manage and track your shortened URLs")}
					</p>
				</div>
			</div>

			<div className="links-header-actions">
				{refreshButton}
				<DateRangeFilter
					clickRange={clickRange}
					customClickRange={customClickRange}
					onClickRangeChange={onClickRangeChange}
					onCustomClickRangeChange={onCustomClickRangeChange}
				/>
			</div>
		</div>
	);
};

export default Header;
