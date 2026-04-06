// @ts-nocheck
import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Button, ConfirmDialog, Input } from '@/components/ui';
import {
	ShieldCheck,
	ShieldOff,
	Monitor,
	RefreshCw,
	Download,
	AlertCircle,
} from 'lucide-react';
import {
	useGetSecuritySettingsQuery,
	useStartTwoFactorSetupMutation,
	useVerifyTwoFactorMutation,
	useDisableTwoFactorMutation,
	useRegenerateBackupCodesMutation,
	useDownloadBackupCodesMutation,
	useRevokeSessionMutation,
	useRevokeOtherSessionsMutation,
} from '@/store/slices/api/user';
import { __, sprintf } from '@/i18n';

const buildBackupCodesFile = (codes) =>
	[
		__('PeakURL Backup Codes'),
		__('Keep these codes safe. Each code can be used once.'),
		'',
		...codes.map((code) => `- ${code}`),
		'',
		sprintf(__('Generated at: %s'), new Date().toISOString()),
	].join('\n');

const formatTimestamp = (value) => {
	if (!value) return __('Unknown');
	try {
		return new Date(value).toLocaleString();
	} catch (error) {
		return __('Unknown');
	}
};

function SecurityTab({
	securityForm,
	setSecurityForm,
	onSubmit,
	isUpdating,
	notification,
}) {
	const [recentCodes, setRecentCodes] = useState([]);
	const [qrDataUrl, setQrDataUrl] = useState(null);
	const [secret, setSecret] = useState(null);
	const [otpauthUrl, setOtpauthUrl] = useState(null);
	const [verificationCode, setVerificationCode] = useState('');
	const [isDownloading, setIsDownloading] = useState(false);
	const [revokingId, setRevokingId] = useState(null);
	const [isRevokingOthers, setIsRevokingOthers] = useState(false);
	const [showRevokeOthersConfirm, setShowRevokeOthersConfirm] =
		useState(false);
	const [protectedAction, setProtectedAction] = useState(null);
	const [protectedActionPassword, setProtectedActionPassword] =
		useState('');

	const {
		data: securityData,
		isFetching: isSecurityLoading,
		refetch: refetchSecurity,
	} = useGetSecuritySettingsQuery();

	const security = securityData?.data || {};
	const sessions = security.sessions || [];
	const twoFactorEnabled = security.twoFactorEnabled;
	const hasPendingSetup = security.hasPendingSetup;
	const actionLabel = __('Set up 2FA');
	const statusMessage = twoFactorEnabled
		? sprintf(
				__('Backup codes remaining: %s'),
				security.backupCodesRemaining ?? 0
		  )
		: __('Enable to generate backup codes for account recovery.');

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
		(session) => !session.isCurrent && !session.revokedAt
	);
	const isProtectedActionLoading =
		'download' === protectedAction
			? isDownloading || isDownloadingFromApi
			: 'disable' === protectedAction
			? isDisabling
			: isRegenerating;
	const protectedActionConfig =
		'download' === protectedAction
			? {
					title: __('Download backup codes'),
					description: __(
						'Enter your current password to download the latest backup codes for this account.'
					),
					confirmText: __('Download'),
			  }
			: 'disable' === protectedAction
			? {
					title: __('Disable two-factor authentication'),
					description: __(
						'Enter your current password to disable two-factor authentication and clear the current backup codes for this account.'
					),
					confirmText: __('Disable'),
					confirmVariant: 'danger',
			  }
			: 'regenerate' === protectedAction
			? {
					title: __('Regenerate backup codes'),
					description: __(
						'Enter your current password to replace the existing backup codes with a new set.'
					),
					confirmText: __('Regenerate Codes'),
			  }
			: null;

	const closeProtectedActionDialog = () => {
		if (isProtectedActionLoading) {
			return;
		}

		setProtectedAction(null);
		setProtectedActionPassword('');
	};

	const openProtectedActionDialog = (action) => {
		if (isProtectedActionLoading) {
			return;
		}

		setProtectedAction(action);
		setProtectedActionPassword('');
	};

	const downloadBackupCodesFile = (content) => {
		const blob = new Blob([content], { type: 'text/plain' });
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = 'peakurl-backup-codes.txt';
		link.click();
		window.URL.revokeObjectURL(url);
	};

	const handleDownloadRequest = () => {
		if (recentCodes.length > 0) {
			downloadBackupCodesFile(buildBackupCodesFile(recentCodes));
			notification?.success(
				__('Backup codes downloaded'),
				__('PeakURL downloaded the visible backup codes for this account.')
			);
			return;
		}

		openProtectedActionDialog('download');
	};

	const startSetup = async (options = { silent: false }) => {
		try {
			const res = await startTwoFactor().unwrap();
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
			setVerificationCode('');
			setRecentCodes([]);
			if (!options.silent) {
				notification?.info(
					__('Scan the QR code'),
					__(
						'Scan with your authenticator app and enter the 6-digit code.'
					)
				);
			}
		} catch (err) {
			if (!options.silent) {
				notification?.error(
					__('Error'),
					err?.data?.message ||
						__('Failed to start two-factor authentication setup')
				);
			}
		}
	};

	const handleStartSetup = () => startSetup({ silent: false });

	const handleVerify = async () => {
		if (!verificationCode.trim()) {
			notification?.error(
				__('Error'),
				__('Enter the 6-digit code to verify')
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
			setVerificationCode('');
			notification?.success(
				__('Two-factor enabled'),
				__('Backup codes generated. Store them safely.')
			);
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__('Error'),
				err?.data?.message ||
					__('Failed to verify code. Check the code and try again.')
			);
		}
	};

	const handleProtectedAction = async () => {
		if (!protectedAction) {
			return;
		}

		if (!protectedActionPassword) {
			notification?.error(
				__('Error'),
				__('Enter your current password to continue')
			);
			return;
		}

		const currentPassword = protectedActionPassword;
		const action = protectedAction;

		try {
			if ('disable' === action) {
				await disableTwoFactor({
					currentPassword,
				}).unwrap();
				setRecentCodes([]);
				setQrDataUrl(null);
				setSecret(null);
				setOtpauthUrl(null);
				setVerificationCode('');
				notification?.info(
					__('Two-factor disabled'),
					__('Backup codes cleared for this account.')
				);
				refetchSecurity();
			} else if ('regenerate' === action) {
				const res = await regenerateBackupCodes({
					currentPassword,
				}).unwrap();
				setRecentCodes(res?.data?.backupCodes || []);
				notification?.success(
					__('Backup codes refreshed'),
					__('Save the new codes before leaving this page.')
				);
				refetchSecurity();
			} else {
				setIsDownloading(true);
				const content = await downloadBackupCodes({
					currentPassword,
				}).unwrap();
				downloadBackupCodesFile(content);
				notification?.success(
					__('Backup codes downloaded'),
					__('PeakURL downloaded the latest backup codes for this account.')
				);
			}

			setProtectedAction(null);
			setProtectedActionPassword('');
		} catch (err) {
			notification?.error(
				__('Error'),
				err?.data?.message ||
					('disable' === action
						? __('Failed to disable two-factor authentication')
						: 'regenerate' === action
						? __('Failed to regenerate backup codes')
						: __('Failed to download backup codes'))
			);
		} finally {
			setIsDownloading(false);
		}
	};

	const handleRevokeSession = async (sessionId, isCurrent) => {
		setRevokingId(sessionId);
		try {
			await revokeSession(sessionId).unwrap();
			notification?.success(
				__('Session ended'),
				isCurrent
					? __('Current browser session ended.')
					: __('The session was revoked.')
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
				__('Error'),
				err?.data?.message || __('Failed to end the session')
			);
		} finally {
			setRevokingId(null);
		}
	};

	const handleRevokeOtherSessions = async () => {
		if ( 0 === otherActiveSessions.length ) {
			return;
		}

		setIsRevokingOthers(true);

		try {
			const result = await revokeOtherSessions().unwrap();
			const revokedCount = result?.data?.revokedCount ?? 0;
			setShowRevokeOthersConfirm(false);

			notification?.success(
				__('Other sessions ended'),
				revokedCount > 0
					? sprintf(
							__(
								'%1$s other session%2$s were ended.'
							),
							revokedCount,
							1 === revokedCount ? '' : 's'
					  )
					: __('No other active sessions were found.')
			);
			refetchSecurity();
		} catch (err) {
			notification?.error(
				__('Error'),
				err?.data?.message || __('Failed to end the other sessions')
			);
		} finally {
			setIsRevokingOthers(false);
		}
	};

	return (
		<div className="space-y-5">
			<div className="bg-surface border border-stroke rounded-lg p-5">
				<h2 className="text-base font-semibold text-heading mb-5">
					{__('Password & Security')}
				</h2>
				<div className="space-y-4">
					<Input
						label={__('Current Password')}
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
						label={__('New Password')}
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
						label={__('Confirm New Password')}
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
				<div className="flex justify-end mt-5">
					<Button size="sm" onClick={onSubmit} disabled={isUpdating}>
						{isUpdating ? __('Updating...') : __('Update Password')}
					</Button>
				</div>
			</div>

			<div className="bg-surface border border-stroke rounded-lg p-5 space-y-4">
				<div className="flex items-center justify-between">
					<div>
						<h2 className="text-base font-semibold text-heading">
							{__('Two-Factor Authentication')}
						</h2>
						<p className="text-sm text-text-muted mt-1">
							{__(
								'Add an extra layer of security with an authenticator app and backup codes.'
							)}
						</p>
					</div>
					<div className="flex items-center gap-2">
						{twoFactorEnabled ? (
							<>
								<Button
									variant="secondary"
									size="sm"
									onClick={() =>
										openProtectedActionDialog('regenerate')
									}
									loading={isRegenerating}
								>
									<RefreshCw size={14} />
									{__('Regenerate Codes')}
								</Button>
								<Button
									variant="ghost"
									size="sm"
									onClick={() =>
										openProtectedActionDialog('disable')
									}
									loading={isDisabling}
								>
									<ShieldOff size={14} />
									{__('Disable')}
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
					className={`flex items-start gap-3 p-3 rounded-lg border ${
						twoFactorEnabled
							? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
							: 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10'
					}`}
				>
					<div className="p-2 rounded-lg bg-surface shadow-sm">
						{twoFactorEnabled ? (
							<ShieldCheck
								size={18}
								className="text-emerald-600 dark:text-emerald-400"
							/>
						) : (
							<AlertCircle
								size={18}
								className="text-amber-600 dark:text-amber-400"
							/>
						)}
					</div>
					<div className="flex-1">
						<p className="font-medium text-sm text-heading">
							{twoFactorEnabled
								? __('2FA is enabled')
								: __('2FA is disabled')}
						</p>
						<p className="text-xs text-text-muted mt-1">
							{statusMessage}
						</p>
						{security.backupCodesLastGeneratedAt && (
							<p className="text-xs text-text-muted mt-1">
								{__('Last generated:')}{' '}
								{formatTimestamp(
									security.backupCodesLastGeneratedAt
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
						{__('Download')}
					</Button>
				</div>

				{!twoFactorEnabled && qrDataUrl && (
					<div className="border border-stroke rounded-lg p-4 space-y-3">
						<div className="flex items-start gap-3">
							<div className="p-2 rounded-lg bg-surface shadow-sm">
								<ShieldCheck
									size={18}
									className="text-accent"
								/>
							</div>
							<div className="flex-1">
								<p className="font-medium text-sm text-heading">
									{__('Scan the QR code')}
								</p>
								<p className="text-xs text-text-muted">
									{__(
										'Use an authenticator app (Google Authenticator, Authy, etc.) then enter the 6-digit code.'
									)}
								</p>
							</div>
						</div>
						<div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
							<div className="flex justify-center">
								{qrDataUrl ? (
									<img
										src={qrDataUrl}
										alt={__('TOTP QR code')}
										width={192}
										height={192}
										loading="lazy"
										className="w-48 h-48 border border-stroke rounded-lg bg-white"
									/>
								) : (
									<div className="w-48 h-48 border border-dashed border-stroke rounded-lg bg-surface-alt flex items-center justify-center text-center text-xs text-text-muted p-4">
										{__(
											'QR preview is unavailable in this browser. Use the secret or authenticator URI below.'
										)}
									</div>
								)}
							</div>
							<div className="space-y-3">
								<div className="text-xs text-text-muted break-all border border-dashed border-stroke rounded-lg p-3 bg-surface-alt">
									{__('Secret:')} {secret}
								</div>
								{otpauthUrl ? (
									<div className="text-xs text-text-muted break-all border border-dashed border-stroke rounded-lg p-3 bg-surface-alt">
										{__('Authenticator URI:')} {otpauthUrl}
									</div>
								) : null}
								<Input
									label={__('6-digit code')}
									value={verificationCode}
									onChange={(e) =>
										setVerificationCode(e.target.value)
									}
									placeholder={__('123456')}
								/>
								<div className="flex gap-2">
									<Button
										size="sm"
										onClick={handleVerify}
										loading={isVerifying}
										className="flex-1"
									>
										{__('Verify & Enable')}
									</Button>
									<Button
										variant="ghost"
										size="sm"
										onClick={() => {
											setQrDataUrl(null);
											setSecret(null);
											setOtpauthUrl(null);
											setVerificationCode('');
										}}
									>
										{__('Cancel')}
									</Button>
								</div>
							</div>
						</div>
					</div>
				)}

				{twoFactorEnabled && recentCodes.length > 0 && (
					<div className="border border-dashed border-stroke rounded-lg p-4">
						<div className="flex items-center justify-between">
							<div>
								<p className="font-medium text-sm text-heading">
									{__('New backup codes')}
								</p>
								<p className="text-xs text-text-muted">
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
								{__('Download')}
							</Button>
						</div>
						<div className="grid grid-cols-2 md:grid-cols-3 gap-2 mt-3">
							{recentCodes.map((code) => (
								<div
									key={code}
									className="px-3 py-2 rounded-md bg-surface-alt text-sm font-mono text-heading border border-stroke"
								>
									{code}
								</div>
							))}
						</div>
					</div>
				)}
			</div>

			<div className="bg-surface border border-stroke rounded-lg p-5">
				<div className="flex items-center justify-between mb-4">
					<div>
						<h2 className="text-base font-semibold text-heading">
							{__('Active Sessions')}
						</h2>
						<span className="text-xs text-text-muted">
							{sprintf(
								__(
									'%s active session(s)'
								),
								sessions.length
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
							{__('End all other sessions')}
						</Button>
					) : null}
				</div>
				<div className="space-y-3">
					{isSecurityLoading ? (
						<div className="p-3 border border-dashed border-stroke rounded-lg text-sm text-text-muted">
							{__('Loading security data...')}
						</div>
					) : sessions.length === 0 ? (
						<div className="p-3 border border-dashed border-stroke rounded-lg text-sm text-text-muted">
							{__('No active sessions found.')}
						</div>
					) : (
						sessions.map((session) => (
							<div
								key={session.id}
								className={`flex items-center justify-between p-3 border border-stroke rounded-lg ${
									session.revokedAt ? 'opacity-70' : ''
								}`}
							>
								<div className="flex items-center gap-3">
									<div className="p-2 rounded-md bg-surface-alt">
										<Monitor
											size={18}
											className="text-text-muted"
										/>
									</div>
									<div>
										<p className="font-medium text-sm text-heading">
											{session.browser || __('Browser')} •{' '}
											{session.os || __('Unknown OS')}
										</p>
										<p className="text-xs text-text-muted">
											{session.ipAddress || __('Unknown IP')}{' '}
											• {__('Last active')}{' '}
											{formatTimestamp(
												session.lastActiveAt
											)}
										</p>
									</div>
								</div>
								<div className="flex items-center gap-2">
									{session.isCurrent && (
										<span className="text-xs px-2 py-1 rounded bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">
											{__('Current')}
										</span>
									)}
									{session.revokedAt ? (
										<span className="text-xs px-2 py-1 rounded bg-surface-alt text-text-muted">
											{__('Ended')}
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
											loading={revokingId === session.id}
										>
											{__('End session')}
										</Button>
									)}
								</div>
							</div>
						))
					)}
				</div>
			</div>

			<ConfirmDialog
				open={Boolean(protectedAction)}
				onClose={closeProtectedActionDialog}
				title={
					protectedActionConfig?.title ||
					__('Confirm your password')
				}
				description={protectedActionConfig?.description || ''}
				confirmText={
					protectedActionConfig?.confirmText || __('Continue')
				}
				confirmVariant={
					protectedActionConfig?.confirmVariant || 'primary'
				}
				cancelText={__('Cancel')}
				onConfirm={handleProtectedAction}
				loading={isProtectedActionLoading}
			>
				<Input
					label={__('Current Password')}
					type="password"
					value={protectedActionPassword}
					onChange={(event) =>
						setProtectedActionPassword(event.target.value)
					}
					autoComplete="current-password"
					placeholder={__('Enter your current password')}
				/>
			</ConfirmDialog>

			<ConfirmDialog
				open={showRevokeOthersConfirm}
				onClose={() => {
					if (!isRevokingOthers) {
						setShowRevokeOthersConfirm(false);
					}
				}}
				title={__('End all other sessions')}
				description={sprintf(
					__(
						'End %s other active session(s) for this account? Any browsers or devices using them will be signed out immediately.'
					),
					otherActiveSessions.length
				)}
				confirmText={__('End sessions')}
				cancelText={__('Keep sessions')}
				onConfirm={handleRevokeOtherSessions}
				loading={isRevokingOthers}
			/>
		</div>
	);
}

export default SecurityTab;
