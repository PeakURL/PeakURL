import type { TooltipItem } from "chart.js";
import { Chart } from "chart.js/auto";
import { useEffect, useMemo, useRef } from "react";
import { __ } from "@/i18n";
import { formatCount } from "@/utils";
import { useTheme } from "@/components/providers";
import type { MetricDonutChartProps } from "../types";

const DOUGHNUT_CUTOUT = "72%";
function getMetricTotal(values: number[]): number {
	return values.reduce((total, value) => total + value, 0);
}

function formatTooltipLabel(
	context: TooltipItem<"doughnut">,
	total: number
): string {
	const label = context.label || "";
	const value = Number(context.raw || 0);
	const percentage = total > 0 ? Math.round((value / total) * 100) : 0;

	return `${label}: ${formatCount(value)} (${percentage}%)`;
}

/**
 * Render a compact doughnut chart for grouped dashboard metrics.
 *
 * @param props - Donut chart props.
 * @return The metric donut chart.
 */
export default function MetricDonutChart({
	segments,
	ariaLabel,
	totalValue,
	totalLabel = __("Clicks"),
}: MetricDonutChartProps) {
	const { theme } = useTheme();
	const canvasRef = useRef<HTMLCanvasElement | null>(null);
	const chartRef = useRef<Chart<"doughnut", number[], string> | null>(null);
	const values = useMemo(
		() => segments.map((segment) => segment.value),
		[segments]
	);
	const total = getMetricTotal(values);
	const centerValue = totalValue || formatCount(total);
	const isDarkMode = theme === "dark";

	useEffect(() => {
		const canvas = canvasRef.current;

		if (!canvas) {
			return undefined;
		}

		const context = canvas.getContext("2d");

		if (!context) {
			return undefined;
		}

		if (chartRef.current) {
			chartRef.current.destroy();
		}

		chartRef.current = new Chart(context, {
			type: "doughnut",
			data: {
				labels: segments.map((segment) => segment.label),
				datasets: [
					{
						data: values,
						backgroundColor: segments.map(
							(segment) => segment.color
						),
						borderColor: isDarkMode ? "#111827" : "#ffffff",
						borderWidth: 3,
						hoverOffset: 4,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				cutout: DOUGHNUT_CUTOUT,
				plugins: {
					legend: {
						display: false,
					},
					tooltip: {
						backgroundColor: isDarkMode ? "#111827" : "#ffffff",
						titleColor: isDarkMode ? "#f9fafb" : "#111827",
						bodyColor: isDarkMode ? "#d1d5db" : "#4b5563",
						borderColor: isDarkMode ? "#374151" : "#e5e7eb",
						borderWidth: 1,
						displayColors: false,
						callbacks: {
							label: (context) =>
								formatTooltipLabel(context, total),
						},
					},
				},
			},
		});

		return () => {
			chartRef.current?.destroy();
			chartRef.current = null;
		};
	}, [isDarkMode, segments, total, values]);

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
