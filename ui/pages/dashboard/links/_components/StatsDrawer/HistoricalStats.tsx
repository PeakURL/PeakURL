import { Calendar } from 'lucide-react';
import { __, sprintf } from '@/i18n';
import { formatLocalizedDateTime, formatRelativeTime } from '@/utils';
import type { LinkStatsViewProps } from './types';

function HistoricalStats({ link }: Pick<LinkStatsViewProps, 'link'>) {
	const totalClicks = Number(link.clicks || 0);
	const ageInDays = link.createdAt
		? Math.max(
				1,
				Math.ceil(
					(new Date().getTime() -
						new Date(link.createdAt).getTime()) /
						(1000 * 60 * 60 * 24)
				)
			)
		: 1;

	const stats = [
		{
			period: __('Last 24 hours'),
			hits: totalClicks,
			rate: sprintf(__('%s per hour'), (totalClicks / 24).toFixed(2)),
			highlighted: true,
		},
		{
			period: __('Last 7 days'),
			hits: totalClicks,
			rate: null,
			highlighted: false,
		},
		{
			period: __('Last 30 days'),
			hits: totalClicks,
			rate: null,
			highlighted: false,
		},
		{
			period: __('All time'),
			hits: totalClicks,
			rate: link.createdAt
				? sprintf(
						__('%s per day'),
						(totalClicks / ageInDays).toFixed(1)
					)
				: __('0 per day'),
			highlighted: true,
		},
	];

	return (
		<div className="bg-surface-alt border border-stroke rounded-lg p-4">
			<div className="flex items-center gap-2 mb-1">
				<Calendar className="w-4 h-4 text-accent" />
				<h3 className="text-base font-semibold text-heading">
					{__('Historical click count')}
				</h3>
			</div>
			<p className="text-sm text-text-muted mb-4">
				{__('Short URL created on')}{' '}
				{link.createdAt
					? formatLocalizedDateTime(new Date(link.createdAt), {
							year: 'numeric',
							month: 'long',
							day: 'numeric',
							hour: 'numeric',
							minute: '2-digit',
						})
					: __('Unknown')}{' '}
				(
				{link.createdAt
					? formatRelativeTime(new Date(link.createdAt), {
							style: 'long',
							numeric: 'always',
						})
					: __('Unknown')}
				)
			</p>

			{/* Time Range Stats Table */}
			<div className="space-y-1">
				{stats.map((stat, index) => (
					<div
						key={index}
						className={`flex items-center py-2.5 px-3 rounded transition-all hover:bg-accent/10 ${
							stat.highlighted
								? 'bg-accent/5 border border-accent/20'
								: ''
						}`}
					>
						<span
							className={`text-sm w-24 sm:w-32 ${
								stat.highlighted
									? 'font-medium text-accent'
									: 'text-text-muted'
							}`}
						>
							{stat.period}
						</span>
						<span className="text-sm font-semibold text-heading flex-1">
							{stat.hits}{' '}
							{stat.hits === 1 ? __('hit') : __('hits')}
						</span>
						{stat.rate && (
							<span className="text-sm text-text-muted">
								{stat.rate}
							</span>
						)}
					</div>
				))}
			</div>
		</div>
	);
}

export default HistoricalStats;
