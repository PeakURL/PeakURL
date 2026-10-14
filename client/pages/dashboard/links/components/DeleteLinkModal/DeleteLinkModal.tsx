import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { X, Trash2, AlertTriangle } from "lucide-react";
import { useState } from "react";

import { ReadOnlyValueBlock } from "@/components";
import { useAdminAccess } from "@/hooks";
import { useDeleteUrlMutation } from "@/state/slices/api";
import { getErrorMessage } from "@/shared/errors";
import { getShortUrl } from "@/shared/links";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { DeleteLinkModalProps } from "../types";

function DeleteLinkModal({
	open,
	setOpen,
	link,
	isTrashTab = false,
}: DeleteLinkModalProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const {
		canDeleteLinks,
		canTrashLinks,
		canDeleteAnyLink,
		canViewAnalytics,
		isAdmin,
		user,
	} = useAdminAccess();
	const [error, setError] = useState("");
	const [activeAction, setActiveAction] = useState<
		"trash" | "permanent" | null
	>(null);
	const [deleteUrl, { isLoading }] = useDeleteUrlMutation();
	const shortUrl = link ? getShortUrl(link) : "";
	const totalClicks = Number(link?.clicks || 0);
	const uniqueClicks = Number(link?.uniqueClicks || 0);
	const isTrashed = isTrashTab || link?.status === "trashed";

	const currentUserId = user?.id;
	const isOwner = Boolean(
		link?.userId &&
		currentUserId &&
		String(link.userId) === String(currentUserId)
	);
	const canTrashThisLink =
		Boolean(canTrashLinks) && (isOwner || Boolean(isAdmin));
	const canPermanentlyDeleteThisLink =
		Boolean(canDeleteLinks) &&
		(isOwner || Boolean(canDeleteAnyLink) || Boolean(isAdmin));

	const isDirectPermanent =
		!isTrashed && !canTrashThisLink && canPermanentlyDeleteThisLink;
	const isPermanentMode = isTrashed || isDirectPermanent;

	const showMetrics =
		Boolean(canViewAnalytics) &&
		link?.clicks !== null &&
		link?.clicks !== undefined &&
		(totalClicks > 0 || uniqueClicks > 0);

	const handleClose = () => {
		if (isLoading) return;
		setError("");
		setOpen(false);
	};

	const handleDelete = async (permanent: boolean) => {
		if (!link) {
			return;
		}
		if (permanent ? !canPermanentlyDeleteThisLink : !canTrashThisLink) {
			return;
		}

		const linkId = link.id;
		setError("");
		setActiveAction(permanent ? "permanent" : "trash");

		try {
			if (permanent) {
				await deleteUrl({ id: linkId, force: true }).unwrap();
			} else {
				await deleteUrl(linkId).unwrap();
			}
			setOpen(false);
		} catch (err) {
			setError(
				getErrorMessage(
					err,
					permanent
						? __("Failed to permanently delete link")
						: __("Failed to move link to trash")
				)
			);
		} finally {
			setActiveAction(null);
		}
	};

	if (!link) return null;

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
							{isPermanentMode
								? __("Delete Link Permanently")
								: __("Move to Trash")}
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
							{isPermanentMode
								? __(
										"Are you sure you want to delete this link permanently? This action cannot be undone."
									)
								: __(
										"Are you sure you want to move this link to the trash? You can restore it later."
									)}
						</p>

						{/* Link Info */}
						<div className="links-delete-modal-summary">
							<div>
								<p className="links-delete-modal-summary-label">
									{__("Short URL")}
								</p>
								<ReadOnlyValueBlock
									value={shortUrl}
									className="links-readonly-reset"
									valueClassName="text-accent"
								/>
							</div>
							<div>
								<p className="links-delete-modal-summary-label">
									{__("Destination")}
								</p>
								<ReadOnlyValueBlock
									value={link.destinationUrl}
									className="links-readonly-reset"
									monospace={false}
									valueClassName="text-heading"
								/>
							</div>
							{showMetrics && (
								<div className="links-delete-modal-metrics">
									<div>
										<p className="links-delete-modal-metric-label">
											{__("Total Clicks")}
										</p>
										<p className="links-delete-modal-metric-value">
											{totalClicks}
										</p>
									</div>
									<div>
										<p className="links-delete-modal-metric-label">
											{__("Visitors")}
										</p>
										<p className="links-delete-modal-metric-value">
											{uniqueClicks}
										</p>
									</div>
								</div>
							)}
						</div>

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
								{!isPermanentMode &&
									canPermanentlyDeleteThisLink && (
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
													{__("Delete Permanently")}
												</span>
											)}
										</button>
									)}

								<button
									type="button"
									onClick={() =>
										handleDelete(isPermanentMode)
									}
									disabled={
										isLoading ||
										(isPermanentMode
											? !canPermanentlyDeleteThisLink
											: !canTrashThisLink)
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
												? __("Delete Permanently")
												: __("Move to Trash")}
										</span>
									)}
								</button>
							</div>
						</div>
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default DeleteLinkModal;
