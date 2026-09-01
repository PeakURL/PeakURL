import { ConfirmDialog, Input } from "@/components";
import { __, sprintf } from "@/i18n";
import {
	ActiveSessions,
	PasswordSettings,
	TwoFactorSettings,
} from "./_components";
import { useSecurityTab } from "./useSecurityTab";
import type { SecurityTabProps } from "../types";

/**
 * Mounts the modular security settings sections and shared confirmation dialogs.
 */
function SecurityTab({
	securityForm,
	setSecurityForm,
	onSubmit,
	isUpdating,
	notification,
}: SecurityTabProps) {
	const security = useSecurityTab({ notification });
	const { activeSessions, direction, isRtl, twoFactor } = security;

	return (
		<div className="settings-security">
			<PasswordSettings
				securityForm={securityForm}
				setSecurityForm={setSecurityForm}
				onSubmit={onSubmit}
				isUpdating={isUpdating}
				isRtl={isRtl}
			/>

			<TwoFactorSettings direction={direction} {...twoFactor.section} />

			<ActiveSessions direction={direction} {...activeSessions.section} />

			{/* Shared confirmation for password-protected 2FA actions. */}
			<ConfirmDialog
				open={twoFactor.dialog.open}
				onClose={twoFactor.dialog.onClose}
				title={
					twoFactor.dialog.config?.title ||
					__("Confirm your password")
				}
				description={twoFactor.dialog.config?.description || ""}
				confirmText={
					twoFactor.dialog.config?.confirmText || __("Continue")
				}
				confirmVariant={
					twoFactor.dialog.config?.confirmVariant || "primary"
				}
				cancelText={__("Cancel")}
				onConfirm={twoFactor.dialog.onConfirm}
				loading={twoFactor.dialog.isLoading}
			>
				<Input
					label={__("Current Password")}
					type="password"
					value={twoFactor.dialog.password}
					onChange={(event) =>
						twoFactor.dialog.onPasswordChange(event.target.value)
					}
					autoComplete="current-password"
					placeholder={__("Enter your current password")}
				/>
			</ConfirmDialog>

			{/* Shared confirmation for ending all non-current sessions. */}
			<ConfirmDialog
				open={activeSessions.dialog.open}
				onClose={activeSessions.dialog.onClose}
				title={__("End all other sessions")}
				description={
					activeSessions.dialog.sessionCount === 1
						? __(
								"End 1 other active session for this account? Any browsers or devices using it will be signed out immediately."
							)
						: sprintf(
								__(
									"End %s other active sessions for this account? Any browsers or devices using them will be signed out immediately."
								),
								String(activeSessions.dialog.sessionCount)
							)
				}
				confirmText={__("End sessions")}
				cancelText={__("Keep sessions")}
				onConfirm={activeSessions.dialog.onConfirm}
				loading={activeSessions.dialog.isLoading}
			/>
		</div>
	);
}

export default SecurityTab;
