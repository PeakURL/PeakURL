import type { ChartData, TooltipItem } from "chart.js";
import { useEffect, useMemo, useRef, useState } from "react";
import { Chart } from "chart.js/auto";

import { __ } from "@/i18n";
import { formatCount, formatDateOnly } from "@/utils";

import type {
	TrafficChartData,
	TrafficChartProps,
	TrafficChartType,
} from "../types";

export type {
	TrafficChartData,
	TrafficChartProps,
	TrafficChartSeriesMode,
	TrafficChartType,
} from "../types";

function getTrafficLabelYear(label: string): string {
	const match = label.match(/^(\d{4})-/);
	return match?.[1] || "";
}

function trafficLabelsSpanYears(labels: string[]): boolean {
	const years = new Set(
		labels.map(getTrafficLabelYear).filter((year) => "" !== year)
	);

	return years.size > 1;
}

function formatTrafficAxisLabel(
	label: string,
	totalLabels: number,
	granularity: TrafficChartData["granularity"],
	rawLabels: string[]
): string {
	if ("month" === granularity) {
		return (
			formatDateOnly(label, {
				month: "short",
				year: "numeric",
			}) || label
		);
	}

	if (totalLabels <= 7) {
		return (
			formatDateOnly(label, {
				weekday: "short",
				day: "numeric",
			}) || label
		);
	}

	if (trafficLabelsSpanYears(rawLabels)) {
		return (
			formatDateOnly(label, {
				month: "short",
				day: "numeric",
				year: "2-digit",
			}) || label
		);
	}

	return (
		formatDateOnly(label, {
			month: "short",
			day: "numeric",
		}) || label
	);
}

function formatTrafficTooltipLabel(
	label: string,
	granularity: TrafficChartData["granularity"]
): string {
	if ("month" === granularity) {
		return (
			formatDateOnly(label, {
				month: "long",
				year: "numeric",
			}) || label
		);
	}

	return formatDateOnly(label, { dateStyle: "medium" }) || label;
}

const UNIQUE_OVERLAP_OFFSET_SCALE = 0.015;
const UNIQUE_OVERLAP_OFFSET_MIN = 0.02;
const UNIQUE_OVERLAP_OFFSET_MAX = 0.35;

function getTrafficStringValues(values: unknown): string[] {
	return Array.isArray(values) ? values.map((value) => String(value)) : [];
}

function getTrafficNumberValues(values: unknown): number[] {
	if (!Array.isArray(values)) {
		return [];
	}

	return values.map((value) => {
		const numericValue = Number(value);
		return Number.isFinite(numericValue) ? numericValue : 0;
	});
}

/**
 * Parse a memo key created by JSON.stringify().
 *
 * @param value - Serialized chart field value.
 * @return Parsed field values.
 */
function parseTrafficDataKey<T>(value: string): T[] {
	return JSON.parse(value) as T[];
}

/**
 * TrafficChart Component
 * Visualizes traffic data (clicks and unique visitors) using Chart.js
 * @param {Object} props
 * @param {Object} props.data - Chart data containing labels, clicks, and unique arrays
 * @param {TrafficChartType} [props.type="line"] - Chart type (line or bar)
 */
