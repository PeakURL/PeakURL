import { useState } from 'react';
import { TrafficChart } from '@/components';
import { BarChart3, LineChart } from 'lucide-react';
import { __ } from '@/i18n';
import type { TrafficChartType } from '@/components/charts/types';
import type { TrafficOverviewProps } from './types';

const TrafficOverview = ({ trafficData }: TrafficOverviewProps) => {
	const [chartType, setChartType] = useState<TrafficChartType>('line');

	// Check if there's any real data
	const hasData =
		trafficData &&
		Array.isArray(trafficData.labels) &&
		Array.isArray(trafficData.clicks) &&
		Array.isArray(trafficData.unique) &&
		trafficData.labels.length > 0 &&
		(trafficData.clicks.some((val: number) => val > 0) ||
			trafficData.unique.some((val: number) => val > 0));

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<div className="flex items-center justify-between mb-5">
				<h3 className="text-base font-semibold text-heading">
					{__('Traffic Overview')}
				</h3>
				{hasData && (
					<div className="flex items-center gap-6">
						<div className="flex bg-surface-alt rounded-lg p-1">
							<button
								onClick={() => setChartType('line')}
								className={`p-1.5 rounded-md transition-all ${
									chartType === 'line'
										? 'bg-surface shadow-sm text-primary-600 dark:text-primary-400'
										: 'text-text-muted hover:text-heading'
								}`}
								title={__('Line Chart')}
							>
								<LineChart size={16} />
							</button>
							<button
								onClick={() => setChartType('bar')}
								className={`p-1.5 rounded-md transition-all ${
									chartType === 'bar'
										? 'bg-surface shadow-sm text-primary-600 dark:text-primary-400'
										: 'text-text-muted hover:text-heading'
								}`}
								title={__('Bar Chart')}
							>
								<BarChart3 size={16} />
							</button>
						</div>
						<div className="flex items-center gap-4">
							<div className="flex items-center gap-2 text-sm">
								<div className="w-2.5 h-2.5 rounded-full bg-primary-600 dark:bg-primary-400"></div>
								<span className="text-text-muted">
									{__('Clicks')}
								</span>
							</div>
							<div className="flex items-center gap-2 text-sm">
								<div className="w-2.5 h-2.5 rounded-full bg-emerald-600 dark:bg-emerald-500"></div>
								<span className="text-text-muted">
									{__('Unique')}
								</span>
							</div>
						</div>
					</div>
				)}
			</div>
			{hasData ? (
				<TrafficChart data={trafficData} type={chartType} />
			) : (
				<div className="text-center py-8">
					<p className="text-sm text-text-muted">
						{__('No traffic data available')}
					</p>
				</div>
			)}
		</div>
	);
};

export default TrafficOverview;
