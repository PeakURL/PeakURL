import { __ } from "@/i18n";
import { getDocumentDirection } from "@/i18n/direction";
import { ApplicationUpdates, DatabaseSchema } from "./_components";
import {
	formatAppStatus,
	formatDatabaseStatus,
	getVisibleDatabaseIssues,
	hasReinstallAvailable,
	hasUpdateAvailable,
} from "./helpers";
import type { UpdatesTabProps } from "./types";

/**
 * Mounts the modular update-management sections for release and schema work.
 */
function UpdatesTab({
	status,
	errorMessage,
	isLoading,
	isChecking,
	isApplying,
	isReinstalling,
	isRepairing,
	onCheck,
	onApply,
	onReinstall,
	onRepair,
}: UpdatesTabProps) {
	const direction = getDocumentDirection();
	const updateAvailable = hasUpdateAvailable(status);
	const reinstallAvailable = hasReinstallAvailable(status);
	const canApply = Boolean(status?.canApply);
	const showReleaseMeta =
		(updateAvailable || reinstallAvailable) &&
		Boolean(status?.releasedAt || status?.releaseNotesUrl);
	const databaseStatus = status?.database || null;
	const appState = formatAppStatus(status, errorMessage);
	const databaseState = formatDatabaseStatus(databaseStatus);
	// Keep status derivation here so child components stay presentational.
	const visibleDatabaseIssues = getVisibleDatabaseIssues(databaseStatus);

	if (isLoading && !status && !errorMessage) {
		return (
			<div className="settings-updates-loading">
				{__("Loading update status...")}
			</div>
		);
	}

	return (
		<div className="settings-updates">
			<ApplicationUpdates
				direction={direction}
				status={status}
				appState={appState}
				updateAvailable={updateAvailable}
				reinstallAvailable={reinstallAvailable}
				canApply={canApply}
				showReleaseMeta={showReleaseMeta}
				isLoading={isLoading}
				isChecking={isChecking}
				isApplying={isApplying}
				isReinstalling={isReinstalling}
				isRepairing={isRepairing}
				onCheck={onCheck}
				onApply={onApply}
				onReinstall={onReinstall}
			/>

			<DatabaseSchema
				direction={direction}
				databaseStatus={databaseStatus}
				databaseState={databaseState}
				visibleDatabaseIssues={visibleDatabaseIssues}
				isLoading={isLoading}
				isChecking={isChecking}
				isApplying={isApplying}
				isRepairing={isRepairing}
				onRepair={onRepair}
			/>
		</div>
	);
}

export { default as ReleaseInstallProgress } from "./ReleaseInstallProgress";
export { default as useReleaseInstallProgress } from "./useReleaseInstallProgress";
export type {
	DatabaseStatus,
	ReleaseInstallProgressState,
	UpdateStatusPayload,
} from "./types";
export default UpdatesTab;
