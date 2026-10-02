import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
import { Activity, ExternalLink, RefreshCw, X } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { formatLocalizedDateTime } from "@/shared/dates";

import { formatHealthDuration, getHealthStatusDisplay } from "../health-status";
import type { HealthDetailModalProps } from "../types";

export function HealthDetailModal({
	open,
	setOpen,
	link,
	onCheckNow,
	isChecking = false,
}: HealthDetailModalProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	if (!link) {
		return null;
	}

	const health = link.health;
	const statusDisplay = getHealthStatusDisplay(health?.status);
	const statusLabel = statusDisplay.label;
	const dotClass = statusDisplay.dotClass;
	const textClass = statusDisplay.textClass;

	return (
		<Dialog
			open={open}
			onClose={() => setOpen(false)}
			className="relative z-50"
		>
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel
					dir={direction}
					className="links-modal-panel links-modal-panel-medium"
				>
					{/* Header */}
					<div className="links-modal-header">
						<div className="links-modal-title-with-icon">
							<div className="links-modal-title-icon bg-accent/10 text-accent">
								<Activity size={18} />
							</div>
							<DialogTitle className="links-modal-title">
								{__("Destination Health")}
							</DialogTitle>
						</div>
						<button
							type="button"
							onClick={() => setOpen(false)}
							className="links-modal-close"
							aria-label={__("Close")}
						>
							<X className="links-modal-close-icon" />
						</button>
					</div>

					{/* Content */}
					<div className="links-modal-content space-y-4">
						{/* Destination Target */}
						<div>
							<span className="health-detail-label block mb-1">
								{__("Destination URL")}
							</span>
							<div className="health-detail-destination flex items-center justify-between gap-2">
								<span className="truncate preserve-ltr-value">
									{link.destinationUrl}
								</span>
								<a
									href={link.destinationUrl}
									target="_blank"
									rel="noopener noreferrer"
									className="text-text-muted hover:text-heading shrink-0"
									aria-label={__("Open destination URL")}
								>
									<ExternalLink size={14} />
								</a>
							</div>
						</div>

						{/* Details Grid */}
						<div className="health-detail-grid">
							<div className="health-detail-item">
								<span className="health-detail-label">
									{__("Status")}
								</span>
								<div className="health-detail-status">
									<span
										className={`health-detail-dot ${dotClass}`}
									/>
									<span
										className={`health-detail-value ${textClass}`}
									>
										{statusLabel}
									</span>
								</div>
							</div>

							<div className="health-detail-item">
								<span className="health-detail-label">
									{__("Last checked")}
								</span>
								<span className="health-detail-value">
									{health?.checkedAt
										? formatLocalizedDateTime(
												health.checkedAt
											)
										: __("Never")}
								</span>
							</div>

							<div className="health-detail-item">
								<span className="health-detail-label">
									{__("Response")}
								</span>
								<span className="health-detail-value font-mono">
									{health?.responseCode !== null &&
									health?.responseCode !== undefined
										? health.responseCode
										: "—"}
								</span>
							</div>

							<div className="health-detail-item">
								<span className="health-detail-label">
									{__("Duration")}
								</span>
								<span className="health-detail-value font-mono">
									{formatHealthDuration(
										health?.responseTimeMs
									) ?? "—"}
								</span>
							</div>

							<div className="health-detail-item">
								<span className="health-detail-label">
									{__("Redirects")}
								</span>
								<span className="health-detail-value font-mono">
									{health?.redirectCount ?? 0}
								</span>
							</div>

							{health?.errorMessage && (
								<div className="health-detail-reason">
									<span className="health-detail-label block mb-1">
										{__("Reason")}
									</span>
									<span className="text-error break-words">
										{health.errorMessage}
									</span>
								</div>
							)}
						</div>
					</div>

					{/* Actions */}
					<div className="flex items-center justify-end gap-3 border-t border-stroke px-6 py-4">
						<Button
							variant="secondary"
							size="sm"
							onClick={() => setOpen(false)}
						>
							{__("Close")}
						</Button>
						{onCheckNow && (
							<Button
								variant="primary"
								size="sm"
								icon={RefreshCw}
								loading={isChecking}
								disabled={isChecking}
								onClick={() => onCheckNow(link.id)}
							>
								{__("Check Now")}
							</Button>
						)}
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default HealthDetailModal;
