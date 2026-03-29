// @ts-nocheck
'use client';
import { formatNumber } from '@/utils';
import {
	ArrowDown,
	ArrowUp,
	ChartLine,
	Link2,
	MousePointerClick,
	Users,
} from 'lucide-react';

const StatsCards = ({ stats }) => {
	const statsData = [
		{
			title: 'Total Clicks',
			value: formatNumber(stats.totalClicks),
			change: '+12.3%',
			changeType: 'positive',
			icon: MousePointerClick,
			bgColor: 'bg-blue-500/10 dark:bg-blue-500/20',
			iconColor: 'text-blue-600 dark:text-blue-400',
		},
		{
			title: 'Active Links',
			value: stats.totalLinks,
			change: `+${((stats.totalLinks || 0) % 5) + 1}`,
			changeType: 'positive',
			icon: Link2,
			bgColor: 'bg-emerald-500/10 dark:bg-emerald-500/20',
			iconColor: 'text-emerald-600 dark:text-emerald-400',
		},
		{
			title: 'Click Rate',
			value: `${stats.conversionRate.toFixed(1)}%`,
			change: '+0.8%',
			changeType: 'positive',
			icon: ChartLine,
			bgColor: 'bg-purple-500/10 dark:bg-purple-500/20',
			iconColor: 'text-purple-600 dark:text-purple-400',
		},
		{
			title: 'Unique Visitors',
			value: formatNumber(stats.uniqueClicks),
			change: '+15.2%',
			changeType: 'positive',
			icon: Users,
			bgColor: 'bg-orange-500/10 dark:bg-orange-500/20',
			iconColor: 'text-orange-600 dark:text-orange-400',
		},
	];

	return (
		<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
			{statsData.map((stat) => {
				const StatIcon = stat.icon;

				return (
					<div
						key={stat.title}
						className="bg-surface border border-stroke rounded-lg p-4 hover:shadow-md transition-shadow"
					>
						<div className="flex items-start justify-between mb-3">
							<div className="flex-1">
								<p className="text-xs font-medium text-text-muted uppercase tracking-wide mb-2">
									{stat.title}
								</p>
								<p className="text-2xl font-bold text-heading">
									{stat.value}
								</p>
							</div>
							<div
								className={`w-10 h-10 rounded-lg ${stat.bgColor} flex items-center justify-center shrink-0`}
							>
								<StatIcon
									className={`h-4 w-4 ${stat.iconColor}`}
								/>
							</div>
						</div>
						<div className="flex items-center gap-2">
							<span
								className={`inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded ${
									stat.changeType === 'positive'
										? 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/30'
										: 'text-red-700 dark:text-red-300 bg-red-100 dark:bg-red-900/30'
								}`}
							>
								{stat.changeType === 'positive' ? (
									<ArrowUp className="mr-1 h-3 w-3" />
								) : (
									<ArrowDown className="mr-1 h-3 w-3" />
								)}
								{stat.change}
							</span>
							<span className="text-xs text-text-muted">
								vs last month
							</span>
						</div>
					</div>
				);
			})}
		</div>
	);
};

export default StatsCards;
