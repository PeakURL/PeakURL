// @ts-nocheck
'use client';

import { MousePointerClick, Users, Calendar, TrendingUp } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { formatNumber } from '@/utils';
import { __ } from '@/i18n';

function StatCards({ link, stats: fetchedStats, isLoading }) {
	const displayStats = [
		{
			name: __('Total Clicks'),
			value: isLoading
				? '...'
				: formatNumber(
						fetchedStats
							? fetchedStats.totalClicks
							: link.clicks || 0
					),
			icon: MousePointerClick,
			color: 'text-blue-600 dark:text-blue-400',
			bg: 'bg-blue-500/10',
		},
		{
			name: __('Unique Visitors'),
			value: isLoading
				? '...'
				: formatNumber(
						fetchedStats
							? fetchedStats.uniqueClicks
							: link.uniqueClicks || 0
					),
			icon: Users,
			color: 'text-purple-600 dark:text-purple-400',
			bg: 'bg-purple-500/10',
		},
		{
			name: __('Created'),
			value: link.createdAt
				? formatDistanceToNow(new Date(link.createdAt), {
						addSuffix: true,
					})
				: __('Unknown'),
			icon: Calendar,
			color: 'text-green-600 dark:text-green-400',
			bg: 'bg-green-500/10',
		},
		{
			name: __('Click Rate'),
			value: isLoading
				? '...'
				: fetchedStats
					? `${fetchedStats.conversionRate}%`
					: link.uniqueClicks > 0
						? `${((link.clicks / link.uniqueClicks) * 100).toFixed(1)}%`
						: '0%',
			icon: TrendingUp,
			color: 'text-orange-600 dark:text-orange-400',
			bg: 'bg-orange-500/10',
		},
	];

	return (
		<div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
			{displayStats.map((stat) => {
				const Icon = stat.icon;
				return (
					<div
						key={stat.name}
						className="bg-surface-alt border border-stroke rounded-lg p-2.5 hover:shadow-md hover:border-accent/30 transition-all duration-200 group"
					>
						<div className="flex items-center gap-1.5 mb-1.5">
							<div
								className={`w-7 h-7 rounded-lg ${stat.bg} flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform duration-200`}
							>
								<Icon className={`w-3.5 h-3.5 ${stat.color}`} />
							</div>
							<span className="text-xs text-text-muted">
								{stat.name}
							</span>
						</div>
						<div className="text-lg font-bold text-heading">
							{stat.value}
						</div>
					</div>
				);
			})}
		</div>
	);
}

export default StatCards;
