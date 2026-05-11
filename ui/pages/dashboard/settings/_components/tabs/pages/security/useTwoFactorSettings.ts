import { useEffect, useState } from "react";
import QRCode from "qrcode";
import {
	useDisableTwoFactorMutation,
	useDownloadBackupCodesMutation,
	useRegenerateBackupCodesMutation,
	useStartTwoFactorSetupMutation,
	useVerifyTwoFactorMutation,
} from "@/store/slices/api";
import { __, sprintf } from "@/i18n";
import { downloadBrowserFile, getErrorMessage } from "@/utils";
import {
	BACKUP_CODES_FILENAME,
	createBackupCodesFile,
	getProtectedActionConfig,
	getProtectedActionErrorMessage,
} from "./helpers";
import type {
	ProtectedAction,
	SecuritySettingsPayload,
	SecurityTabProps,
} from "../types";

interface UseTwoFactorSettingsOptions {
	notification: SecurityTabProps["notification"];
	refetchSecurity: () => unknown;
	security: SecuritySettingsPayload;
}

/**
 * Owns 2FA setup, backup-code, and protected-action state.
 */
export function useTwoFactorSettings({
	notification,
	refetchSecurity,
	security,
}: UseTwoFactorSettingsOptions) {
	const [recentCodes, setRecentCodes] = useState<string[]>([]);
	const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
	const [secret, setSecret] = useState<string | null>(null);
	const [otpauthUrl, setOtpauthUrl] = useState<string | null>(null);
	const [verificationCode, setVerificationCode] = useState("");
	const [isDownloading, setIsDownloading] = useState(false);
	const [protectedAction, setProtectedAction] =
		useState<ProtectedAction | null>(null);
	const [protectedActionPassword, setProtectedActionPassword] = useState("");

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

	const twoFactorEnabled = security.twoFactorEnabled;
	const actionLabel = security.hasPendingSetup
		? __("Continue 2FA setup")
		: __("Set up 2FA");
	const statusMessage = twoFactorEnabled
		? sprintf(
				__("Backup codes remaining: %s"),
				String(security.backupCodesRemaining ?? 0)
			)
		: __("Enable to generate backup codes for account recovery.");
	const hasSetupDetails = Boolean(qrDataUrl || secret || otpauthUrl);
	const isProtectedActionLoading = protectedAction
		? "download" === protectedAction
			? isDownloading || isDownloadingFromApi
			: "disable" === protectedAction
				? isDisabling
				: isRegenerating
		: false;
	const protectedActionConfig = getProtectedActionConfig(protectedAction);

	useEffect(() => {
		if (!twoFactorEnabled) {
			setRecentCodes([]);
		}
	}, [twoFactorEnabled]);

	const clearSetupDetails = () => {
		setQrDataUrl(null);
		setSecret(null);
		setOtpauthUrl(null);
		setVerificationCode("");
	};

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

	const handleDownloadRequest = () => {
		if (recentCodes.length > 0) {
			downloadBrowserFile(
				createBackupCodesFile(recentCodes),
				BACKUP_CODES_FILENAME
			);
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
					// Keep QR generation client-side so API responses stay transport-only.
					nextQrDataUrl = await QRCode.toDataURL(setupOtpauthUrl, {
						width: 224,
						margin: 1,
					});
				} catch {
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

	const handleStartSetup = () => {
		void startSetup({ silent: false });
	};

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
			clearSetupDetails();
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
			// Each protected 2FA action shares one password prompt but mutates different state.
			if ("disable" === action) {
				await disableTwoFactor({
					currentPassword,
				}).unwrap();
				setRecentCodes([]);
				clearSetupDetails();
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
				downloadBrowserFile(content, BACKUP_CODES_FILENAME);
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
				getErrorMessage(err, getProtectedActionErrorMessage(action))
			);
		} finally {
			setIsDownloading(false);
		}
	};

	return {
		dialog: {
			config: protectedActionConfig,
			isLoading: isProtectedActionLoading,
			onClose: closeProtectedActionDialog,
			onConfirm: handleProtectedAction,
			onPasswordChange: setProtectedActionPassword,
			open: Boolean(protectedAction),
			password: protectedActionPassword,
		},
		section: {
			actionLabel,
			backupCodesLastGeneratedAt: security.backupCodesLastGeneratedAt,
			hasSetupDetails,
			isDisabling,
			isDownloading,
			isDownloadingFromApi,
			isRegenerating,
			isStarting,
			isVerifying,
			onCancelSetup: clearSetupDetails,
			onDownloadRequest: handleDownloadRequest,
			onOpenProtectedAction: openProtectedActionDialog,
			onStartSetup: handleStartSetup,
			onVerificationCodeChange: setVerificationCode,
			onVerify: handleVerify,
			otpauthUrl,
			qrDataUrl,
			recentCodes,
			secret,
			statusMessage,
			twoFactorEnabled,
			verificationCode,
		},
	};
}
