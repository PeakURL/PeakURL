import { Download, RefreshCcw } from "lucide-react";

import { Button, type ButtonVariant } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/utils";

import type { UpdateActionsProps } from "../types";

/**
 * Renders the check, install, and reinstall action cluster.
 */
function UpdateActions({
	direction,
	updateAvailable,
	reinstallAvailable,
	canApply,
	isLoading,
	isChecking,
	isApplying,
	isReinstalling,
	isRepairing,
	disabledReason,
	onCheck,
	onApply,
	onReinstall,
}: UpdateActionsProps) {
	const isRtl = "rtl" === direction;
	const isInstallingRelease = isApplying || isReinstalling;
	const showDisabledReason =
		(updateAvailable || reinstallAvailable) &&
		!canApply &&
		Boolean(disabledReason);
	const primaryVariant: ButtonVariant = canApply ? "primary" : "outline";
	const primaryAction = updateAvailable ? (
		<Button
			variant={primaryVariant}
			size="sm"
			className="settings-updates-install-button"
			onClick={onApply}
			loading={isApplying}
			icon={Download}
			disabled={
				!canApply ||
				isLoading ||
				isChecking ||
				isRepairing ||
				isReinstalling
			}
			title={!canApply ? disabledReason || "" : ""}
		>
			{__("Install Update")}
		</Button>
	) : reinstallAvailable ? (
		<Button
			variant={primaryVariant}
			size="sm"
			className="settings-updates-reinstall-button"
			onClick={onReinstall}
			loading={isReinstalling}
			icon={RefreshCcw}
			disabled={
				!canApply ||
				isLoading ||
				isChecking ||
				isRepairing ||
				isApplying
			}
			title={!canApply ? disabledReason || "" : ""}
		>
			{__("Reinstall Latest Version")}
		</Button>
	) : null;
	const showCheckButton = reinstallAvailable || !updateAvailable;

	return (
		<div className="settings-updates-actions settings-updates-actions-start">
			<div className="settings-updates-actions-row settings-updates-actions-row-start">
				{showCheckButton ? (
					<Button
						variant="outline"
						size="sm"
						className="settings-updates-check-button"
						onClick={onCheck}
						loading={isChecking}
						icon={RefreshCcw}
						disabled={isInstallingRelease || isRepairing}
					>
						{__("Check for Updates")}
					</Button>
				) : null}
				{primaryAction}
			</div>

			{showDisabledReason ? (
				<div
					dir={direction}
					className="settings-updates-disabled-reason"
				>
					{disabledReason}
				</div>
			) : null}
		</div>
	);
}

export default UpdateActions;
