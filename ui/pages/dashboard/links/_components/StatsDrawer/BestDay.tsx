import { useState } from "react";
import { ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatDateOnly } from "@/utils";
import DetailMetric from "./DetailMetric";
import { formatClickCount, formatUniqueVisitorCount } from "./analytics";
import type { LinkBestDay, LinkStatsViewProps } from "./types";

function BestDay({ stats, isLoading }: LinkStatsViewProps) {
	const [showDetails, setShowDetails] = useState(true);
	const bestDay: LinkBestDay | null = stats?.bestDay || null;
	const bestDayDate = bestDay
		? formatDateOnly(bestDay.date, {
				year: "numeric",
				month: "long",
				day: "numeric",
			})
		: "";
	const bestDayYear = bestDay
		? formatDateOnly(bestDay.date, {
				year: "numeric",
			})
		: "";
	const bestDayMonth = bestDay
		? formatDateOnly(bestDay.date, {
				month: "long",
			})
		: "";
	const bestDayDay = bestDay
		? formatDateOnly(bestDay.date, {
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
						{__("on")} {bestDayDate}.{" "}
						<span className="links-best-day-unique">
							{formatUniqueVisitorCount(bestDay.uniqueClicks)}
						</span>{" "}
						<button
							type="button"
							onClick={() =>
								setShowDetails((isShowing) => !isShowing)
							}
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
						<div className="links-detail-list">
							<section className="links-detail-group">
								<div className="links-detail-row">
									<div className="links-detail-heading">
										<span
											className="links-detail-marker links-detail-marker-primary"
											aria-hidden="true"
										></span>
										<h4 className="links-detail-title">
											{sprintf(
												__("Year %s"),
												bestDayYear
											)}
										</h4>
									</div>
								</div>
								<div className="links-detail-children">
									<div className="links-detail-group-nested">
										<div className="links-detail-row">
											<span
												className="links-detail-marker links-detail-marker-secondary"
												aria-hidden="true"
											></span>
											<h5 className="links-detail-title-muted">
												{bestDayMonth}
											</h5>
										</div>
										<div className="links-detail-items">
											<div className="links-detail-item">
												<span className="links-detail-item-label">
													{bestDayDay}
												</span>
												<DetailMetric
													tone="clicks"
													value={formatClickCount(
														bestDay.totalClicks
													)}
												/>
												<DetailMetric
													tone="unique"
													value={formatUniqueVisitorCount(
														bestDay.uniqueClicks
													)}
												/>
											</div>
										</div>
									</div>
								</div>
							</section>
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
