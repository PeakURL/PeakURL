import { Info, ShieldCheck, Users } from "lucide-react";

import type { RoleDefinition } from "@/api";
import { __ } from "@/i18n";

export interface RoleMetadataCardProps {
	role: RoleDefinition;
}

export function RoleMetadataCard({ role }: RoleMetadataCardProps) {
	return (
		<div className="rounded-xl border border-stroke bg-surface-alt/40 p-4 text-start space-y-2">
			<div className="flex items-center justify-between gap-3">
				<div className="flex items-center gap-2">
					<span className="font-semibold text-sm text-heading">
						{role.name}
					</span>
					{role.id === "admin" ? (
						<span className="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2 py-0.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400">
							<ShieldCheck size={12} />
							{__("System Administrator")}
						</span>
					) : (
						<span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
							<Users size={12} />
							{__("Standard Role")}
						</span>
					)}
				</div>

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

			<p className="text-xs text-text-muted leading-relaxed">
				{role.description}
			</p>

			{role.id === "admin" ? (
				<div className="flex items-start gap-2 pt-1 text-[11px] text-text-muted">
					<Info size={13} className="shrink-0 text-accent mt-0.5" />
					<span>
						{__(
							"Protected administrative capabilities cannot be disabled to safeguard site access and prevent administrative lockout."
						)}
					</span>
				</div>
			) : null}
		</div>
	);
}
