import { getNestedRecord, getStringRecordValue, isObjectRecord } from './records';
import type {
	InstallRecoveryPayload,
	InstallRecoveryResult,
} from './types';

function getInstallRecoveryPayload(
	error: unknown
): InstallRecoveryPayload | null {
	if (!isObjectRecord(error)) {
		return null;
	}

	const responseData = getNestedRecord(error, 'data');
	const payload = responseData ? getNestedRecord(responseData, 'data') : null;

	if (!payload) {
		return null;
	}

	const recoveryState = getStringRecordValue(payload, 'recoveryState');

	if (
		'needs_setup' !== recoveryState &&
		'needs_install' !== recoveryState
	) {
		return null;
	}

	return {
		recoveryState,
		setupConfigUrl: getStringRecordValue(payload, 'setupConfigUrl'),
		installUrl: getStringRecordValue(payload, 'installUrl'),
	};
}

/**
 * Extracts a setup or install recovery target from an API error payload.
 */
export function getInstallRecovery(error: unknown): InstallRecoveryResult | null {
	const payload = getInstallRecoveryPayload(error);

	if (!payload?.recoveryState) {
		return null;
	}

	if ('needs_setup' === payload.recoveryState && payload.setupConfigUrl) {
		return {
			state: payload.recoveryState,
			url: payload.setupConfigUrl,
		};
	}

	if ('needs_install' === payload.recoveryState && payload.installUrl) {
		return {
			state: payload.recoveryState,
			url: payload.installUrl,
		};
	}

	return null;
}

/**
 * Redirects the browser to the setup or install recovery flow when present.
 */
export function redirectToInstallRecovery(error: unknown): boolean {
	const recovery = getInstallRecovery(error);

	if (!recovery || 'undefined' === typeof window) {
		return false;
	}

	window.location.replace(recovery.url);
	return true;
}
