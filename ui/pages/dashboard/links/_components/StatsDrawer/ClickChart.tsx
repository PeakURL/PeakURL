import { useEffect, useRef } from 'react';
import { Chart } from 'chart.js/auto';
import { __ } from '@/i18n';
import type { ClickChartProps, StatsTimeRange } from './types';

const timeRangeOptions: Array<{ label: string; value: StatsTimeRange }> = [
	{ label: '24h', value: '24h' },
	{ label: '7d', value: '7d' },
	{ label: '30d', value: '30d' },
	{ label: __('All'), value: 'all' },
];

function ClickChart({
	link,
	stats,
	isLoading,
	timeRange,
	setTimeRange,
	selectedTab,
	open,
}: ClickChartProps) {
	const chartRef = useRef<HTMLCanvasElement | null>(null);
	const chartInstanceRef = useRef<Chart<'line', number[], string> | null>(
		null
	);

	useEffect(() => {
		if (selectedTab === 0 && chartRef.current && open && stats?.traffic) {
			// Destroy previous chart instance
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
			}

			const ctx = chartRef.current.getContext('2d');

			if (!ctx) {
				return undefined;
			}

			const labels = stats.traffic.labels || [];
			const data = stats.traffic.clicks || [];

			chartInstanceRef.current = new Chart(ctx, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [
						{
							label: __('Clicks'),
							data: data,
							borderColor: 'rgb(59, 130, 246)',
							backgroundColor: 'rgba(59, 130, 246, 0.1)',
							fill: true,
							tension: 0.4,
							pointRadius: 4,
							pointHoverRadius: 6,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: false,
						},
						tooltip: {
							mode: 'index',
							intersect: false,
							backgroundColor: 'rgba(0, 0, 0, 0.8)',
							padding: 12,
							borderColor: 'rgba(255, 255, 255, 0.1)',
							borderWidth: 1,
						},
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: {
								stepSize: 1,
								color: '#9CA3AF',
							},
							grid: {
								color: 'rgba(156, 163, 175, 0.1)',
							},
						},
						x: {
							ticks: {
								color: '#9CA3AF',
							},
							grid: {
								display: false,
							},
						},
					},
				},
			});
		}

		return () => {
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
			}
		};
	}, [selectedTab, timeRange, open, link, stats]);

	return (
		<div className="links-click-chart">
			<div className="links-click-chart-header">
				<h3 className="links-click-chart-title">
					{__('Click History')}
				</h3>
				{/* Time Range Selector */}
				<div className="links-click-chart-ranges">
					{timeRangeOptions.map((range) => (
						<button
							key={range.value}
							type="button"
							onClick={() => setTimeRange(range.value)}
							className={`links-click-chart-range ${
								timeRange === range.value
									? 'links-click-chart-range-current'
									: 'links-click-chart-range-idle'
							}`}
						>
							{range.label}
						</button>
					))}
				</div>
			</div>
			<div className="links-click-chart-canvas-wrap">
				{isLoading ? (
					<div className="links-click-chart-loading">
						<div className="links-click-chart-spinner" />
					</div>
				) : null}
				<canvas ref={chartRef}></canvas>
			</div>
		</div>
	);
}

export default ClickChart;
