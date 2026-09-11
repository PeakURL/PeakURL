import { Calendar } from "lucide-react";

import { __ } from "@/i18n";
import { formatLocalizedDateTime, formatRelativeTime } from "@/utils";

import {
	formatAverageClicks,
	formatClickCount,
	formatUniqueCount,
} from "./analytics";
import type {
	LinkPeriodSummary,
	LinkStatsViewProps,
	StatsTimeRange,
} from "./types";

interface HistoricalPeriodRow {
	key: "custom" | "last24Hours" | "last7Days" | "last30Days" | "allTime";
	label: string;
	highlighted: boolean;
	summary?: LinkPeriodSummary;
}

interface HistoricalStatsProps extends LinkStatsViewProps {
	/** Currently selected Traffic History range. */
	timeRange: StatsTimeRange;
}

/**
 * Resolve the historical row that matches the selected chart range.
 */
function getActiveHistoricalPeriodKey(
	timeRange: StatsTimeRange
): HistoricalPeriodRow["key"] {
	if ("24h" === timeRange) {
		return "last24Hours";
	}

	if ("30d" === timeRange) {
		return "last30Days";
	}

	if ("custom" === timeRange) {
		return "custom";
	}

	return "last7Days";
}

function HistoricalStats({ link, stats, timeRange }: HistoricalStatsProps) {
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
	const selectedRangeDays = Math.max(1, Number(stats?.range?.days || 1));
	const selectedRangeTotalClicks = Math.max(
		0,
		Number(stats?.totalClicks || 0)
	);
	const selectedRangeUniqueClicks = Math.max(
		0,
		Math.min(Number(stats?.uniqueClicks || 0), selectedRangeTotalClicks)
	);
	const hasCustomRangeSummary =
		stats && "custom" === timeRange && "custom" === stats.range?.key;
	const customRangeSummary: LinkPeriodSummary | undefined =
		hasCustomRangeSummary
			? {
					key: "custom",
					totalClicks: selectedRangeTotalClicks,
					uniqueClicks: selectedRangeUniqueClicks,
					uniqueClickRate:
						selectedRangeTotalClicks > 0
							? Number(
									(
										(selectedRangeUniqueClicks /
											selectedRangeTotalClicks) *
										100
									).toFixed(1)
								)
							: 0,
					averageClicks: selectedRangeTotalClicks / selectedRangeDays,
					averageUnit: "day",
				}
			: undefined;
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
	const activePeriodKey = getActiveHistoricalPeriodKey(timeRange);
	const customRangeRows: HistoricalPeriodRow[] =
		"custom" === timeRange
			? [
					{
						key: "custom",
						label: __("Custom range"),
						highlighted: activePeriodKey === "custom",
						summary: customRangeSummary,
					},
				]
			: [];
	const rows: HistoricalPeriodRow[] = [
		...customRangeRows,
		{
			key: "last24Hours",
			label: __("Last 24 hours"),
			highlighted: activePeriodKey === "last24Hours",
			summary: periodSummaries.last24Hours,
		},
		{
			key: "last7Days",
			label: __("Last 7 days"),
			highlighted: activePeriodKey === "last7Days",
			summary: periodSummaries.last7Days,
		},
		{
			key: "last30Days",
			label: __("Last 30 days"),
			highlighted: activePeriodKey === "last30Days",
			summary: periodSummaries.last30Days,
		},
		{
			key: "allTime",
			label: __("All time"),
			highlighted: activePeriodKey === "allTime",
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
								{!summary
									? "..."
									: formatClickCount(summary.totalClicks)}
							</span>
							<span className="links-historical-stats-unique">
								{!summary
									? ""
									: formatUniqueCount(summary.uniqueClicks)}
							</span>
							<span className="links-historical-stats-rate">
								{!summary
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
