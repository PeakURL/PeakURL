import { Chart } from "chart.js/auto";
import type { ChartOptions } from "chart.js";
import { useEffect, useMemo, useRef } from "react";

import { __ } from "@/i18n";
import { formatCount } from "@/shared/formatting";
import { getActiveLocale } from "@/shared/dates";
import { useTheme } from "@/providers/ThemeProvider";

import { getChartPalette } from "../palette";
import type { MetricDonutChartProps } from "../types";
import { useReducedMotion } from "../useReducedMotion";

const ENTRANCE_MS = 440;
const UPDATE_MS = 280;
const MIN_ROUNDED_SHARE = 0.03;

/** Render a compact doughnut chart for grouped dashboard metrics. */
export default function MetricDonutChart({
	segments,
	ariaLabel,
	totalValue,
	totalLabel = __("Clicks"),
}: MetricDonutChartProps) {
	const { theme } = useTheme();
	const reducedMotion = useReducedMotion();
	const canvasRef = useRef<HTMLCanvasElement>(null);
	const chartRef = useRef<Chart<"doughnut", number[], string> | null>(null);
	const values = useMemo(
		() => segments.map((segment) => segment.value),
		[segments]
	);
	const total = values.reduce((sum, value) => sum + value, 0);
	const hasMultipleSegments = values.filter((value) => value > 0).length > 1;
	const centerValue = totalValue || formatCount(total);
	const locale = getActiveLocale();

	useEffect(
		() => () => {
			chartRef.current?.destroy();
			chartRef.current = null;
		},
		[]
	);

	useEffect(() => {
		const canvas = canvasRef.current;
		if (!canvas) return;

		const chart = chartRef.current;
		const palette = getChartPalette(canvas);
		const labels = segments.map((segment) => segment.label);
		const dataset = {
			data: values,
			backgroundColor: segments.map((segment) =>
				palette.resolveColor(segment.color)
			),
			borderColor: palette.surface,
			borderWidth: hasMultipleSegments ? 2 : 0,
			borderRadius: values.map((value) =>
				hasMultipleSegments && value / total >= MIN_ROUNDED_SHARE
					? 3
					: 0
			),
			hoverOffset: 4,
		};
		const percentage = new Intl.NumberFormat(locale, { style: "percent" });
		const options: ChartOptions<"doughnut"> = {
			locale,
			responsive: true,
			maintainAspectRatio: false,
			animation: reducedMotion
				? false
				: {
						duration: chart ? UPDATE_MS : ENTRANCE_MS,
						easing: "easeOutCubic",
						animateScale: false,
					},
			resizeDelay: 80,
			cutout: "72%",
			plugins: {
				legend: { display: false },
				tooltip: {
					backgroundColor: palette.surface,
					titleColor: palette.heading,
					bodyColor: palette.muted,
					borderColor: palette.grid,
					borderWidth: 1,
					displayColors: false,
					rtl: document.documentElement.dir === "rtl",
					callbacks: {
						label: ({ label, raw }) => {
							const value = Number(raw ?? 0);
							return `${label}: ${formatCount(value)} (${percentage.format(total > 0 ? value / total : 0)})`;
						},
					},
				},
			},
		};

		if (!chart) {
			chartRef.current = new Chart<"doughnut", number[], string>(canvas, {
				type: "doughnut",
				data: { labels, datasets: [dataset] },
				options,
			});
			return;
		}

		const current = chart.data.datasets[0];
		if (!current) return;
		const dataChanged =
			current.data.length !== values.length ||
			values.some((value, index) => value !== current.data[index]) ||
			labels.some((label, index) => label !== chart.data.labels?.[index]);
		const themeChanged = current.borderColor !== dataset.borderColor;
		if (reducedMotion || themeChanged) chart.stop();
		chart.options = options;
		chart.data.labels = labels;
		Object.assign(current, dataset);
		chart.update(
			reducedMotion || themeChanged || !dataChanged ? "none" : undefined
		);
	}, [
		hasMultipleSegments,
		locale,
		reducedMotion,
		segments,
		theme,
		total,
		values,
	]);

	return (
		<div className="metric-donut-chart" role="img" aria-label={ariaLabel}>
			<canvas ref={canvasRef} className="metric-donut-chart-canvas" />
			<div className="metric-donut-chart-center" aria-hidden="true">
				<span className="metric-donut-chart-value">{centerValue}</span>
				<span className="metric-donut-chart-label">{totalLabel}</span>
			</div>
		</div>
	);
}
