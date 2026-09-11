import { Trash2 } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { formatCount } from "@/shared/formatting";

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
				<div className="flex items-center gap-3">
					{isAdmin && hasItems ? (
						<div className="activity-page-panel-header-selection">
							<input
								type="checkbox"
								checked={isAllSelected}
								ref={(input) => {
									if (input) {
										input.indeterminate = isIndeterminate;
									}
								}}
								onChange={onToggleSelectAll}
								className="activity-page-checkbox"
								aria-label={__(
									"Select all visible activity logs"
								)}
							/>
						</div>
					) : null}
					<h2 className="activity-page-panel-title">
						{__("Recorded Events")}
					</h2>
					{hasSelection ? (
						<span className="activity-page-panel-selection-count">
							{sprintf(
								__("%s selected"),
								formatCount(selectedCount)
							)}
						</span>
					) : null}
				</div>

				{isAdmin && hasItems ? (
					<div className="activity-page-panel-actions">
						{hasSelection ? (
							<button
								type="button"
								onClick={onBulkDeleteClick}
								className="activity-page-selection-delete"
							>
								<Trash2 size={14} />
								<span>
									{sprintf(
										__("Delete Selected (%s)"),
										formatCount(selectedCount)
									)}
								</span>
							</button>
						) : null}

						<button
							type="button"
							onClick={onClearAllClick}
							className="activity-page-selection-delete-all"
						>
							<Trash2 size={14} />
							<span>{__("Delete All Activity")}</span>
						</button>
					</div>
				) : null}
			</div>

			<div className="activity-page-panel-content">
				{hasItems ? (
					<div className="activity-page-table-wrapper">
						<div className="activity-page-events-list">
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
									onToggleSelect={onToggleSelectOne}
									onRestoreLink={onRestoreLink}
									onDeleteActivity={onDeleteActivity}
									isRestoringLink={isRestoringLink}
								/>
							))}
						</div>
					</div>
				) : (
					<ActivityEmptyState category={category} />
				)}
			</div>
		</div>
	);
}
