import { useState } from "react";

import { useNotification } from "@/components";
import { useAdminAccess } from "@/hooks";
import { __, sprintf } from "@/i18n";
import {
	useBulkDeleteActivityLogsMutation,
	useClearActivityLogsMutation,
	useDeleteActivityLogMutation,
	useRestoreActivityLinkMutation,
} from "@/store/slices/api";
import { getErrorMessage } from "@/utils";

import {
	ActivityCategoryTabs,
	ActivityHeader,
	ActivityModals,
	ActivityPagination,
	ActivitySkeleton,
	ActivityTable,
} from "./components";
import { useActivityFilter, useActivitySelection } from "./hooks";
import type { RecentActivity } from "./types";

function ActivityPage() {
	const { isAdmin } = useAdminAccess();
	const notifications = useNotification();

	const {
		category,
		setCategory,
		setCurrentPage,
		limit,
		setLimit,
		items,
		meta,
		summaryCounts,
		isLoading,
		isFetching,
		refetch,
	} = useActivityFilter();

	const {
		selectedActivityIds,
		isAllSelected,
		isIndeterminate,
		toggleSelectAll,
		toggleSelectOne,
		clearSelection,
		selectedCount,
	} = useActivitySelection(items);

	const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
	const [clearAllOpen, setClearAllOpen] = useState(false);
	const [activityPendingDelete, setActivityPendingDelete] =
		useState<RecentActivity | null>(null);

	const [deleteActivityLog, { isLoading: isDeletingActivity }] =
		useDeleteActivityLogMutation();
	const [bulkDeleteActivityLogs, { isLoading: isBulkDeletingActivities }] =
		useBulkDeleteActivityLogsMutation();
	const [clearActivityLogs, { isLoading: isClearingAllActivities }] =
		useClearActivityLogsMutation();
	const [restoreActivityLink, { isLoading: isRestoringLink }] =
		useRestoreActivityLinkMutation();

	const handleRefresh = async () => {
		try {
			await refetch();
		} catch {
			// Handled by RTK Query
		}
	};

	const handleRestoreActivityLink = async (activity: RecentActivity) => {
		if (!activity.id) return;

		try {
			await restoreActivityLink(activity.id).unwrap();
			notifications.success(
				__("Link restored"),
				__("The link has been restored to active status.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to restore link"),
				getErrorMessage(err, __("Failed to restore link."))
			);
		}
	};

	const handleDeleteActivity = async () => {
		if (!activityPendingDelete?.id) return;

		try {
			await deleteActivityLog(activityPendingDelete.id).unwrap();
			notifications.success(
				__("Activity deleted"),
				__("The activity log entry has been deleted.")
			);
			setActivityPendingDelete(null);
		} catch (err) {
			notifications.error(
				__("Unable to delete activity"),
				getErrorMessage(err, __("Failed to delete activity log."))
			);
		}
	};

	const handleBulkDeleteActivities = async () => {
		if (selectedActivityIds.length === 0) return;

		try {
			await bulkDeleteActivityLogs(selectedActivityIds).unwrap();
			notifications.success(
				__("Activities deleted"),
				sprintf(
					__("Deleted %s activity log entries."),
					String(selectedCount)
				)
			);
			clearSelection();
			setBulkDeleteOpen(false);
		} catch (err) {
			notifications.error(
				__("Unable to delete activities"),
				getErrorMessage(
					err,
					__("Failed to delete selected activity logs.")
				)
			);
		}
	};

	const handleClearAllActivities = async () => {
		try {
			await clearActivityLogs().unwrap();
			notifications.success(
				__("All activity deleted"),
				__("All activity log entries have been cleared.")
			);
			clearSelection();
			setClearAllOpen(false);
		} catch (err) {
			notifications.error(
				__("Unable to clear activity"),
				getErrorMessage(err, __("Failed to clear activity logs."))
			);
		}
	};

	if (isLoading) {
		return <ActivitySkeleton />;
	}

	return (
		<div className="activity-page">
			<ActivityHeader
				summaryCounts={summaryCounts}
				isRefreshing={isFetching}
				onRefresh={handleRefresh}
			/>

			<ActivityCategoryTabs
				category={category}
				onCategoryChange={setCategory}
				limit={limit}
				onLimitChange={setLimit}
				summaryCounts={summaryCounts}
			/>

			<ActivityTable
				items={items}
				category={category}
				isAdmin={isAdmin}
				selectedActivityIds={selectedActivityIds}
				isAllSelected={isAllSelected}
				isIndeterminate={isIndeterminate}
				onToggleSelectAll={toggleSelectAll}
				onToggleSelectOne={toggleSelectOne}
				onBulkDeleteClick={() => setBulkDeleteOpen(true)}
				onClearAllClick={() => setClearAllOpen(true)}
				onRestoreLink={handleRestoreActivityLink}
				onDeleteActivity={setActivityPendingDelete}
				isRestoringLink={isRestoringLink}
			/>

			<ActivityPagination meta={meta} onPageChange={setCurrentPage} />

			<ActivityModals
				bulkDeleteOpen={bulkDeleteOpen}
				onCloseBulkDelete={() => setBulkDeleteOpen(false)}
				selectedCount={selectedCount}
				onConfirmBulkDelete={handleBulkDeleteActivities}
				isBulkDeleting={isBulkDeletingActivities}
				clearAllOpen={clearAllOpen}
				onCloseClearAll={() => setClearAllOpen(false)}
				onConfirmClearAll={handleClearAllActivities}
				isClearingAll={isClearingAllActivities}
				activityPendingDelete={activityPendingDelete}
				onCloseSingleDelete={() => setActivityPendingDelete(null)}
				onConfirmSingleDelete={handleDeleteActivity}
				isDeletingSingle={isDeletingActivity}
			/>
		</div>
	);
}

export default ActivityPage;
