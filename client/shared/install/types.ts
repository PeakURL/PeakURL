/**
 * Recovery states returned when the PHP runtime needs setup or installation.
 */
export type InstallRecoveryState = "needs_setup" | "needs_install";

/**
 * Redirect target returned for setup or install recovery flows.
 */
export interface InstallRecoveryResult {
	/** Recovery state that triggered the redirect target. */
	state: InstallRecoveryState;

	/** Absolute or relative URL to continue the recovery flow. */
	url: string;
}

/**
 * Recovery payload nested inside install/setup error responses.
 */
export interface InstallRecoveryPayload {
	/** Recovery mode requested by the backend. */
	recoveryState?: InstallRecoveryState | null;

	/** Setup URL returned when the install still needs `setup-config.php`. */
	setupConfigUrl?: string | null;

	/** Install URL returned when the app needs the browser installer. */
	installUrl?: string | null;
}
