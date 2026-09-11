import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { X, Trash2, AlertTriangle } from "lucide-react";
import { useState } from "react";

import { useBulkDeleteUrlMutation } from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { getDocumentDirection } from "@/i18n/direction";
import { getErrorMessage } from "@/utils";

import type { BulkDeleteModalProps } from "../types";

function BulkDeleteModal({
	open,
	setOpen,
	selectedIds,
	isTrashTab = false,
	onSuccess,
}: BulkDeleteModalProps) {
	const direction = getDocumentDirection();
	const [error, setError] = useState("");
	const [activeAction, setActiveAction] = useState<
		"trash" | "permanent" | null
	>(null);
	const [bulkDeleteUrl, { isLoading }] = useBulkDeleteUrlMutation();

	const handleClose = () => {
		if (isLoading) return;
		setError("");
		setOpen(false);
	};

	const handleDelete = async (permanent: boolean) => {
		if (!selectedIds || selectedIds.length === 0) {
			return;
		}

		const idsToDelete = [...selectedIds];
		setError("");
		setActiveAction(permanent ? "permanent" : "trash");

		try {
			if (permanent) {
				await bulkDeleteUrl({ ids: idsToDelete, force: true }).unwrap();
			} else {
				await bulkDeleteUrl(idsToDelete).unwrap();
			}
			setOpen(false);
			if (onSuccess) onSuccess();
		} catch (err) {
			setError(
				getErrorMessage(
					err,
					permanent
						? __("Failed to permanently delete links")
						: __("Failed to move links to trash")
				)
			);
		} finally {
			setActiveAction(null);
		}
	};

	if (!selectedIds || selectedIds.length === 0) return null;

	return (
		<Dialog open={open} onClose={handleClose} className="relative z-50">
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel
					dir={direction}
					className="links-modal-panel links-modal-panel-medium"
				>
					{/* Header */}
					<div className="links-modal-header">
						<DialogTitle className="links-modal-title links-modal-title-with-icon">
							<div className="links-modal-title-icon links-bulk-delete-modal-title-icon">
								<AlertTriangle className="links-bulk-delete-modal-title-icon-svg" />
							</div>
							{isTrashTab
								? __("Delete Links Permanently")
								: __("Move Links to Trash")}
						</DialogTitle>
						<button
							onClick={handleClose}
							disabled={isLoading}
							className="links-modal-close"
						>
							<X className="links-modal-close-icon" />
						</button>
					</div>

					{/* Content */}
					<div className="links-modal-content">
						{error && (
							<div className="links-modal-alert links-modal-alert-error">
								<p className="links-modal-alert-error-text">
									{error}
								</p>
							</div>
						)}

						<p className="links-bulk-delete-modal-copy">
							{isTrashTab
								? selectedIds.length === 1
									? __(
											"Are you sure you want to delete 1 selected link permanently? This action cannot be undone."
										)
									: sprintf(
											__(
												"Are you sure you want to delete %s selected links permanently? This action cannot be undone."
											),
											String(selectedIds.length)
										)
								: selectedIds.length === 1
									? __(
											"Are you sure you want to move 1 selected link to the trash? You can restore it later."
										)
									: sprintf(
											__(
												"Are you sure you want to move %s selected links to the trash? You can restore them later."
											),
											String(selectedIds.length)
										)}
						</p>

						{/* Action Buttons */}
						<div className="links-modal-actions">
							<button
								type="button"
								onClick={handleClose}
								disabled={isLoading}
								className="links-modal-button links-modal-button-secondary"
							>
								{__("Cancel")}
							</button>

							{!isTrashTab && (
								<button
									type="button"
									onClick={() => handleDelete(true)}
									disabled={isLoading}
									className="links-modal-button links-modal-button-danger-outline"
								>
									{isLoading &&
									activeAction === "permanent" ? (
										<span className="links-modal-button-content">
											<div className="links-modal-spinner"></div>
											{__("Deleting...")}
										</span>
									) : (
										<span className="links-modal-button-content">
											<Trash2 className="links-modal-button-icon" />
											{sprintf(
												__("Delete Permanently (%s)"),
												String(selectedIds.length)
											)}
										</span>
									)}
								</button>
							)}

							<button
								type="button"
								onClick={() => handleDelete(isTrashTab)}
								disabled={isLoading}
								className="links-modal-button links-modal-button-danger"
							>
								{isLoading &&
								(activeAction === "trash" ||
									(isTrashTab &&
										activeAction === "permanent")) ? (
									<span className="links-modal-button-content">
										<div className="links-modal-spinner"></div>
										{__("Deleting...")}
									</span>
								) : (
									<span className="links-modal-button-content">
										<Trash2 className="links-modal-button-icon" />
										{isTrashTab
											? sprintf(
													__(
														"Delete Permanently (%s)"
													),
													String(selectedIds.length)
												)
											: sprintf(
													__("Move to Trash (%s)"),
													String(selectedIds.length)
												)}
									</span>
								)}
							</button>
						</div>
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default BulkDeleteModal;
