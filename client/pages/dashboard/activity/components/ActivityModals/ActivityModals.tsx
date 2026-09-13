import { ConfirmDialog } from "@/components";
import { __, sprintf } from "@/i18n";

import { getActivityMessage } from "../../lib";
import type { RecentActivity } from "../../types";

interface ActivityModalsProps {
	bulkDeleteOpen: boolean;
	onCloseBulkDelete: () => void;
	selectedCount: number;
	onConfirmBulkDelete: () => void;
	isBulkDeleting: boolean;

	clearAllOpen: boolean;
	onCloseClearAll: () => void;
	onConfirmClearAll: () => void;
	isClearingAll: boolean;

	activityPendingDelete: RecentActivity | null;
	onCloseSingleDelete: () => void;
	onConfirmSingleDelete: () => void;
	isDeletingSingle: boolean;
}

export function ActivityModals({
	bulkDeleteOpen,
	onCloseBulkDelete,
	selectedCount,
	onConfirmBulkDelete,
	isBulkDeleting,
	clearAllOpen,
	onCloseClearAll,
	onConfirmClearAll,
	isClearingAll,
	activityPendingDelete,
	onCloseSingleDelete,
	onConfirmSingleDelete,
	isDeletingSingle,
}: ActivityModalsProps) {
	return (
		<>
			<ConfirmDialog
				open={bulkDeleteOpen && selectedCount > 0}
				onClose={onCloseBulkDelete}
				title={__("Delete activity logs")}
				description={
					selectedCount > 0
						? sprintf(
								__(
									"Delete %s selected activity log entries? This action cannot be undone."
								),
								String(selectedCount)
							)
						: ""
				}
				confirmText={__("Delete selected")}
				confirmVariant="danger"
				onConfirm={onConfirmBulkDelete}
				loading={isBulkDeleting}
			/>

			<ConfirmDialog
				open={clearAllOpen}
				onClose={onCloseClearAll}
				title={__("Delete all activity")}
				description={__(
					"Are you sure you want to delete all activity logs? This action cannot be undone."
				)}
				confirmText={__("Delete all activity")}
				confirmVariant="danger"
				onConfirm={onConfirmClearAll}
				loading={isClearingAll}
			/>

			<ConfirmDialog
				open={Boolean(activityPendingDelete)}
				onClose={onCloseSingleDelete}
				title={__("Delete activity log")}
				description={
					activityPendingDelete
						? sprintf(
								__(
									'Delete the activity entry "%s"? This action cannot be undone.'
								),
								getActivityMessage(activityPendingDelete)
							)
						: ""
				}
				confirmText={__("Delete activity")}
				confirmVariant="danger"
				onConfirm={onConfirmSingleDelete}
				loading={isDeletingSingle}
			/>
		</>
	);
}
