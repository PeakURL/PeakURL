import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { formatDate, getLinkDisplayTitle } from '@/utils';
import { Circle, Link2, MousePointerClick } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { ActivityFeedProps, RecentActivity } from './types';

const ActivityFeed = ({ recentActivities }: ActivityFeedProps) => {
	const formatActivityMessage = (activity: RecentActivity) => {
		// Prioritize title, fallback to shortId, then "Unknown"
		const linkName = getLinkDisplayTitle(
			activity.link?.title,
			activity.link?.shortCode || __('Unknown')
		);

		if (activity.type === 'link_created') {
			return sprintf(__('Created new link %s'), linkName);
		} else if (activity.type === 'click') {
			const location = activity.location
				? sprintf(
						__('from %s'),
						activity.location.city ||
							activity.location.country ||
							__('Unknown')
					)
				: '';
			return location
				? sprintf(__('Link %1$s was clicked %2$s'), [
						linkName,
						location,
					])
				: sprintf(__('Link %s was clicked'), linkName);
		}
		return activity.message || __('Unknown activity');
	};

	// Get activity icon
	const getActivityIcon = (type?: string | null) => {
		if (type === 'link_created') {
			return (
				<Link2 className="w-3 h-3 text-primary-600 dark:text-primary-400" />
			);
		} else if (type === 'click') {
			return (
				<MousePointerClick className="w-3 h-3 text-primary-600 dark:text-primary-400" />
			);
		}
		return (
			<Circle className="h-2 w-2 fill-current stroke-0 text-primary-600 dark:text-primary-400" />
		);
	};

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5 flex flex-col">
			<h3 className="text-base font-semibold text-heading mb-4">
				{__('Recent Activity')}
			</h3>
			<div className="flex-1 space-y-3.5 overflow-y-auto max-h-96">
				{recentActivities.length === 0 ? (
					<div className="text-center py-8">
						<p className="text-sm text-text-muted">
							{__('No recent activity')}
						</p>
					</div>
				) : (
					recentActivities.map(
						(activity: RecentActivity, index: number) => (
							<div
								key={activity.id || index}
								className="flex gap-2.5"
							>
								<div className="w-6 h-6 rounded-full bg-primary-500/10 dark:bg-primary-500/20 flex items-center justify-center shrink-0 mt-0.5">
									{getActivityIcon(activity.type)}
								</div>
								<div className="flex-1 min-w-0">
									<p className="text-sm text-heading leading-relaxed">
										{formatActivityMessage(activity)}
									</p>
									<p className="text-xs text-text-muted mt-1">
										{formatDate(activity.timestamp)}
									</p>
								</div>
							</div>
						)
					)
				)}
			</div>
			<Link to="/dashboard/links" className="mt-4">
				<Button variant="ghost" className="w-full" size="sm">
					{__('View All Links')}
				</Button>
			</Link>
		</div>
	);
};

export default ActivityFeed;
