import { useState } from "react";
import {
	BarChart3,
	LineChart,
	MousePointerClick,
	Users,
	type LucideIcon,
} from "lucide-react";
import {
	TrafficChart,
	type TrafficChartSeriesMode,
	type TrafficChartType,
} from "@/components";
import { __ } from "@/i18n";
import { cn, formatNumber } from "@/utils";
import {
	getStatsTotals,
	hasTrafficActivity,
	normalizeTrafficSeries,
} from "./analytics";
import type { TrafficHistoryProps, StatsTimeRange } from "./types";

const timeRangeOptions: Array<{ label: string; value: StatsTimeRange }> = [
	{ label: "24h", value: "24h" },
	{ label: "7d", value: "7d" },
	{ label: "30d", value: "30d" },
	{ label: __("All"), value: "all" },
];

const seriesOptions: Array<{
	label: string;
	value: TrafficChartSeriesMode;
	icon: LucideIcon;
}> = [
	{ label: __("Both"), value: "both", icon: BarChart3 },
	{ label: __("Clicks"), value: "clicks", icon: MousePointerClick },
	{ label: __("Unique"), value: "unique", icon: Users },
];

function TrafficHistory({
	link,
	stats,
	isLoading,
	timeRange,
	setTimeRange,
}: TrafficHistoryProps) {
	const [chartType, setChartType] = useState<TrafficChartType>("line");
	const [seriesMode, setSeriesMode] =
		useState<TrafficChartSeriesMode>("both");
	const trafficSeries = normalizeTrafficSeries(stats?.traffic);
	const hasData = hasTrafficActivity(trafficSeries);
	const { totalClicks, uniqueClicks } = link
		? getStatsTotals(link, stats)
		: { totalClicks: 0, uniqueClicks: 0 };

	const getRangeButtonClassName = (isActive: boolean) =>
		cn(
			"links-traffic-history-range",
			isActive
				? "links-traffic-history-range-current"
				: "links-traffic-history-range-idle"
		);

	const getToolButtonClassName = (isActive: boolean) =>
		cn(
			"links-traffic-history-tool-button",
			isActive
				? "links-traffic-history-tool-button-current"
				: "links-traffic-history-tool-button-idle"
		);

	return (
		<div className="links-traffic-history">
			<div className="links-traffic-history-header">
				<div className="links-traffic-history-heading">
					<h3 className="links-traffic-history-title">
						{__("Traffic History")}
					</h3>
					<div className="links-traffic-history-legend">
						<div className="links-traffic-history-legend-item">
							<span className="links-traffic-history-legend-dot links-traffic-history-legend-dot-clicks" />
							<span>{__("Clicks")}</span>
							<strong>
								{isLoading ? "..." : formatNumber(totalClicks)}
							</strong>
						</div>
						<div className="links-traffic-history-legend-item">
							<span className="links-traffic-history-legend-dot links-traffic-history-legend-dot-unique" />
							<span>{__("Unique")}</span>
							<strong>
								{isLoading ? "..." : formatNumber(uniqueClicks)}
							</strong>
						</div>
					</div>
				</div>

				<div className="links-traffic-history-toolbar">
					<div className="links-traffic-history-tools">
						<button
							type="button"
							onClick={() => setChartType("line")}
							className={getToolButtonClassName(
								"line" === chartType
							)}
							title={__("Line Chart")}
						>
							<LineChart className="links-traffic-history-tool-icon" />
							<span className="sr-only">{__("Line Chart")}</span>
						</button>
						<button
							type="button"
							onClick={() => setChartType("bar")}
							className={getToolButtonClassName(
								"bar" === chartType
							)}
							title={__("Bar Chart")}
						>
							<BarChart3 className="links-traffic-history-tool-icon" />
							<span className="sr-only">{__("Bar Chart")}</span>
						</button>
					</div>

					<div className="links-traffic-history-series">
						{seriesOptions.map((series) => {
							const Icon = series.icon;
							return (
								<button
									key={series.value}
									type="button"
									onClick={() => setSeriesMode(series.value)}
									className={getToolButtonClassName(
										series.value === seriesMode
									)}
									title={series.label}
								>
									<Icon className="links-traffic-history-tool-icon" />
									<span>{series.label}</span>
								</button>
							);
						})}
					</div>

					<div className="links-traffic-history-ranges">
						{timeRangeOptions.map((range) => (
							<button
								key={range.value}
								type="button"
								onClick={() => setTimeRange(range.value)}
								className={getRangeButtonClassName(
									timeRange === range.value
								)}
							>
								{range.label}
							</button>
						))}
					</div>
				</div>
			</div>

			<div className="links-traffic-history-canvas-wrap">
				{isLoading ? (
					<div className="links-traffic-history-loading">
						<div className="links-traffic-history-spinner" />
					</div>
				) : null}
				{hasData ? (
					<TrafficChart
						data={trafficSeries}
						type={chartType}
						timeRange={timeRange}
						seriesMode={seriesMode}
					/>
				) : (
					<div className="links-traffic-history-empty">
						<p>{__("No traffic data available for this range.")}</p>
					</div>
				)}
			</div>
		</div>
	);
}

export default TrafficHistory;
