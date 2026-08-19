import { useState } from "react";
import { AlertCircle, CheckCircle2, Download } from "lucide-react";

import { __ } from "@/i18n";
import { formatRelativeTime } from "@/utils";
import UpdateDetailsModal from "@/pages/layout/dashboard/AdminNotices/UpdateDetailsModal";

import type { ApplicationUpdatesProps } from "../types";
import InlineNotice from "./InlineNotice";
import MetricGrid from "./MetricGrid";
import SectionHeader from "./SectionHeader";
import UpdateActions from "./UpdateActions";
import UpdateSection from "./UpdateSection";

/**
 * Renders application release checks, install controls, and release metadata.
 */
function ApplicationUpdates({
	direction,
	status,
	appState,
	updateAvailable,
	reinstallAvailable,
	canApply,
	showReleaseMeta,
	isLoading,
	isChecking,
	isApplying,
	isReinstalling,
	isRepairing,
	onCheck,
	onApply,
	onReinstall,
}: ApplicationUpdatesProps) {
	const [updateModalOpen, setUpdateModalOpen] = useState(false);

	// Match the notice icon to the strongest application update state.
	const noticeIcon =
		appState.tone === "error"
			? AlertCircle
			: updateAvailable
				? Download
				: CheckCircle2;

	return (
		<>
			<UpdateSection>
				<SectionHeader
					direction={direction}
					title={__("PeakURL Updates")}
					description={__(
						"Check for new PeakURL releases, install updates, or reinstall the latest packaged release from the dashboard."
					)}
					badge={appState}
					badgeAction={
						<button
							type="button"
							onClick={() => setUpdateModalOpen(true)}
							className="settings-updates-detail-link text-[13px]! font-normal!"
						>
							{__("Release notes")}
						</button>
					}
					primaryAction={
						<UpdateActions
							direction={direction}
							updateAvailable={updateAvailable}
							reinstallAvailable={reinstallAvailable}
							canApply={canApply}
							isLoading={isLoading}
							isChecking={isChecking}
							isApplying={isApplying}
							isReinstalling={isReinstalling}
							isRepairing={isRepairing}
							disabledReason={status?.applyDisabledReason || ""}
							onCheck={onCheck}
							onApply={onApply}
							onReinstall={onReinstall}
						/>
					}
				/>

				<MetricGrid
					direction={direction}
					items={[
						{
							label: __("Installed Version"),
							value: status?.currentVersion || __("Unknown"),
							valueDirection: "ltr",
						},
						{
							label: __("Latest Version"),
							value: status?.latestVersion || __("Unknown"),
							valueDirection: "ltr",
						},
						...(showReleaseMeta && status?.releasedAt
							? [
									{
										label: __("Released"),
										value: formatRelativeTime(
											status.releasedAt
										),
										valueDirection: "ltr" as const,
									},
								]
							: []),
						{
							label: __("Last Checked"),
							value: status?.lastCheckedAt
								? formatRelativeTime(status.lastCheckedAt)
								: __("Never"),
							valueDirection: "ltr",
						},
					]}
				/>

				<div className="settings-updates-block">
					<InlineNotice
						direction={direction}
						icon={noticeIcon}
						title={appState.title}
						description={appState.description}
						tone={appState.tone}
					/>
				</div>
			</UpdateSection>

			<UpdateDetailsModal
				open={updateModalOpen}
				setOpen={setUpdateModalOpen}
			/>
		</>
	);
}

export default ApplicationUpdates;
