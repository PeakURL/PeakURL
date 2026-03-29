// @ts-nocheck
'use client';

import { useEffect, useRef } from 'react';
import { Chart } from 'chart.js/auto';

function ClickChart({
	link,
	stats,
	isLoading,
	timeRange,
	setTimeRange,
	selectedTab,
	open,
}) {
	const chartRef = useRef(null);
	const chartInstanceRef = useRef(null);

	useEffect(() => {
		if (selectedTab === 0 && chartRef.current && open && stats?.traffic) {
			// Destroy previous chart instance
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
			}

			const ctx = chartRef.current.getContext('2d');

			const labels = stats.traffic.labels || [];
			const data = stats.traffic.clicks || [];

			chartInstanceRef.current = new Chart(ctx, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [
						{
							label: 'Clicks',
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
		<div className="bg-surface-alt border border-stroke rounded-lg p-4">
			<div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-0 mb-4">
				<h3 className="text-sm font-semibold text-heading">
					Click History
				</h3>
				{/* Time Range Selector */}
				<div className="flex gap-1 bg-surface rounded-lg p-1">
					{[
						{ label: '24h', value: '24h' },
						{ label: '7d', value: '7d' },
						{ label: '30d', value: '30d' },
						{ label: 'All', value: 'all' },
					].map((range) => (
						<button
							key={range.value}
							onClick={() => setTimeRange(range.value)}
							className={`px-3 py-1 text-xs font-medium rounded transition-all ${
								timeRange === range.value
									? 'bg-accent text-white'
									: 'text-text-muted hover:text-heading'
							}`}
						>
							{range.label}
						</button>
					))}
				</div>
			</div>
			<div className="h-64 relative">
				{isLoading ? (
					<div className="absolute inset-0 flex items-center justify-center bg-surface-alt/50 z-10">
						<div className="w-6 h-6 border-2 border-accent border-t-transparent rounded-full animate-spin" />
					</div>
				) : null}
				<canvas ref={chartRef}></canvas>
			</div>
		</div>
	);
}

export default ClickChart;
