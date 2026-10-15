import { RefreshCw, ShieldCheck, ShieldOff } from "lucide-react";

import { Button } from "@/components";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
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
		<section className="settings-section">
			<div
				dir={direction}
				className="settings-security-two-factor-header settings-section-heading"
			>
				<div className="settings-section-copy">
					<SectionTitle icon={ShieldCheck}>
						{__("Two-Factor Authentication")}
					</SectionTitle>
					<p className="settings-group-description">
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
					) : !hasSetupDetails ? (
						<Button
							size="sm"
							onClick={onStartSetup}
							loading={isStarting}
						>
							<ShieldCheck size={14} />
							{__("Enable 2FA")}
						</Button>
					) : null}
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
		</section>
	);
}

export default TwoFactorSettings;
