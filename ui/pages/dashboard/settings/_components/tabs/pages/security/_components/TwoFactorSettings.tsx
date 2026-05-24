import { RefreshCw, ShieldCheck, ShieldOff } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";

import BackupCodesList from "./BackupCodesList";
import TwoFactorSetup from "./TwoFactorSetup";
import TwoFactorStatus from "./TwoFactorStatus";
import type { TwoFactorSettingsProps } from "../types";

/**
 * Coordinates the visible two-factor authentication controls.
 */
function TwoFactorSettings({
	direction,
	twoFactorEnabled,
	actionLabel,
	statusMessage,
	backupCodesLastGeneratedAt,
	hasSetupDetails,
	qrDataUrl,
	secret,
	otpauthUrl,
	verificationCode,
	recentCodes,
	isStarting,
	isRegenerating,
	isDisabling,
	isDownloading,
	isDownloadingFromApi,
	isVerifying,
	onStartSetup,
	onOpenProtectedAction,
	onDownloadRequest,
	onVerify,
	onCancelSetup,
	onVerificationCodeChange,
}: TwoFactorSettingsProps) {
	return (
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
									onOpenProtectedAction("regenerate")
								}
								loading={isRegenerating}
							>
								<RefreshCw size={14} />
								{__("Regenerate Codes")}
							</Button>
							<Button
								variant="ghost"
								size="sm"
								onClick={() => onOpenProtectedAction("disable")}
								loading={isDisabling}
							>
								<ShieldOff size={14} />
								{__("Disable")}
							</Button>
						</>
					) : (
						<Button
							size="sm"
							onClick={onStartSetup}
							loading={isStarting}
						>
							<ShieldCheck size={14} />
							{actionLabel}
						</Button>
					)}
				</div>
			</div>

			<TwoFactorStatus
				direction={direction}
				twoFactorEnabled={twoFactorEnabled}
				statusMessage={statusMessage}
				backupCodesLastGeneratedAt={backupCodesLastGeneratedAt}
				isDownloading={isDownloading}
				isDownloadingFromApi={isDownloadingFromApi}
				onDownloadRequest={onDownloadRequest}
			/>

			{!twoFactorEnabled && hasSetupDetails ? (
				<TwoFactorSetup
					direction={direction}
					qrDataUrl={qrDataUrl}
					secret={secret}
					otpauthUrl={otpauthUrl}
					verificationCode={verificationCode}
					isVerifying={isVerifying}
					onVerify={onVerify}
					onCancelSetup={onCancelSetup}
					onVerificationCodeChange={onVerificationCodeChange}
				/>
			) : null}

			{twoFactorEnabled && recentCodes.length > 0 ? (
				<BackupCodesList
					direction={direction}
					recentCodes={recentCodes}
					isDownloading={isDownloading}
					isDownloadingFromApi={isDownloadingFromApi}
					onDownloadRequest={onDownloadRequest}
				/>
			) : null}
		</div>
	);
}

export default TwoFactorSettings;
