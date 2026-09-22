import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { X, Trash2, AlertTriangle } from "lucide-react";
import { useState } from "react";

import { useNotification } from "@/components";
import { useAdminAccess } from "@/hooks";
import { useDeleteAllUrlsMutation } from "@/state/slices/api";
import { getErrorMessage } from "@/shared/errors";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { DeleteAllModalProps } from "../types";

function DeleteAllModal({ open, setOpen, onSuccess }: DeleteAllModalProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const notifications = useNotification();
	const { canDeleteLinks, canTrashLinks } = useAdminAccess();
	const [error, setError] = useState("");
	const [activeAction, setActiveAction] = useState<
		"trash" | "permanent" | null
	>(null);
	const [deleteAllUrls, { isLoading }] = useDeleteAllUrlsMutation();

	const handleClose = () => {
		if (isLoading) return;
		setError("");
		setOpen(false);
	};

	const handleDelete = async (permanent: boolean) => {
		setError("");
		setActiveAction(permanent ? "permanent" : "trash");

		try {
			if (permanent) {
				await deleteAllUrls({ mode: "permanent" }).unwrap();
				notifications.success(
					__("Links deleted"),
					__("All links have been permanently deleted.")
				);
			} else {
				await deleteAllUrls({ mode: "trash" }).unwrap();
				notifications.success(
					__("Links moved to trash"),
					__("All links have been moved to trash.")
				);
			}
			onSuccess?.();
			setOpen(false);
		} catch (err) {
			setError(
				getErrorMessage(
					err,
					permanent
						? __("Failed to permanently delete all links")
						: __("Failed to move all links to trash")
				)
			);
		} finally {
			setActiveAction(null);
		}
	};

	return (
		<Dialog open={open} onClose={handleClose} className="relative z-50">
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel
					dir={direction}
					className="links-modal-panel links-modal-panel-large"
				>
					{/* Header */}
					<div className="links-modal-header">
						<DialogTitle className="links-modal-title links-modal-title-with-icon">
							<div className="links-modal-title-icon links-delete-modal-title-icon">
								<AlertTriangle className="links-delete-modal-title-icon-svg" />
							</div>
							{__("Delete all links")}
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

						<p className="links-delete-modal-copy">
							{canDeleteLinks && canTrashLinks
								? __(
										"Are you sure you want to delete all links? You can move them to trash to restore later, or delete them permanently."
									)
								: canDeleteLinks
									? __(
											"Are you sure you want to delete all links permanently? This action cannot be undone."
										)
									: __(
											"Are you sure you want to move all your links to the trash? You can restore them later."
										)}
						</p>

						{!canDeleteLinks && canTrashLinks && (
							<p className="mt-2 text-xs text-text-muted">
								{__(
									"Only links you have permission to remove will be affected. Admin-owned links will remain unchanged."
								)}
							</p>
						)}

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
								{canDeleteLinks && (
									<button
										type="button"
										onClick={() => handleDelete(true)}
										disabled={isLoading}
										className={
											canTrashLinks
												? "links-modal-button links-modal-button-danger-outline"
												: "links-modal-button links-modal-button-danger"
										}
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
												{__("Delete Permanently")}
											</span>
										)}
									</button>
								)}

								{canTrashLinks && (
									<button
										type="button"
										onClick={() => handleDelete(false)}
										disabled={isLoading}
										className="links-modal-button links-modal-button-danger"
									>
										{isLoading &&
										activeAction === "trash" ? (
											<span className="links-modal-button-content">
												<div className="links-modal-spinner"></div>
												{__("Moving to Trash...")}
											</span>
										) : (
											<span className="links-modal-button-content">
												<Trash2 className="links-modal-button-icon" />
												{__("Move to Trash")}
											</span>
										)}
									</button>
								)}
							</div>
						</div>
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default DeleteAllModal;
