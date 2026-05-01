import { __, sprintf } from "@/i18n";
import type { ReleaseAction } from "../../types";
import type {
	ReleaseInstallProgressState,
	ReleaseInstallStage,
} from "./types";

export const RELEASE_INSTALL_REDIRECT_DELAY_MS = 2400;

const releaseInstallStageOrder: ReleaseInstallStage[] = [
	"preparing",
	"downloading",
	"installing",
	"finishing",
];

/**
 * Formats the release-install progress title for an install or reinstall.
 */
function formatReleaseInstallTitle(action: ReleaseAction): string {
	return action === "reinstall"
		? __("Restoring the latest version")
		: __("Installing the latest version");
}

/**
 * Formats a single release-install stage label.
 */
function formatReleaseInstallStepLabel(
	action: ReleaseAction,
	stage: ReleaseInstallStage
): string {
	if ("preparing" === stage) {
		return __("Getting ready");
	}

	if ("downloading" === stage) {
		return __("Downloading update");
	}

	if ("installing" === stage) {
		return action === "reinstall"
			? __("Restoring included files")
			: __("Installing update");
	}

	return __("Finishing up");
}

/**
 * Finds the active stage index from the render-ready progress state.
 */
export function findReleaseInstallStageIndex(
	progress: ReleaseInstallProgressState | null
): number {
	const currentStageIndex =
		progress?.steps.findIndex(({ state }) => "current" === state) ?? -1;

	if (currentStageIndex >= 0) {
		return currentStageIndex;
	}

	const completedStepCount =
		progress?.steps.filter(({ state }) => "complete" === state).length ?? 0;

	return Math.min(
		Math.max(completedStepCount, 0),
		releaseInstallStageOrder.length - 1
	);
}

/**
 * Creates the progress state for the currently active release-install stage.
 */
export function createReleaseInstallProgress(
	action: ReleaseAction,
	stage: ReleaseInstallStage
): ReleaseInstallProgressState {
	const currentStepIndex = releaseInstallStageOrder.indexOf(stage);

	return {
		title: formatReleaseInstallTitle(action),
		description:
			stage === "preparing"
				? __("PeakURL is getting everything ready.")
				: stage === "downloading"
					? __("PeakURL is downloading the update.")
					: stage === "installing"
						? __(
								"PeakURL is applying the included files and content updates."
							)
						: __(
								"PeakURL is finishing up and getting the dashboard ready."
							),
		steps: releaseInstallStageOrder.map((step, index) => ({
			id: step,
			label: formatReleaseInstallStepLabel(action, step),
			state:
				index < currentStepIndex
					? "complete"
					: index === currentStepIndex
						? "current"
						: "upcoming",
		})),
	};
}

/**
 * Creates the all-complete progress state shown after a successful release install.
 */
export function createCompletedReleaseInstallProgress(
	action: ReleaseAction,
	appliedVersion?: string | null
): ReleaseInstallProgressState {
	const isReinstall = action === "reinstall";

	return {
		title: formatReleaseInstallTitle(action),
		description: appliedVersion
			? sprintf(
					isReinstall
						? __("PeakURL %s has been reinstalled.")
						: __("PeakURL %s is now installed."),
					appliedVersion
				)
			: isReinstall
				? __("The latest version has been reinstalled.")
				: __("The latest version is now installed."),
		steps: releaseInstallStageOrder.map((step) => ({
			id: step,
			label: formatReleaseInstallStepLabel(action, step),
			state: "complete",
		})),
	};
}

/**
 * Returns the ordered install stages used by the progress timer.
 */
export function getReleaseInstallStageOrder(): ReleaseInstallStage[] {
	return [...releaseInstallStageOrder];
}