export function TrafficChart({
	data,
	type = "line",
	seriesMode = "both",
}: TrafficChartProps) {
	const chartRef = useRef<HTMLCanvasElement | null>(null);
	const chartInstanceRef = useRef<Chart<
		TrafficChartType,
		number[],
		string
	> | null>(null);
	const labelsKey = JSON.stringify(getTrafficStringValues(data?.labels));
	const clicksKey = JSON.stringify(getTrafficNumberValues(data?.clicks));
	const uniqueKey = JSON.stringify(getTrafficNumberValues(data?.unique));
	const granularity = "month" === data?.granularity ? "month" : "day";
	const chartDataInput = useMemo<Required<TrafficChartData>>(
		() => ({
			labels: parseTrafficDataKey<string>(labelsKey),
			clicks: parseTrafficDataKey<number>(clicksKey),
			unique: parseTrafficDataKey<number>(uniqueKey),
			granularity,
		}),
		[labelsKey, clicksKey, uniqueKey, granularity]
	);
	const [isDark, setIsDark] = useState(false);

	// Detect dark mode
	useEffect(() => {
		const checkDarkMode = () => {
			setIsDark(document.documentElement.classList.contains("dark"));
		};

		checkDarkMode();
		const observer = new MutationObserver(checkDarkMode);
		observer.observe(document.documentElement, {
			attributes: true,
			attributeFilter: ["class"],
		});

		return () => observer.disconnect();
	}, []);

	useEffect(() => {
		const canvas = chartRef.current;

		if (!canvas) {
			return undefined;
		}

		const initChart = async () => {
			const context = canvas.getContext("2d");

			if (!context) {
				return;
			}

			// Destroy any existing chart on this canvas
			const existingChart = Chart.getChart(canvas);
			if (existingChart) {
				existingChart.destroy();
			}

			// Also clear our ref if it exists
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
				chartInstanceRef.current = null;
			}

			// Theme colors
			const clicksColor = isDark
				? "rgb(99, 102, 241)"
				: "rgb(79, 70, 229)";
			const uniqueColor = isDark
				? "rgb(16, 185, 129)"
				: "rgb(5, 150, 105)";

			// Create gradients
			const clicksGradient = context.createLinearGradient(0, 0, 0, 300);
			clicksGradient.addColorStop(
				0,
				isDark ? "rgba(99, 102, 241, 0.2)" : "rgba(99, 102, 241, 0.15)"
			);
			clicksGradient.addColorStop(
				1,
				isDark ? "rgba(99, 102, 241, 0)" : "rgba(99, 102, 241, 0)"
			);

			// Use provided data if it exists and has the right structure, otherwise use demo data
			const hasValidStructure =
				Array.isArray(chartDataInput.labels) &&
				Array.isArray(chartDataInput.clicks) &&
				Array.isArray(chartDataInput.unique) &&
				chartDataInput.labels.length > 0;

			const rawLabels = chartDataInput.labels;
			const chartData: TrafficChartData = hasValidStructure
				? {
						labels: rawLabels.map((label) =>
							formatTrafficAxisLabel(
								label,
								rawLabels.length,
								granularity,
								rawLabels
							)
						),
						clicks: chartDataInput.clicks,
						unique: chartDataInput.unique,
						granularity: chartDataInput.granularity,
					}
				: {
						labels: [
							__("Mon"),
							__("Tue"),
							__("Wed"),
							__("Thu"),
							__("Fri"),
							__("Sat"),
							__("Sun"),
						],
						clicks: [420, 380, 520, 478, 589, 639, 749],
						unique: [340, 289, 420, 390, 480, 520, 630],
						granularity: "day",
					};

			// Theme colors
			const textColor = isDark
				? "rgb(156, 163, 175)"
				: "rgb(107, 114, 128)";
			const gridColor = isDark
				? "rgba(75, 85, 99, 0.3)"
				: "rgba(229, 231, 235, 0.8)";
			const isLineChart = type === "line";
			const clicksLabel = __("Total Clicks");
			const uniqueLabel = __("Visitors");
			const maxTrafficValue = Math.max(
				...chartData.clicks,
				...chartData.unique,
				0
			);
			// Separate equal click and unique lines just enough to keep both visible.
			const overlapOffset = Math.min(
				Math.max(
					maxTrafficValue * UNIQUE_OVERLAP_OFFSET_SCALE,
					UNIQUE_OVERLAP_OFFSET_MIN
				),
				UNIQUE_OVERLAP_OFFSET_MAX
			);
			const renderedUniqueData =
				isLineChart && "both" === seriesMode
					? chartData.unique.map((value, index) => {
							const clickValue = chartData.clicks[index] ?? 0;
							return value > 0 && value === clickValue
								? Math.max(value - overlapOffset, 0)
								: value;
						})
					: chartData.unique;

			const datasets: ChartData<
				TrafficChartType,
				number[],
				string
			>["datasets"] = [];

			if ("unique" !== seriesMode) {
				datasets.push({
					label: clicksLabel,
					data: chartData.clicks,
					borderColor: clicksColor,
					backgroundColor:
						type === "bar" ? clicksColor : clicksGradient,
					fill: isLineChart,
					tension: 0.4,
					borderWidth: 2,
					pointRadius: 0,
					pointHoverRadius: 6,
					pointBackgroundColor: isDark ? "rgb(17, 24, 39)" : "white",
					pointBorderColor: clicksColor,
					pointBorderWidth: 2,
					pointHoverBackgroundColor: clicksColor,
					pointHoverBorderColor: isDark ? "rgb(17, 24, 39)" : "white",
					pointHoverBorderWidth: 2,
					borderRadius: 4,
					order: 1,
				});
			}

			if ("clicks" !== seriesMode) {
				datasets.push({
					label: uniqueLabel,
					data: renderedUniqueData,
					borderColor: uniqueColor,
					backgroundColor:
						type === "bar" ? uniqueColor : "transparent",
					fill: type === "bar",
					tension: 0.4,
					borderWidth: 2,
					pointRadius: 0,
					pointHoverRadius: 6,
					pointBackgroundColor: isDark ? "rgb(17, 24, 39)" : "white",
					pointBorderColor: uniqueColor,
					pointBorderWidth: 2,
					pointHoverBackgroundColor: uniqueColor,
					pointHoverBorderColor: isDark ? "rgb(17, 24, 39)" : "white",
					pointHoverBorderWidth: 2,
					borderRadius: 4,
					order: 2,
				});
			}

			const chartDataConfig: ChartData<
				TrafficChartType,
				number[],
				string
			> = {
				labels: chartData.labels,
				datasets,
			};

			chartInstanceRef.current = new Chart<
				TrafficChartType,
				number[],
				string
			>(canvas, {
				type,
				data: chartDataConfig,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: "index",
						intersect: false,
					},
					plugins: {
						legend: {
							display: false,
						},
						tooltip: {
							enabled: true,
							backgroundColor: isDark
								? "rgb(31, 41, 55)"
								: "white",
							titleColor: isDark
								? "rgb(243, 244, 246)"
								: "rgb(17, 24, 39)",
							bodyColor: isDark
								? "rgb(209, 213, 219)"
								: "rgb(55, 65, 81)",
							borderColor: isDark
								? "rgb(75, 85, 99)"
								: "rgb(229, 231, 235)",
							borderWidth: 1,
							padding: 12,
							boxPadding: 6,
							usePointStyle: true,
							callbacks: {
								title(
									context: TooltipItem<TrafficChartType>[]
								) {
									const item = context[0];
									if (!item) {
										return "";
									}

									const dataIndex = item.dataIndex;
									const rawLabel = rawLabels[dataIndex];

									return rawLabel
										? formatTrafficTooltipLabel(
												rawLabel,
												chartData.granularity
											)
										: item.label || "";
								},
								label(context: TooltipItem<TrafficChartType>) {
									const dataIndex = context.dataIndex;
									const rawSeries =
										context.dataset.label === uniqueLabel
											? chartData.unique
											: chartData.clicks;
									const rawValue = Number(
										rawSeries[dataIndex] || 0
									);
									return ` ${
										context.dataset.label
									}: ${formatCount(rawValue)}`;
								},
							},
						},
					},
					scales: {
						x: {
							grid: {
								display: false,
							},
							border: {
								display: false,
							},
							ticks: {
								color: textColor,
								font: {
									size: 12,
								},
								padding: 8,
							},
						},
						y: {
							beginAtZero: true,
							grid: {
								color: gridColor,
							},
							border: {
								display: false,
								dash: [5, 5],
							},
							ticks: {
								color: textColor,
								font: {
									size: 12,
								},
								padding: 10,
								callback(value: string | number) {
									const numericValue =
										"number" === typeof value
											? value
											: Number(value);

									if (
										Number.isFinite(numericValue) &&
										numericValue >= 1000
									) {
										return `${(numericValue / 1000).toFixed(1)}k`;
									}
									return value;
								},
							},
						},
					},
				},
			});
		};

		initChart();

		return () => {
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
				chartInstanceRef.current = null;
			}
		};
	}, [chartDataInput, granularity, isDark, type, seriesMode]);

	return (
		<div className="traffic-chart">
			<canvas ref={chartRef} />
		</div>
	);
}
