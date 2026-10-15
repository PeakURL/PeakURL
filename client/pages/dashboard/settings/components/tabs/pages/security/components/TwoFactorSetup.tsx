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
						{__("Connect your authenticator app")}
					</p>
					<p className="settings-security-two-factor-setup-description">
						{__(
							"Scan the QR code or enter the setup key in your authenticator app. Verify a code below to finish enabling 2FA."
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
								"QR preview unavailable. Enter the setup key in your authenticator app."
							)}
						</div>
					)}
				</div>
				<div className="settings-security-two-factor-secret-stack">
					<div className="settings-security-two-factor-secret-card">
						<p className="settings-security-two-factor-secret-label">
							{__("Setup key")}
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
						inputMode="numeric"
						autoComplete="one-time-code"
						maxLength={6}
						valueDirection="ltr"
						value={verificationCode}
						onChange={(event) =>
							onVerificationCodeChange(event.target.value)
						}
						placeholder={__("123456")}
						disabled={isVerifying}
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
							disabled={isVerifying}
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
