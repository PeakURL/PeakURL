import { __, sprintf } from "@/i18n";

import type {
	BadgeState,
	DatabaseStatus,
	UpdateIssue,
	UpdateStatusPayload,
} from "./types";

const DEFAULT_VISIBLE_ISSUE_COUNT = 6;

/**
 * Compares two normalized semantic-version strings.
 *
 * Returns a positive number when `latestVersion` is newer, a negative number
 * when `currentVersion` is newer, and zero when both versions are equivalent.
 */
function compareVersionStrings(
	currentVersion: string,
	latestVersion: string
): number {
	const currentParts = currentVersion
		.split(".")
		.map((part) => Number.parseInt(part, 10));
	const latestParts = latestVersion
		.split(".")
		.map((part) => Number.parseInt(part, 10));

	// Preserve the previous fallback behavior for non-semver manifest values.
	if (
		currentParts.some((part) => Number.isNaN(part)) ||
		latestParts.some((part) => Number.isNaN(part))
	) {
		return latestVersion === currentVersion ? 0 : 1;
	}

	const maxLength = Math.max(currentParts.length, latestParts.length);

	for (let index = 0; index < maxLength; index += 1) {
		const currentPart = currentParts[index] ?? 0;
		const latestPart = latestParts[index] ?? 0;

		if (latestPart !== currentPart) {
			return latestPart - currentPart;
		}
	}

	return 0;
}

/**
 * Returns whether the manifest or version comparison indicates an update.
 */
export function hasUpdateAvailable(
	status?: UpdateStatusPayload | null
): boolean {
	if (status?.updateAvailable) {
		return true;
	}

	const currentVersion = String(status?.currentVersion || "")
		.trim()
		.replace(/^v/i, "");
	const latestVersion = String(status?.latestVersion || "")
		.trim()
		.replace(/^v/i, "");

	if (!currentVersion || !latestVersion || currentVersion === latestVersion) {
		return false;
	}

	return compareVersionStrings(currentVersion, latestVersion) > 0;
}

/**
 * Returns whether the current package can be restored from the dashboard.
 */
export function hasReinstallAvailable(
	status?: UpdateStatusPayload | null
): boolean {
	return Boolean(status?.reinstallAvailable);
}

/**
 * Formats the current application updater status for badges and notices.
 */
export function formatAppStatus(
	status: UpdateStatusPayload | null | undefined,
	errorMessage?: string | null
): BadgeState {
	const updateAvailable = hasUpdateAvailable(status);
	const reinstallAvailable = hasReinstallAvailable(status);

	if (errorMessage || status?.lastError) {
		return {
			tone: "error",
			label: __("Unavailable"),
			title: __("Update service unavailable"),
			description:
				errorMessage ||
				status?.lastError ||
				__("PeakURL could not reach the update manifest."),
		};
	}

	if (updateAvailable) {
		return {
			tone: "info",
			label: __("Update Available"),
			title: sprintf(
				__("PeakURL %s is available"),
				status?.latestVersion || __("update")
			),
			description: status?.canApply
				? __(
						"A newer PeakURL version is ready to install when you are ready."
					)
				: __(
						"A newer PeakURL version is available, but this install cannot apply it automatically from the dashboard."
					),
		};
	}

	if (reinstallAvailable) {
		return {
			tone: "success",
			label: __("Latest"),
			title: sprintf(
				__("PeakURL %s is the latest version"),
				status?.currentVersion || __("Unknown")
			),
			description: status?.canApply
				? __(
						"This site is already on the latest version. Reinstall the latest package if you need to restore packaged files."
					)
				: __(
						"This site is already on the latest version, but this install cannot reinstall the latest package automatically from the dashboard."
					),
		};
	}

	return {
		tone: "success",
		label: __("Latest"),
		title: sprintf(
			__("PeakURL %s is the latest version"),
			status?.currentVersion || __("Unknown")
		),
		description: __(
			"This site is already running the latest known PeakURL version."
		),
	};
}

/**
 * Formats the database schema status for badges and notices.
 */
export function formatDatabaseStatus(
	databaseStatus: DatabaseStatus | null | undefined
): BadgeState {
	if (databaseStatus?.lastError) {
		return {
			tone: "error",
			label: __("Attention Needed"),
			title: __("Database upgrade failed"),
			description:
				databaseStatus.lastError ||
				__("PeakURL could not repair the database schema."),
		};
	}

	if (databaseStatus?.upgradeRequired) {
		return {
			tone: "info",
			label: __("Upgrade Recommended"),
			title: __("Database upgrade recommended"),
			description: __(
				"The database needs attention before every runtime path is fully current."
			),
		};
	}

	return {
		tone: "success",
		label: __("Up to Date"),
		title: __("Database schema is up to date"),
		description: __(
			"PeakURL has no outstanding schema repairs for this release."
		),
	};
}

/**
 * Returns the bounded database issue list displayed in the updates tab.
 */
export function getVisibleDatabaseIssues(
	databaseStatus: DatabaseStatus | null | undefined,
	limit = DEFAULT_VISIBLE_ISSUE_COUNT
): UpdateIssue[] {
	if (!Array.isArray(databaseStatus?.issues)) {
		return [];
	}

	return databaseStatus.issues.slice(0, limit);
}
