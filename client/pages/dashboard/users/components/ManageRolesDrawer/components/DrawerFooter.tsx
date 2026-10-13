import { RotateCcw, Save } from "lucide-react";

import type { RoleDefinition } from "@/api";
import { Button } from "@/components";
import { __ } from "@/i18n";

export interface DrawerFooterProps {
	activeRole?: RoleDefinition;
	isDirty: boolean;
	isSaving: boolean;
	onResetClick: () => void;
	onCancelClick: () => void;
	onSaveClick: () => void;
}

export function DrawerFooter({
	activeRole,
	isDirty,
	isSaving,
	onResetClick,
	onCancelClick,
	onSaveClick,
}: DrawerFooterProps) {
	return (
		<div className="users-roles-drawer-footer">
			<div>
				{activeRole ? (
					<Button
						type="button"
						variant="outline"
						size="sm"
						icon={RotateCcw}
						disabled={
							isSaving || (!activeRole.isCustomized && !isDirty)
						}
						onClick={onResetClick}
						title={__(
							"Reset this role's capabilities to shipped defaults"
						)}
					>
						<span>{__("Reset to Defaults")}</span>
					</Button>
				) : null}
			</div>

			<div className="flex items-center gap-2.5">
				<Button
					type="button"
					variant="secondary"
					size="sm"
					onClick={onCancelClick}
					disabled={isSaving}
				>
					<span>{__("Cancel")}</span>
				</Button>
				<Button
					type="button"
					size="sm"
					icon={Save}
					disabled={!isDirty || isSaving}
					onClick={onSaveClick}
				>
					<span>
						{isSaving ? __("Saving...") : __("Save Changes")}
					</span>
				</Button>
			</div>
		</div>
	);
}
