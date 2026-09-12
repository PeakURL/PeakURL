import { Trash2 } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { cn } from "@/shared/formatting";

import { ActivityEmptyState } from "./ActivityEmptyState";
import { ActivityRow } from "./ActivityRow";
import type { ActivityCategory, RecentActivity } from "../../types";

interface ActivityTableProps {
	items: RecentActivity[];
	category: ActivityCategory;
	isAdmin: boolean;
	selectedActivityIds: string[];
	isAllSelected: boolean;
	isIndeterminate: boolean;
	onToggleSelectAll: () => void;
	onToggleSelectOne: (id: string) => void;
	onBulkDeleteClick?: () => void;
	onClearAllClick?: () => void;
	onRestoreLink?: (activity: RecentActivity) => void;
	onDeleteActivity?: (activity: RecentActivity) => void;
	isRestoringLink?: boolean;
}

export function ActivityTable({
	items,
	category,
	isAdmin,
	selectedActivityIds,
	isAllSelected,
	isIndeterminate,
	onToggleSelectAll,
	onToggleSelectOne,
	onBulkDeleteClick,
	onClearAllClick,
	onRestoreLink,
	onDeleteActivity,
	isRestoringLink,
}: ActivityTableProps) {
	const hasItems = items.length > 0;
	const selectedCount = selectedActivityIds.length;
	const hasSelection = selectedCount > 0;

	return (
		<div className="activity-page-panel">
			<div className="activity-page-panel-header">
				<div>
					<h2 className="activity-page-panel-title">
						{__("Activity history")}
					</h2>
				</div>
				{isAdmin && hasSelection ? (
					<div className="activity-page-panel-actions">
						<span className="activity-page-panel-selection-count">
							{sprintf(__("%s selected"), String(selectedCount))}
						</span>
						<button
							type="button"
							onClick={onBulkDeleteClick}
							className="activity-page-selection-delete"
						>
							<Trash2 size={13} />
							<span>{__("Delete selected")}</span>
						</button>
						<button
							type="button"
							onClick={onClearAllClick}
							className="activity-page-selection-delete-all"
						>
							<Trash2 size={13} />
							<span>{__("Delete all")}</span>
						</button>
					</div>
				) : null}
			</div>

			<div className="activity-page-table">
				{hasItems ? (
					<div className="activity-page-table-scroll">
						<div className="activity-page-table-element">
							<div
								className={cn(
									"activity-page-table-head",
									isAdmin && "activity-page-table-head-admin"
								)}
							>
								{isAdmin && hasItems ? (
									<input
										type="checkbox"
										checked={isAllSelected}
										onChange={onToggleSelectAll}
										ref={(node) => {
											if (node) {
												node.indeterminate =
													isIndeterminate;
											}
										}}
										className="links-checkbox"
										aria-label={__(
											"Select all events on this page"
										)}
									/>
								) : (
									<span aria-hidden="true"></span>
								)}
								<span>{__("Event")}</span>
								<span>{__("Details")}</span>
								<span>{__("Time")}</span>
								{isAdmin ? (
									<span className="activity-page-table-head-actions">
										{__("Actions")}
									</span>
								) : null}
							</div>

							<div className="activity-page-events">
								{items.map((activity, index) => (
									<ActivityRow
										key={activity.id || `activity-${index}`}
										activity={activity}
										isAdmin={isAdmin}
										isSelected={Boolean(
											activity.id &&
											selectedActivityIds.includes(
												activity.id
											)
										)}
										category={category}
										onToggleSelect={onToggleSelectOne}
										onRestoreLink={onRestoreLink}
										onDeleteActivity={onDeleteActivity}
										isRestoringLink={isRestoringLink}
									/>
								))}
							</div>
						</div>
					</div>
				) : (
					<ActivityEmptyState category={category} />
				)}
			</div>
		</div>
	);
}
