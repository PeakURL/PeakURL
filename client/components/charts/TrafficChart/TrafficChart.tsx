import { useEffect, useMemo, useRef } from "react";
import { Chart } from "chart.js/auto";
import { color } from "chart.js/helpers";
import type { ChartArea, ChartDataset, ChartOptions } from "chart.js";

import { __ } from "@/i18n";
import { useTheme } from "@/providers/ThemeProvider";
import { formatLocalizedDateTime, getActiveLocale } from "@/shared/dates";

import { getChartPalette } from "../palette";
import type {
	TrafficChartData,
	TrafficChartProps,
	TrafficChartType,
} from "../types";
import { useReducedMotion } from "../useReducedMotion";

export type {
	TrafficChartData,
	TrafficChartProps,
	TrafficChartSeriesMode,
	TrafficChartType,
} from "../types";

const ENTRANCE_MS = 500;
const RANGE_UPDATE_MS = 300;
const VISIBILITY_MS = 220;

function getDateLabels(
	labels: string[],
	granularity: TrafficChartData["granularity"],
	locale: string
) {
	if (granularity === "hour") {
		return {
			axis: labels.map((label) =>
				formatLocalizedDateTime(label, { timeStyle: "short" })
			),
			full: labels.map((label) =>
				formatLocalizedDateTime(label, {
					month: "short",
					day: "numeric",
					hour: "numeric",
					minute: "2-digit",
					timeZoneName: "short",
				})
			),
		};
	}

	const monthly = granularity === "month";
	const years = new Set(labels.map((label) => label.slice(0, 4)));
	const axisFormat: Intl.DateTimeFormatOptions = monthly
		? { month: "short", year: "numeric" }
		: labels.length <= 7
			? { weekday: "short", day: "numeric" }
			: {
					month: "short",
					day: "numeric",
					year: years.size > 1 ? "2-digit" : undefined,
				};
	const fullFormat: Intl.DateTimeFormatOptions = monthly
		? { month: "long", year: "numeric" }
		: { dateStyle: "medium" };

	const axis = new Intl.DateTimeFormat(locale, {
		...axisFormat,
		timeZone: "UTC",
	});
	const full = new Intl.DateTimeFormat(locale, {
		...fullFormat,
		timeZone: "UTC",
	});
	const dates = labels.map((label) => Date.parse(label));

	return {
		axis: dates.map((date, index) =>
			Number.isNaN(date) ? (labels[index] ?? "") : axis.format(date)
		),
		full: dates.map((date, index) =>
			Number.isNaN(date) ? (labels[index] ?? "") : full.format(date)
		),
	};
}

