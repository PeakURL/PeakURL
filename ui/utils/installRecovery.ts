import {
	getNestedRecord,
	getStringRecordValue,
	isObjectRecord,
} from "./records";
import type { InstallRecoveryPayload, InstallRecoveryResult } from "./types";

/**
 * Extract the raw recovery payload from an API error.
 *
 * @param error - The error object to parse.
 * @return The recovery payload or null if not found or invalid.
 */
function getInstallRecoveryPayload(
	error: unknown
): InstallRecoveryPayload | null {
	if (!isObjectRecord(error)) {
		return null;
	}

	const responseData = getNestedRecord(error, "data");
	const payload = responseData ? getNestedRecord(responseData, "data") : null;

	if (!payload) {
		return null;
	}

	const recoveryState = getStringRecordValue(payload, "recoveryState");

	/* Only allow specific recovery states. */
	if ("needs_setup" !== recoveryState && "needs_install" !== recoveryState) {
		return null;
	}

	return {
		recoveryState,
		setupConfigUrl: getStringRecordValue(payload, "setupConfigUrl"),
		installUrl: getStringRecordValue(payload, "installUrl"),
	};
}

/**
 * Extract a setup or install recovery target from an API error payload.
 *
 * @param error - The error object to parse.
 * @return The normalized recovery result or null.
 */
export function getInstallRecovery(
	error: unknown
): InstallRecoveryResult | null {
	const payload = getInstallRecoveryPayload(error);

	if (!payload?.recoveryState) {
		return null;
	}

	/* Map the recovery state to the appropriate redirect URL. */
	if ("needs_setup" === payload.recoveryState && payload.setupConfigUrl) {
		return {
			state: payload.recoveryState,
			url: payload.setupConfigUrl,
		};
	}

	if ("needs_install" === payload.recoveryState && payload.installUrl) {
		return {
			state: payload.recoveryState,
			url: payload.installUrl,
		};
	}

	return null;
}

/**
 * Redirect the browser to the setup or install recovery flow when present.
 *
 * @param error - The error object to check for recovery instructions.
 * @return Whether a redirect was initiated.
 */
export function redirectToInstallRecovery(error: unknown): boolean {
	const recovery = getInstallRecovery(error);

	if (!recovery || "undefined" === typeof window) {
		return false;
	}

	window.location.replace(recovery.url);
	return true;
}
