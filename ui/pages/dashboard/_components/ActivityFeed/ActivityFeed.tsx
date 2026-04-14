import { Button } from '@/components';
import { __, sprintf } from '@/i18n';
import { cn, formatDate, getLinkDisplayTitle } from '@/utils';
import {
	Link2,
	MousePointerClick,
	PencilLine,
	Shield,
	Trash2,
	UserMinus,
	UserPen,
	UserPlus,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import type {
	ActivityFeedProps,
	ActivityPerson,
	RecentActivity,
} from '../types';

const ActivityFeed = ({
	recentActivities,
	title = __('Recent Activity'),
	emptyText = __('No recent activity'),
	actionLabel = __('View All Activity'),
	actionTo = '/dashboard/activity',
	isScrollable = true,
}: ActivityFeedProps) => {
	const getActivityPersonName = (
		person?: ActivityPerson | null
	): string | null => {
		if (!person) {
			return null;
		}

		const fullName = [person.firstName, person.lastName]
			.filter(Boolean)
			.join(' ')
			.trim();

		return (
			fullName ||
			person.username ||
			person.email ||
			null
		);
	};

	const formatActivityMessage = (activity: RecentActivity) => {
		const linkName = getLinkDisplayTitle(
			activity.link?.title,
			activity.link?.shortCode || __('Unknown')
		);
		const userName =
			getActivityPersonName(activity.user) || __('Unknown user');

		if (activity.type === 'link_created') {
			return sprintf(__('Created new link %s'), linkName);
		} else if (activity.type === 'link_updated') {
			return sprintf(__('Updated link %s'), linkName);
		} else if (activity.type === 'link_deleted') {
			return sprintf(__('Deleted link %s'), linkName);
		} else if (activity.type === 'user_created') {
			return sprintf(__('Created user %s'), userName);
		} else if (activity.type === 'user_updated') {
			return sprintf(__('Updated user %s'), userName);
		} else if (activity.type === 'user_deleted') {
			return sprintf(__('Deleted user %s'), userName);
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
			'link_updated' === type &&
				'dashboard-activity-item-icon-link',
			'link_deleted' === type &&
				'dashboard-activity-item-icon-danger',
			'click' === type && 'dashboard-activity-item-icon-click',
			('user_created' === type ||
				'user_updated' === type) &&
				'dashboard-activity-item-icon-user',
			'user_deleted' === type &&
				'dashboard-activity-item-icon-danger',
			'link_created' !== type &&
				'link_updated' !== type &&
				'link_deleted' !== type &&
				'click' !== type &&
				'user_created' !== type &&
				'user_updated' !== type &&
				'user_deleted' !== type &&
				'dashboard-activity-item-icon-default'
		);

	const getActivityIcon = (type?: string | null) => {
		if (type === 'link_created') {
			return (
				<Link2 className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'link_updated') {
			return (
				<PencilLine className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'link_deleted') {
			return (
				<Trash2 className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'click') {
			return (
				<MousePointerClick className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'user_created') {
			return (
				<UserPlus className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'user_updated') {
			return (
				<UserPen className="dashboard-activity-item-icon-glyph" />
			);
		} else if (type === 'user_deleted') {
			return (
				<UserMinus className="dashboard-activity-item-icon-glyph" />
			);
		}
		return <Shield className="dashboard-activity-item-icon-glyph" />;
	};

	return (
		<div className="dashboard-activity">
			<h3 className="dashboard-activity-title">
				{title}
			</h3>

			<div
				className={cn(
					'dashboard-activity-list',
					!isScrollable && 'dashboard-activity-list-full'
				)}
			>
				{recentActivities.length === 0 ? (
					<div className="dashboard-activity-empty">
						<p className="dashboard-activity-empty-text">
							{emptyText}
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
										{activity.actor
											? sprintf(
													__('By %1$s • %2$s'),
													[
														getActivityPersonName(
															activity.actor
														) || __('Unknown user'),
														formatDate(
															activity.timestamp
														),
													]
												)
											: formatDate(
													activity.timestamp
												)}
									</p>
								</div>
							</div>
						)
					)
				)}
			</div>

			{actionLabel && actionTo ? (
				<Link to={actionTo} className="dashboard-activity-link">
					<Button
						variant="ghost"
						className="dashboard-activity-button"
						size="sm"
					>
						{actionLabel}
					</Button>
				</Link>
			) : null}
		</div>
	);
};

export default ActivityFeed;
