// @ts-nocheck

import { TrendingUp, TrendingDown, Activity, Clock } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { __, sprintf } from '@/i18n';

function QuickInsights({ link }) {
	// Calculate click rate trend (mock - should come from actual data)
	const clickRate =
		link.uniqueClicks > 0
			? ((link.clicks / link.uniqueClicks) * 100).toFixed(1)
			: 0;
	const avgClicksPerDay = link.createdAt
		? (link.clicks || 0) /
			Math.max(
				1,
				Math.ceil(
					(new Date() - new Date(link.createdAt)) /
						(1000 * 60 * 60 * 24)
				)
			)
		: 0;

	const isActive = link.status === 'active';
	const hasClicks = (link.clicks || 0) > 0;

	const insights = [
		{
			icon: Activity,
			label: __('Status'),
			value: isActive ? __('Active & Tracking') : __('Inactive'),
			color: isActive
				? 'text-green-600 dark:text-green-400'
				: 'text-gray-500',
			bg: isActive ? 'bg-green-500/10' : 'bg-gray-500/10',
		},
		{
			icon: TrendingUp,
			label: __('Engagement'),
			value:
				clickRate > 50
					? __('High')
					: clickRate > 20
						? __('Medium')
						: __('Low'),
			color:
				clickRate > 50
					? 'text-green-600 dark:text-green-400'
					: clickRate > 20
						? 'text-yellow-600 dark:text-yellow-400'
						: 'text-orange-600 dark:text-orange-400',
			bg:
				clickRate > 50
					? 'bg-green-500/10'
					: clickRate > 20
						? 'bg-yellow-500/10'
						: 'bg-orange-500/10',
			subtext: sprintf(__('%s%% click-through rate'), clickRate),
		},
		{
			icon: Clock,
			label: __('Average'),
			value: sprintf(
				__('%s clicks/day'),
				avgClicksPerDay.toFixed(1)
			),
			color: 'text-blue-600 dark:text-blue-400',
			bg: 'bg-blue-500/10',
		},
	];

	return (
		<div className="bg-linear-to-br from-accent/5 to-info/5 border border-accent/20 rounded-lg p-4">
			<div className="flex items-center justify-between mb-3">
				<h3 className="text-sm font-semibold text-heading">
					{__('Quick Insights')}
				</h3>
				<span className="text-xs text-text-muted">
					{__('Last updated:')}{' '}
					{formatDistanceToNow(new Date(), { addSuffix: true })}
				</span>
			</div>

			<div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
				{insights.map((insight, index) => {
					const Icon = insight.icon;
					return (
						<div
							key={index}
							className="bg-surface border border-stroke rounded-lg p-3"
						>
							<div className="flex items-center gap-2 mb-2">
								<div
									className={`w-7 h-7 rounded-lg ${insight.bg} flex items-center justify-center`}
								>
									<Icon
										className={`w-3.5 h-3.5 ${insight.color}`}
									/>
								</div>
								<span className="text-xs text-text-muted">
									{insight.label}
								</span>
							</div>
							<div className="text-sm font-semibold text-heading">
								{insight.value}
							</div>
							{insight.subtext && (
								<div className="text-xs text-muted mt-1">
									{insight.subtext}
								</div>
							)}
						</div>
					);
				})}
			</div>
		</div>
	);
}

export default QuickInsights;
