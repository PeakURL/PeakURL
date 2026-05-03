import { Calendar } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime, formatRelativeTime } from "@/utils";
import { formatAverageClicks, formatClickCount } from "./analytics";
import type { LinkPeriodSummary, LinkStatsViewProps } from "./types";

interface HistoricalPeriodRow {
	key: "last24Hours" | "last7Days" | "last30Days" | "allTime";
	label: string;
	highlighted: boolean;
	summary?: LinkPeriodSummary;
}

function HistoricalStats({ link, stats, isLoading }: LinkStatsViewProps) {
	const linkAgeInDays = link.createdAt
		? Math.max(
				1,
				Math.ceil(
					(new Date().getTime() -
						new Date(link.createdAt).getTime()) /
						(1000 * 60 * 60 * 24)
				)
			)
		: 1;
	const allTimeTotalClicks = Number(link.clicks || 0);
	const allTimeUniqueClicks = Math.min(
		Number(link.uniqueClicks || 0),
		allTimeTotalClicks
	);
	const allTimeFallback: LinkPeriodSummary = {
		key: "allTime",
		totalClicks: allTimeTotalClicks,
		uniqueClicks: allTimeUniqueClicks,
		uniqueClickRate:
			allTimeTotalClicks > 0
				? Number(
						(
							(allTimeUniqueClicks / allTimeTotalClicks) *
							100
						).toFixed(1)
					)
				: 0,
		averageClicks: allTimeTotalClicks / linkAgeInDays,
		averageUnit: "day",
	};
	const periodSummaries = stats?.periodSummaries || {};
	const rows: HistoricalPeriodRow[] = [
		{
			key: "last24Hours",
			label: __("Last 24 hours"),
			highlighted: true,
			summary: periodSummaries.last24Hours,
		},
		{
			key: "last7Days",
			label: __("Last 7 days"),
			highlighted: false,
			summary: periodSummaries.last7Days,
		},
		{
			key: "last30Days",
			label: __("Last 30 days"),
			highlighted: false,
			summary: periodSummaries.last30Days,
		},
		{
			key: "allTime",
			label: __("All time"),
			highlighted: true,
			summary: periodSummaries.allTime || allTimeFallback,
		},
	];

	return (
		<div className="links-historical-stats">
			<div className="links-historical-stats-header">
				<Calendar className="links-drawer-section-icon" />
				<h3 className="links-historical-stats-title">
					{__("Historical click count")}
				</h3>
			</div>
			<p className="links-historical-stats-copy">
				{__("Short URL created on")}{" "}
				{link.createdAt
					? formatLocalizedDateTime(new Date(link.createdAt), {
							year: "numeric",
							month: "long",
							day: "numeric",
							hour: "numeric",
							minute: "2-digit",
						})
					: __("Unknown")}{" "}
				(
				{link.createdAt
					? formatRelativeTime(new Date(link.createdAt), {
							style: "long",
							numeric: "always",
						})
					: __("Unknown")}
				)
			</p>

			<div className="links-historical-stats-list">
				{rows.map((row) => {
					const summary = row.summary;

					return (
						<div
							key={row.key}
							className={`links-historical-stats-item ${
								row.highlighted
									? "links-historical-stats-item-highlighted"
									: ""
							}`}
						>
							<span
								className={`links-historical-stats-period ${
									row.highlighted
										? "links-historical-stats-period-highlighted"
										: "links-historical-stats-period-muted"
								}`}
							>
								{row.label}
							</span>
							<span className="links-historical-stats-value">
								{isLoading || !summary
									? "..."
									: formatClickCount(summary.totalClicks)}
							</span>
							<span className="links-historical-stats-unique">
								{isLoading || !summary
									? ""
									: sprintf(
											__("%s unique"),
											String(summary.uniqueClicks)
										)}
							</span>
							<span className="links-historical-stats-rate">
								{isLoading || !summary
									? ""
									: formatAverageClicks(
											summary.averageClicks,
											summary.averageUnit
										)}
							</span>
						</div>
					);
				})}
			</div>
		</div>
	);
}

export default HistoricalStats;
