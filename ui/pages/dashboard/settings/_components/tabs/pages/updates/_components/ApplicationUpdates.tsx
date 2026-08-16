import { AlertCircle, CheckCircle2, Clock3, Download } from "lucide-react";

import { __ } from "@/i18n";
import { formatRelativeTime } from "@/utils";

import type { ApplicationUpdatesProps } from "../types";
import DetailRow from "./DetailRow";
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
	// Match the notice icon to the strongest application update state.
	const noticeIcon =
		appState.tone === "error"
			? AlertCircle
			: updateAvailable
				? Download
				: CheckCircle2;

	return (
		<UpdateSection>
			<SectionHeader
				direction={direction}
				title={__("Application Updates")}
				description={__(
					"Check for new PeakURL releases, install updates, or reinstall the latest packaged release from the dashboard."
				)}
				badge={appState}
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

			{showReleaseMeta ? (
				<div className="settings-updates-divider">
					{status?.releasedAt ? (
						<DetailRow
							direction={direction}
							label={__("Released")}
							value={formatRelativeTime(status.releasedAt)}
							icon={Clock3}
							valueDirection="ltr"
						/>
					) : null}
					{status?.releaseNotesUrl ? (
						<DetailRow
							direction={direction}
							label={__("Release Notes")}
							value={__("Read release notes")}
							href={status.releaseNotesUrl}
						/>
					) : null}
				</div>
			) : null}
		</UpdateSection>
	);
}

export default ApplicationUpdates;
