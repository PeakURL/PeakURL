import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { cn, formatDate, getLinkDisplayTitle } from '@/utils';
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

	const getActivityIconWrapperClassName = (type?: string | null) =>
		cn(
			'dashboard-activity-item-icon',
			'link_created' === type &&
				'dashboard-activity-item-icon-link',
			'click' === type && 'dashboard-activity-item-icon-click',
			'link_created' !== type &&
				'click' !== type &&
				'dashboard-activity-item-icon-default'
		);

	// Get activity icon
	const getActivityIcon = (type?: string | null) => {
		if (type === 'link_created') {
			return (
				<Link2 className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'click') {
			return (
				<MousePointerClick className="dashboard-activity-item-icon-glyph" />
			);
		}
		return (
			<Circle className="dashboard-activity-item-icon-dot" />
		);
	};

	return (
		<div className="dashboard-activity">
			<h3 className="dashboard-activity-title">
				{__('Recent Activity')}
			</h3>

			<div className="dashboard-activity-list">
				{recentActivities.length === 0 ? (
					<div className="dashboard-activity-empty">
						<p className="dashboard-activity-empty-text">
							{__('No recent activity')}
						</p>
					</div>
				) : (
					recentActivities.map(
						(activity: RecentActivity, index: number) => (
							<div
								key={activity.id || index}
								className="dashboard-activity-item"
							>
								<div
									className={getActivityIconWrapperClassName(
										activity.type
									)}
								>
									{getActivityIcon(activity.type)}
								</div>

								<div className="dashboard-activity-item-copy">
									<p className="dashboard-activity-item-title">
										{formatActivityMessage(activity)}
									</p>
									<p className="dashboard-activity-item-meta">
										{formatDate(activity.timestamp)}
									</p>
								</div>
							</div>
						)
					)
				)}
			</div>

			<Link to="/dashboard/links" className="dashboard-activity-link">
				<Button
					variant="ghost"
					className="dashboard-activity-button"
					size="sm"
				>
					{__('View All Links')}
				</Button>
			</Link>
		</div>
	);
};

export default ActivityFeed;
