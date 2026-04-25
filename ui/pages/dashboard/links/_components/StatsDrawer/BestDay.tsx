import { useState } from "react";
import { ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime } from "@/utils";
import type { LinkStatsViewProps } from "./types";

function BestDay({ link }: Pick<LinkStatsViewProps, "link">) {
	const [showDetails, setShowDetails] = useState(true);
	const totalClicks = Number(link.clicks || 0);

	return (
		<div className="links-best-day">
			<h3 className="links-best-day-title">{__("Best day")}</h3>
			<p className="links-best-day-copy">
				<span className="links-best-day-value">
					{sprintf(__("%s hits"), String(totalClicks))}
				</span>{" "}
				{__("on")}{" "}
				{formatLocalizedDateTime(new Date(), {
					year: "numeric",
					month: "long",
					day: "numeric",
				})}
				.{" "}
				<button
					onClick={() => setShowDetails(!showDetails)}
					className="links-best-day-toggle"
				>
					{showDetails ? __("Hide details") : __("View details")}
					{showDetails ? (
						<ChevronUp className="w-3 h-3" />
					) : (
						<ChevronDown className="w-3 h-3" />
					)}
				</button>
			</p>

			{/* Year/Month/Day Breakdown */}
			{showDetails && (
				<div className="links-best-day-details">
					<div className="links-best-day-tree">
						<div className="links-best-day-tree-row">
							<span className="links-best-day-tree-dot-large"></span>
							<span className="text-sm font-medium text-heading">
								{__("Year")} {new Date().getFullYear()}
							</span>
						</div>
						<div className="links-best-day-tree-branch">
							<div className="links-best-day-tree-row">
								<span className="links-best-day-tree-dot-medium"></span>
								<span className="text-sm text-text-muted">
									{formatLocalizedDateTime(new Date(), {
										month: "long",
									})}
								</span>
							</div>
							<div className="links-best-day-tree-row links-best-day-tree-branch">
								<span className="links-best-day-tree-dot-small"></span>
								<span className="text-sm font-medium text-heading">
									{formatLocalizedDateTime(new Date(), {
										day: "numeric",
									})}
									:{" "}
									{sprintf(
										__("%s hits"),
										String(totalClicks)
									)}
								</span>
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
}

export default BestDay;
