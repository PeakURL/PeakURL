import { formatNumber } from '@/utils';
import {
	ArrowDown,
	ArrowUp,
	ChartLine,
	Link2,
	MousePointerClick,
	Users,
} from 'lucide-react';
import { __ } from '@/i18n';
import { cn } from '@/utils';
import type { StatsCardsProps } from '../types';

type DashboardStatTone = 'clicks' | 'links' | 'rate' | 'users';

const StatsCards = ({ stats }: StatsCardsProps) => {
	const statsData = [
		{
			title: __('Total Clicks'),
			value: formatNumber(stats.totalClicks),
			change: '+12.3%',
			changeType: 'positive',
			icon: MousePointerClick,
			tone: 'clicks' as DashboardStatTone,
		},
		{
			title: __('Active Links'),
			value: stats.totalLinks,
			change: `+${((stats.totalLinks || 0) % 5) + 1}`,
			changeType: 'positive',
			icon: Link2,
			tone: 'links' as DashboardStatTone,
		},
		{
			title: __('Click Rate'),
			value: `${stats.conversionRate.toFixed(1)}%`,
			change: '+0.8%',
			changeType: 'positive',
			icon: ChartLine,
			tone: 'rate' as DashboardStatTone,
		},
		{
			title: __('Unique Visitors'),
			value: formatNumber(stats.uniqueClicks),
			change: '+15.2%',
			changeType: 'positive',
			icon: Users,
			tone: 'users' as DashboardStatTone,
		},
	];

	const getIconClassName = (tone: DashboardStatTone) =>
		cn(
			'dashboard-stats-card-icon',
			`dashboard-stats-card-icon-${tone}`
		);

	const getIconGlyphClassName = (tone: DashboardStatTone) =>
		cn(
			'dashboard-stats-card-icon-glyph',
			`dashboard-stats-card-icon-glyph-${tone}`
		);

	const getChangeBadgeClassName = (changeType: string) =>
		cn(
			'dashboard-stats-card-change-badge',
			'positive' === changeType
				? 'dashboard-stats-card-change-badge-positive'
				: 'dashboard-stats-card-change-badge-negative'
		);

	return (
		<div className="dashboard-stats-grid">
			{statsData.map((stat) => {
				const StatIcon = stat.icon;

				return (
					<div key={stat.title} className="dashboard-stats-card">
						<div className="dashboard-stats-card-header">
							<div className="dashboard-stats-card-copy">
								<p className="dashboard-stats-card-title">
									{stat.title}
								</p>
								<p className="dashboard-stats-card-value">
									{stat.value}
								</p>
							</div>
							<div className={getIconClassName(stat.tone)}>
								<StatIcon className={getIconGlyphClassName(stat.tone)} />
							</div>
						</div>

						<div className="dashboard-stats-card-change">
							<span className={getChangeBadgeClassName(stat.changeType)}>
								{stat.changeType === 'positive' ? (
									<ArrowUp className="dashboard-stats-card-change-icon" />
								) : (
									<ArrowDown className="dashboard-stats-card-change-icon" />
								)}
								{stat.change}
							</span>
							<span className="dashboard-stats-card-change-note">
								{__('vs last month')}
							</span>
						</div>
					</div>
				);
			})}
		</div>
	);
};

export default StatsCards;
