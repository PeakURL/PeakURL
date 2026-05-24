/**
 * One updater or database issue returned by the update service.
 */
export interface UpdateIssue {
	id?: string | null;
	label: string;
}

/**
 * Database schema status returned by the update service.
 */
export interface DatabaseStatus {
	upgradeRequired?: boolean;
	lastError?: string | null;
	currentVersion?: string | number | null;
	targetVersion?: string | number | null;
	lastUpgradedAt?: string | null;
	issues?: UpdateIssue[] | null;
}

/**
 * Update status data shown on the admin-only updates tab.
 */
export interface UpdateStatusPayload {
	updateAvailable?: boolean;
	reinstallAvailable?: boolean;
	currentVersion?: string | null;
	latestVersion?: string | null;
	canApply?: boolean;
	lastError?: string | null;
	lastCheckedAt?: string | null;
	releasedAt?: string | null;
	releaseNotesUrl?: string | null;
	applyDisabledReason?: string | null;
	database?: DatabaseStatus | null;
}
