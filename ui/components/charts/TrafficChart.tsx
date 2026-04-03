// @ts-nocheck
'use client';
import { useEffect, useRef, useState } from 'react';
import { Chart } from 'chart.js/auto';
import { __ } from '@/i18n';

/**
 * TrafficChart Component
 * Visualizes traffic data (clicks and unique visitors) using Chart.js
 * @param {Object} props
 * @param {Object} props.data - Chart data containing labels, clicks, and unique arrays
 * @param {string} [props.timeRange="7d"] - Selected time range filter
 * @param {string} [props.type="line"] - Chart type (line or bar)
 */
export function TrafficChart({ data, timeRange = '7d', type = 'line' }) {
	const chartRef = useRef(null);
	const chartInstanceRef = useRef(null);
	const [isDark, setIsDark] = useState(false);

	// Detect dark mode
	useEffect(() => {
		const checkDarkMode = () => {
			setIsDark(document.documentElement.classList.contains('dark'));
		};

		checkDarkMode();
		const observer = new MutationObserver(checkDarkMode);
		observer.observe(document.documentElement, {
			attributes: true,
			attributeFilter: ['class'],
		});

		return () => observer.disconnect();
	}, []);

	useEffect(() => {
		if (!chartRef.current) return;
		let isCancelled = false;

		const initChart = async () => {
			const ctx = chartRef.current.getContext('2d');

			// Destroy any existing chart on this canvas
			const existingChart = Chart.getChart(chartRef.current);
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
				? 'rgb(99, 102, 241)'
				: 'rgb(79, 70, 229)';
			const uniqueColor = isDark
				? 'rgb(16, 185, 129)'
				: 'rgb(5, 150, 105)';

			// Create gradients
			const clicksGradient = ctx.createLinearGradient(0, 0, 0, 300);
			clicksGradient.addColorStop(
				0,
				isDark ? 'rgba(99, 102, 241, 0.2)' : 'rgba(99, 102, 241, 0.15)'
			);
			clicksGradient.addColorStop(
				1,
				isDark ? 'rgba(99, 102, 241, 0)' : 'rgba(99, 102, 241, 0)'
			);

			const uniqueGradient = ctx.createLinearGradient(0, 0, 0, 300);
			uniqueGradient.addColorStop(
				0,
				isDark ? 'rgba(16, 185, 129, 0.2)' : 'rgba(16, 185, 129, 0.15)'
			);
			uniqueGradient.addColorStop(
				1,
				isDark ? 'rgba(16, 185, 129, 0)' : 'rgba(16, 185, 129, 0)'
			);

			// Use provided data if it exists and has the right structure, otherwise use demo data
			const hasValidStructure =
				data &&
				Array.isArray(data.labels) &&
				Array.isArray(data.clicks) &&
				Array.isArray(data.unique) &&
				data.labels.length > 0;

			const chartData = hasValidStructure
				? data
				: {
						labels: [
							__('Mon'),
							__('Tue'),
							__('Wed'),
							__('Thu'),
							__('Fri'),
							__('Sat'),
							__('Sun'),
						],
						clicks: [420, 380, 520, 478, 589, 639, 749],
						unique: [340, 289, 420, 390, 480, 520, 630],
					};

			// Theme colors
			const textColor = isDark
				? 'rgb(156, 163, 175)'
				: 'rgb(107, 114, 128)';
			const gridColor = isDark
				? 'rgba(75, 85, 99, 0.3)'
				: 'rgba(229, 231, 235, 0.8)';

			chartInstanceRef.current = new Chart(chartRef.current, {
				type: type,
				data: {
					labels: chartData.labels,
					datasets: [
						{
							label: __('Total Clicks'),
							data: chartData.clicks,
							borderColor: clicksColor,
							backgroundColor:
								type === 'bar' ? clicksColor : clicksGradient,
							fill: true,
							tension: 0.4,
							borderWidth: 2,
							pointRadius: 0,
							pointHoverRadius: 6,
							pointBackgroundColor: isDark
								? 'rgb(17, 24, 39)'
								: 'white',
							pointBorderColor: clicksColor,
							pointBorderWidth: 2,
							pointHoverBackgroundColor: clicksColor,
							pointHoverBorderColor: isDark
								? 'rgb(17, 24, 39)'
								: 'white',
							pointHoverBorderWidth: 2,
							borderRadius: 4,
						},
						{
							label: __('Unique Visitors'),
							data: chartData.unique,
							borderColor: uniqueColor,
							backgroundColor:
								type === 'bar' ? uniqueColor : uniqueGradient,
							fill: true,
							tension: 0.4,
							borderWidth: 2,
							pointRadius: 0,
							pointHoverRadius: 6,
							pointBackgroundColor: isDark
								? 'rgb(17, 24, 39)'
								: 'white',
							pointBorderColor: uniqueColor,
							pointBorderWidth: 2,
							pointHoverBackgroundColor: uniqueColor,
							pointHoverBorderColor: isDark
								? 'rgb(17, 24, 39)'
								: 'white',
							pointHoverBorderWidth: 2,
							borderRadius: 4,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false,
					},
					plugins: {
						legend: {
							display: false,
						},
						tooltip: {
							enabled: true,
							backgroundColor: isDark
								? 'rgb(31, 41, 55)'
								: 'white',
							titleColor: isDark
								? 'rgb(243, 244, 246)'
								: 'rgb(17, 24, 39)',
							bodyColor: isDark
								? 'rgb(209, 213, 219)'
								: 'rgb(55, 65, 81)',
							borderColor: isDark
								? 'rgb(75, 85, 99)'
								: 'rgb(229, 231, 235)',
							borderWidth: 1,
							padding: 12,
							boxPadding: 6,
							usePointStyle: true,
							callbacks: {
								title: function (context) {
									return context[0].label;
								},
								label: function (context) {
									return ` ${
										context.dataset.label
									}: ${context.parsed.y.toLocaleString()}`;
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
								drawBorder: false,
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
								callback: function (value) {
									if (value >= 1000) {
										return (value / 1000).toFixed(1) + 'k';
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
			isCancelled = true;
			if (chartInstanceRef.current) {
				chartInstanceRef.current.destroy();
				chartInstanceRef.current = null;
			}
		};
	}, [data, timeRange, isDark, type]);

	return (
		<div className="h-80 w-full">
			<canvas ref={chartRef} />
		</div>
	);
}
