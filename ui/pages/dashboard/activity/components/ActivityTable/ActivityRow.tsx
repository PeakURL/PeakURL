import { Link2, MapPin, RotateCcw, Trash2, User } from "lucide-react";

import { __ } from "@/i18n";
import { cn, formatDate } from "@/shared/formatting";

import {
	formatExactTimestamp,
	getActivityLinkDisplayName,
	getActivityMessage,
	getActivityPersonName,
	getActivityVisual,
	getRoleLabel,
} from "../../lib";
import type { RecentActivity } from "../../types";

interface ActivityRowProps {
	activity: RecentActivity;
	isAdmin: boolean;
	isSelected: boolean;
	onToggleSelect: (id: string) => void;
	onRestoreLink?: (activity: RecentActivity) => void;
	onDeleteActivity?: (activity: RecentActivity) => void;
	isRestoringLink?: boolean;
}

export function ActivityRow({
	activity,
	isAdmin,
	isSelected,
	onToggleSelect,
	onRestoreLink,
	onDeleteActivity,
	isRestoringLink,
}: ActivityRowProps) {
	const visual = getActivityVisual(activity.type);
	const IconComponent = visual.icon;
	const message = getActivityMessage(activity);
	const exactTime = formatExactTimestamp(activity.timestamp);
	const personName = getActivityPersonName(activity.user);
	const roleLabel = getRoleLabel(activity.user?.role);
	const linkSlug = getActivityLinkDisplayName(activity.link);

	const isRestorable =
		activity.isRestorable === true && activity.linkStatus === "trashed";
	const isAlreadyActive = activity.linkStatus === "active";
	const isPermanentlyDeleted =
		activity.linkStatus === "deleted" || activity.type === "link_deleted";

	const showRestoreButton =
		isAdmin &&
		activity.id &&
		activity.link?.destinationUrl &&
		("link_deleted" === activity.type ||
			"link_trashed" === activity.type) &&
		!(isPermanentlyDeleted && !isRestorable);

	return (
		<article className="activity-page-event">
			{isAdmin && activity.id ? (
				<div className="activity-page-event-selection">
					<input
						type="checkbox"
						checked={isSelected}
						onChange={() => {
							if (activity.id) {
								onToggleSelect(activity.id);
							}
						}}
						className="activity-page-checkbox"
						aria-label={message}
					/>
				</div>
			) : null}

			<div
				className={`activity-page-event-icon activity-page-event-icon-${visual.tone}`}
			>
				<IconComponent className="h-4 w-4" />
			</div>

			<div className="activity-page-event-body">
				<p className="activity-page-event-message">{message}</p>

				<div className="activity-page-event-meta">
					{/* Location */}
					{activity.location?.country || activity.location?.city ? (
						<span className="activity-page-event-meta-item">
							<MapPin className="h-3 w-3 shrink-0 text-text-muted" />
							<span>
								{[
									activity.location.city,
									activity.location.country,
								]
									.filter(Boolean)
									.join(", ")}
							</span>
						</span>
					) : null}

					{/* User */}
					{personName ? (
						<span className="activity-page-event-meta-item">
							<User className="h-3 w-3 shrink-0 text-text-muted" />
							<span>{personName}</span>
							<span className="activity-page-event-role-badge">
								{roleLabel}
							</span>
						</span>
					) : null}

					{/* Link */}
					{activity.link?.shortCode || activity.link?.alias ? (
						<span className="activity-page-event-meta-item">
							<Link2 className="h-3 w-3 shrink-0 text-text-muted" />
							<span>{linkSlug}</span>
						</span>
					) : null}
				</div>
			</div>

			<div className="activity-page-event-time">
				<p className="activity-page-event-time-relative">
					{formatDate(activity.timestamp)}
				</p>
				{exactTime ? (
					<p className="activity-page-event-time-exact" dir="auto">
						{exactTime}
					</p>
				) : null}
			</div>

			{isAdmin ? (
				<div className="activity-page-event-actions">
					{showRestoreButton ? (
						<button
							type="button"
							onClick={() => onRestoreLink?.(activity)}
							disabled={!isRestorable || isRestoringLink}
							className={cn(
								"activity-page-event-action activity-page-event-action-restore",
								!isRestorable &&
									"pointer-events-none cursor-not-allowed opacity-30"
							)}
							aria-label={
								isAlreadyActive
									? __("Link is already active")
									: __("Restore link")
							}
							title={
								isAlreadyActive
									? __("Link is already active")
									: __("Restore link")
							}
						>
							<RotateCcw size={14} />
						</button>
					) : null}

					{activity.id ? (
						<button
							type="button"
							onClick={() => onDeleteActivity?.(activity)}
							className="activity-page-event-action activity-page-event-action-delete"
							aria-label={__("Delete activity log")}
							title={__("Delete activity log")}
						>
							<Trash2 size={14} />
						</button>
					) : null}
				</div>
			) : null}
		</article>
	);
}
