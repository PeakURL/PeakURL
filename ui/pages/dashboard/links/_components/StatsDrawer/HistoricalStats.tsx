// @ts-nocheck

import { format, formatDistanceToNow } from 'date-fns';
import { Calendar } from 'lucide-react';
import { __, sprintf } from '@/i18n';

function HistoricalStats({ link }) {
	const stats = [
		{
			period: __('Last 24 hours'),
			hits: link.clicks || 0,
			rate: sprintf(
				__('%s per hour'),
				((link.clicks || 0) / 24).toFixed(2)
			),
			highlighted: true,
		},
		{
			period: __('Last 7 days'),
			hits: link.clicks || 0,
			rate: null,
			highlighted: false,
		},
		{
			period: __('Last 30 days'),
			hits: link.clicks || 0,
			rate: null,
			highlighted: false,
		},
		{
			period: __('All time'),
			hits: link.clicks || 0,
			rate: link.createdAt
				? sprintf(
						__('%s per day'),
						(
							(link.clicks || 0) /
							Math.max(
								1,
								Math.ceil(
									(new Date() - new Date(link.createdAt)) /
										(1000 * 60 * 60 * 24)
								)
							)
						).toFixed(1)
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
					? format(
							new Date(link.createdAt),
							"MMMM d, yyyy '@' h:mm a"
						)
					: __('Unknown')}{' '}
				(
				{link.createdAt
					? formatDistanceToNow(new Date(link.createdAt), {
							addSuffix: true,
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
