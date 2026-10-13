import type { RoleDefinition } from "@/api";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";

export interface RolesTabsProps {
	roles: RoleDefinition[];
	selectedRoleId: string;
	onSelectRole: (roleId: string) => void;
	roleDrafts: Record<string, Record<string, boolean>>;
	disabled?: boolean;
}

export function RolesTabs({
	roles,
	selectedRoleId,
	onSelectRole,
	roleDrafts,
	disabled,
}: RolesTabsProps) {
	return (
		<div className="flex items-center gap-2 border-b border-stroke pb-3">
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
						{role.isCustomized ? (
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
	);
}
