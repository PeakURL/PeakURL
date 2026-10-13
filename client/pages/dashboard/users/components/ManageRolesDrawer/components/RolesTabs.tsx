import { Plus } from "lucide-react";

import type { RoleDefinition } from "@/api";
import { Button } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";

export interface RolesTabsProps {
	roles: RoleDefinition[];
	selectedRoleId: string;
	onSelectRole: (roleId: string) => void;
	roleDrafts: Record<string, Record<string, boolean>>;
	onCreateRoleClick?: () => void;
	disabled?: boolean;
}

export function RolesTabs({
	roles,
	selectedRoleId,
	onSelectRole,
	roleDrafts,
	onCreateRoleClick,
	disabled,
}: RolesTabsProps) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-2 border-b border-stroke pb-3">
			<div className="flex flex-wrap items-center gap-1.5 min-w-0">
				{roles.map((role) => {
					const isActive = role.id === selectedRoleId;
					const drafts = roleDrafts[role.id];
					const roleHasDraftDirty = Boolean(
						drafts &&
						role.capabilities.some(
							(cap) =>
								cap.applicable !== false &&
								!cap.protected &&
								drafts[cap.id] !== undefined &&
								drafts[cap.id] !== cap.enabled
						)
					);

					return (
						<button
							key={role.id}
							type="button"
							onClick={() => onSelectRole(role.id)}
							disabled={disabled}
							className={cn(
								"users-roles-tab",
								isActive && "users-roles-tab-active",
								disabled && "opacity-70 cursor-not-allowed"
							)}
						>
							<span>{role.name}</span>
							{!role.isBuiltin ? (
								<span className="rounded-full bg-accent/15 px-1.5 py-0.2 text-[9px] font-bold text-accent">
									{__("Custom")}
								</span>
							) : role.isCustomized ? (
								<span className="users-roles-tab-badge-customized">
									{__("Customized")}
								</span>
							) : (
								<span className="users-roles-tab-badge-default">
									{__("Default")}
								</span>
							)}
							{roleHasDraftDirty ? (
								<span
									className="h-2 w-2 rounded-full bg-accent animate-pulse"
									title={__("Unsaved changes")}
								/>
							) : null}
						</button>
					);
				})}
			</div>

			{onCreateRoleClick ? (
				<Button
					type="button"
					variant="outline"
					size="xs"
					icon={Plus}
					onClick={onCreateRoleClick}
					disabled={disabled}
				>
					{__("Create Role")}
				</Button>
			) : null}
		</div>
	);
}
