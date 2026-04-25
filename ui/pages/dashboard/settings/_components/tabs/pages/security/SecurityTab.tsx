import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { Button, ConfirmDialog, Input, ReadOnlyValueBlock } from "@/components";
import {
	ShieldCheck,
	ShieldOff,
	Monitor,
	MapPin,
	RefreshCw,
	Download,
	AlertCircle,
} from "lucide-react";
import {
	useGetSecuritySettingsQuery,
	useStartTwoFactorSetupMutation,
	useVerifyTwoFactorMutation,
	useDisableTwoFactorMutation,
	useRegenerateBackupCodesMutation,
	useDownloadBackupCodesMutation,
	useRevokeSessionMutation,
	useRevokeOtherSessionsMutation,
} from "@/store/slices/api";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatDateTimeValue, getErrorMessage } from "@/utils";
import type {
	ProtectedAction,
	ProtectedActionConfig,
	SecuritySession,
	SecurityTabProps,
} from "../types";

const buildBackupCodesFile = (codes: string[]) =>
	[
		__("PeakURL Backup Codes"),
		__("Keep these codes safe. Each code can be used once."),
		"",
		...codes.map((code) => `- ${code}`),
		"",
		sprintf(__("Generated at: %s"), new Date().toISOString()),
	].join("\n");

const getSessionLocationLabel = (session: SecuritySession) => {
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
};

