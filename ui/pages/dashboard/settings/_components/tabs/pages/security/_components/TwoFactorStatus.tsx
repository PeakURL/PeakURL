import { Button } from "@/components";
import { __ } from "@/i18n";
import { cn, formatDateTimeValue } from "@/utils";
import { AlertCircle, Download, ShieldCheck } from "lucide-react";
import type { TwoFactorStatusProps } from "../types";

/**
 * Shows the current 2FA state and the backup-code download action.
 */
function TwoFactorStatus({
	direction,
	twoFactorEnabled,
	statusMessage,
	backupCodesLastGeneratedAt,
	isDownloading,
	isDownloadingFromApi,
	onDownloadRequest,
}: TwoFactorStatusProps) {
	return (
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
				{backupCodesLastGeneratedAt ? (
					<p className="settings-security-two-factor-status-text">
						{__("Last generated:")}{" "}
						{formatDateTimeValue(
							backupCodesLastGeneratedAt,
							__("Unknown")
						)}
					</p>
				) : null}
			</div>
			<Button
				variant="outline"
				size="sm"
				onClick={onDownloadRequest}
				loading={isDownloading || isDownloadingFromApi}
				disabled={!twoFactorEnabled}
			>
				<Download size={14} />
				{__("Download")}
			</Button>
		</div>
	);
}

export default TwoFactorStatus;
