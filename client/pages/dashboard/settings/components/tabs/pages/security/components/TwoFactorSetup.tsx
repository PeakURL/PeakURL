import { ShieldCheck } from "lucide-react";

import { Button, Input, ReadOnlyValueBlock } from "@/components";
import { __ } from "@/i18n";

import type { TwoFactorSetupProps } from "../types";

/**
 * Renders the authenticator setup QR, secret, URI, and verification input.
 */
function TwoFactorSetup({
	direction,
	qrDataUrl,
	secret,
	otpauthUrl,
	verificationCode,
	isVerifying,
	onVerify,
	onCancelSetup,
	onVerificationCodeChange,
}: TwoFactorSetupProps) {
	return (
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
						onChange={(event) =>
							onVerificationCodeChange(event.target.value)
						}
						placeholder={__("123456")}
					/>
					<div className="settings-security-two-factor-verify-actions">
						<Button
							size="sm"
							onClick={onVerify}
							loading={isVerifying}
							className="settings-security-two-factor-verify-submit"
						>
							{__("Verify & Enable")}
						</Button>
						<Button
							variant="ghost"
							size="sm"
							onClick={onCancelSetup}
						>
							{__("Cancel")}
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
}

export default TwoFactorSetup;