/** Render the supplied click and visitor series with the reporting timezone. */
export function TrafficChart({
	data,
	type = "line",
	seriesMode = "both",
}: TrafficChartProps) {
	const canvasRef = useRef<HTMLCanvasElement>(null);
	const chartRef = useRef<Chart<TrafficChartType, number[], string> | null>(
		null
	);
	const { theme } = useTheme();
	const reducedMotion = useReducedMotion();
	const locale = getActiveLocale();
	const numberFormat = useMemo(() => new Intl.NumberFormat(locale), [locale]);
	const { labels, clicks, unique, granularity } = data ?? {};
	const dates = useMemo(
		() => getDateLabels(labels ?? [], granularity, locale),
		[labels, granularity, locale]
	);

	useEffect(
		() => () => {
			chartRef.current?.destroy();
			chartRef.current = null;
		},
		[]
	);

	useEffect(() => {
		const canvas = canvasRef.current;
		if (!canvas || !dates.axis.length) return;

		const palette = getChartPalette(canvas);
		const line = type === "line";
		const chart = chartRef.current;
		const paletteChanged =
			chart?.data.datasets[0]?.borderColor !== palette.primary;
		const typeChanged =
			chart && "type" in chart.config && chart.config.type !== type;
		const series = [
			{
				name: __("Clicks"),
				values: clicks,
				color: palette.primary,
				id: "clicks",
			},
			{
				name: __("Visitors"),
				values: unique,
				color: palette.secondary,
				id: "unique",
			},
		];
		const datasets: ChartDataset<TrafficChartType, number[]>[] = series.map(
			({ name, values, color: stroke, id }, index) => {
				const primary = index === 0;
				const fill = line && primary;
				return {
					label: name,
					data: dates.axis.map(
						(_, position) => values?.[position] ?? 0
					),
					hidden: seriesMode !== "both" && seriesMode !== id,
					borderColor: stroke,
					backgroundColor: fill
						? ({
								chart: { ctx, chartArea },
							}: {
								chart: {
									ctx: CanvasRenderingContext2D;
									chartArea?: ChartArea;
								};
							}) => {
								if (!chartArea) return "transparent";
								const gradient = ctx.createLinearGradient(
									0,
									chartArea.top,
									0,
									chartArea.bottom
								);
								gradient.addColorStop(
									0,
									color(stroke).alpha(0.18).rgbString() ??
										"transparent"
								);
								gradient.addColorStop(
									1,
									color(stroke).alpha(0.015).rgbString() ??
										"transparent"
								);
								return gradient;
							}
						: stroke,
					fill,
					// Keep the jade edges visible underneath an identical Visitors line.
					order: line && primary ? 1 : 0,
					borderWidth: line ? (primary ? 3.5 : 2) : 0,
					borderRadius: 3,
					pointRadius:
						dates.axis.length === 1 ? (primary ? 5 : 3) : 0,
					pointBackgroundColor: stroke,
					pointHoverRadius: primary ? 6 : 4,
					pointHoverBorderColor: palette.surface,
					pointHoverBorderWidth: 1,
					cubicInterpolationMode: "monotone",
				};
			}
		);
		const visibilityChanged = datasets.map(
			(dataset, index) =>
				dataset.hidden !== chart?.data.datasets[index]?.hidden
		);
		const options: ChartOptions<TrafficChartType> = {
			locale,
			responsive: true,
			maintainAspectRatio: false,
			resizeDelay: 80,
			animation: reducedMotion
				? false
				: {
						duration: chart ? RANGE_UPDATE_MS : ENTRANCE_MS,
						easing: "easeOutCubic",
					},
			transitions: {
				show: { animation: { duration: VISIBILITY_MS } },
				hide: { animation: { duration: VISIBILITY_MS } },
			},
			interaction: { mode: "index", intersect: false },
			plugins: {
				legend: { display: false },
				tooltip: {
					backgroundColor: palette.surface,
					titleColor: palette.heading,
					bodyColor: palette.muted,
					borderColor: palette.grid,
					borderWidth: 1,
					rtl: document.documentElement.dir === "rtl",
					itemSort: (first, second) =>
						first.datasetIndex - second.datasetIndex,
					callbacks: {
						title: ([item]) =>
							item ? (dates.full[item.dataIndex] ?? "") : "",
						label: (item) =>
							`${item.dataset.label}: ${numberFormat.format(Number(item.raw))}`,
					},
				},
			},
			scales: {
				x: {
					border: { display: false },
					grid: { display: false },
					ticks: {
						color: palette.muted,
						autoSkipPadding: 12,
						maxRotation: 0,
						padding: 8,
					},
				},
				y: {
					beginAtZero: true,
					border: { display: false, dash: [3, 4] },
					grid: { color: palette.grid },
					ticks: {
						color: palette.muted,
						precision: 0,
						padding: 8,
						callback: (value) => numberFormat.format(Number(value)),
					},
				},
			},
		};

		if (!chart) {
			chartRef.current = new Chart<TrafficChartType, number[], string>(
				canvas,
				{
					type,
					data: { labels: dates.axis, datasets },
					options,
				}
			);
			return;
		}

		const dataChanged =
			chart.data.labels?.length !== dates.axis.length ||
			dates.axis.some(
				(label, index) => chart.data.labels?.[index] !== label
			) ||
			datasets.some((dataset, seriesIndex) =>
				dataset.data.some(
					(value, index) =>
						value !== chart.data.datasets[seriesIndex]?.data[index]
				)
			);
		if (reducedMotion || paletteChanged || typeChanged) chart.stop();
		if ("type" in chart.config) chart.config.type = type;
		chart.options = options;
		chart.data.labels = dates.axis;
		datasets.forEach((dataset, index) => {
			const current = chart.data.datasets[index];
			if (current) Object.assign(current, dataset);
			if (visibilityChanged[index])
				chart.setDatasetVisibility(index, !dataset.hidden);
		});
		chart.update(
			reducedMotion ||
				paletteChanged ||
				typeChanged ||
				(!visibilityChanged.some(Boolean) && !dataChanged)
				? "none"
				: ({ datasetIndex }) => {
						if (!visibilityChanged[datasetIndex]) return "default";
						return datasets[datasetIndex]?.hidden ? "hide" : "show";
					}
		);
	}, [
		clicks,
		dates,
		locale,
		numberFormat,
		reducedMotion,
		seriesMode,
		theme,
		type,
		unique,
	]);

	return (
		<>
			<div className="traffic-chart">
				<canvas
					ref={canvasRef}
					role="img"
					aria-label={__("Traffic Overview")}
				/>
			</div>
			{dates.full.length > 0 && (
				<table className="sr-only">
					<caption>{__("Traffic Overview")}</caption>
					<thead>
						<tr>
							<th scope="col">{__("Date")}</th>
							{seriesMode !== "unique" && (
								<th scope="col">{__("Clicks")}</th>
							)}
							{seriesMode !== "clicks" && (
								<th scope="col">{__("Visitors")}</th>
							)}
						</tr>
					</thead>
					<tbody>
						{dates.full.map((label, index) => (
							<tr key={labels?.[index]}>
								<th scope="row">{label}</th>
								{seriesMode !== "unique" && (
									<td>
										{numberFormat.format(
											clicks?.[index] ?? 0
										)}
									</td>
								)}
								{seriesMode !== "clicks" && (
									<td>
										{numberFormat.format(
											unique?.[index] ?? 0
										)}
									</td>
								)}
							</tr>
						))}
					</tbody>
				</table>
			)}
		</>
	);
}
