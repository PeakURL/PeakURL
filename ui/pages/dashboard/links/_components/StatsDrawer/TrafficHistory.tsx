import { Popover, PopoverButton, PopoverPanel } from "@headlessui/react";
import { useState, type SetStateAction } from "react";
import {
	BarChart3,
	CalendarDays,
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
import { __, sprintf } from "@/i18n";
import { cn, formatCount, formatDateOnly } from "@/utils";

import {
	getStatsTotals,
	hasTrafficActivity,
	normalizeTrafficSeries,
} from "./analytics";
import type {
	StatsCustomDateRange,
	TrafficHistoryProps,
	StatsTimeRange,
} from "./types";

const timeRangeOptions: Array<{ label: string; value: StatsTimeRange }> = [
	{ label: __("24h"), value: "24h" },
	{ label: __("7d"), value: "7d" },
	{ label: __("30d"), value: "30d" },
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
	customDateRange,
	setCustomDateRange,
}: TrafficHistoryProps) {
	const [chartType, setChartType] = useState<TrafficChartType>("line");
	const [seriesMode, setSeriesMode] =
		useState<TrafficChartSeriesMode>("both");
	const customDateRangeKey = `${customDateRange.from}:${customDateRange.to}`;
	const [draftCustomDateRangeState, setDraftCustomDateRangeState] = useState<{
		key: string;
		range: StatsCustomDateRange;
	} | null>(null);
	const draftCustomDateRange =
		draftCustomDateRangeState?.key === customDateRangeKey
			? draftCustomDateRangeState.range
			: customDateRange;
	const trafficSeries = normalizeTrafficSeries(stats?.traffic);
	const hasData = hasTrafficActivity(trafficSeries);
	const { totalClicks, uniqueClicks } = link
		? getStatsTotals(link, stats)
		: { totalClicks: 0, uniqueClicks: 0 };
	const customFromLabel =
		formatDateOnly(draftCustomDateRange.from, {
			year: "numeric",
			month: "short",
			day: "numeric",
		}) || draftCustomDateRange.from;
	const customToLabel =
		formatDateOnly(draftCustomDateRange.to, {
			year: "numeric",
			month: "short",
			day: "numeric",
		}) || draftCustomDateRange.to;

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

	const setDraftCustomDateRange = (
		nextRange: SetStateAction<StatsCustomDateRange>
	) => {
		setDraftCustomDateRangeState((currentState) => {
			const currentRange =
				currentState?.key === customDateRangeKey
					? currentState.range
					: customDateRange;
			const range =
				"function" === typeof nextRange
					? nextRange(currentRange)
					: nextRange;

			return { key: customDateRangeKey, range };
		});
	};

	const updateDraftCustomDateRange = (
		field: "from" | "to",
		value: string
	) => {
		if (!value) {
			return;
		}

		setDraftCustomDateRange((currentRange) => {
			const nextRange = {
				...currentRange,
				[field]: value,
			};

			if (nextRange.from > nextRange.to) {
				return { from: value, to: value };
			}

			return nextRange;
		});
	};

	const applyCustomDateRange = () => {
		if (!draftCustomDateRange.from || !draftCustomDateRange.to) {
			return;
		}

		setCustomDateRange(draftCustomDateRange);
		setTimeRange("custom");
	};

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
								{isLoading ? "..." : formatCount(totalClicks)}
							</strong>
						</div>
						<div className="links-traffic-history-legend-item">
							<span className="links-traffic-history-legend-dot links-traffic-history-legend-dot-unique" />
							<span>{__("Unique")}</span>
							<strong>
								{isLoading ? "..." : formatCount(uniqueClicks)}
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
							<span>{__("Line")}</span>
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
							<span>{__("Bar")}</span>
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
						<Popover className="links-traffic-history-custom">
							{({ close }) => (
								<>
									<PopoverButton
										type="button"
										onClick={() =>
											setDraftCustomDateRange(
												customDateRange
											)
										}
										className={getRangeButtonClassName(
											"custom" === timeRange
										)}
									>
										<CalendarDays className="links-traffic-history-range-icon" />
										<span>{__("Custom")}</span>
									</PopoverButton>
									<PopoverPanel
										anchor={{
											to: "bottom end",
											gap: 8,
											padding: 16,
										}}
										portal
										className="links-traffic-history-custom-panel"
									>
										<div className="links-traffic-history-custom-header">
											<h4 className="links-traffic-history-custom-title">
												{__("Custom date range")}
											</h4>
										</div>
										<div className="links-traffic-history-date-grid">
											<label className="links-traffic-history-date-field">
												<span>{__("From")}</span>
												<input
													type="date"
													value={
														draftCustomDateRange.from
													}
													max={
														draftCustomDateRange.to
													}
													onChange={(event) =>
														updateDraftCustomDateRange(
															"from",
															event.target.value
														)
													}
													aria-label={__(
														"Start date"
													)}
												/>
											</label>
											<label className="links-traffic-history-date-field">
												<span>{__("To")}</span>
												<input
													type="date"
													value={
														draftCustomDateRange.to
													}
													min={
														draftCustomDateRange.from
													}
													onChange={(event) =>
														updateDraftCustomDateRange(
															"to",
															event.target.value
														)
													}
													aria-label={__("End date")}
												/>
											</label>
										</div>
										<p className="links-traffic-history-custom-summary">
											{sprintf(
												__("Showing %s to %s"),
												customFromLabel,
												customToLabel
											)}
										</p>
										<div className="links-traffic-history-custom-actions">
											<button
												type="button"
												className="links-traffic-history-custom-apply"
												onClick={() => {
													applyCustomDateRange();
													close();
												}}
											>
												{__("Apply")}
											</button>
										</div>
									</PopoverPanel>
								</>
							)}
						</Popover>
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
