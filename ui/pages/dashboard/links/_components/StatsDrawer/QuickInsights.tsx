import { TrendingUp, Activity, Clock } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatRelativeTime } from "@/utils";
import {
	formatAverageClicks,
	getStatsTimeRangeDays,
	getStatsTimeRangeLabel,
	getStatsTotals,
} from "./analytics";
import type { LinkStatsViewProps, StatsTimeRange } from "./types";

interface QuickInsightsProps extends LinkStatsViewProps {
	timeRange: StatsTimeRange;
}

function QuickInsights({
	link,
	stats,
	isLoading,
	timeRange,
}: QuickInsightsProps) {
	const { totalClicks, uniqueClickRate } = getStatsTotals(link, stats);
	const uniqueClickRateValue = Number(uniqueClickRate);
	const averageUnit = "24h" === timeRange ? "hour" : "day";
	const averageWindow =
		"hour" === averageUnit
			? 24
			: Math.max(
					1,
					Number(
						stats?.range?.days || getStatsTimeRangeDays(timeRange)
					)
				);
	const averageClicks = totalClicks / averageWindow;

	const isActive = link.status === "active";
	const insights = [
		{
			icon: Activity,
			label: __("Status"),
			value: isActive ? __("Active & Tracking") : __("Inactive"),
			color: isActive
				? "text-green-600 dark:text-green-400"
				: "text-gray-500",
			bg: isActive ? "bg-green-500/10" : "bg-gray-500/10",
		},
		{
			icon: TrendingUp,
			label: __("Engagement"),
			value: isLoading
				? "..."
				: uniqueClickRateValue > 50
					? __("High")
					: uniqueClickRateValue > 20
						? __("Medium")
						: __("Low"),
			color:
				uniqueClickRateValue > 50
					? "text-green-600 dark:text-green-400"
					: uniqueClickRateValue > 20
						? "text-yellow-600 dark:text-yellow-400"
						: "text-orange-600 dark:text-orange-400",
			bg:
				uniqueClickRateValue > 50
					? "bg-green-500/10"
					: uniqueClickRateValue > 20
						? "bg-yellow-500/10"
						: "bg-orange-500/10",
			subtext: sprintf(
				__("%s%% unique click rate"),
				uniqueClickRate.toFixed(1)
			),
		},
		{
			icon: Clock,
			label: __("Average"),
			value: isLoading
				? "..."
				: formatAverageClicks(averageClicks, averageUnit),
			color: "text-blue-600 dark:text-blue-400",
			bg: "bg-blue-500/10",
			subtext: sprintf(
				__("Showing %s"),
				getStatsTimeRangeLabel(timeRange)
			),
		},
	];

	return (
		<div className="links-quick-insights">
			<div className="links-quick-insights-header">
				<h3 className="links-quick-insights-title">
					{__("Quick Insights")}
				</h3>
				<span className="links-quick-insights-updated">
					{__("Last updated:")}{" "}
					{formatRelativeTime(new Date(), {
						style: "long",
						numeric: "auto",
					})}
				</span>
			</div>

			<div className="links-quick-insights-grid">
				{insights.map((insight, index) => {
					const Icon = insight.icon;
					return (
						<div key={index} className="links-quick-insight-card">
							<div className="links-quick-insight-row">
								<div
									className={`links-quick-insight-icon ${insight.bg}`}
								>
									<Icon
										className={`w-3.5 h-3.5 ${insight.color}`}
									/>
								</div>
								<span className="links-quick-insight-label">
									{insight.label}
								</span>
							</div>
							<div className="links-quick-insight-value">
								{insight.value}
							</div>
							{insight.subtext && (
								<div className="links-quick-insight-subtext">
									{insight.subtext}
								</div>
							)}
						</div>
					);
				})}
			</div>
		</div>
	);
}

export default QuickInsights;
