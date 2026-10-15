import { Link } from "react-router";
import {
	AlertCircle,
	ExternalLink,
	PackageCheck,
	PackagePlus,
} from "lucide-react";

import { __ } from "@/i18n";
import { formatRelativeTime } from "@/shared/dates";
import { sanitizeUrl } from "@/shared/links";

import type { ApplicationUpdatesProps } from "../types";
import UpdateStatus from "./UpdateStatus";
import MetricGrid from "./MetricGrid";
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
	// Available updates link to their published notes; otherwise show the installed release.
	const releaseNotesUrl = updateAvailable
		? sanitizeUrl(status?.releaseNotesUrl) ||
			"https://go.peakurl.org/release-notes"
		: "";

	// Match the notice icon to the strongest application update state.
	const noticeIcon =
		appState.tone === "error"
			? AlertCircle
			: updateAvailable
				? PackagePlus
				: PackageCheck;

	return (
		<UpdateSection>
			<div className="settings-updates-release">
				<UpdateStatus
					direction={direction}
					icon={noticeIcon}
					title={appState.title}
					description={appState.description}
					tone={appState.tone}
					prominent
					details={
						releaseNotesUrl ? (
							<a
								href={releaseNotesUrl}
								target="_blank"
								rel="noopener noreferrer"
								className="settings-updates-detail-link"
							>
								{__("Release notes")}{" "}
								<ExternalLink size={13} aria-hidden="true" />
							</a>
						) : (
							<Link
								to="/dashboard/about?tab=releases"
								className="settings-updates-detail-link"
							>
								{__("Release notes")}
							</Link>
						)
					}
				/>
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
			</div>
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
		</UpdateSection>
	);
}

export default ApplicationUpdates;
