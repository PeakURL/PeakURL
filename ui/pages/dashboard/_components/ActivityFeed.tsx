// @ts-nocheck
import { Button } from '@/components/ui';
import { formatDate } from '@/utils';
import { Circle, Link2, MousePointerClick } from 'lucide-react';
import { Link } from 'react-router-dom';

const ActivityFeed = ({ recentActivities }) => {
	// Format activity message
	const formatActivityMessage = (activity) => {
		// Prioritize title, fallback to shortId, then "Unknown"
		const linkName =
			activity.link?.title || activity.link?.shortCode || 'Unknown';

		if (activity.type === 'link_created') {
			return `Created new link <strong>${linkName}</strong>`;
		} else if (activity.type === 'click') {
			const location = activity.location
				? `from ${
						activity.location.city ||
						activity.location.country ||
						'Unknown'
					}`
				: '';
			return `Link <strong>${linkName}</strong> was clicked ${location}`;
		}
		return activity.message || 'Unknown activity';
	};

	// Get activity icon
	const getActivityIcon = (type) => {
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
				Recent Activity
			</h3>
			<div className="flex-1 space-y-3.5 overflow-y-auto max-h-96">
				{recentActivities.length === 0 ? (
					<div className="text-center py-8">
						<p className="text-sm text-text-muted">
							No recent activity
						</p>
					</div>
				) : (
					recentActivities.map((activity, index) => (
						<div
							key={activity.id || index}
							className="flex gap-2.5"
						>
							<div className="w-6 h-6 rounded-full bg-primary-500/10 dark:bg-primary-500/20 flex items-center justify-center shrink-0 mt-0.5">
								{getActivityIcon(activity.type)}
							</div>
							<div className="flex-1 min-w-0">
								<p
									className="text-sm text-heading leading-relaxed"
									dangerouslySetInnerHTML={{
										__html: formatActivityMessage(activity),
									}}
								/>
								<p className="text-xs text-text-muted mt-1">
									{formatDate(activity.timestamp)}
								</p>
							</div>
						</div>
					))
				)}
			</div>
			<Link to="/dashboard/links" className="mt-4">
				<Button variant="ghost" className="w-full" size="sm">
					View All Links
				</Button>
			</Link>
		</div>
	);
};

export default ActivityFeed;
