import { DialogTitle } from "@headlessui/react";
import { X, Trash2, AlertTriangle } from "lucide-react";
import { useState } from "react";

import { useAdminAccess } from "@/hooks";
import { useBulkDeleteUrlMutation } from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { getErrorMessage } from "@/shared/errors";

import { Modal } from "@/components/ui";
import type { BulkDeleteModalProps } from "../types";

function BulkDeleteModal({
	open,
	setOpen,
	selectedIds,
	isTrashTab = false,
	canTrash,
	canPermanentlyDelete,
	onConfirm,
	onSuccess,
	afterLeave,
}: BulkDeleteModalProps) {
	const { canDeleteLinks, canTrashLinks } = useAdminAccess();
	const effectiveCanTrash =
		canTrash !== undefined ? canTrash : Boolean(canTrashLinks);
	const effectiveCanDelete =
		canPermanentlyDelete !== undefined
			? canPermanentlyDelete
			: Boolean(canDeleteLinks);
	const [error, setError] = useState("");
	const [activeAction, setActiveAction] = useState<
		"trash" | "permanent" | null
	>(null);
	const [bulkDeleteUrl, { isLoading }] = useBulkDeleteUrlMutation();

	const isDirectPermanent =
		!isTrashTab && !effectiveCanTrash && effectiveCanDelete;
	const isPermanentMode = isTrashTab || isDirectPermanent;

	const handleClose = () => {
		if (isLoading) return;
		setError("");
		setOpen(false);
	};

	const handleDelete = async (permanent: boolean) => {
		if (!selectedIds || selectedIds.length === 0) {
			return;
		}
		if (permanent ? !effectiveCanDelete : !effectiveCanTrash) {
			return;
		}

		const idsToDelete = [...selectedIds];
		setError("");
		setActiveAction(permanent ? "permanent" : "trash");

		try {
			if (onConfirm) {
				await onConfirm(idsToDelete, permanent);
			} else if (permanent) {
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

	const hasAnyPermission = isTrashTab
		? effectiveCanDelete
		: effectiveCanDelete || effectiveCanTrash;

	if (!selectedIds || selectedIds.length === 0 || !hasAnyPermission)
		return null;

	return (
		<Modal
			isOpen={open}
			onClose={handleClose}
			panelClassName="links-modal-panel"
			afterLeave={afterLeave}
		>
			{/* Header */}
			<div className="links-modal-header">
				<DialogTitle className="links-modal-title links-modal-title-with-icon">
					<div className="links-modal-title-icon links-bulk-delete-modal-title-icon">
						<AlertTriangle className="links-bulk-delete-modal-title-icon-svg" />
					</div>
					{isPermanentMode
						? __("Delete Links Permanently")
						: __("Move Links to Trash")}
				</DialogTitle>
				<button
					onClick={handleClose}
					disabled={isLoading}
					className="links-modal-close"
					aria-label={__("Close")}
				>
					<X className="links-modal-close-icon" />
				</button>
			</div>

			{/* Content */}
			<div className="links-modal-content">
				{error && (
					<div className="links-modal-alert links-modal-alert-error">
						<p className="links-modal-alert-error-text">{error}</p>
					</div>
				)}

				<p className="links-bulk-delete-modal-copy">
					{isPermanentMode
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
				<div className="links-modal-actions-responsive">
					<button
						type="button"
						onClick={handleClose}
						disabled={isLoading}
						className="links-modal-button links-modal-button-secondary"
					>
						{__("Cancel")}
					</button>

					<div className="links-modal-actions-group">
						{!isPermanentMode && effectiveCanDelete && (
							<button
								type="button"
								onClick={() => handleDelete(true)}
								disabled={isLoading}
								className="links-modal-button links-modal-button-danger-outline"
							>
								{isLoading && activeAction === "permanent" ? (
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
							onClick={() => handleDelete(isPermanentMode)}
							disabled={
								isLoading ||
								(isPermanentMode
									? !effectiveCanDelete
									: !effectiveCanTrash)
							}
							className="links-modal-button links-modal-button-danger"
						>
							{isLoading &&
							(activeAction === "trash" ||
								(isPermanentMode &&
									activeAction === "permanent")) ? (
								<span className="links-modal-button-content">
									<div className="links-modal-spinner"></div>
									{__("Deleting...")}
								</span>
							) : (
								<span className="links-modal-button-content">
									<Trash2 className="links-modal-button-icon" />
									{isPermanentMode
										? sprintf(
												__("Delete Permanently (%s)"),
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
			</div>
		</Modal>
	);
}

export default BulkDeleteModal;
