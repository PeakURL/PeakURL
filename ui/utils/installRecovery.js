export function getInstallRecovery(error) {
	const recoveryState = error?.data?.data?.recoveryState;

	if (
		'needs_setup' === recoveryState &&
		'string' === typeof error?.data?.data?.setupConfigUrl &&
		error.data.data.setupConfigUrl
	) {
		return {
			state: recoveryState,
			url: error.data.data.setupConfigUrl,
		};
	}

	if (
		'needs_install' === recoveryState &&
		'string' === typeof error?.data?.data?.installUrl &&
		error.data.data.installUrl
	) {
		return {
			state: recoveryState,
			url: error.data.data.installUrl,
		};
	}

	return null;
}

export function redirectToInstallRecovery(error) {
	const recovery = getInstallRecovery(error);

	if (!recovery || 'undefined' === typeof window) {
		return false;
	}

	window.location.replace(recovery.url);
	return true;
}
