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
		<div className="links-historical-stats">
			<div className="links-historical-stats-header">
				<Calendar className="links-drawer-section-icon" />
				<h3 className="links-historical-stats-title">
					{__('Historical click count')}
				</h3>
			</div>
			<p className="links-historical-stats-copy">
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
			<div className="links-historical-stats-list">
				{stats.map((stat, index) => (
					<div
						key={index}
						className={`links-historical-stats-item ${
							stat.highlighted
								? 'links-historical-stats-item-highlighted'
								: ''
						}`}
					>
						<span
							className={`links-historical-stats-period ${
								stat.highlighted
									? 'links-historical-stats-period-highlighted'
									: 'links-historical-stats-period-muted'
							}`}
						>
							{stat.period}
						</span>
						<span className="links-historical-stats-value">
							{stat.hits}{' '}
							{stat.hits === 1 ? __('hit') : __('hits')}
						</span>
						{stat.rate && (
							<span className="links-historical-stats-rate">
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
