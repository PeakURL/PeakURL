import { Pencil, Sparkles, Trash2, Users } from "lucide-react";

import type { RoleDefinition } from "@/api";
import { Button } from "@/components";
import { __, _n, sprintf } from "@/i18n";

export interface RoleMetadataCardProps {
	role: RoleDefinition;
	onEditRoleClick?: () => void;
	onDeleteRoleClick?: () => void;
	disabled?: boolean;
}

export function RoleMetadataCard({
	role,
	onEditRoleClick,
	onDeleteRoleClick,
	disabled = false,
}: RoleMetadataCardProps) {
	const isCustom = !role.isBuiltin;

	return (
		<div className="rounded-xl border border-stroke bg-surface-alt/40 p-4 text-start space-y-3">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div className="flex flex-wrap items-center gap-2">
					<span className="font-semibold text-sm text-heading">
						{role.name}
					</span>
					{isCustom ? (
						<>
							<span className="inline-flex items-center gap-1 rounded-full bg-accent/10 px-2 py-0.5 text-[11px] font-semibold text-accent">
								<Sparkles size={11} />
								{__("Custom Role")}
							</span>
							<span className="inline-flex items-center rounded-full bg-surface-alt px-2 py-0.5 text-[10px] font-mono text-text-muted border border-stroke">
								@{role.id}
							</span>
						</>
					) : (
						<span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
							<Users size={12} />
							{__("Standard Role")}
						</span>
					)}
				</div>

				<div className="flex items-center gap-2">
					{isCustom ? (
						<span className="inline-flex items-center gap-1 rounded-full bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-text-muted border border-stroke">
							<Users size={11} />
							{role.userCount > 0
								? sprintf(
										_n(
											"%d user assigned",
											"%d users assigned",
											role.userCount
										),
										role.userCount
									)
								: __("0 users assigned")}
						</span>
					) : null}

					{role.isCustomized ? (
						<span className="text-xs font-medium text-accent">
							{__("Custom permissions active")}
						</span>
					) : (
						<span className="text-xs text-text-muted">
							{__("Using default permissions")}
						</span>
					)}
				</div>
			</div>

			{role.description ? (
				<p className="text-xs text-text-muted leading-relaxed">
					{role.description}
				</p>
			) : isCustom ? (
				<p className="text-xs text-text-muted/70 italic">
					{__("No description provided.")}
				</p>
			) : null}

			{isCustom ? (
				<div className="flex items-center justify-end gap-2 pt-1 border-t border-stroke/50">
					{onEditRoleClick ? (
						<Button
							type="button"
							variant="outline"
							size="xs"
							icon={Pencil}
							onClick={onEditRoleClick}
							disabled={disabled}
						>
							{__("Edit Details")}
						</Button>
					) : null}
					{onDeleteRoleClick ? (
						<Button
							type="button"
							variant="ghost"
							size="xs"
							icon={Trash2}
							className="text-rose-600 hover:text-rose-700 hover:bg-rose-500/10 dark:text-rose-400 dark:hover:bg-rose-500/20"
							onClick={onDeleteRoleClick}
							disabled={disabled}
							title={
								role.userCount > 0
									? sprintf(
											__(
												"Cannot delete: %d users assigned"
											),
											role.userCount
										)
									: __("Delete this custom role")
							}
						>
							{__("Delete Role")}
						</Button>
					) : null}
				</div>
			) : null}
		</div>
	);
}