function SecurityTab({
	securityForm,
	setSecurityForm,
	onSubmit,
	isUpdating,
	notification,
}: SecurityTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const [recentCodes, setRecentCodes] = useState<string[]>([]);
	const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
	const [secret, setSecret] = useState<string | null>(null);
	const [otpauthUrl, setOtpauthUrl] = useState<string | null>(null);
	const [verificationCode, setVerificationCode] = useState("");
	const [isDownloading, setIsDownloading] = useState(false);
	const [revokingId, setRevokingId] = useState<string | null>(null);
	const [isRevokingOthers, setIsRevokingOthers] = useState(false);
	const [showRevokeOthersConfirm, setShowRevokeOthersConfirm] =
		useState(false);
	const [protectedAction, setProtectedAction] =
		useState<ProtectedAction | null>(null);
	const [protectedActionPassword, setProtectedActionPassword] = useState("");

	const {
		data: securityData,
		isFetching: isSecurityLoading,
		refetch: refetchSecurity,
	} = useGetSecuritySettingsQuery(undefined);

	const security = securityData?.data || {};
	const sessions: SecuritySession[] = security.sessions || [];
	const twoFactorEnabled = security.twoFactorEnabled;
	const hasPendingSetup = security.hasPendingSetup;
	const actionLabel = hasPendingSetup
		? __("Continue 2FA setup")
		: __("Set up 2FA");
	const statusMessage = twoFactorEnabled
		? sprintf(
				__("Backup codes remaining: %s"),
				String(security.backupCodesRemaining ?? 0)
			)
		: __("Enable to generate backup codes for account recovery.");
	const hasTwoFactorSetupDetails = Boolean(qrDataUrl || secret || otpauthUrl);

	useEffect(() => {
		if (!twoFactorEnabled) {
			setRecentCodes([]);
		}
	}, [twoFactorEnabled]);

	const [startTwoFactor, { isLoading: isStarting }] =
		useStartTwoFactorSetupMutation();
	const [verifyTwoFactor, { isLoading: isVerifying }] =
		useVerifyTwoFactorMutation();
	const [disableTwoFactor, { isLoading: isDisabling }] =
		useDisableTwoFactorMutation();
	const [regenerateBackupCodes, { isLoading: isRegenerating }] =
		useRegenerateBackupCodesMutation();
	const [downloadBackupCodes, { isLoading: isDownloadingFromApi }] =
		useDownloadBackupCodesMutation();
	const [revokeSession] = useRevokeSessionMutation();
	const [revokeOtherSessions] = useRevokeOtherSessionsMutation();
	const otherActiveSessions = sessions.filter(
		(session: SecuritySession) => !session.isCurrent && !session.revokedAt
	);
	const isProtectedActionLoading =
		"download" === protectedAction
			? isDownloading || isDownloadingFromApi
			: "disable" === protectedAction
				? isDisabling
				: isRegenerating;
	const protectedActionConfig: ProtectedActionConfig | null =
		"download" === protectedAction
			? {
					title: __("Download backup codes"),
					description: __(
						"Enter your current password to download the latest backup codes for this account."
					),
					confirmText: __("Download"),
				}
			: "disable" === protectedAction
				? {
						title: __("Disable two-factor authentication"),
						description: __(
							"Enter your current password to disable two-factor authentication and clear the current backup codes for this account."
						),
						confirmText: __("Disable"),
						confirmVariant: "danger",
					}
				: "regenerate" === protectedAction
					? {
							title: __("Regenerate backup codes"),
							description: __(
								"Enter your current password to replace the existing backup codes with a new set."
							),
							confirmText: __("Regenerate Codes"),
						}
					: null;

	const closeProtectedActionDialog = () => {
		if (isProtectedActionLoading) {
			return;
		}

		setProtectedAction(null);
		setProtectedActionPassword("");
	};

	const openProtectedActionDialog = (action: ProtectedAction) => {
		if (isProtectedActionLoading) {
			return;
		}

		setProtectedAction(action);
		setProtectedActionPassword("");
	};

	const downloadBackupCodesFile = (content: string) => {
		const blob = new Blob([content], { type: "text/plain" });
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement("a");
		link.href = url;
		link.download = "peakurl-backup-codes.txt";
		link.click();
		window.URL.revokeObjectURL(url);
	};

	const handleDownloadRequest = () => {
		if (recentCodes.length > 0) {
			downloadBackupCodesFile(buildBackupCodesFile(recentCodes));
			notification?.success(
				__("Backup codes downloaded"),
				__(
					"PeakURL downloaded the visible backup codes for this account."
				)
			);
			return;
		}

		openProtectedActionDialog("download");
	};

	const startSetup = async (
		options: { silent: boolean } = { silent: false }
	) => {
		try {
			const res = await startTwoFactor(undefined).unwrap();
			const setupSecret = res?.data?.secret || null;
			const setupOtpauthUrl = res?.data?.otpauthUrl || null;
			let nextQrDataUrl = res?.data?.qrDataUrl || null;

			if (setupOtpauthUrl) {
				try {
					nextQrDataUrl = await QRCode.toDataURL(setupOtpauthUrl, {
						width: 224,
						margin: 1,
					});
				} catch (error) {
					nextQrDataUrl = null;
				}
			}

			setQrDataUrl(nextQrDataUrl);
			setSecret(setupSecret);
			setOtpauthUrl(setupOtpauthUrl);
			setVerificationCode("");
			setRecentCodes([]);
			if (!options.silent) {
				notification?.info(
					__("Scan the QR code"),
					__(
						"Scan with your authenticator app and enter the 6-digit code."
					)
				);
			}
		} catch (err) {
			if (!options.silent) {
				notification?.error(
					__("Error"),
					getErrorMessage(
						err,
						__("Failed to start two-factor authentication setup")
					)
				);
			}
		}
	};

	const handleStartSetup = () => startSetup({ silent: false });

	const handleVerify = async () => {
		if (!verificationCode.trim()) {
			notification?.error(
				__("Error"),
				__("Enter the 6-digit code to verify")
			);
			return;
		}

		try {
			const res = await verifyTwoFactor({
				token: verificationCode.trim(),
			}).unwrap();
			setRecentCodes(res?.data?.backupCodes || []);
			setQrDataUrl(null);
			setSecret(null);
			setOtpauthUrl(null);
			setVerificationCode("");
			notification?.success(
				__("Two-factor enabled"),
				__("Backup codes generated. Store them safely.")
			);
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(
					err,
					__("Failed to verify code. Check the code and try again.")
				)
			);
		}
	};

	const handleProtectedAction = async () => {
		if (!protectedAction) {
			return;
		}

		if (!protectedActionPassword) {
			notification?.error(
				__("Error"),
				__("Enter your current password to continue")
			);
			return;
		}

		const currentPassword = protectedActionPassword;
		const action = protectedAction;

		try {
			if ("disable" === action) {
				await disableTwoFactor({
					currentPassword,
				}).unwrap();
				setRecentCodes([]);
				setQrDataUrl(null);
				setSecret(null);
				setOtpauthUrl(null);
				setVerificationCode("");
				notification?.info(
					__("Two-factor disabled"),
					__("Backup codes cleared for this account.")
				);
				refetchSecurity();
			} else if ("regenerate" === action) {
				const res = await regenerateBackupCodes({
					currentPassword,
				}).unwrap();
				setRecentCodes(res?.data?.backupCodes || []);
				notification?.success(
					__("Backup codes refreshed"),
					__("Save the new codes before leaving this page.")
				);
				refetchSecurity();
			} else {
				setIsDownloading(true);
				const content = await downloadBackupCodes({
					currentPassword,
				}).unwrap();
				downloadBackupCodesFile(content);
				notification?.success(
					__("Backup codes downloaded"),
					__(
						"PeakURL downloaded the latest backup codes for this account."
					)
				);
			}

			setProtectedAction(null);
			setProtectedActionPassword("");
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(
					err,
					"disable" === action
						? __("Failed to disable two-factor authentication")
						: "regenerate" === action
							? __("Failed to regenerate backup codes")
							: __("Failed to download backup codes")
				)
			);
		} finally {
			setIsDownloading(false);
		}
	};

	const handleRevokeSession = async (
		sessionId: string,
		isCurrent?: boolean
	) => {
		setRevokingId(sessionId);
		try {
			await revokeSession(sessionId).unwrap();
			notification?.success(
				__("Session ended"),
				isCurrent
					? __("Current browser session ended.")
					: __("The session was revoked.")
			);
			if (isCurrent) {
				setTimeout(() => {
					window.location.reload();
				}, 400);
				return;
			}
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(err, __("Failed to end the session"))
			);
		} finally {
			setRevokingId(null);
		}
	};

	const handleRevokeOtherSessions = async () => {
		if (0 === otherActiveSessions.length) {
			return;
		}

		setIsRevokingOthers(true);

		try {
			const result = await revokeOtherSessions(undefined).unwrap();
			const revokedCount = result?.data?.revokedCount ?? 0;
			setShowRevokeOthersConfirm(false);

			notification?.success(
				__("Other sessions ended"),
				revokedCount > 0
					? sprintf(__("%1$s other session%2$s were ended."), [
							String(revokedCount),
							1 === revokedCount ? "" : "s",
						])
					: __("No other active sessions were found.")
			);
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__("Error"),
				getErrorMessage(err, __("Failed to end the other sessions"))
			);
		} finally {
			setIsRevokingOthers(false);
		}
	};

	return (
		<div className="settings-security">
			<div className="settings-security-password-card">
				<h2 className="settings-security-card-title">
					{__("Password & Security")}
				</h2>
				<div className="settings-security-password-fields">
					<Input
						label={__("Current Password")}
						type="password"
						value={securityForm.currentPassword}
						autoComplete="current-password"
						onChange={(e) =>
							setSecurityForm({
								...securityForm,
								currentPassword: e.target.value,
							})
						}
					/>
					<Input
						label={__("New Password")}
						type="password"
						value={securityForm.newPassword}
						onChange={(e) =>
							setSecurityForm({
								...securityForm,
								newPassword: e.target.value,
							})
						}
					/>
					<Input
						label={__("Confirm New Password")}
						type="password"
						value={securityForm.confirmPassword}
						onChange={(e) =>
							setSecurityForm({
								...securityForm,
								confirmPassword: e.target.value,
							})
						}
					/>
				</div>
				<div
					className={cn(
						"settings-security-password-actions",
						isRtl
							? "settings-security-password-actions-start"
							: "settings-security-password-actions-end"
					)}
				>
					<Button size="sm" onClick={onSubmit} disabled={isUpdating}>
						{isUpdating ? __("Updating...") : __("Update Password")}
					</Button>
				</div>
			</div>

			<div className="settings-security-two-factor-card">
				<div
					dir={direction}
					className="settings-security-two-factor-header"
				>
					<div className="settings-security-two-factor-copy">
						<h2 className="settings-security-card-title">
							{__("Two-Factor Authentication")}
						</h2>
						<p className="settings-security-two-factor-description">
							{__(
								"Add an extra layer of security with an authenticator app and backup codes."
							)}
						</p>
					</div>
					<div className="settings-security-two-factor-actions">
						{twoFactorEnabled ? (
							<>
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										openProtectedActionDialog("regenerate")
									}
									loading={isRegenerating}
								>
									<RefreshCw size={14} />
									{__("Regenerate Codes")}
								</Button>
								<Button
									variant="ghost"
									size="sm"
									onClick={() =>
										openProtectedActionDialog("disable")
									}
									loading={isDisabling}
								>
									<ShieldOff size={14} />
									{__("Disable")}
								</Button>
							</>
						) : (
							<Button
								size="sm"
								onClick={handleStartSetup}
								loading={isStarting}
							>
								<ShieldCheck size={14} />
								{actionLabel}
							</Button>
						)}
					</div>
				</div>

				<div
					dir={direction}
					className={cn(
						"settings-security-two-factor-status",
						twoFactorEnabled
							? "settings-security-two-factor-status-active"
							: "settings-security-two-factor-status-inactive"
					)}
				>
					<div className="settings-security-two-factor-status-icon-panel">
						{twoFactorEnabled ? (
							<ShieldCheck
								size={18}
								className="settings-security-two-factor-status-icon-active"
							/>
						) : (
							<AlertCircle
								size={18}
								className="settings-security-two-factor-status-icon-inactive"
							/>
						)}
					</div>
					<div className="settings-security-two-factor-status-content">
						<p className="settings-security-two-factor-status-title">
							{twoFactorEnabled
								? __("2FA is enabled")
								: __("2FA is disabled")}
						</p>
						<p className="settings-security-two-factor-status-text">
							{statusMessage}
						</p>
						{security.backupCodesLastGeneratedAt && (
							<p className="settings-security-two-factor-status-text">
								{__("Last generated:")}{" "}
								{formatDateTimeValue(
									security.backupCodesLastGeneratedAt,
									__("Unknown")
								)}
							</p>
						)}
					</div>
					<Button
						variant="outline"
						size="sm"
						onClick={handleDownloadRequest}
						loading={isDownloading || isDownloadingFromApi}
						disabled={!twoFactorEnabled}
					>
						<Download size={14} />
						{__("Download")}
					</Button>
				</div>

				{!twoFactorEnabled && hasTwoFactorSetupDetails && (
					<div className="settings-security-two-factor-setup">
						<div
							dir={direction}
							className="settings-security-two-factor-setup-header"
						>
							<div className="settings-security-two-factor-setup-icon-panel">
								<ShieldCheck
									size={18}
									className="settings-security-two-factor-setup-icon"
								/>
							</div>
							<div className="settings-security-two-factor-setup-copy">
								<p className="settings-security-two-factor-setup-title">
									{__("Scan the QR code")}
								</p>
								<p className="settings-security-two-factor-setup-description">
									{__(
										"Use an authenticator app (Google Authenticator, Authy, etc.) then enter the 6-digit code."
									)}
								</p>
							</div>
						</div>
						<div className="settings-security-two-factor-setup-grid">
							<div className="settings-security-two-factor-qr">
								{qrDataUrl ? (
									<img
										src={qrDataUrl}
										alt={__("TOTP QR code")}
										width={192}
										height={192}
										loading="lazy"
										className="settings-security-two-factor-qr-image"
									/>
								) : (
									<div className="settings-security-two-factor-qr-fallback">
										{__(
											"QR preview is unavailable in this browser. Use the secret or authenticator URI below."
										)}
									</div>
								)}
							</div>
							<div className="settings-security-two-factor-secret-stack">
								<div className="settings-security-two-factor-secret-card">
									<p className="settings-security-two-factor-secret-label">
										{__("Secret")}
									</p>
									<ReadOnlyValueBlock
										value={secret}
										className="settings-security-two-factor-secret-value"
										valueClassName="settings-security-two-factor-secret-value-text"
									/>
								</div>
								{otpauthUrl ? (
									<div className="settings-security-two-factor-secret-card">
										<p className="settings-security-two-factor-secret-label">
											{__("Authenticator URI")}
										</p>
										<ReadOnlyValueBlock
											value={otpauthUrl}
											className="settings-security-two-factor-secret-value"
											valueClassName="settings-security-two-factor-secret-value-text"
										/>
									</div>
								) : null}
								<Input
									label={__("6-digit code")}
									valueDirection="ltr"
									value={verificationCode}
									onChange={(e) =>
										setVerificationCode(e.target.value)
									}
									placeholder={__("123456")}
								/>
								<div className="settings-security-two-factor-verify-actions">
									<Button
										size="sm"
										onClick={handleVerify}
										loading={isVerifying}
										className="settings-security-two-factor-verify-submit"
									>
										{__("Verify & Enable")}
									</Button>
									<Button
										variant="ghost"
										size="sm"
										onClick={() => {
											setQrDataUrl(null);
											setSecret(null);
											setOtpauthUrl(null);
											setVerificationCode("");
										}}
									>
										{__("Cancel")}
									</Button>
								</div>
							</div>
						</div>
					</div>
				)}

				{twoFactorEnabled && recentCodes.length > 0 && (
					<div className="settings-security-backup-codes">
						<div
							dir={direction}
							className="settings-security-backup-codes-header"
						>
							<div>
								<p className="settings-security-backup-codes-title">
									{__("New backup codes")}
								</p>
								<p className="settings-security-backup-codes-description">
									{__(
										"Save or download these codes now—they won't be shown again."
									)}
								</p>
							</div>
							<Button
								variant="outline"
								size="sm"
								onClick={handleDownloadRequest}
								disabled={
									recentCodes.length === 0 ||
									isDownloading ||
									isDownloadingFromApi
								}
							>
								<Download size={14} />
								{__("Download")}
							</Button>
						</div>
						<div className="settings-security-backup-codes-grid">
							{recentCodes.map((code) => (
								<div
									key={code}
									className="settings-security-backup-code"
								>
									{code}
								</div>
							))}
						</div>
					</div>
				)}
			</div>

			<div className="settings-security-sessions-card">
				<div
					dir={direction}
					className="settings-security-sessions-header"
				>
					<div>
						<h2 className="settings-security-card-title">
							{__("Active Sessions")}
						</h2>
						<span className="settings-security-sessions-count">
							{sprintf(
								__("%s active session(s)"),
								String(sessions.length)
							)}
						</span>
					</div>
					{otherActiveSessions.length > 0 ? (
						<Button
							variant="outline"
							size="sm"
							onClick={() => setShowRevokeOthersConfirm(true)}
							loading={isRevokingOthers}
						>
							{__("End all other sessions")}
						</Button>
					) : null}
				</div>
				<div className="settings-security-sessions-list">
					{isSecurityLoading ? (
						<div className="settings-security-sessions-empty">
							{__("Loading security data...")}
						</div>
					) : sessions.length === 0 ? (
						<div className="settings-security-sessions-empty">
							{__("No active sessions found.")}
						</div>
					) : (
						sessions.map((session: SecuritySession) => {
							const locationLabel =
								getSessionLocationLabel(session);

							return (
								<div
									key={session.id}
									dir={direction}
									className={cn(
										"settings-security-session-row",
										session.revokedAt
											? "settings-security-session-row-ended"
											: ""
									)}
								>
									<div className="settings-security-session-meta">
										<div className="settings-security-session-icon-panel">
											<Monitor
												size={18}
												className="settings-security-session-icon"
											/>
										</div>
										<div className="settings-security-session-copy">
											<p className="settings-security-session-title">
												{session.browser ||
													__("Browser")}{" "}
												•{" "}
												{session.os || __("Unknown OS")}
											</p>
											<div className="settings-security-session-details">
												<span className="settings-security-session-detail settings-security-session-detail-location">
													<MapPin
														size={13}
														className="settings-security-session-detail-icon"
														aria-hidden="true"
													/>
													<span>{locationLabel}</span>
												</span>
												<span className="settings-security-session-detail">
													{session.ipAddress ||
														__("Unknown IP")}
												</span>
												<span className="settings-security-session-detail">
													{__("Last active")}{" "}
													{formatDateTimeValue(
														session.lastActiveAt,
														__("Unknown")
													)}
												</span>
											</div>
										</div>
									</div>
									<div className="settings-security-session-actions">
										{session.isCurrent && (
											<span className="settings-security-session-badge-current">
												{__("Current")}
											</span>
										)}
										{session.revokedAt ? (
											<span className="settings-security-session-badge-ended">
												{__("Ended")}
											</span>
										) : session.isCurrent ? null : (
											<Button
												variant="ghost"
												size="sm"
												onClick={() =>
													handleRevokeSession(
														session.id,
														session.isCurrent
													)
												}
												loading={
													revokingId === session.id
												}
											>
												{__("End session")}
											</Button>
										)}
									</div>
								</div>
							);
						})
					)}
				</div>
			</div>

			<ConfirmDialog
				open={Boolean(protectedAction)}
				onClose={closeProtectedActionDialog}
				title={
					protectedActionConfig?.title || __("Confirm your password")
				}
				description={protectedActionConfig?.description || ""}
				confirmText={
					protectedActionConfig?.confirmText || __("Continue")
				}
				confirmVariant={
					protectedActionConfig?.confirmVariant || "primary"
				}
				cancelText={__("Cancel")}
				onConfirm={handleProtectedAction}
				loading={isProtectedActionLoading}
			>
				<Input
					label={__("Current Password")}
					type="password"
					value={protectedActionPassword}
					onChange={(event) =>
						setProtectedActionPassword(event.target.value)
					}
					autoComplete="current-password"
					placeholder={__("Enter your current password")}
				/>
			</ConfirmDialog>

			<ConfirmDialog
				open={showRevokeOthersConfirm}
				onClose={() => {
					if (!isRevokingOthers) {
						setShowRevokeOthersConfirm(false);
					}
				}}
				title={__("End all other sessions")}
				description={sprintf(
					__(
						"End %s other active session(s) for this account? Any browsers or devices using them will be signed out immediately."
					),
					String(otherActiveSessions.length)
				)}
				confirmText={__("End sessions")}
				cancelText={__("Keep sessions")}
				onConfirm={handleRevokeOtherSessions}
				loading={isRevokingOthers}
			/>
		</div>
	);
}

export default SecurityTab;
