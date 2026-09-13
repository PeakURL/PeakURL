import { Globe, MapPin, RotateCcw, Trash2, User } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { cn, formatDate } from "@/shared/formatting";

import {
	formatExactTimestamp,
	getActivityMessage,
	getActivityPersonName,
	getActivityVisual,
	getRoleLabel,
} from "../../lib";
import type { ActivityCategory, RecentActivity } from "../../types";

interface ActivityRowProps {
	activity: RecentActivity;
	isAdmin: boolean;
	isSelected: boolean;
	category: ActivityCategory;
	onToggleSelect: (id: string) => void;
	onRestoreLink?: (activity: RecentActivity) => void;
	onDeleteActivity?: (activity: RecentActivity) => void;
	isRestoringLink?: boolean;
}

export function ActivityRow({
	activity,
	isAdmin,
	isSelected,
	category,
	onToggleSelect,
	onRestoreLink,
	onDeleteActivity,
	isRestoringLink,
}: ActivityRowProps) {
	const visual = getActivityVisual(activity.type);
	const Icon = visual.icon;
	const message = getActivityMessage(activity);
	const actorName = getActivityPersonName(activity.actor);
	const userName = getActivityPersonName(activity.user);
	const locationName = activity.location
		? activity.location.city || activity.location.country || null
		: null;
	const exactTime = formatExactTimestamp(activity.timestamp);
	const destinationUrl = activity.link?.destinationUrl;
	const hasTargetUser = Boolean(userName && userName !== actorName);

	return (
		<article
			className={cn(
				"activity-page-event",
				activity.id && isSelected && "activity-page-event-selected",
				isAdmin && "activity-page-event-admin"
			)}
		>
			<div className="activity-page-event-identity">
				{isAdmin && activity.id ? (
					<input
						type="checkbox"
						checked={isSelected}
						onChange={() => onToggleSelect(activity.id as string)}
						className="links-checkbox"
						aria-label={message}
					/>
				) : null}
				<div
					className={cn(
						"activity-page-event-icon",
						`activity-page-event-icon-${visual.tone}`
					)}
				>
					<Icon size={17} />
				</div>
			</div>

			<div className="activity-page-event-primary">
				<p className="activity-page-event-title" dir="auto">
					{message}
				</p>
				{activity.user?.role && "users" === category ? (
					<span className="activity-page-event-role-badge">
						{getRoleLabel(activity.user.role)}
					</span>
				) : null}
			</div>

			<div className="activity-page-event-context">
				{actorName ? (
					<div
						className="activity-page-detail-item"
						title={sprintf(__("Actor: %s"), actorName)}
					>
						<User
							size={13}
							className="text-text-muted/70 shrink-0"
						/>
						<span className="activity-page-detail-actor">
							<span className="text-text-muted/70 font-normal">
								{__("By")}{" "}
							</span>
							<span className="font-medium text-heading">
								{actorName}
							</span>
						</span>
					</div>
				) : null}
				{destinationUrl ? (
					<div
						className="activity-page-detail-item activity-page-detail-destination"
						title={destinationUrl}
					>
						<Globe
							size={13}
							className="text-text-muted/70 shrink-0"
						/>
						<span
							className="activity-page-detail-destination-url truncate"
							dir="ltr"
						>
							{destinationUrl}
						</span>
					</div>
				) : null}
				{hasTargetUser && userName ? (
					<div
						className="activity-page-detail-item"
						title={sprintf(__("User: %s"), userName)}
					>
						<User
							size={13}
							className="text-text-muted/70 shrink-0"
						/>
						<span className="activity-page-detail-user font-medium text-heading">
							{userName}
						</span>
					</div>
				) : null}
				{locationName ? (
					<div
						className="activity-page-detail-item"
						title={sprintf(__("Location: %s"), locationName)}
					>
						<MapPin
							size={13}
							className="text-text-muted/70 shrink-0"
						/>
						<span className="activity-page-detail-location">
							{locationName}
						</span>
					</div>
				) : null}
				{!actorName &&
				!destinationUrl &&
				!hasTargetUser &&
				!locationName ? (
					<span className="activity-page-event-detail-empty">—</span>
				) : null}
			</div>

			<div className="activity-page-event-time">
				<p className="activity-page-event-time-relative" dir="auto">
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
					{activity.id &&
					activity.link?.destinationUrl &&
					("link_deleted" === activity.type ||
						"link_trashed" === activity.type)
						? (() => {
								const isRestorable =
									activity.isRestorable === true &&
									activity.linkStatus === "trashed";
								const isAlreadyActive =
									activity.linkStatus === "active";
								const isPermanentlyDeleted =
									activity.linkStatus === "deleted" ||
									activity.type === "link_deleted";

								if (isPermanentlyDeleted && !isRestorable) {
									return null;
								}

								return (
									<button
										type="button"
										onClick={() =>
											onRestoreLink?.(activity)
										}
										disabled={
											!isRestorable || isRestoringLink
										}
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
								);
							})()
						: null}
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
