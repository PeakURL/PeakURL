import { useState } from "react";
import { ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatDateOnly } from "@/utils";
import { formatClickCount } from "./analytics";
import type { LinkBestDay, LinkStatsViewProps } from "./types";

function BestDay({ stats, isLoading }: LinkStatsViewProps) {
	const [showDetails, setShowDetails] = useState(true);
	const bestDay: LinkBestDay | null = stats?.bestDay || null;
	const bestDayLabel = bestDay
		? formatDateOnly(bestDay.date, {
				year: "numeric",
				month: "long",
				day: "numeric",
			})
		: "";

	return (
		<div className="links-best-day">
			<h3 className="links-best-day-title">{__("Best day")}</h3>

			{isLoading ? (
				<p className="links-best-day-copy">
					{__("Loading best day...")}
				</p>
			) : bestDay ? (
				<>
					<p className="links-best-day-copy">
						<span className="links-best-day-value">
							{formatClickCount(bestDay.totalClicks)}
						</span>{" "}
						{__("on")} {bestDayLabel}.{" "}
						<span className="links-best-day-unique">
							{sprintf(
								__("%s unique visitors"),
								String(bestDay.uniqueClicks)
							)}
						</span>{" "}
						<button
							type="button"
							onClick={() => setShowDetails(!showDetails)}
							className="links-best-day-toggle"
						>
							{showDetails
								? __("Hide details")
								: __("View details")}
							{showDetails ? (
								<ChevronUp className="w-3 h-3" />
							) : (
								<ChevronDown className="w-3 h-3" />
							)}
						</button>
					</p>

					{showDetails && (
						<div className="links-best-day-details">
							<div className="links-best-day-tree">
								<div className="links-best-day-tree-row">
									<span className="links-best-day-tree-dot-large"></span>
									<span className="text-sm font-medium text-heading">
										{__("Year")}{" "}
										{formatDateOnly(bestDay.date, {
											year: "numeric",
										})}
									</span>
								</div>
								<div className="links-best-day-tree-branch">
									<div className="links-best-day-tree-row">
										<span className="links-best-day-tree-dot-medium"></span>
										<span className="text-sm text-text-muted">
											{formatDateOnly(bestDay.date, {
												month: "long",
											})}
										</span>
									</div>
									<div className="links-best-day-tree-row links-best-day-tree-branch">
										<span className="links-best-day-tree-dot-small"></span>
										<span className="text-sm font-medium text-heading">
											{formatDateOnly(bestDay.date, {
												day: "numeric",
											})}
											:{" "}
											{formatClickCount(
												bestDay.totalClicks
											)}
										</span>
									</div>
								</div>
							</div>
						</div>
					)}
				</>
			) : (
				<p className="links-best-day-empty">
					{__("No click activity has been recorded yet.")}
				</p>
			)}
		</div>
	);
}

export default BestDay;
