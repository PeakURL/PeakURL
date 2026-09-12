import { Download } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";

import type { BackupCodesListProps } from "../types";

/**
 * Displays newly generated backup codes while they are still available.
 */
function BackupCodesList({
	direction,
	recentCodes,
	isDownloading,
	isDownloadingFromApi,
	onDownloadRequest,
}: BackupCodesListProps) {
	return (
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
					onClick={onDownloadRequest}
					disabled={
						0 === recentCodes.length ||
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
					<div key={code} className="settings-security-backup-code">
						{code}
					</div>
				))}
			</div>
		</div>
	);
}

export default BackupCodesList;
