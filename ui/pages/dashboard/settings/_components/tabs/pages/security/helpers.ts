import { __, sprintf } from "@/i18n";

import type {
	ProtectedAction,
	ProtectedActionConfig,
	SecuritySession,
} from "../types";

export const BACKUP_CODES_FILENAME = "peakurl-backup-codes.txt";

/**
 * Creates the plain-text content used for backup-code downloads.
 */
export function createBackupCodesFile(codes: string[]): string {
	return [
		__("PeakURL Backup Codes"),
		__("Keep these codes safe. Each code can be used once."),
		"",
		...codes.map((code) => `- ${code}`),
		"",
		sprintf(__("Generated at: %s"), new Date().toISOString()),
	].join("\n");
}

/**
 * Returns the confirmation dialog copy for password-protected 2FA actions.
 */
export function getProtectedActionConfig(
	action: ProtectedAction | null
): ProtectedActionConfig | null {
	if ("download" === action) {
		return {
			title: __("Download backup codes"),
			description: __(
				"Enter your current password to download the latest backup codes for this account."
			),
			confirmText: __("Download"),
		};
	}

	if ("disable" === action) {
		return {
			title: __("Disable two-factor authentication"),
			description: __(
				"Enter your current password to disable two-factor authentication and clear the current backup codes for this account."
			),
			confirmText: __("Disable"),
			confirmVariant: "danger",
		};
	}

	if ("regenerate" === action) {
		return {
			title: __("Regenerate backup codes"),
			description: __(
				"Enter your current password to replace the existing backup codes with a new set."
			),
			confirmText: __("Regenerate Codes"),
		};
	}

	return null;
}

/**
 * Returns the user-facing fallback error copy for a protected 2FA action.
 */
export function getProtectedActionErrorMessage(
	action: ProtectedAction
): string {
	if ("disable" === action) {
		return __("Failed to disable two-factor authentication");
	}

	if ("regenerate" === action) {
		return __("Failed to regenerate backup codes");
	}

	return __("Failed to download backup codes");
}

/**
 * Formats the best available city/country label for an active session.
 */
export function getSessionLocationLabel(session: SecuritySession): string {
	const city = session.location?.city?.trim();
	const country = session.location?.country?.trim();

	if (city && country) {
		return `${city}, ${country}`;
	}

	if (city || country) {
		return city || country || "";
	}

	if (false === session.location?.isPublic && session.ipAddress) {
		return __("Private network");
	}

	return __("Location unavailable");
}
