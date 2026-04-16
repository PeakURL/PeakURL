import { MousePointerClick, Users, Calendar, TrendingUp } from 'lucide-react';
import { formatNumber, formatRelativeTime } from '@/utils';
import { __ } from '@/i18n';
import type { LinkStatsViewProps } from './types';

function StatCards({
	link,
	stats: fetchedStats,
	isLoading,
}: LinkStatsViewProps) {
	const totalClicks = Number(link.clicks || 0);
	const uniqueClicks = Number(link.uniqueClicks || 0);
	const uniqueClickRate = fetchedStats
		? Number(
				fetchedStats.uniqueClickRate ?? fetchedStats.conversionRate ?? 0
			)
		: totalClicks > 0
			? Number(((uniqueClicks / totalClicks) * 100).toFixed(1))
			: 0;

	const displayStats = [
		{
			name: __('Total Clicks'),
			value: isLoading
				? '...'
				: formatNumber(
						fetchedStats ? fetchedStats.totalClicks : totalClicks
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
						fetchedStats ? fetchedStats.uniqueClicks : uniqueClicks
					),
			icon: Users,
			color: 'text-purple-600 dark:text-purple-400',
			bg: 'bg-purple-500/10',
		},
		{
			name: __('Created'),
			value: link.createdAt
				? formatRelativeTime(new Date(link.createdAt), {
						style: 'long',
						numeric: 'always',
					})
				: __('Unknown'),
			icon: Calendar,
			color: 'text-green-600 dark:text-green-400',
			bg: 'bg-green-500/10',
		},
		{
			name: __('Unique Click Rate'),
			value: isLoading
				? '...'
				: `${uniqueClickRate}%`,
			icon: TrendingUp,
			color: 'text-orange-600 dark:text-orange-400',
			bg: 'bg-orange-500/10',
		},
	];

	return (
		<div className="links-stat-cards">
			{displayStats.map((stat) => {
				const Icon = stat.icon;
				return (
					<div key={stat.name} className="links-stat-card">
						<div className="links-stat-card-row">
							<div
								className={`links-stat-card-icon ${stat.bg}`}
							>
								<Icon className={`w-3.5 h-3.5 ${stat.color}`} />
							</div>
							<span className="links-stat-card-label">
								{stat.name}
							</span>
						</div>
						<div className="links-stat-card-value">
							{stat.value}
						</div>
					</div>
				);
			})}
		</div>
	);
}

export default StatCards;
